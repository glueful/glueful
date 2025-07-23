<?php

namespace Glueful\Services;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Cache\CacheStore;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Helpers\Utils;
use Glueful\Helpers\CacheHelper;
use Exception;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\DatabaseException;

/**
 * Service for collecting and analyzing API metrics
 *
 * This service handles recording API metrics, storing them efficiently,
 * and providing aggregated data for the admin dashboard.
 */
class ApiMetricsService
{
    private QueryBuilder $db;
    private SchemaManager $schemaManager;
    private ?CacheStore $cache;
    private string $metricsTable = 'api_metrics';
    private string $dailyMetricsTable = 'api_metrics_daily';
    private string $rateLimitsTable = 'api_rate_limits';

    // Configuration
    private int $metricsTTL = 86400 * 30; // Store raw metrics for 30 days
    private int $aggregatedMetricsTTL = 86400 * 365; // Store aggregated metrics for 1 year
    private int $metricsFlushThreshold = 50; // Flush to database after this many metrics
    private string $cacheKeyPrefix = 'api_metrics_';

    public function __construct(
        ?CacheStore $cache = null,
        ?Connection $connection = null,
        ?SchemaManager $schemaManager = null
    ) {
        try {
            // Assign dependencies with sensible defaults
            $this->cache = $cache ?? CacheHelper::createCacheInstance();
            $connection = $connection ?? new Connection();
            $this->schemaManager = $schemaManager ?? $connection->getSchemaManager();

            // Initialize derived dependencies
            $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Ensure metrics tables exist
            $this->ensureTablesExist();
        } catch (\Exception $e) {
            error_log("ApiMetricsService CRITICAL ERROR: Service initialization failed: " . $e->getMessage());
            error_log($e->getTraceAsString());
            throw $e; // Re-throw so the middleware knows the service failed
        }
    }


    /**
     * Record a metric asynchronously to avoid impacting API performance
     *
     * @param array $metric The metric data to record
     */
    public function recordMetricAsync(array $metric): void
    {
        try {
            // Queue the metric for batch processing
            $cacheKey = $this->cacheKeyPrefix . 'pending';

            // Get existing metrics from cache
            try {
                $pendingMetrics = $this->cache?->get($cacheKey);
            } catch (\Exception $e) {
                $pendingMetrics = null;
            }

            // Initialize as empty array if null
            if ($pendingMetrics === null) {
                $pendingMetrics = [];
            }

            // Add the new metric
            $pendingMetrics[] = $metric;
            $count = count($pendingMetrics);

            // Store back in cache with a 24-hour TTL to prevent loss
            try {
                $this->cache?->set($cacheKey, $pendingMetrics, 86400);
            } catch (\Exception $e) {
                // Silently continue - we don't want metrics to break functionality
            }

            // If we've reached the threshold, flush metrics to database
            if ($count >= $this->metricsFlushThreshold) {
                try {
                    $this->flushMetrics();
                } catch (\Exception $e) {
                    // Continue silently
                }
            }

            // Update current rate limit counters for this IP and endpoint
            try {
                $this->updateRateLimit($metric['ip'], $metric['endpoint']);
            } catch (\Exception $e) {
                // Continue silently
            }
        } catch (\Exception $e) {
            // Silently continue - metrics should not affect main functionality
        }
    }

    /**
     * Flush metrics from cache to database
     */
    public function flushMetrics(): void
    {
        // Initialize the variable before the try block to avoid undefined variable in catch
        $pendingMetrics = [];
        try {
            $cacheKey = $this->cacheKeyPrefix . 'pending';
            $pendingMetrics = $this->cache?->get($cacheKey) ?? [];

            if (empty($pendingMetrics)) {
                error_log("API Metrics: No pending metrics to flush");
                return;
            }

            // Reset the pending metrics list first to avoid losing metrics
            // if an error occurs during processing
            $this->cache?->set($cacheKey, []);

            // Insert raw metrics in batch
            $rawMetricsToInsert = [];
            foreach ($pendingMetrics as $metric) {
                $rawMetricsToInsert[] = [
                    'uuid' => Utils::generateNanoID(),
                    'endpoint' => $metric['endpoint'],
                    'method' => $metric['method'],
                    'response_time' => $metric['response_time'],
                    'status_code' => $metric['status_code'],
                    'is_error' => $metric['is_error'] ? 1 : 0,
                    'timestamp' => date('Y-m-d H:i:s', $metric['timestamp']),
                    'ip' => $metric['ip']
                ];
            }

            // Batch insert raw metrics
            if (!empty($rawMetricsToInsert)) {
                try {
                    $this->db->insertBatch($this->metricsTable, $rawMetricsToInsert);
                } catch (Exception $e) {
                    error_log("API Metrics ERROR: Batch insert failed: " . $e->getMessage());
                }
            }

            // Update daily aggregates in bulk to avoid N+1 queries
            $this->updateDailyAggregatesBulk($pendingMetrics);

            // Purge old metrics to keep the database size manageable
            $this->purgeOldMetrics();
        } catch (Exception $e) {
            error_log("Error flushing API metrics: " . $e->getMessage());

            // If an error occurs, put the metrics back in the queue to try again later
            $currentPending = $this->cache->get($this->cacheKeyPrefix . 'pending') ?? [];
            $this->cache->set($this->cacheKeyPrefix . 'pending', array_merge($currentPending, $pendingMetrics));
        }
    }

