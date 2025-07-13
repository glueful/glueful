<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\ExtensionManager;
use Glueful\Database\Schema\SchemaManager;

class MetricsController extends BaseController
{
    private SchemaManager $schemaManager;

    public function __construct(
        ?\Glueful\Repository\RepositoryFactory $repositoryFactory = null,
        ?\Glueful\Auth\AuthenticationManager $authManager = null,
        ?\Symfony\Component\HttpFoundation\Request $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $request);
        $connection = $this->getConnection();
        $this->schemaManager = $connection->getSchemaManager();
    }

    /**
     * Get comprehensive API metrics and statistics
     *
     * Returns detailed metrics about API usage including endpoint performance,
     * request volumes, error rates, and rate limiting information.
     *
     * @return mixed HTTP response
     */
    /**
     * Get API metrics data without sending response (for internal use)
     *
     * @return array API metrics data
     */
    public function getApiMetricsData(): array
    {
        // Cache API metrics with permission-aware caching
        return $this->cacheByPermission(
            'api_metrics_data',
            function () {
                $metricsService = new \Glueful\Services\ApiMetricsService();
                return $metricsService->getApiMetrics();
            },
            $this->isAdmin() ? 60 : 300 // 1 min for admins, 5 min for users
        );
    }

    public function getApiMetrics(): mixed
    {
        $this->requirePermission('system.metrics.view', 'metrics:api');
        $this->conditionalRateLimit('api_metrics_view');


        // Use the new method to get data
        $endpointMetrics = $this->getApiMetricsData();

        // Use private caching for metrics (sensitive data)
        $ttl = $this->isAdmin() ? 60 : 300; // 1 min for admins, 5 min for users
        return $this->privateCached(
            Response::success($endpointMetrics, 'API metrics retrieved successfully'),
            $ttl
        );
    }

    /**
     * Reset API metrics statistics
     *
     * Clears stored metrics data for API endpoints.
     *
     * @return mixed HTTP response
     */
    public function resetApiMetrics(): mixed
    {
        $this->requirePermission('system.metrics.reset', 'metrics:api');
        $this->rateLimit('metrics_reset', 5, 3600);
        $this->requireLowRiskBehavior(0.4, 'metrics_reset');


        $metricsService = new \Glueful\Services\ApiMetricsService();
        $success = $metricsService->resetApiMetrics();

        if ($success) {
            // Invalidate all metrics-related caches
            $this->invalidateCache([
                'api_metrics_data',
                'system_health_metrics',
                'admin_cache',
                'user_cache',
                'metrics:api',
                'metrics:system'
            ]);

            return Response::success(null, 'API metrics reset successfully');
        } else {
            return Response::error(
                'Failed to reset API metrics',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get system health metrics
     *
     * Provides comprehensive metrics about the system's health including:
     * - PHP information and version
     * - Database connection status and statistics
     * - File system storage metrics
     * - Memory usage
     * - Cache status
     * - Extension status
     * - Recent errors/logs
     * - Application uptime
     *
     * @return mixed HTTP response with system health metrics
     */
    /**
     * Get system health data without sending response (for internal use)
     *
     * @return array System health data
     */
    public function getSystemHealthData(): array
    {
        // Use permission-aware caching for system health data
        return $this->cacheByPermission(
            'system_health_metrics',
            function () {
                return $this->generateSystemHealthMetrics();
            },
            $this->isAdmin() ? 300 : 600 // 5 min for admins, 10 min for users
        );
    }

    public function systemHealth(): mixed
    {
        $this->requirePermission('system.health.view', 'metrics:system');
        $this->multiLevelRateLimit('system_health', [
            'user' => ['attempts' => 60, 'window' => 60, 'adaptive' => true],
            'ip' => ['attempts' => 100, 'window' => 60, 'adaptive' => false]
        ]);
        $this->requireLowRiskBehavior(0.6, 'system_health_access');


        // Use the new method to get data
        $metrics = $this->getSystemHealthData();

        // Use private caching for system health metrics (sensitive data)
        $ttl = $this->isAdmin() ? 300 : 600; // 5 min for admins, 10 min for users
        return $this->privateCached(
            Response::success($metrics, 'System health metrics retrieved successfully'),
            $ttl
        );
    }

    /**
     * Generate system health metrics data
     *
     * @return array System health metrics
     */
    private function generateSystemHealthMetrics(): array
    {
        $metrics = [];

        // Filter PHP information based on permissions
        $phpInfo = [
            'version' => phpversion(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];

        // Add sensitive PHP info only for admins
        if ($this->isAdmin()) {
            $phpInfo['upload_max_filesize'] = ini_get('upload_max_filesize');
            $phpInfo['post_max_size'] = ini_get('post_max_size');
            $phpInfo['extensions'] = get_loaded_extensions();
        }

        $metrics['php'] = $phpInfo;

        // Memory usage
        $metrics['memory'] = [
            'current_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_usage' => $this->formatBytes(memory_get_peak_usage(true)),
        ];

        // Database health
        try {
            $dbStartTime = microtime(true);
            $databaseTables = $this->schemaManager->getTables();
            $dbResponseTime = microtime(true) - $dbStartTime;

            $dbMetrics = [
                'status' => 'connected',
                'response_time_ms' => round($dbResponseTime * 1000, 2),
                'table_count' => count($databaseTables),
            ];

            // Add sensitive database info only for admins
            if ($this->isAdmin()) {
                // Get database size (total of all tables)
                $totalSize = 0;
                foreach ($databaseTables as $table) {
                    $tableSize = $this->schemaManager->getTableSize($table);
                    $totalSize += $tableSize['size_bytes'] ?? 0;
                }
                $dbMetrics['total_size'] = $this->formatBytes($totalSize);
                $dbMetrics['table_names'] = $databaseTables;
            }

            $metrics['database'] = $dbMetrics;
        } catch (\Exception $e) {
            $metrics['database'] = [
                'status' => 'error',
                'error' => $this->isAdmin() ? $e->getMessage() : 'Database connection failed'
            ];
        }

            // File system metrics
            $storagePath = realpath(__DIR__ . '/../../storage');
            $fileSystemMetrics = [
                'storage_free_space' => $this->formatBytes(disk_free_space($storagePath)),
                'storage_total_space' => $this->formatBytes(disk_total_space($storagePath)),
                'storage_usage_percent' => $this->calculateStoragePercentage($storagePath)
            ];

            // Add sensitive file system info only for admins
            if ($this->isAdmin()) {
                $fileSystemMetrics['storage_path'] = $storagePath;
            }

            $metrics['file_system'] = $fileSystemMetrics;

            // Check for log files
            $logPath = realpath(__DIR__ . '/../../storage/logs');
            if ($logPath && is_dir($logPath)) {
                $logFiles = glob($logPath . '/*.log');
                $recentLogs = [];

                if (!empty($logFiles)) {
                    // Get the most recent log file
                    usort($logFiles, function ($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });

                    $mostRecentLog = $logFiles[0];
                    // Get the last 10 lines from the most recent log file
                    $recentLogContent = file_exists($mostRecentLog) ?
                        array_slice(file($mostRecentLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -10) : [];

                    $recentLogs = [
                        'file' => basename($mostRecentLog),
                        'last_modified' => date('Y-m-d H:i:s', filemtime($mostRecentLog)),
                        'recent_entries' => $recentLogContent
                    ];
                }

                $metrics['logs'] = [
                    'log_file_count' => count($logFiles),
                    'recent_logs' => $recentLogs
                ];
            }

            // Cache status
            if (function_exists('\apcu_cache_info')) {
                try {
                    $cacheInfo = \apcu_cache_info(true);
                    $metrics['cache'] = [
                        'type' => 'APCu',
                        'status' => 'enabled',
                        'memory_usage' => $this->formatBytes($cacheInfo['mem_size']),
                        'hit_rate' => $this->calculateHitRate(
                            $cacheInfo['num_hits'],
                            $cacheInfo['num_misses']
                        ),
                    ];
                } catch (\Exception $e) {
                    $metrics['cache'] = [
                        'type' => 'APCu',
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            } else {
                // Check if Redis is available
                if (class_exists('Redis')) {
                    try {
                        $redis = new \Redis();
                        $redis->connect('127.0.0.1', 6379, 1);
                        $info = $redis->info();
                        $metrics['cache'] = [
                            'type' => 'Redis',
                            'status' => 'enabled',
                            'version' => $info['redis_version'] ?? 'unknown',
                            'memory_usage' => $this->getRedisMemoryUsage($info),
                            'connected_clients' => $info['connected_clients'] ?? 0,
                        ];
                        $redis->close();
                    } catch (\Exception $e) {
                        $metrics['cache'] = [
                            'type' => 'Redis',
                            'status' => 'error',
                            'error' => $e->getMessage()
                        ];
                    }
                } else {
                    $metrics['cache'] = [
                        'type' => 'unknown',
                        'status' => 'not configured'
                    ];
                }
            }

            // Extensions status - get from config without loading classes
            try {
                $extensionManager = container()->get(ExtensionManager::class);
                $globalConfig = $extensionManager->getGlobalConfig();
                $extensionConfigFile = $globalConfig['config_path'] ?? 'config/extensions.json';
                $content = file_get_contents($extensionConfigFile);
                $config = json_decode($content, true);

                $extensionStatus = [];
                $enabledCount = 0;

                if (is_array($config) && isset($config['extensions'])) {
                    foreach ($config['extensions'] as $extensionName => $extensionInfo) {
                        $isEnabled = $extensionInfo['enabled'] ?? false;
                        if ($isEnabled) {
                            $enabledCount++;
                        }

                        $extensionStatus[] = [
                            'name' => $extensionName,
                            'status' => $isEnabled ? 'enabled' : 'disabled',
                            'version' => $extensionInfo['version'] ?? 'unknown',
                        ];
                    }
                }

                $metrics['extensions'] = [
                    'total_count' => count($extensionStatus),
                    'enabled_count' => $enabledCount,
                    'extensions' => $extensionStatus
                ];
            } catch (\Exception $e) {
                $metrics['extensions'] = [
                    'total_count' => 0,
                    'enabled_count' => 0,
                    'extensions' => [],
                    'error' => 'Failed to load extension information: ' . $e->getMessage()
                ];
            }

            // Server load
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                $metrics['server_load'] = [
                    '1min' => $load[0],
                    '5min' => $load[1],
                    '15min' => $load[2]
                ];
            }

            // Application uptime (if possible)
            if (function_exists('posix_times')) {
                $uptime = posix_times();
                $metrics['app_uptime'] = [
                    'system_seconds' => $uptime['ticks'],
                    'formatted' => $this->formatUptime($uptime['ticks'])
                ];
            }

            // Current time and timezone
            $metrics['time'] = [
                'current' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get()
            ];

            // Apply user context filtering before returning
            return $this->filterMetricsByUserContext($metrics);
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int|float $bytes Number of bytes
     * @param int $precision Precision of rounding
     * @return string Formatted size with unit
     */
    private function formatBytes($bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max((float)$bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Format system uptime to human-readable format
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted uptime
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $seconds %= 86400;

        $hours = floor($seconds / 3600);
        $seconds %= 3600;

        $minutes = floor($seconds / 60);
        $seconds %= 60;

        $result = '';
        if ($days > 0) {
            $result .= $days . ' days, ';
        }

        return $result . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Calculate storage usage percentage
     *
     * @param string $path Storage path
     * @return string Formatted percentage
     */
    private function calculateStoragePercentage(string $path): string
    {
        $freeSpace = disk_free_space($path);
        $totalSpace = disk_total_space($path);
        return round((1 - $freeSpace / $totalSpace) * 100, 2) . '%';
    }

    /**
     * Calculate cache hit rate
     *
     * @param int $hits Number of cache hits
     * @param int $misses Number of cache misses
     * @return string Formatted hit rate percentage
     */
    private function calculateHitRate(float|int $hits, float|int $misses): string
    {
        if ($hits > 0) {
            return round($hits / ($hits + $misses) * 100, 2) . '%';
        }
        return '0%';
    }

    /**
     * Get Redis memory usage
     *
     * @param array $info Redis info array
     * @return string Formatted memory usage
     */
    private function getRedisMemoryUsage(array $info): string
    {
        return isset($info['used_memory'])
            ? $this->formatBytes((int)$info['used_memory'])
            : 'unknown';
    }

    /**
     * Get health status for a specific extension
     *
     * @param array|null $extension Extension information array
     * @return mixed HTTP response
     */
    public function getExtensionHealth(?array $extension): mixed
    {
        $this->requirePermission('system.extensions.health.view', 'metrics:extensions');
        $this->rateLimit('extension_health', 30, 60);

        $extensionName = $extension['name'] ?? 'unknown';


        if (!isset($extension['name'])) {
            return Response::error(
                'Extension name is required',
                Response::HTTP_BAD_REQUEST
            );
        }

        $extensionName = $extension['name'];

        $extensionManager = container()->get(ExtensionManager::class);
        if (!$extensionManager->isInstalled($extensionName)) {
            return Response::error(
                'Extension not found',
                Response::HTTP_NOT_FOUND,
                ['extension_name' => $extensionName]
            );
        }

        // Cache extension health data
        $health = $this->cacheResponse(
            "extension_health_{$extensionName}",
            function () use ($extensionName) {
                $extensionManager = container()->get(ExtensionManager::class);
                return $extensionManager->checkHealth($extensionName);
            },
            600, // 10 minutes
            ['extensions', 'extension:' . $extensionName]
        );

        // Filter health data based on permissions
        $healthData = $this->filterExtensionHealthData($health, $extensionName);

        return $this->withSecurityHeaders(
            $this->withCacheHeaders(
                Response::success([
                    'extension' => $extensionName,
                    'health' => $healthData
                ], 'Extension health status retrieved successfully'),
                [
                    'public' => false,
                    'max_age' => 600,
                    'must_revalidate' => true,
                    'vary' => ['Authorization']
                ]
            )
        );
    }

    /**
     * Filter extension health data based on user permissions
     *
     * @param array $health Raw health data
     * @param string $extensionName Extension name
     * @return array Filtered health data
     */
    private function filterExtensionHealthData(array $health, string $extensionName): array
    {
        // Basic health info available to all users with permission
        $filteredHealth = [
            'status' => $health['status'] ?? 'unknown',
            'enabled' => $health['enabled'] ?? false
        ];

        // Add more detailed information for admins
        if ($this->isAdmin()) {
            $filteredHealth = array_merge($filteredHealth, [
                'version' => $health['version'] ?? 'unknown',
                'last_checked' => $health['last_checked'] ?? null,
                'errors' => $health['errors'] ?? [],
                'warnings' => $health['warnings'] ?? [],
                'dependencies' => $health['dependencies'] ?? [],
                'file_integrity' => $health['file_integrity'] ?? null
            ]);
        } elseif ($this->can('extensions.detailed_health', $extensionName)) {
            // Premium users or those with detailed permissions get version info
            $filteredHealth['version'] = $health['version'] ?? 'unknown';
            $filteredHealth['last_checked'] = $health['last_checked'] ?? null;
        }

        return $filteredHealth;
    }

    /**
     * Apply context-aware data filtering for system metrics
     *
     * @param array $metrics Raw metrics data
     * @return array Filtered metrics based on user context
     */
    private function filterMetricsByUserContext(array $metrics): array
    {
        if ($this->isAdmin()) {
            // Admins get all data
            return $metrics;
        }

        // Remove sensitive paths and detailed system information for non-admins
        if (isset($metrics['file_system']['storage_path'])) {
            unset($metrics['file_system']['storage_path']);
        }

        if (isset($metrics['logs']['recent_logs']['recent_entries'])) {
            // Limit log entries for non-admins
            $metrics['logs']['recent_logs']['recent_entries'] = array_slice(
                $metrics['logs']['recent_logs']['recent_entries'],
                -3 // Only last 3 entries
            );
        }

        // Remove detailed PHP extensions list for non-admins
        if (isset($metrics['php']['extensions'])) {
            $metrics['php']['extensions'] = count($metrics['php']['extensions']);
        }

        return $metrics;
    }

    /**
     * Add security headers to response for metrics endpoints
     *
     * @param Response $response Response object
     * @return Response Response with security headers
     */
    private function withSecurityHeaders(Response $response): Response
    {
        // Security headers for sensitive metrics data
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Content Security Policy for JSON responses
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        // Ensure no sensitive data is cached by browsers
        if (!$this->isAdmin()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        return $response;
    }
}
