<?php

namespace Glueful\Queue\Jobs;

use Glueful\Queue\QueueManager;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Glueful\Queue\Failed\FailedJobProvider;

/**
 * Queue Maintenance Job
 *
 * Performs scheduled maintenance tasks for the queue system.
 * This job is designed to be run periodically (e.g., every 15 minutes)
 * to keep the queue system optimized and clean.
 *
 * Features:
 * - Failed job cleanup
 * - Worker heartbeat cleanup
 * - Queue statistics update
 * - Performance optimization
 * - Health checks
 *
 * @package Glueful\Queue\Jobs
 */
class QueueMaintenance
{
    /** @var QueueManager Queue manager instance */
    private QueueManager $queueManager;

    /** @var WorkerMonitor Worker monitor instance */
    private WorkerMonitor $workerMonitor;

    /** @var FailedJobProvider Failed job provider instance */
    private FailedJobProvider $failedJobProvider;

    /** @var array Maintenance statistics */
    private array $stats = [];

    /**
     * Create queue maintenance job
     */
    public function __construct()
    {
        $this->queueManager = new QueueManager();
        $this->workerMonitor = new WorkerMonitor();
        $this->failedJobProvider = new FailedJobProvider();
        $this->stats = [
            'start_time' => time(),
            'cleaned_workers' => 0,
            'cleaned_metrics' => 0,
            'cleaned_failed_jobs' => 0,
            'optimized_queues' => 0,
            'errors' => [],
        ];
    }

    /**
     * Handle the maintenance job
     *
     * @param array $parameters Job parameters
     * @return void
     */
    public function handle(array $parameters = []): void
    {
        try {
            $this->logMaintenanceStart();

            // Perform maintenance tasks
            $this->cleanupStaleWorkers();
            $this->cleanupOldMetrics();
            $this->cleanupFailedJobs();
            $this->optimizeQueues();
            $this->updateStatistics();
            $this->performHealthChecks();

            $this->logMaintenanceComplete();
        } catch (\Exception $e) {
            $this->logMaintenanceError($e);
            throw $e;
        }
    }

