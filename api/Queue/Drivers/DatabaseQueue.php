<?php

namespace Glueful\Queue\Drivers;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\DriverInfo;
use Glueful\Queue\Contracts\HealthStatus;
use Glueful\Queue\Jobs\DatabaseJob;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

/**
 * Database Queue Driver
 *
 * Queue driver implementation using database tables for job storage.
 * Provides reliable job queuing with transaction support and atomic operations.
 *
 * Features:
 * - ACID compliance with transaction support
 * - Atomic job reservation using row locking
 * - Priority-based job ordering
 * - Delayed job scheduling
 * - Batch operations for performance
 * - Failed job isolation
 * - Queue-level separation
 *
 * Performance Optimizations:
 * - Indexed columns for fast queries
 * - Efficient job picking with compound indexes
 * - Batch insertions for bulk operations
 * - Connection pooling support
 *
 * @package Glueful\Queue\Drivers
 */
class DatabaseQueue implements QueueDriverInterface
{
    /** @var QueryBuilder Database query builder */
    private QueryBuilder $db;

    /** @var string Queue jobs table name */
    private string $table;

    /** @var string Failed jobs table name */
    private string $failedTable;

    /** @var int Seconds before job retry */
    private int $retryAfter;

    /** @var Connection Database connection */
    private Connection $connection;

