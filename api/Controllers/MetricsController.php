<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\{Request, ExtensionsManager};
use Glueful\Database\{Connection, QueryBuilder};
use Glueful\Database\Schema\SchemaManager;

class MetricsController
{
    private SchemaManager $schemaManager;
    private QueryBuilder $queryBuilder;

    public function __construct()
    {
        $connection = new Connection();
        $this->schemaManager = $connection->getSchemaManager();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Get comprehensive API metrics and statistics
     *
     * Returns detailed metrics about API usage including endpoint performance,
     * request volumes, error rates, and rate limiting information.
     *
     * @return mixed HTTP response
     */
    public function getApiMetrics(): mixed
    {
        try {
            // Use the real API metrics service to get metrics
            $metricsService = new \Glueful\Services\ApiMetricsService();
            $endpointMetrics = $metricsService->getApiMetrics();

            return Response::ok($endpointMetrics, 'API metrics retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get API metrics error: " . $e->getMessage());
            return Response::error(
                'Failed to get API metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
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
        try {
            $metricsService = new \Glueful\Services\ApiMetricsService();
            $success = $metricsService->resetApiMetrics();

            if ($success) {
                return Response::ok(null, 'API metrics reset successfully')->send();
            } else {
                return Response::error(
                    'Failed to reset API metrics',
                    Response::HTTP_INTERNAL_SERVER_ERROR
                )->send();
            }
        } catch (\Exception $e) {
            error_log("Reset API metrics error: " . $e->getMessage());
            return Response::error(
                'Failed to reset API metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
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
    public function systemHealth(): mixed
    {
        try {
            $metrics = [];

            // PHP information
            $metrics['php'] = [
                'version' => phpversion(),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'extensions' => get_loaded_extensions(),
            ];

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

                $metrics['database'] = [
                    'status' => 'connected',
                    'response_time_ms' => round($dbResponseTime * 1000, 2),
                    'table_count' => count($databaseTables),
                ];

                // Get database size (total of all tables)
                $totalSize = 0;
                foreach ($databaseTables as $table) {
                    $tableSize = $this->schemaManager->getTableSize($table);
                    $totalSize += $tableSize['size_bytes'] ?? 0;
                }
                $metrics['database']['total_size'] = $this->formatBytes($totalSize);
            } catch (\Exception $e) {
                $metrics['database'] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }

            // File system metrics
            $storagePath = realpath(__DIR__ . '/../../storage');
            $metrics['file_system'] = [
                'storage_path' => $storagePath,
                'storage_free_space' => $this->formatBytes(disk_free_space($storagePath)),
                'storage_total_space' => $this->formatBytes(disk_total_space($storagePath)),
                'storage_usage_percent' => $this->calculateStoragePercentage($storagePath)
            ];

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

            // Extensions status
            $extensions = ExtensionsManager::getLoadedExtensions();
            $enabledExtensions = ExtensionsManager::getEnabledExtensions(ExtensionsManager::getConfigPath());

            $extensionStatus = [];
            foreach ($extensions as $extension) {
                $reflection = new \ReflectionClass($extension);
                $shortName = $reflection->getShortName();
                $extensionStatus[] = [
                    'name' => $shortName,
                    'status' => in_array($shortName, $enabledExtensions) ? 'enabled' : 'disabled',
                    'version' => ExtensionsManager::getExtensionMetadata($shortName, 'version'),
                ];
            }

            $metrics['extensions'] = [
                'total_count' => count($extensions),
                'enabled_count' => count($enabledExtensions),
                'extensions' => $extensionStatus
            ];

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

            return Response::ok($metrics, 'System health metrics retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("System health check error: " . $e->getMessage());
            return Response::error(
                'Failed to retrieve system health metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
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
    private function calculateHitRate(int $hits, int $misses): string
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
     * @param Request $request HTTP request
     * @return mixed HTTP response
     */
    public function getExtensionHealth(?array $extension): mixed
    {
        try {
            if (!isset($extension['name'])) {
                return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $extensionName = $extension['name'];

            if (!ExtensionsManager::extensionExists($extensionName)) {
                return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
            }

            $health = ExtensionsManager::checkExtensionHealth($extensionName);

            return Response::ok([
                'extension' => $extensionName,
                'health' => $health
            ], 'Extension health status retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get extension health error: " . $e->getMessage());
            return Response::error(
                'Failed to get extension health: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}