    /**
     * Update daily aggregates for the metrics in bulk to avoid N+1 queries
     *
     * @param array $metrics Array of metric data
     */
    private function updateDailyAggregatesBulk(array $metrics): void
    {
        if (empty($metrics)) {
            return;
        }

        // Group metrics by date and endpoint_key
        $aggregates = [];
        foreach ($metrics as $metric) {
            $date = date('Y-m-d', $metric['timestamp']);
            $key = $metric['endpoint'] . '|' . $metric['method'];
            $combinedKey = $date . '|' . $key;

            if (!isset($aggregates[$combinedKey])) {
                $aggregates[$combinedKey] = [
                    'date' => $date,
                    'endpoint' => $metric['endpoint'],
                    'method' => $metric['method'],
                    'endpoint_key' => $key,
                    'calls' => 0,
                    'total_response_time' => 0,
                    'error_count' => 0,
                    'last_called' => date('Y-m-d H:i:s', $metric['timestamp'])
                ];
            }

            // Aggregate the metrics
            $aggregates[$combinedKey]['calls']++;
            $aggregates[$combinedKey]['total_response_time'] += $metric['response_time'];
            $aggregates[$combinedKey]['error_count'] += $metric['is_error'] ? 1 : 0;

            // Keep the latest timestamp
            $currentTimestamp = date('Y-m-d H:i:s', $metric['timestamp']);
            if (strtotime($currentTimestamp) > strtotime($aggregates[$combinedKey]['last_called'])) {
                $aggregates[$combinedKey]['last_called'] = $currentTimestamp;
            }
        }

        // Get unique date-endpoint_key combinations to check for existing records
        $dateEndpointKeys = [];
        foreach ($aggregates as $aggregate) {
            $dateEndpointKeys[] = [
                'date' => $aggregate['date'],
                'endpoint_key' => $aggregate['endpoint_key']
            ];
        }

        // Fetch existing aggregates in bulk using QueryBuilder methods
        $existingAggregates = [];
        if (count($dateEndpointKeys) > 0) {
            // Group combinations by date for more efficient querying
            $dateGroups = [];
            foreach ($dateEndpointKeys as $combo) {
                $dateGroups[$combo['date']][] = $combo['endpoint_key'];
            }

            // Query each date group separately and combine results
            foreach ($dateGroups as $date => $endpointKeys) {
                $results = $this->db->select($this->dailyMetricsTable, ['*'])
                    ->where(['date' => $date])
                    ->whereIn('endpoint_key', $endpointKeys)
                    ->get();

                foreach ($results as $existing) {
                    $key = $existing['date'] . '|' . $existing['endpoint_key'];
                    $existingAggregates[$key] = $existing;
                }
            }
        }

        // Process updates and inserts
        $toUpdate = [];
        $toInsert = [];

        foreach ($aggregates as $combinedKey => $aggregate) {
            if (isset($existingAggregates[$combinedKey])) {
                // Update existing aggregate
                $existing = $existingAggregates[$combinedKey];
                $toUpdate[] = [
                    'id' => $existing['id'],
                    'calls' => $existing['calls'] + $aggregate['calls'],
                    'total_response_time' => $existing['total_response_time'] + $aggregate['total_response_time'],
                    'error_count' => $existing['error_count'] + $aggregate['error_count'],
                    'last_called' => $aggregate['last_called']
                ];
            } else {
                // Insert new aggregate
                $toInsert[] = [
                    'uuid' => Utils::generateNanoID(),
                    'date' => $aggregate['date'],
                    'endpoint' => $aggregate['endpoint'],
                    'method' => $aggregate['method'],
                    'endpoint_key' => $aggregate['endpoint_key'],
                    'calls' => $aggregate['calls'],
                    'total_response_time' => $aggregate['total_response_time'],
                    'error_count' => $aggregate['error_count'],
                    'last_called' => $aggregate['last_called']
                ];
            }
        }

        // Perform bulk operations in transaction for better performance
        $this->db->transaction(function () use ($toUpdate, $toInsert) {
            if (!empty($toUpdate)) {
                foreach ($toUpdate as $update) {
                    $id = $update['id'];
                    unset($update['id']);
                    $this->db->update($this->dailyMetricsTable, $update, ['id' => $id]);
                }
            }

            if (!empty($toInsert)) {
                $this->db->insertBatch($this->dailyMetricsTable, $toInsert);
            }
        });
    }