    /**
     * Get driver information
     *
     * @return DriverInfo Driver metadata
     */
    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            name: 'database',
            version: '1.0.0',
            author: 'Glueful Team',
            description: 'Database-backed queue driver with transaction support',
            supportedFeatures: [
                'delayed_jobs',
                'priority_queues',
                'bulk_operations',
                'atomic_operations',
                'failed_jobs',
                'job_batching',
                'queue_separation'
            ],
            requiredDependencies: []
        );
    }

    /**
     * Initialize driver with configuration
     *
     * @param array $config Configuration options
     * @return void
     */
    public function initialize(array $config): void
    {
        $this->connection = new Connection();
        $this->db = new QueryBuilder($this->connection->getPDO(), $this->connection->getDriver());
        $this->table = $config['table'] ?? 'queue_jobs';
        $this->failedTable = $config['failed_table'] ?? 'queue_failed_jobs';
        $this->retryAfter = $config['retry_after'] ?? 90;
    }

    /**
     * Perform health check on database connection
     *
     * @return HealthStatus Health status
     */
    public function healthCheck(): HealthStatus
    {
        $startTime = microtime(true);

        try {
            // Test database connection
            $this->db->rawQuery("SELECT 1");

            // Check if queue table exists using database-agnostic approach
            try {
                $this->db->rawQuery("SELECT 1 FROM {$this->table} LIMIT 1");
                $tableExists = true;
            } catch (\Exception $e) {
                $tableExists = false;
            }

            if (!$tableExists) {
                return HealthStatus::unhealthy(
                    "Queue table '{$this->table}' does not exist",
                    [],
                    (microtime(true) - $startTime) * 1000
                );
            }

            // Get queue statistics
            $stats = $this->db->rawQuery(
                "SELECT 
                    COUNT(*) as total_jobs,
                    COUNT(CASE WHEN reserved_at IS NULL THEN 1 END) as pending_jobs,
                    COUNT(CASE WHEN reserved_at IS NOT NULL THEN 1 END) as reserved_jobs
                FROM {$this->table}"
            );

            $responseTime = (microtime(true) - $startTime) * 1000;

            return HealthStatus::healthy(
                [
                    'total_jobs' => $stats[0]['total_jobs'] ?? 0,
                    'pending_jobs' => $stats[0]['pending_jobs'] ?? 0,
                    'reserved_jobs' => $stats[0]['reserved_jobs'] ?? 0,
                    'table' => $this->table
                ],
                'Database connection is healthy',
                $responseTime
            );
        } catch (\Exception $e) {
            return HealthStatus::unhealthy(
                'Database connection failed: ' . $e->getMessage(),
                [],
                (microtime(true) - $startTime) * 1000
            );
        }
    }

    /**
     * Push job to queue
     *
     * @param string $job Job class name
     * @param array $data Job data
     * @param string|null $queue Queue name
     * @return string Job UUID
     */
    public function push(string $job, array $data = [], ?string $queue = null): string
    {
        return $this->pushToDatabase($job, $data, 0, $queue);
    }

    /**
     * Push delayed job to queue
     *
     * @param int $delay Delay in seconds
     * @param string $job Job class name
     * @param array $data Job data
     * @param string|null $queue Queue name
     * @return string Job UUID
     */
    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
    {
        return $this->pushToDatabase($job, $data, $delay, $queue);
    }

    /**
     * Push job to database
     *
     * @param string $job Job class name
     * @param array $data Job data
     * @param int $delay Delay in seconds
     * @param string|null $queue Queue name
     * @param string|null $batchUuid Batch UUID if part of batch
     * @return string Job UUID
     */
    private function pushToDatabase(
        string $job,
        array $data,
        int $delay = 0,
        ?string $queue = null,
        ?string $batchUuid = null
    ): string {
        $uuid = Utils::generateNanoID();
        $now = new \DateTime();
        $availableAt = clone $now;
        if ($delay > 0) {
            $availableAt->add(new \DateInterval("PT{$delay}S"));
        }

        $this->db->insert($this->table, [
            'uuid' => $uuid,
            'queue' => $queue ?? 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => $job,
                'job' => $job,
                'data' => $data,
                'attempts' => 0,
                'maxAttempts' => $data['maxAttempts'] ?? 3,
                'timeout' => $data['timeout'] ?? 60,
                'pushedAt' => $now->getTimestamp()
            ]),
            'attempts' => 0,
            'available_at' => $availableAt->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'priority' => $data['priority'] ?? 0,
            'batch_uuid' => $batchUuid
        ]);

        return $uuid;
    }

    /**
     * Pop next job from queue
     *
     * @param string|null $queue Queue name
     * @return JobInterface|null Next job or null
     */
    public function pop(?string $queue = null): ?JobInterface
    {
        $queue = $queue ?? 'default';

        // Use transaction for atomic operation
        return $this->db->transaction(function () use ($queue) {
            // Clean up expired reserved jobs
            $this->releaseExpiredJobs($queue);

            // Get next available job using QueryBuilder
            $job = $this->db->select($this->table, ['*'], [
                'queue' => $queue
            ])
            ->whereNull('reserved_at')
            ->whereRaw('available_at <= ?', [date('Y-m-d H:i:s')])
            ->orderBy(['priority' => 'DESC', 'available_at' => 'ASC'])
            ->limit(1)
            ->first();

            if (empty($job)) {
                return null;
            }

            // Mark job as reserved
            $this->db->update($this->table, [
                'reserved_at' => date('Y-m-d H:i:s'),
                'attempts' => $job['attempts'] + 1
            ], ['uuid' => $job['uuid']]);

            return new DatabaseJob($this, $job, $queue);
        });
    }

    /**
     * Release expired reserved jobs back to queue
     *
     * @param string $queue Queue name
     * @return void
     */
    private function releaseExpiredJobs(string $queue): void
    {
        $expiredTime = (new \DateTime())
            ->sub(new \DateInterval("PT{$this->retryAfter}S"))
            ->format('Y-m-d H:i:s');

        // Use rawQuery for complex WHERE conditions in UPDATE
        $this->db->rawQuery(
            "UPDATE {$this->table} 
            SET reserved_at = NULL 
            WHERE queue = ? 
            AND reserved_at < ?",
            [$queue, $expiredTime]
        );
    }

    /**
     * Release job back to queue
     *
     * @param JobInterface $job Job to release
     * @param int $delay Delay before retry
     * @return void
     */
    public function release(JobInterface $job, int $delay = 0): void
    {
        if (!$job instanceof DatabaseJob) {
            throw new \InvalidArgumentException('Job must be a DatabaseJob instance');
        }

        $availableAt = new \DateTime();
        if ($delay > 0) {
            $availableAt->add(new \DateInterval("PT{$delay}S"));
        }

        $this->db->update($this->table, [
            'reserved_at' => null,
            'available_at' => $availableAt->format('Y-m-d H:i:s')
        ], ['uuid' => $job->getUuid()]);
    }

    /**
     * Delete job from queue
     *
     * @param JobInterface $job Job to delete
     * @return void
     */
    public function delete(JobInterface $job): void
    {
        if (!$job instanceof DatabaseJob) {
            throw new \InvalidArgumentException('Job must be a DatabaseJob instance');
        }

        $this->db->delete($this->table, ['uuid' => $job->getUuid()]);
    }

    /**
     * Get number of jobs in queue
     *
     * @param string|null $queue Queue name
     * @return int Number of jobs
     */
    public function size(?string $queue = null): int
    {
        $conditions = [];
        if ($queue !== null) {
            $conditions['queue'] = $queue;
        }

        $result = $this->db->count($this->table, $conditions);
        return (int) $result;
    }

    /**
     * Push multiple jobs in bulk
     *
     * @param array $jobs Array of job definitions
     * @param string|null $queue Queue name
     * @return array Array of job UUIDs
     */
    public function bulk(array $jobs, ?string $queue = null): array
    {
        $uuids = [];
        $rows = [];
        $now = new \DateTime();

        foreach ($jobs as $jobDef) {
            $uuid = Utils::generateNanoID();
            $uuids[] = $uuid;

            $delay = $jobDef['delay'] ?? 0;
            $availableAt = clone $now;
            if ($delay > 0) {
                $availableAt->add(new \DateInterval("PT{$delay}S"));
            }

            $rows[] = [
                'uuid' => $uuid,
                'queue' => $queue ?? 'default',
                'payload' => json_encode([
                    'uuid' => $uuid,
                    'displayName' => $jobDef['job'],
                    'job' => $jobDef['job'],
                    'data' => $jobDef['data'] ?? [],
                    'attempts' => 0,
                    'maxAttempts' => $jobDef['data']['maxAttempts'] ?? 3,
                    'timeout' => $jobDef['data']['timeout'] ?? 60,
                    'pushedAt' => $now->getTimestamp()
                ]),
                'attempts' => 0,
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'priority' => $jobDef['data']['priority'] ?? 0,
                'batch_uuid' => $jobDef['batch_uuid'] ?? null
            ];
        }

        // Use batch insert for performance
        if (!empty($rows)) {
            $this->db->insertBatch($this->table, $rows);
        }

        return $uuids;
    }

    /**
     * Remove all jobs from queue
     *
     * @param string|null $queue Queue name
     * @return int Number of jobs purged
     */
    public function purge(?string $queue = null): int
    {
        $conditions = [];
        if ($queue !== null) {
            $conditions['queue'] = $queue;
        }

        // Get count before deletion
        $count = $this->db->count($this->table, $conditions);

        // Delete all matching jobs
        $this->db->delete($this->table, $conditions);

        return (int) $count;
    }

    /**
     * Get queue statistics
     *
     * @param string|null $queue Queue name
     * @return array Statistics
     */
    public function getStats(?string $queue = null): array
    {
        $conditions = [];
        if ($queue !== null) {
            $conditions['queue'] = $queue;
        }

        // Build SQL conditions
        $whereClause = '';
        $params = [];
        if ($queue !== null) {
            $whereClause = 'WHERE queue = ?';
            $params = [$queue];
        }

        // Get various counts using raw SQL for better performance
        $stats = $this->db->rawQuery(
            "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN reserved_at IS NULL THEN 1 END) as pending,
                COUNT(CASE WHEN reserved_at IS NOT NULL THEN 1 END) as reserved,
                COUNT(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 END) as delayed
            FROM {$this->table} {$whereClause}",
            array_merge([date('Y-m-d H:i:s')], $params)
        );

        $total = (int) ($stats[0]['total'] ?? 0);
        $pending = (int) ($stats[0]['pending'] ?? 0);
        $reserved = (int) ($stats[0]['reserved'] ?? 0);
        $delayed = (int) ($stats[0]['delayed'] ?? 0);

        // Get failed job count
        $failedConditions = [];
        if ($queue !== null) {
            $failedConditions['queue'] = $queue;
        }
        $failed = $this->db->count($this->failedTable, $failedConditions);

        return [
            'total' => $total,
            'pending' => $pending,
            'reserved' => $reserved,
            'delayed' => $delayed,
            'failed' => (int) $failed,
            'queues' => $this->getQueueList()
        ];
    }

    /**
     * Get list of all queues
     *
     * @return array Queue names
     */
    private function getQueueList(): array
    {
        $result = $this->db->rawQuery(
            "SELECT DISTINCT queue FROM {$this->table} ORDER BY queue"
        );

        return array_column($result, 'queue');
    }

    /**
     * Get supported features
     *
     * @return array Feature list
     */
    public function getFeatures(): array
    {
        return $this->getDriverInfo()->supportedFeatures;
    }

    /**
     * Get configuration schema
     *
     * @return array Schema definition
     */
    public function getConfigSchema(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'required' => false,
                'default' => 'queue_jobs',
                'description' => 'Database table for queue jobs'
            ],
            'failed_table' => [
                'type' => 'string',
                'required' => false,
                'default' => 'queue_failed_jobs',
                'description' => 'Database table for failed jobs'
            ],
            'retry_after' => [
                'type' => 'int',
                'required' => false,
                'default' => 90,
                'description' => 'Seconds before retrying reserved jobs'
            ]
        ];
    }

    /**
     * Handle failed job (interface requirement)
     *
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(JobInterface $job, \Exception $exception): void
    {
        $this->fail($job, $exception);
    }

    /**
     * Mark job as failed
     *
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function fail(JobInterface $job, \Exception $exception): void
    {
        $this->db->transaction(function () use ($job, $exception) {
            // Move to failed jobs table
            $this->db->insert($this->failedTable, [
                'uuid' => Utils::generateNanoID(),
                'connection' => 'database',
                'queue' => $job->getQueue(),
                'payload' => json_encode($job->getPayload()),
                'exception' => $exception->getMessage() . "\n\n" . $exception->getTraceAsString(),
                'failed_at' => date('Y-m-d H:i:s')
            ]);

            // Remove from main queue
            $this->delete($job);
        });
    }
}