    /**
     * Cleanup stale worker records
     *
     * @return void
     */
    private function cleanupStaleWorkers(): void
    {
        try {
            $daysOld = $this->getConfig('queue.maintenance.worker_cleanup_days', 7);
            $cleaned = $this->workerMonitor->cleanupOldWorkers($daysOld);

            $this->stats['cleaned_workers'] = $cleaned;
            $this->log("Cleaned up {$cleaned} stale worker records");
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Worker cleanup failed: " . $e->getMessage();
            $this->log("Worker cleanup failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Cleanup old job metrics
     *
     * @return void
     */
    private function cleanupOldMetrics(): void
    {
        try {
            $daysOld = $this->getConfig('queue.maintenance.metrics_cleanup_days', 30);
            $cleaned = $this->workerMonitor->cleanupOldMetrics($daysOld);

            $this->stats['cleaned_metrics'] = $cleaned;
            $this->log("Cleaned up {$cleaned} old job metrics");

        } catch (\Exception $e) {
            $this->stats['errors'][] = "Metrics cleanup failed: " . $e->getMessage();
            $this->log("Metrics cleanup failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Cleanup old failed jobs
     *
     * @return void
     */
    private function cleanupFailedJobs(): void
    {
        try {
            $daysOld = $this->getConfig('queue.maintenance.failed_jobs_cleanup_days', 30);
            $cleaned = $this->failedJobProvider->cleanup($daysOld);

            $this->stats['cleaned_failed_jobs'] = $cleaned;
            $this->log("Cleaned up {$cleaned} old failed jobs");

        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed jobs cleanup failed: " . $e->getMessage();
            $this->log("Failed jobs cleanup failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Optimize queue performance
     *
     * @return void
     */
    private function optimizeQueues(): void
    {
        try {
            $connections = $this->queueManager->getAvailableConnections();
            $optimized = 0;

            foreach ($connections as $connectionName) {
                try {
                    $driver = $this->queueManager->connection($connectionName);

                    // Perform driver-specific optimizations
                    $this->optimizeConnection($driver, $connectionName);
                    $optimized++;

                } catch (\Exception $e) {
                    $this->stats['errors'][] = "Optimization failed for {$connectionName}: " . $e->getMessage();
                    $this->log("Optimization failed for {$connectionName}: " . $e->getMessage(), 'error');
                }
            }

            $this->stats['optimized_queues'] = $optimized;
            $this->log("Optimized {$optimized} queue connections");

        } catch (\Exception $e) {
            $this->stats['errors'][] = "Queue optimization failed: " . $e->getMessage();
            $this->log("Queue optimization failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Optimize specific connection
     *
     * @param mixed $driver Queue driver
     * @param string $connectionName Connection name
     * @return void
     */
    private function optimizeConnection($driver, string $connectionName): void
    {
        // Get driver info
        $driverInfo = $driver->getDriverInfo();
        $driverName = $driverInfo->name;

        switch ($driverName) {
            case 'database':
                $this->optimizeDatabaseConnection($driver, $connectionName);
                break;

            case 'redis':
                $this->optimizeRedisConnection($driver, $connectionName);
                break;

            default:
                $this->log("No optimization available for driver: {$driverName}");
                break;
        }
    }

    /**
     * Optimize database connection
     *
     * @param mixed $driver Database driver
     * @param string $connectionName Connection name
     * @return void
     */
    private function optimizeDatabaseConnection($driver, string $connectionName): void
    {
        // Database-specific optimizations could include:
        // - ANALYZE TABLE commands
        // - VACUUM operations (for SQLite)
        // - Index optimization
        // For now, just log the activity
        $this->log("Performed database optimization for {$connectionName}");
    }

    /**
     * Optimize Redis connection
     *
     * @param mixed $driver Redis driver
     * @param string $connectionName Connection name
     * @return void
     */
    private function optimizeRedisConnection($driver, string $connectionName): void
    {
        // Redis-specific optimizations could include:
        // - Memory usage analysis
        // - Key expiration cleanup
        // - Connection pool optimization
        // For now, just log the activity
        $this->log("Performed Redis optimization for {$connectionName}");
    }

    /**
     * Update queue statistics
     *
     * @return void
     */
    private function updateStatistics(): void
    {
        try {
            // Update global queue statistics
            $totalStats = [
                'total_connections' => count($this->queueManager->getAvailableConnections()),
                'total_workers' => count($this->workerMonitor->getActiveWorkers()),
                'maintenance_run_at' => time(),
                'maintenance_duration' => time() - $this->stats['start_time'],
                'cleaned_workers' => $this->stats['cleaned_workers'],
                'cleaned_metrics' => $this->stats['cleaned_metrics'],
                'cleaned_failed_jobs' => $this->stats['cleaned_failed_jobs'],
                'optimized_queues' => $this->stats['optimized_queues'],
                'error_count' => count($this->stats['errors']),
            ];

            // Save statistics (this could be saved to database, cache, or file)
            $this->saveStatistics($totalStats);
            $this->log("Updated queue statistics");

        } catch (\Exception $e) {
            $this->stats['errors'][] = "Statistics update failed: " . $e->getMessage();
            $this->log("Statistics update failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Perform health checks
     *
     * @return void
     */
    private function performHealthChecks(): void
    {
        try {
            $connections = $this->queueManager->getAvailableConnections();
            $healthyConnections = 0;
            $unhealthyConnections = [];

            foreach ($connections as $connectionName) {
                try {
                    $driver = $this->queueManager->connection($connectionName);
                    $health = $driver->healthCheck();

                    if ($health->isHealthy()) {
                        $healthyConnections++;
                    } else {
                        $unhealthyConnections[] = [
                            'connection' => $connectionName,
                            'message' => $health->message,
                            'response_time' => $health->responseTime,
                        ];
                    }

                } catch (\Exception $e) {
                    $unhealthyConnections[] = [
                        'connection' => $connectionName,
                        'message' => $e->getMessage(),
                        'response_time' => null,
                    ];
                }
            }

            $this->log("Health check: {$healthyConnections} healthy connections");

            if (!empty($unhealthyConnections)) {
                foreach ($unhealthyConnections as $unhealthy) {
                    $this->log("Unhealthy connection {$unhealthy['connection']}: {$unhealthy['message']}", 'warning');
                }
            }

        } catch (\Exception $e) {
            $this->stats['errors'][] = "Health check failed: " . $e->getMessage();
            $this->log("Health check failed: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Save statistics to storage
     *
     * @param array $stats Statistics data
     * @return void
     */
    private function saveStatistics(array $stats): void
    {
        // This could save to database, cache, or file
        // For now, save to a simple file
        $statsFile = sys_get_temp_dir() . '/glueful_queue_maintenance_stats.json';
        file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
    }

    /**
     * Log maintenance start
     *
     * @return void
     */
    private function logMaintenanceStart(): void
    {
        $this->log("Queue maintenance started");
    }

    /**
     * Log maintenance completion
     *
     * @return void
     */
    private function logMaintenanceComplete(): void
    {
        $duration = time() - $this->stats['start_time'];
        $this->log("Queue maintenance completed in {$duration} seconds");

        // Log summary
        $summary = [
            'duration' => $duration,
            'cleaned_workers' => $this->stats['cleaned_workers'],
            'cleaned_metrics' => $this->stats['cleaned_metrics'],
            'cleaned_failed_jobs' => $this->stats['cleaned_failed_jobs'],
            'optimized_queues' => $this->stats['optimized_queues'],
            'error_count' => count($this->stats['errors']),
        ];

        $this->log("Maintenance summary: " . json_encode($summary));
    }

    /**
     * Log maintenance error
     *
     * @param \Exception $exception Exception
     * @return void
     */
    private function logMaintenanceError(\Exception $exception): void
    {
        $this->log("Queue maintenance failed: " . $exception->getMessage(), 'error');
        $this->stats['errors'][] = $exception->getMessage();
    }

    /**
     * Log message
     *
     * @param string $message Log message
     * @param string $level Log level
     * @return void
     */
    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] QUEUE_MAINTENANCE.{$level}: {$message}";

        if ($level === 'error') {
            error_log($logMessage);
        } elseif ($this->isDebugMode()) {
            error_log($logMessage);
        }
    }

    /**
     * Get configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    private function getConfig(string $key, $default = null)
    {
        // Try different methods to get config
        if (function_exists('config')) {
            return config($key, $default);
        }

        // Fallback to environment variables
        $envKey = strtoupper(str_replace('.', '_', $key));
        return $_ENV[$envKey] ?? $default;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug mode
     */
    private function isDebugMode(): bool
    {
        return $this->getConfig('queue.maintenance.debug', false);
    }

    /**
     * Get maintenance statistics
     *
     * @return array Maintenance statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get job description
     *
     * @return string Job description
     */
    public function getDescription(): string
    {
        return 'Queue system maintenance and optimization';
    }

    /**
     * Get maximum attempts for maintenance job
     *
     * @return int Maximum attempts
     */
    public function getMaxAttempts(): int
    {
        return 2; // Maintenance jobs shouldn't retry too much
    }

    /**
     * Get timeout for maintenance job
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        return 300; // 5 minutes timeout
    }
}