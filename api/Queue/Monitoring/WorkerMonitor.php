<?php

namespace Glueful\Queue\Monitoring;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

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
    /** @var Connection Database connection */
    private Connection $db;

    /** @var SchemaBuilderInterface Schema builder for table operations */
    private SchemaBuilderInterface $schema;

    /** @var string Workers table name */
    private string $workersTable = 'queue_workers';

    /** @var string Job metrics table name */
    private string $metricsTable = 'queue_job_metrics';

    /** @var bool Whether monitoring is enabled */
    private bool $enabled;

    /**
     * Create worker monitor instance
     *
     * @param Connection|null $connection Database connection (optional)
     * @param bool $enabled Whether monitoring is enabled
     */
    public function __construct(?Connection $connection = null, bool $enabled = true)
    {
        $this->db = $connection ?? new Connection();
        $this->schema = $this->db->getSchemaBuilder();
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

        $this->db->table($this->workersTable)->insert($data);
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

            $this->db->table($this->workersTable)
                ->where('uuid', $workerUuid)
                ->update($updateData);
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

        $this->db->table($this->workersTable)
            ->where('uuid', $workerUuid)
            ->update($updateData);
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

        $this->db->table($this->metricsTable)->insert($data);
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

        $this->db->table($this->metricsTable)
            ->where('job_uuid', $job->getUuid())
            ->update($updateData);
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

        $this->db->table($this->metricsTable)
            ->where('job_uuid', $job->getUuid())
            ->update($updateData);
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

        return $this->db->table($this->workersTable)
            ->select(['*'])
            ->where('status', 'active')
            ->where('last_seen', '>=', $cutoff)
            ->get();
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

        $query = $this->db->table($this->workersTable)->select(['*']);
        if ($workerUuid) {
            $query->where('uuid', $workerUuid);
        }
        $workers = $query->get();

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

        $limit = $filters['limit'] ?? 100;

        $query = $this->db->table($this->metricsTable)->select(['*']);

        if (isset($filters['queue'])) {
            $query->where('queue', $filters['queue']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->orderBy('created_at', 'DESC')
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

        // Get basic stats
        $totalQuery = $this->db->table($this->metricsTable);
        if ($queue) {
            $totalQuery->where('queue', $queue);
        }
        $totalJobs = $totalQuery->count();

        $completedQuery = $this->db->table($this->metricsTable)
            ->where('status', 'completed');
        if ($queue) {
            $completedQuery->where('queue', $queue);
        }
        $completedJobs = $completedQuery->count();

        $failedQuery = $this->db->table($this->metricsTable)
            ->where('status', 'failed');
        if ($queue) {
            $failedQuery->where('queue', $queue);
        }
        $failedJobs = $failedQuery->count();

        // Get average processing time for completed jobs
        $avgProcessingTime = 0;
        if ($completedJobs > 0) {
            $query = $this->db->table($this->metricsTable)
                ->selectRaw('AVG(processing_time) as avg_time')
                ->where('status', 'completed');

            if ($queue) {
                $query->where('queue', $queue);
            }

            $results = $query->get();
            $result = $results[0] ?? null;
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

        return $this->db->table($this->workersTable)
            ->where('status', 'stopped')
            ->where('updated_at', '<', $cutoff)
            ->delete() > 0;
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

        return $this->db->table($this->metricsTable)
            ->where('created_at', '<', $cutoff)
            ->delete() > 0;
    }

    /**
     * Get worker memory peak
     *
     * @param string $workerUuid Worker UUID
     * @return int Memory peak in bytes
     */
    private function getWorkerMemoryPeak(string $workerUuid): int
    {
        $results = $this->db->table($this->workersTable)
            ->select(['memory_peak'])
            ->where('uuid', $workerUuid)
            ->limit(1)
            ->get();
        $worker = $results[0] ?? null;

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
            $this->db->table($tableName)->selectRaw('1')->limit(1)->get();
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
        if (!$this->schema->hasTable($this->workersTable)) {
            $table = $this->schema->table($this->workersTable);

            // Define columns
            $table->integer('id')->primary()->autoIncrement();
            $table->string('uuid', 255);
            $table->string('connection', 255);
            $table->string('queue', 255);
            $table->integer('pid');
            $table->string('hostname', 255);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->integer('jobs_processed')->default(0);
            $table->integer('jobs_failed')->default(0);
            $table->bigInteger('memory_usage')->default(0);
            $table->bigInteger('memory_peak')->default(0);
            $table->integer('total_runtime')->default(0);
            $table->integer('final_jobs_processed')->default(0);
            $table->integer('final_jobs_failed')->default(0);
            $table->bigInteger('final_memory_peak')->default(0);
            $table->string('status', 20)->default('active'); // ENUM replacement
            $table->text('options')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('status', 'idx_status');
            $table->index('last_seen', 'idx_last_seen');
            $table->index(['connection', 'queue'], 'idx_connection_queue');

            // Create the table
            $table->create();

            // Execute the operation
            $this->schema->execute();
        }
    }

    /**
     * Create metrics table
     *
     * @return void
     */
    private function createMetricsTable(): void
    {
        if (!$this->schema->hasTable($this->metricsTable)) {
            $table = $this->schema->table($this->metricsTable);

            // Define columns
            $table->integer('id')->primary()->autoIncrement();
            $table->string('job_uuid', 255);
            $table->string('job_class', 255);
            $table->string('queue', 255);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->decimal('processing_time', 10, 4)->default(0);
            $table->bigInteger('memory_used')->default(0);
            $table->string('status', 20)->default('processing'); // ENUM replacement
            $table->integer('attempts')->default(1);
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('job_uuid', 'idx_job_uuid');
            $table->index('status', 'idx_status');
            $table->index('queue', 'idx_queue');
            $table->index('created_at', 'idx_created_at');

            // Create the table
            $table->create();

            // Execute the operation
            $this->schema->execute();
        }
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