    /**
     * Update rate limit counters
     *
     * @param string $ip The client IP
     * @param string $endpoint The API endpoint
     */
    private function updateRateLimit(string $ip, string $endpoint): void
    {
        // Create a key for this IP + endpoint
        $key = 'rate_limit|' . $ip . '|' . $endpoint;
        $minute = floor(time() / 60) * 60; // Round to the current minute

        // Get current rate limit info from cache
        $rateLimit = $this->cache?->get($key) ?? [
            'count' => 0,
            'minute' => $minute,
            'limit' => 100 // Default limit (could be dynamic based on endpoint)
        ];

        // If this is a new minute, reset the counter
        if ($rateLimit['minute'] != $minute) {
            $rateLimit = [
                'count' => 1,
                'minute' => $minute,
                'limit' => $rateLimit['limit'] // Maintain the same limit
            ];
        } else {
            // Increment the counter
            $rateLimit['count']++;
        }

        // Store the updated rate limit info
        $this->cache?->set($key, $rateLimit, 3600); // Store for 1 hour

        // If the rate limit is approaching threshold, store in the database
        // for admin visibility in the dashboard
        if ($rateLimit['count'] >= $rateLimit['limit'] * 0.8) {
            // Delete existing record for this IP and endpoint first
            // Use hard delete (false for softDelete parameter) for metrics data
            $this->db->delete($this->rateLimitsTable, [
                'ip' => $ip,
                'endpoint' => $endpoint
            ], false);

            $this->db->insert($this->rateLimitsTable, [
                'uuid' => Utils::generateNanoID(),
                'ip' => $ip,
                'endpoint' => $endpoint,
                'remaining' => $rateLimit['limit'] - $rateLimit['count'],
                'limit' => $rateLimit['limit'],
                'reset_time' => date('Y-m-d H:i:s', $rateLimit['minute'] + 60),
                'usage_percentage' => ($rateLimit['count'] / $rateLimit['limit']) * 100
            ]);
        }
    }

