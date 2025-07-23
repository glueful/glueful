<?php

namespace Glueful\Queue\Monitoring;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Schema\SchemaManager;

/**
 * Worker Monitor
 *
 * Monitors worker processes and job execution for queue system.
 * Tracks worker registration, heartbeats, job processing metrics,
 * and provides monitoring data for queue management.
 *
 * Features:
 * - Worker registration and lifecycle tracking
 * - Job execution monitoring and metrics
 * - Failed job tracking and analysis
 * - Performance statistics collection
 * - Worker health monitoring
 * - Cleanup of stale worker records
 *
 * @package Glueful\Queue\Monitoring
 */
class WorkerMonitor
{
    /** @var QueryBuilder Database query builder */
    private QueryBuilder $db;

    /** @var SchemaManager Schema manager for table operations */
    private SchemaManager $schema;

    /** @var string Workers table name */
    private string $workersTable = 'queue_workers';

    /** @var string Job metrics table name */
    private string $metricsTable = 'queue_job_metrics';

    /** @var bool Whether monitoring is enabled */
    private bool $enabled;

    /**
     * Create worker monitor instance
     *
     * @param QueryBuilder|null $queryBuilder Database query builder (optional)
     * @param bool $enabled Whether monitoring is enabled
     */
    public function __construct(?QueryBuilder $queryBuilder = null, bool $enabled = true)
    {
        if ($queryBuilder) {
            $this->db = $queryBuilder;
            // Try to get schema manager from connection if available
            // For now, we'll create a new connection to get the schema manager
            $connection = new Connection();
            $this->schema = $connection->getSchemaManager();
        } else {
            $connection = new Connection();
            $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
            $this->schema = $connection->getSchemaManager();
        }
        $this->enabled = $enabled;
    }

