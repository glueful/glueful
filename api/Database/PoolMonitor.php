<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Database\Connection;
use Glueful\Database\ConnectionPoolManager;

/**
 * PoolMonitor
 *
 * Comprehensive monitoring and debugging utilities for database connection pools.
 * Provides real-time metrics, performance analysis, and diagnostic tools for
 * optimizing connection pool behavior and identifying performance bottlenecks.
 *
 * Features:
 * - Real-time pool metrics collection
 * - Performance trend analysis
 * - Health status monitoring
 * - Diagnostic logging and alerting
 * - Configuration recommendations
 * - Historical data tracking
 *
 * @package Glueful\Database
 */
class PoolMonitor
{
    /** @var array Historical metrics storage */
    private static array $historicalMetrics = [];

    /** @var int Maximum historical entries to keep */
    private static int $maxHistoryEntries = 1000;

    /** @var array Performance thresholds for alerting */
    private static array $thresholds = [
        'max_utilization' => 90,        // % - Alert if pool utilization exceeds
        'min_success_rate' => 95,       // % - Alert if success rate drops below
        'max_avg_acquisition_time' => 500, // ms - Alert if avg acquisition time exceeds
        'max_error_rate' => 5,          // % - Alert if error rate exceeds
        'min_health_success_rate' => 98 // % - Alert if health checks fail
    ];

    /**
     * Get comprehensive metrics for all connection pools
     *
     * @param bool $includeDetails Include detailed statistics
     * @return array Pool metrics by engine
     */
    public static function getMetrics(bool $includeDetails = false): array
    {
        $manager = Connection::getPoolManager();
        if (!$manager) {
            return [];
        }

        $pools = $manager->getAllPools();
        $metrics = [];

        foreach ($pools as $name => $pool) {
            $stats = $pool->getStats();

            $poolMetrics = [
                'active_connections' => $stats['active_connections'] ?? 0,
                'idle_connections' => $stats['idle_connections'] ?? 0,
                'total_connections' => $stats['total_connections'] ?? 0,
                'utilization_percent' => $stats['utilization_percent'] ?? 0,
                'success_rate' => $stats['success_rate'] ?? 100,
                'health_success_rate' => $stats['health_success_rate'] ?? 100,
                'total_acquisitions' => $stats['total_acquisitions'] ?? 0,
                'total_timeouts' => $stats['total_timeouts'] ?? 0,
                'peak_active' => $stats['peak_active'] ?? 0,
                'avg_acquisition_time' => self::calculateAvgAcquisitionTime($stats),
                'connection_errors' => $stats['failed_health_checks'] ?? 0,
                'pool_efficiency' => self::calculatePoolEfficiency($stats),
                'last_maintenance' => $stats['last_maintenance'] ?? null,
                'status' => self::getPoolStatus($stats)
            ];

            if ($includeDetails) {
                $poolMetrics['detailed_stats'] = $stats;
                $poolMetrics['maintenance_worker'] = $pool->getMaintenanceWorkerStatus();
                $poolMetrics['configuration'] = $stats['config'] ?? [];
                $poolMetrics['trends'] = self::getTrends($name);
                $poolMetrics['alerts'] = self::checkAlerts($poolMetrics);
            }

            $metrics[$name] = $poolMetrics;
        }

        // Store metrics for historical analysis
        self::storeHistoricalMetrics($metrics);

        return $metrics;
    }