    /**
     * Get API metrics for the admin dashboard
     *
     * @return array Metrics data
     */
    public function getApiMetrics(): array
    {
        // First, flush any pending metrics
        $this->flushMetrics();

        // Base data structure
        $result = [
            'endpoints' => [],
            'total_requests' => 0,
            'avg_response_time' => 0,
            'total_errors' => 0,
            'error_rate' => 0,
            'rate_limits' => [],
            'requests_over_time' => [],
            'categories' => [],
            'category_distribution' => []
        ];

        try {
            // Get the last 7 days of daily aggregates
            $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

            $dailyMetrics = $this->db->select($this->dailyMetricsTable, ['*'])
                ->where(['date' => ['>=', $sevenDaysAgo]])
                ->orderBy(['date' => 'ASC'])
                ->get();

            // If no records from last 7 days, just get the latest records
            if (empty($dailyMetrics)) {
                $dailyMetrics = $this->db->select($this->dailyMetricsTable, ['*'])
                    ->orderBy(['date' => 'DESC'])
                    ->limit(30)
                    ->get();
            }

            // Process daily metrics
            $endpointMap = [];
            $dateMap = [];
            $totalRequests = 0;
            $totalResponseTime = 0;
            $totalErrors = 0;

            foreach ($dailyMetrics as $metric) {
                $endpointKey = $metric['endpoint'] . '|' . $metric['method'];

                // Add to endpoint tracking
                if (!isset($endpointMap[$endpointKey])) {
                    $category = $this->getCategoryFromEndpoint($metric['endpoint']);

                    $endpointMap[$endpointKey] = [
                        'endpoint' => $metric['endpoint'],
                        'method' => $metric['method'],
                        'route' => $metric['endpoint'],
                        'calls' => 0,
                        'total_response_time' => 0,
                        'error_count' => 0,
                        'lastCalled' => null,
                        'category' => $category
                    ];
                }

                // Update endpoint stats
                $endpointMap[$endpointKey]['calls'] += $metric['calls'];
                $endpointMap[$endpointKey]['total_response_time'] += $metric['total_response_time'];
                $endpointMap[$endpointKey]['error_count'] += $metric['error_count'];

                // Update lastCalled if the current metric's timestamp is more recent
                // Check for null values to avoid passing null to strtotime()
                if (
                    $metric['last_called'] !== null &&
                    (!$endpointMap[$endpointKey]['lastCalled'] ||
                     strtotime($metric['last_called']) > strtotime((string)$endpointMap[$endpointKey]['lastCalled']))
                ) {
                    $endpointMap[$endpointKey]['lastCalled'] = $metric['last_called'];
                }

                // Track by date for time series
                $date = $metric['date'];
                if (!isset($dateMap[$date])) {
                    $dateMap[$date] = 0;
                }
                $dateMap[$date] += $metric['calls'];

                // Update totals
                $totalRequests += $metric['calls'];
                $totalResponseTime += $metric['total_response_time'];
                $totalErrors += $metric['error_count'];
            }

            // Format endpoints data
            $endpoints = [];
            foreach ($endpointMap as $data) {
                $avgResponseTime = $data['calls'] > 0 ?
                    $data['total_response_time'] / $data['calls'] : 0;

                $errorRate = $data['calls'] > 0 ?
                    ($data['error_count'] / $data['calls']) * 100 : 0;

                $endpoints[] = [
                    'endpoint' => $data['endpoint'],
                    'method' => $data['method'],
                    'route' => $data['route'],
                    'calls' => $data['calls'],
                    'avgResponseTime' => round($avgResponseTime, 2),
                    'errorRate' => round($errorRate, 2),
                    'lastCalled' => $data['lastCalled'] ?? '', // Ensure it's never null
                    'category' => $data['category']
                ];
            }

            // Sort endpoints by call volume
            usort($endpoints, function ($a, $b) {
                return $b['calls'] - $a['calls'];
            });

            // Format time series data
            $requestsOverTime = [];
            foreach ($dateMap as $date => $count) {
                $requestsOverTime[] = [
                    'date' => $date,
                    'count' => $count
                ];
            }

            // Get rate limits approaching threshold
            $rateLimits = $this->db->select($this->rateLimitsTable, ['*'])
                ->where(['usage_percentage' => ['>', 80]])
                ->orderBy(['usage_percentage' => 'DESC'])
                ->get();

            // Calculate overall stats
            $avgResponseTime = $totalRequests > 0 ?
                $totalResponseTime / $totalRequests : 0;

            $errorRate = $totalRequests > 0 ?
                ($totalErrors / $totalRequests) * 100 : 0;

            // Extract categories and build distribution
            $categories = array_unique(array_column($endpoints, 'category'));
            $categoryDist = [];

            foreach ($categories as $category) {
                $categoryEndpoints = array_filter($endpoints, function ($e) use ($category) {
                    return $e['category'] === $category;
                });

                $categoryDist[] = [
                    'category' => $category,
                    'count' => array_sum(array_column($categoryEndpoints, 'calls'))
                ];
            }

            // Build result
            $result = [
                'endpoints' => $endpoints,
                'total_requests' => $totalRequests,
                'avg_response_time' => round($avgResponseTime, 2),
                'total_errors' => $totalErrors,
                'error_rate' => round($errorRate, 2),
                'rate_limits' => $rateLimits,
                'requests_over_time' => $requestsOverTime,
                'categories' => $categories,
                'category_distribution' => $categoryDist,
                'top_endpoints' => array_slice($endpoints, 0, 5)
            ];
        } catch (Exception $e) {
            error_log("Error retrieving API metrics: " . $e->getMessage());
            // Return empty data structure if an error occurs
        }

        return $result;
    }