    /**
     * Register a new worker
     *
     * @param string $workerUuid Worker UUID
     * @param array $workerData Worker information
     * @return void
     */
    public function registerWorker(string $workerUuid, array $workerData): void
    {
        if (!$this->enabled) {
            return;
        }

        // Check if workers table exists
        if (!$this->tableExists($this->workersTable)) {
            $this->createWorkersTable();
        }

        $data = array_merge([
            'uuid' => $workerUuid,
            'connection' => $workerData['connection'] ?? 'default',
            'queue' => $workerData['queue'] ?? 'default',
            'pid' => $workerData['pid'] ?? getmypid(),
            'hostname' => $workerData['hostname'] ?? gethostname(),
            'started_at' => date('Y-m-d H:i:s', $workerData['started_at'] ?? time()),
            'last_seen' => date('Y-m-d H:i:s'),
            'jobs_processed' => 0,
            'jobs_failed' => 0,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'status' => 'active',
            'options' => json_encode($workerData['options'] ?? []),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->db->insert($this->workersTable, $data);
    }

    /**
     * Update worker heartbeat
     *
     * @param string $workerUuid Worker UUID
     * @param array $data Heartbeat data
     * @return void
     */
    public function updateWorkerHeartbeat(string $workerUuid, array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        // Silently fail heartbeat updates to avoid noise
        try {
            $updateData = [
                'last_seen' => date('Y-m-d H:i:s', $data['last_seen'] ?? time()),
                'jobs_processed' => $data['jobs_processed'] ?? 0,
                'jobs_failed' => $data['jobs_failed'] ?? 0,
                'memory_usage' => $data['memory_usage'] ?? memory_get_usage(true),
                'memory_peak' => max(
                    $data['memory_peak'] ?? 0,
                    $this->getWorkerMemoryPeak($workerUuid)
                ),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update(
                $this->workersTable,
                $updateData,
                ['uuid' => $workerUuid]
            );
        } catch (\Exception) {
            // Intentionally silent - heartbeat failures shouldn't disrupt worker operation
        }
    }

    /**
     * Unregister worker
     *
     * @param string $workerUuid Worker UUID
     * @param array $finalStats Final worker statistics
     * @return void
     */
    public function unregisterWorker(string $workerUuid, array $finalStats = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $updateData = [
            'status' => 'stopped',
            'stopped_at' => date('Y-m-d H:i:s'),
            'total_runtime' => $finalStats['total_runtime'] ?? 0,
            'final_jobs_processed' => $finalStats['jobs_processed'] ?? 0,
            'final_jobs_failed' => $finalStats['jobs_failed'] ?? 0,
            'final_memory_peak' => $finalStats['memory_peak'] ?? 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update(
            $this->workersTable,
            $updateData,
            ['uuid' => $workerUuid]
        );
    }

    /**
     * Record job start
     *
     * @param JobInterface $job Job instance
     * @return void
     */
    public function recordJobStart(JobInterface $job): void
    {
        if (!$this->enabled) {
            return;
        }

        // Check if metrics table exists
        if (!$this->tableExists($this->metricsTable)) {
            $this->createMetricsTable();
        }

        $data = [
            'job_uuid' => $job->getUuid(),
            'job_class' => get_class($job),
            'queue' => $job->getQueue() ?? 'default',
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'processing',
            'attempts' => $job->getAttempts(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert($this->metricsTable, $data);
    }

    /**
     * Record job success
     *
     * @param JobInterface $job Job instance
     * @param float $processingTime Processing time in seconds
     * @return void
     */
    public function recordJobSuccess(JobInterface $job, float $processingTime): void
    {
        if (!$this->enabled) {
            return;
        }

        $updateData = [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'processing_time' => round($processingTime, 4),
            'memory_used' => memory_get_usage(true),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update(
            $this->metricsTable,
            $updateData,
            ['job_uuid' => $job->getUuid()]
        );
    }

    /**
     * Record job failure
     *
     * @param JobInterface $job Job instance
     * @param \Exception $exception Exception that occurred
     * @param float $processingTime Processing time in seconds
     * @return void
     */
    public function recordJobFailure(JobInterface $job, \Exception $exception, float $processingTime): void
    {
        if (!$this->enabled) {
            return;
        }

        $updateData = [
            'status' => 'failed',
            'failed_at' => date('Y-m-d H:i:s'),
            'processing_time' => round($processingTime, 4),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'memory_used' => memory_get_usage(true),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update(
            $this->metricsTable,
            $updateData,
            ['job_uuid' => $job->getUuid()]
        );
    }

    /**
     * Get active workers
     *
     * @return array Active worker list
     */
    public function getActiveWorkers(): array
    {
        if (!$this->enabled) {
            return [];
        }

        // Consider workers active if they've been seen in the last 2 minutes
        $cutoff = date('Y-m-d H:i:s', time() - 120);

        return $this->db->select($this->workersTable, ['*'], [
            'status' => 'active',
            'last_seen >=' => $cutoff
        ])->get();
    }

    /**
     * Get worker statistics
     *
     * @param string|null $workerUuid Specific worker UUID (optional)
     * @return array Worker statistics
     */
    public function getWorkerStats(?string $workerUuid = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        $conditions = [];
        if ($workerUuid) {
            $conditions['uuid'] = $workerUuid;
        }

        $workers = $this->db->select($this->workersTable, ['*'], $conditions)->get();

        $stats = [];
        foreach ($workers as $worker) {
            $stats[] = [
                'uuid' => $worker['uuid'],
                'connection' => $worker['connection'],
                'queue' => $worker['queue'],
                'pid' => $worker['pid'],
                'hostname' => $worker['hostname'],
                'status' => $worker['status'],
                'started_at' => $worker['started_at'],
                'last_seen' => $worker['last_seen'],
                'jobs_processed' => (int) $worker['jobs_processed'],
                'jobs_failed' => (int) $worker['jobs_failed'],
                'memory_usage' => (int) $worker['memory_usage'],
                'memory_peak' => (int) $worker['memory_peak'],
                'uptime' => $worker['started_at'] ?
                    time() - strtotime($worker['started_at']) : 0
            ];
        }

        return $stats;
    }

    /**
     * Get job processing metrics
     *
     * @param array $filters Filters for metrics
     * @return array Job metrics
     */
    public function getJobMetrics(array $filters = []): array
    {
        if (!$this->enabled) {
            return [];
        }

        $conditions = [];

        if (isset($filters['queue'])) {
            $conditions['queue'] = $filters['queue'];
        }

        if (isset($filters['status'])) {
            $conditions['status'] = $filters['status'];
        }

        if (isset($filters['from_date'])) {
            $conditions['created_at >='] = $filters['from_date'];
        }

        if (isset($filters['to_date'])) {
            $conditions['created_at <='] = $filters['to_date'];
        }

        $limit = $filters['limit'] ?? 100;

        return $this->db->select($this->metricsTable, ['*'], $conditions)
            ->orderBy(['created_at' => 'DESC'])
            ->limit($limit)
            ->get();
    }

    /**
     * Get performance statistics
     *
     * @param string|null $queue Specific queue (optional)
     * @return array Performance stats
     */
    public function getPerformanceStats(?string $queue = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        $conditions = [];
        if ($queue) {
            $conditions['queue'] = $queue;
        }

        // Get basic stats
        $totalJobs = $this->db->count($this->metricsTable, $conditions);

        $completedConditions = array_merge($conditions, ['status' => 'completed']);
        $completedJobs = $this->db->count($this->metricsTable, $completedConditions);

        $failedConditions = array_merge($conditions, ['status' => 'failed']);
        $failedJobs = $this->db->count($this->metricsTable, $failedConditions);

        // Get average processing time for completed jobs
        $avgProcessingTime = 0;
        if ($completedJobs > 0) {
            $query = $this->db->select($this->metricsTable, [
                $this->db->raw('AVG(processing_time) as avg_time')
            ], [
                'status' => 'completed'
            ]);

            if ($queue) {
                $query->where(['queue' => $queue]);
            }

            $result = $query->first();
            $avgProcessingTime = $result['avg_time'] ?? 0;
        }

        return [
            'total_jobs' => $totalJobs,
            'completed_jobs' => $completedJobs,
            'failed_jobs' => $failedJobs,
            'success_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 2) : 0,
            'failure_rate' => $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0,
            'avg_processing_time' => round($avgProcessingTime, 4),
            'active_workers' => count($this->getActiveWorkers())
        ];
    }

    /**
     * Cleanup old worker records
     *
     * @param int $daysOld Number of days old to cleanup
     * @return bool True if records were cleaned up
     */
    public function cleanupOldWorkers(int $daysOld = 7): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $cutoff = date('Y-m-d H:i:s', time() - ($daysOld * 24 * 60 * 60));

        return $this->db->delete($this->workersTable, [
            'status' => 'stopped',
            'updated_at <' => $cutoff
        ]);
    }

    /**
     * Cleanup old job metrics
     *
     * @param int $daysOld Number of days old to cleanup
     * @return bool True if records were cleaned up
     */
    public function cleanupOldMetrics(int $daysOld = 30): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $cutoff = date('Y-m-d H:i:s', time() - ($daysOld * 24 * 60 * 60));

        return $this->db->delete($this->metricsTable, [
            'created_at <' => $cutoff
        ]);
    }

    /**
     * Get worker memory peak
     *
     * @param string $workerUuid Worker UUID
     * @return int Memory peak in bytes
     */
    private function getWorkerMemoryPeak(string $workerUuid): int
    {
        $worker = $this->db->select($this->workersTable, ['memory_peak'], [
            'uuid' => $workerUuid
        ])->first();

        return (int) ($worker['memory_peak'] ?? 0);
    }

    /**
     * Check if table exists
     *
     * @param string $tableName Table name
     * @return bool True if exists
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $this->db->select($tableName, ['1'])->limit(1)->first();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Create workers table
     *
     * @return void
     */
    private function createWorkersTable(): void
    {
        $this->schema->createTable($this->workersTable, [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'uuid' => 'VARCHAR(255) NOT NULL',
            'connection' => 'VARCHAR(255) NOT NULL',
            'queue' => 'VARCHAR(255) NOT NULL',
            'pid' => 'INT NOT NULL',
            'hostname' => 'VARCHAR(255) NOT NULL',
            'started_at' => 'TIMESTAMP NULL',
            'stopped_at' => 'TIMESTAMP NULL',
            'last_seen' => 'TIMESTAMP NULL',
            'jobs_processed' => 'INT DEFAULT 0',
            'jobs_failed' => 'INT DEFAULT 0',
            'memory_usage' => 'BIGINT DEFAULT 0',
            'memory_peak' => 'BIGINT DEFAULT 0',
            'total_runtime' => 'INT DEFAULT 0',
            'final_jobs_processed' => 'INT DEFAULT 0',
            'final_jobs_failed' => 'INT DEFAULT 0',
            'final_memory_peak' => 'BIGINT DEFAULT 0',
            'status' => 'ENUM(\'active\', \'stopped\') DEFAULT \'active\'',
            'options' => 'TEXT',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'status', 'name' => 'idx_status'],
            ['type' => 'INDEX', 'column' => 'last_seen', 'name' => 'idx_last_seen'],
            ['type' => 'INDEX', 'column' => ['connection', 'queue'], 'name' => 'idx_connection_queue']
        ]);
    }

    /**
     * Create metrics table
     *
     * @return void
     */
    private function createMetricsTable(): void
    {
        $this->schema->createTable($this->metricsTable, [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'job_uuid' => 'VARCHAR(255) NOT NULL',
            'job_class' => 'VARCHAR(255) NOT NULL',
            'queue' => 'VARCHAR(255) NOT NULL',
            'started_at' => 'TIMESTAMP NULL',
            'completed_at' => 'TIMESTAMP NULL',
            'failed_at' => 'TIMESTAMP NULL',
            'processing_time' => 'DECIMAL(10, 4) DEFAULT 0',
            'memory_used' => 'BIGINT DEFAULT 0',
            'status' => 'ENUM(\'processing\', \'completed\', \'failed\') DEFAULT \'processing\'',
            'attempts' => 'INT DEFAULT 1',
            'error_message' => 'TEXT',
            'error_trace' => 'TEXT',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'job_uuid', 'name' => 'idx_job_uuid'],
            ['type' => 'INDEX', 'column' => 'status', 'name' => 'idx_status'],
            ['type' => 'INDEX', 'column' => 'queue', 'name' => 'idx_queue'],
            ['type' => 'INDEX', 'column' => 'created_at', 'name' => 'idx_created_at']
        ]);
    }

    /**
     * Enable monitoring
     *
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable monitoring
     *
     * @return void
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if monitoring is enabled
     *
     * @return bool True if enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