    /**
     * Get aggregate metrics across all pools
     *
     * @return array Aggregate statistics
     */
    public static function getAggregateMetrics(): array
    {
        $manager = Connection::getPoolManager();
        if (!$manager) {
            return [];
        }

        $aggregateStats = $manager->getAggregateStats();
        $healthStatus = $manager->getHealthStatus();

        return [
            'total_pools' => $aggregateStats['total_pools'] ?? 0,
            'total_active_connections' => $aggregateStats['total_active_connections'] ?? 0,
            'total_idle_connections' => $aggregateStats['total_idle_connections'] ?? 0,
            'total_connections_created' => $aggregateStats['total_connections_created'] ?? 0,
            'total_connections_destroyed' => $aggregateStats['total_connections_destroyed'] ?? 0,
            'total_acquisitions' => $aggregateStats['total_acquisitions'] ?? 0,
            'total_timeouts' => $aggregateStats['total_timeouts'] ?? 0,
            'overall_success_rate' => self::calculateOverallSuccessRate($aggregateStats),
            'healthy_pools' => self::countHealthyPools($healthStatus),
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Log pool statistics to error log
     *
     * @param bool $includeDetails Include detailed information
     * @return void
     */
    public static function logPoolStats(bool $includeDetails = false): void
    {
        $metrics = self::getMetrics($includeDetails);

        foreach ($metrics as $pool => $stats) {
            $logMessage = sprintf(
                "Pool[%s]: Active=%d, Idle=%d, Total=%d, Util=%.1f%%, Success=%.1f%%, Efficiency=%.2f",
                $pool,
                $stats['active_connections'],
                $stats['idle_connections'],
                $stats['total_connections'],
                $stats['utilization_percent'],
                $stats['success_rate'],
                $stats['pool_efficiency']
            );

            if ($includeDetails && !empty($stats['alerts'])) {
                $logMessage .= sprintf(" [ALERTS: %s]", implode(', ', $stats['alerts']));
            }

            error_log($logMessage);
        }

        // Log aggregate metrics
        $aggregate = self::getAggregateMetrics();
        error_log(sprintf(
            "Pool Summary: %d pools, %d total connections, %.1f%% overall success rate, %d healthy pools",
            $aggregate['total_pools'],
            $aggregate['total_active_connections'] + $aggregate['total_idle_connections'],
            $aggregate['overall_success_rate'],
            $aggregate['healthy_pools']
        ));
    }

    /**
     * Get performance recommendations based on current metrics
     *
     * @return array Performance recommendations
     */
    public static function getRecommendations(): array
    {
        $metrics = self::getMetrics(true);
        $recommendations = [];

        foreach ($metrics as $poolName => $stats) {
            $poolRecommendations = [];

            // High utilization recommendations
            if ($stats['utilization_percent'] > 80) {
                $poolRecommendations[] = [
                    'type' => 'scaling',
                    'priority' => 'high',
                    'message' => 'Consider increasing max_connections - pool utilization is ' .
                                $stats['utilization_percent'] . '%'
                ];
            }

            // Low success rate recommendations
            if ($stats['success_rate'] < 95) {
                $poolRecommendations[] = [
                    'type' => 'performance',
                    'priority' => 'high',
                    'message' => 'High timeout rate detected - consider tuning acquisition_timeout ' .
                                'or increasing pool size'
                ];
            }

            // Health check failures
            if ($stats['health_success_rate'] < 98) {
                $poolRecommendations[] = [
                    'type' => 'reliability',
                    'priority' => 'medium',
                    'message' => 'Health check failures detected - review database connection stability'
                ];
            }

            // Low efficiency recommendations
            if ($stats['pool_efficiency'] < 0.7) {
                $poolRecommendations[] = [
                    'type' => 'optimization',
                    'priority' => 'medium',
                    'message' => 'Low pool efficiency - consider adjusting idle_timeout or min_connections'
                ];
            }

            if (!empty($poolRecommendations)) {
                $recommendations[$poolName] = $poolRecommendations;
            }
        }

        return $recommendations;
    }

    /**
     * Get historical trends for a specific pool
     *
     * @param string $poolName Pool name
     * @param int $minutes Minutes of history to analyze
     * @return array Trend analysis
     */
    public static function getTrends(string $poolName, int $minutes = 60): array
    {
        $cutoffTime = microtime(true) - ($minutes * 60);
        $relevantMetrics = array_filter(
            self::$historicalMetrics,
            fn($entry) => $entry['timestamp'] > $cutoffTime && isset($entry['pools'][$poolName])
        );

        if (empty($relevantMetrics)) {
            return ['trend' => 'insufficient_data'];
        }

        $poolData = array_map(fn($entry) => $entry['pools'][$poolName], $relevantMetrics);

        return [
            'utilization_trend' => self::calculateTrend(array_column($poolData, 'utilization_percent')),
            'success_rate_trend' => self::calculateTrend(array_column($poolData, 'success_rate')),
            'connection_count_trend' => self::calculateTrend(array_column($poolData, 'total_connections')),
            'avg_utilization' => array_sum(array_column($poolData, 'utilization_percent')) / count($poolData),
            'data_points' => count($poolData),
            'time_range_minutes' => $minutes
        ];
    }

    /**
     * Check for alert conditions
     *
     * @param array $metrics Pool metrics
     * @return array List of active alerts
     */
    private static function checkAlerts(array $metrics): array
    {
        $alerts = [];

        if ($metrics['utilization_percent'] > self::$thresholds['max_utilization']) {
            $alerts[] = 'high_utilization';
        }

        if ($metrics['success_rate'] < self::$thresholds['min_success_rate']) {
            $alerts[] = 'low_success_rate';
        }

        if (
            isset($metrics['avg_acquisition_time']) &&
            $metrics['avg_acquisition_time'] > self::$thresholds['max_avg_acquisition_time']
        ) {
            $alerts[] = 'slow_acquisition';
        }

        if ($metrics['health_success_rate'] < self::$thresholds['min_health_success_rate']) {
            $alerts[] = 'health_check_failures';
        }

        $errorRate = ($metrics['total_acquisitions'] > 0)
            ? ($metrics['connection_errors'] / $metrics['total_acquisitions']) * 100
            : 0;

        if ($errorRate > self::$thresholds['max_error_rate']) {
            $alerts[] = 'high_error_rate';
        }

        return $alerts;
    }

    /**
     * Calculate average acquisition time from statistics
     *
     * @param array $stats Pool statistics
     * @return float Average acquisition time in milliseconds
     */
    private static function calculateAvgAcquisitionTime(array $stats): float
    {
        // This would require tracking acquisition times in the pool
        // For now, estimate based on timeouts and success rate
        $timeouts = $stats['total_timeouts'] ?? 0;
        $acquisitions = $stats['total_acquisitions'] ?? 0;

        if ($acquisitions === 0) {
            return 0.0;
        }

        // Rough estimate: if many timeouts, average time is higher
        $timeoutRatio = $timeouts / $acquisitions;
        return $timeoutRatio > 0.1 ? 250.0 : 50.0; // Simplified estimation
    }

    /**
     * Calculate pool efficiency ratio
     *
     * @param array $stats Pool statistics
     * @return float Efficiency ratio (0.0 to 1.0)
     */
    private static function calculatePoolEfficiency(array $stats): float
    {
        $acquisitions = $stats['total_acquisitions'] ?? 0;
        $timeouts = $stats['total_timeouts'] ?? 0;
        $healthChecks = $stats['total_health_checks'] ?? 0;
        $failedHealthChecks = $stats['failed_health_checks'] ?? 0;

        if ($acquisitions === 0) {
            return 1.0;
        }

        $successRate = ($acquisitions - $timeouts) / $acquisitions;
        $healthRate = $healthChecks > 0 ? ($healthChecks - $failedHealthChecks) / $healthChecks : 1.0;

        return ($successRate + $healthRate) / 2;
    }

    /**
     * Get pool status based on metrics
     *
     * @param array $stats Pool statistics
     * @return string Status (healthy, warning, critical)
     */
    private static function getPoolStatus(array $stats): string
    {
        $utilization = $stats['utilization_percent'] ?? 0;
        $successRate = $stats['success_rate'] ?? 100;
        $healthRate = $stats['health_success_rate'] ?? 100;

        if ($utilization > 90 || $successRate < 90 || $healthRate < 95) {
            return 'critical';
        }

        if ($utilization > 70 || $successRate < 98 || $healthRate < 99) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Store metrics for historical analysis
     *
     * @param array $metrics Current metrics
     * @return void
     */
    private static function storeHistoricalMetrics(array $metrics): void
    {
        self::$historicalMetrics[] = [
            'timestamp' => microtime(true),
            'pools' => $metrics
        ];

        // Trim old entries
        if (count(self::$historicalMetrics) > self::$maxHistoryEntries) {
            self::$historicalMetrics = array_slice(self::$historicalMetrics, -self::$maxHistoryEntries);
        }
    }

    /**
     * Calculate trend direction for a metric series
     *
     * @param array $values Metric values over time
     * @return string Trend direction (increasing, decreasing, stable)
     */
    private static function calculateTrend(array $values): string
    {
        if (count($values) < 2) {
            return 'insufficient_data';
        }

        $first = array_slice($values, 0, max(1, count($values) / 3));
        $last = array_slice($values, -max(1, count($values) / 3));

        $firstAvg = array_sum($first) / count($first);
        $lastAvg = array_sum($last) / count($last);

        $change = (($lastAvg - $firstAvg) / $firstAvg) * 100;

        if (abs($change) < 5) {
            return 'stable';
        }

        return $change > 0 ? 'increasing' : 'decreasing';
    }

    /**
     * Calculate overall success rate from aggregate stats
     *
     * @param array $stats Aggregate statistics
     * @return float Overall success rate percentage
     */
    private static function calculateOverallSuccessRate(array $stats): float
    {
        $acquisitions = $stats['total_acquisitions'] ?? 0;
        $timeouts = $stats['total_timeouts'] ?? 0;

        if ($acquisitions === 0) {
            return 100.0;
        }

        return (($acquisitions - $timeouts) / $acquisitions) * 100;
    }

    /**
     * Count healthy pools from health status
     *
     * @param array $healthStatus Health status by pool
     * @return int Number of healthy pools
     */
    private static function countHealthyPools(array $healthStatus): int
    {
        return count(array_filter($healthStatus, fn($status) => $status['healthy'] ?? false));
    }

    /**
     * Configure monitoring thresholds
     *
     * @param array $thresholds New threshold values
     * @return void
     */
    public static function setThresholds(array $thresholds): void
    {
        self::$thresholds = array_merge(self::$thresholds, $thresholds);
    }

    /**
     * Clear historical metrics (useful for testing)
     *
     * @return void
     */
    public static function clearHistory(): void
    {
        self::$historicalMetrics = [];
    }
}