    /**
     * Reset API metrics
     *
     * @return bool Success status
     */
    public function resetApiMetrics(): bool
    {
        try {
            // Clear the metrics tables (use hard delete for metrics data)
            $this->db->delete($this->metricsTable, [], false);

            $this->db->delete($this->dailyMetricsTable, [], false);

            $this->db->delete($this->rateLimitsTable, [], false);

            // Clear cached metrics using the proper cache key prefix
            $this->cache?->delete($this->cacheKeyPrefix . 'pending');

            return true;
        } catch (Exception $e) {
            error_log("Error resetting API metrics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge old metrics to keep database size manageable
     */
    private function purgeOldMetrics(): void
    {
        try {
            // Remove detailed metrics older than the TTL
            $cutoff = date('Y-m-d H:i:s', time() - $this->metricsTTL);
            $this->db->delete($this->metricsTable, [
                'timestamp <' => $cutoff
            ], false); // Use hard delete

            // Remove aggregated metrics older than their TTL
            $aggCutoff = date('Y-m-d', time() - $this->aggregatedMetricsTTL);
            $this->db->delete($this->dailyMetricsTable, [
                'date <' => $aggCutoff
            ], false); // Use hard delete

            // Clean up old rate limits
            $rateLimitCutoff = date('Y-m-d H:i:s', time() - 3600); // 1 hour
            $this->db->delete($this->rateLimitsTable, [
                'reset_time <' => $rateLimitCutoff
            ], false); // Use hard delete
        } catch (Exception $e) {
            error_log("Error purging old API metrics: " . $e->getMessage());
        }
    }

    /**
     * Create necessary tables if they don't exist
     */
    private function ensureTablesExist(): void
    {
        try {
            // Check and create metrics table
            if (!$this->schemaManager->tableExists($this->metricsTable)) {
                $this->schemaManager->createTable($this->metricsTable, [
                    'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
                    'uuid' => 'CHAR(12) NOT NULL',
                    'endpoint' => 'VARCHAR(255) NOT NULL',
                    'method' => 'VARCHAR(10) NOT NULL',
                    'response_time' => 'FLOAT NOT NULL',
                    'status_code' => 'INT NOT NULL',
                    'is_error' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'timestamp' => 'DATETIME NOT NULL',
                    'ip' => 'VARCHAR(45) NOT NULL' // IPv6 compatible
                ])->addIndex([
                    [
                        'type' => 'INDEX',
                        'column' => 'timestamp',
                        'name' => 'idx_' . $this->metricsTable . '_timestamp'
                    ],
                    [
                        'type' => 'INDEX',
                        'column' => ['endpoint', 'method'],
                        'name' => 'idx_' . $this->metricsTable . '_endpoint_method'
                    ]
                ]);
            }

            // Check and create daily metrics table
            if (!$this->schemaManager->tableExists($this->dailyMetricsTable)) {
                $this->schemaManager->createTable($this->dailyMetricsTable, [
                    'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
                    'uuid' => 'CHAR(12) NOT NULL',
                    'date' => 'DATE NOT NULL',
                    'endpoint' => 'VARCHAR(255) NOT NULL',
                    'method' => 'VARCHAR(10) NOT NULL',
                    'endpoint_key' => 'VARCHAR(266) NOT NULL', // endpoint|method
                    'calls' => 'INT NOT NULL DEFAULT 0',
                    'total_response_time' => 'FLOAT NOT NULL DEFAULT 0',
                    'error_count' => 'INT NOT NULL DEFAULT 0',
                    'last_called' => 'DATETIME NULL'
                ])->addIndex([
                    [
                        'type' => 'UNIQUE',
                        'column' => ['date', 'endpoint_key'],
                        'name' => 'idx_' . $this->dailyMetricsTable . '_date_endpoint_key'
                    ],
                    [
                        'type' => 'INDEX',
                        'column' => 'date',
                        'name' => 'idx_' . $this->dailyMetricsTable . '_date'
                    ]
                ]);
            }

            // Check and create rate limits table
            if (!$this->schemaManager->tableExists($this->rateLimitsTable)) {
                $this->schemaManager->createTable($this->rateLimitsTable, [
                    'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
                    'uuid' => 'CHAR(12) NOT NULL',
                    'ip' => 'VARCHAR(45) NOT NULL',
                    'endpoint' => 'VARCHAR(255) NOT NULL',
                    'remaining' => 'INT NOT NULL',
                    'limit' => 'INT NOT NULL',
                    'reset_time' => 'DATETIME NOT NULL',
                    'usage_percentage' => 'FLOAT NOT NULL'
                ])->addIndex([
                    [
                        'type' => 'UNIQUE',
                        'column' => ['ip', 'endpoint'],
                        'name' => 'idx_' . $this->rateLimitsTable . '_ip_endpoint'
                    ]
                ]);
            }
        } catch (Exception $e) {
            error_log("Error ensuring API metrics tables exist: " . $e->getMessage());
        }
    }

    /**
     * Determine the category from an endpoint
     *
     * @param string $endpoint The API endpoint
     * @return string The category
     */
    private function getCategoryFromEndpoint(string $endpoint): string
    {
        // Simple categorization based on the first path segment after /api
        $parts = explode('/', trim($endpoint, '/'));

        // Skip 'api' prefix if it exists
        $categoryIndex = ($parts[0] === 'api' && count($parts) > 1) ? 1 : 0;

        return isset($parts[$categoryIndex]) ? ucfirst($parts[$categoryIndex]) : 'Other';
    }
}
