<?php

namespace Glueful\Queue\Drivers;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\DriverInfo;
use Glueful\Queue\Contracts\HealthStatus;
use Glueful\Queue\Jobs\RedisJob;
use Glueful\Helpers\Utils;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\DatabaseException;

/**
 * Redis Queue Driver
 *
 * High-performance queue driver using Redis for job storage and processing.
 * Provides atomic operations, priority queues, and delayed job scheduling.
 *
 * Features:
 * - Atomic job operations with Redis transactions
 * - Priority-based job ordering with sorted sets
 * - Delayed job scheduling with Redis ZADD
 * - Bulk operations for high throughput
 * - Connection pooling and failover support
 * - Memory-efficient job storage
 * - Redis Lua scripts for complex operations
 *
 * Redis Data Structures:
 * - Lists: queue:{name} for job storage
 * - Sorted Sets: queue:{name}:delayed for delayed jobs
 * - Sorted Sets: queue:{name}:reserved for reserved jobs
 * - Hashes: job:{uuid} for job data
 * - Sets: queues for queue discovery
 *
 * @package Glueful\Queue\Drivers
 */
class RedisQueue implements QueueDriverInterface
{
    /** @var \Redis Redis connection */
    private \Redis $redis;

    /** @var string Redis key prefix */
    private string $prefix;

    /** @var int Seconds before job retry */
    private int $retryAfter;

    /** @var int Job expiration in seconds */
    private int $jobExpiration;

    /**
     * Get driver information
     *
     * @return DriverInfo Driver metadata
     */
    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            name: 'redis',
            version: '1.0.0',
            author: 'Glueful Team',
            description: 'High-performance Redis-backed queue driver',
            supportedFeatures: [
                'delayed_jobs',
                'priority_queues',
                'bulk_operations',
                'atomic_operations',
                'failed_jobs',
                'job_batching',
                'queue_separation',
                'high_throughput',
                'memory_efficient'
            ],
            requiredDependencies: ['redis']
        );
    }

    /**
     * Initialize driver with configuration
     *
     * @param array $config Configuration options
     * @return void
     * @throws \Exception If Redis extension not available
     */
    public function initialize(array $config): void
    {
        if (!extension_loaded('redis')) {
            throw BusinessLogicException::operationNotAllowed(
                'redis_queue_setup',
                'Redis extension is required for RedisQueue driver'
            );
        }

        $this->redis = new \Redis();

        // Connect to Redis
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 5;
        $persistent = $config['persistent'] ?? false;

        if ($persistent) {
            $connected = $this->redis->pconnect($host, $port, $timeout);
        } else {
            $connected = $this->redis->connect($host, $port, $timeout);
        }

        if (!$connected) {
            throw DatabaseException::connectionFailed(
                "Failed to connect to Redis server at {$host}:{$port}"
            );
        }

        // Authenticate if password provided
        if (!empty($config['password'])) {
            if (!$this->redis->auth($config['password'])) {
                throw DatabaseException::connectionFailed(
                    'Redis authentication failed'
                );
            }
        }

        // Select database
        $database = $config['database'] ?? 0;
        $this->redis->select($database);

        // Set configuration
        $this->prefix = $config['prefix'] ?? 'glueful:queue:';
        $this->retryAfter = $config['retry_after'] ?? 90;
        $this->jobExpiration = $config['job_expiration'] ?? 3600; // 1 hour

        // Set Redis options for reliability
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
        $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
    }

    /**
     * Perform health check on Redis connection
     *
     * @return HealthStatus Health status
     */
    public function healthCheck(): HealthStatus
    {
        $startTime = microtime(true);

        try {
            // Test Redis connection
            $pong = $this->redis->ping();
            if ($pong !== '+PONG' && $pong !== 'PONG') {
                return HealthStatus::unhealthy(
                    'Redis ping failed',
                    [],
                    (microtime(true) - $startTime) * 1000
                );
            }

            // Get Redis info
            $info = $this->redis->info();
            $responseTime = (microtime(true) - $startTime) * 1000;

            // Get queue statistics
            $queueCount = $this->redis->sCard('queues');
            $totalJobs = 0;
            $queues = $this->redis->sMembers('queues');

            foreach ($queues as $queue) {
                $totalJobs += $this->redis->lLen("queue:{$queue}");
            }

            return HealthStatus::healthy(
                [
                    'redis_version' => $info['redis_version'] ?? 'unknown',
                    'connected_clients' => $info['connected_clients'] ?? 0,
                    'used_memory_human' => $info['used_memory_human'] ?? '0B',
                    'total_queues' => $queueCount,
                    'total_jobs' => $totalJobs,
                    'prefix' => $this->prefix
                ],
                'Redis connection is healthy',
                $responseTime
            );
        } catch (\Exception $e) {
            return HealthStatus::unhealthy(
                'Redis connection failed: ' . $e->getMessage(),
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
        return $this->pushToRedis($job, $data, 0, $queue);
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
        return $this->pushToRedis($job, $data, $delay, $queue);
    }

    /**
     * Push job to Redis
     *
     * @param string $job Job class name
     * @param array $data Job data
     * @param int $delay Delay in seconds
     * @param string|null $queue Queue name
     * @param string|null $batchUuid Batch UUID if part of batch
     * @return string Job UUID
     */
    private function pushToRedis(
        string $job,
        array $data,
        int $delay = 0,
        ?string $queue = null,
        ?string $batchUuid = null
    ): string {
        $uuid = Utils::generateNanoID();
        $queue = $queue ?? 'default';
        $now = time();
        $availableAt = $now + $delay;

        $jobData = [
            'uuid' => $uuid,
            'displayName' => $job,
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'maxAttempts' => $data['maxAttempts'] ?? 3,
            'timeout' => $data['timeout'] ?? 60,
            'pushedAt' => $now,
            'availableAt' => $availableAt,
            'priority' => $data['priority'] ?? 0,
            'batchUuid' => $batchUuid,
            'queue' => $queue
        ];

        // Use Redis transaction for atomicity
        $this->redis->multi();

        // Store job data
        $this->redis->hMSet("job:{$uuid}", $jobData);
        $this->redis->expire("job:{$uuid}", $this->jobExpiration);

        // Add to queue registry
        $this->redis->sAdd('queues', $queue);

        if ($delay > 0) {
            // Add to delayed queue (sorted set with timestamp as score)
            $this->redis->zAdd("queue:{$queue}:delayed", $availableAt, $uuid);
        } else {
            // Add to immediate queue (list)
            if (($data['priority'] ?? 0) > 0) {
                // High priority jobs go to front
                $this->redis->lPush("queue:{$queue}", $uuid);
            } else {
                // Normal priority jobs go to back
                $this->redis->rPush("queue:{$queue}", $uuid);
            }
        }

        $this->redis->exec();

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

        // Process delayed jobs first
        $this->releaseDelayedJobs($queue);

        // Also clean up expired reserved jobs
        $this->releaseExpiredJobs($queue);

        // Get next job atomically
        $uuid = $this->redis->lPop("queue:{$queue}");

        if (!$uuid) {
            return null;
        }

        // Get job data
        $jobData = $this->redis->hGetAll("job:{$uuid}");

        if (empty($jobData)) {
            // Job data not found, skip
            return null;
        }

        // Mark as reserved with timestamp
        $reservedAt = time();
        $this->redis->multi();
        $this->redis->hMSet("job:{$uuid}", [
            'attempts' => $jobData['attempts'] + 1,
            'reservedAt' => $reservedAt
        ]);
        $this->redis->zAdd("queue:{$queue}:reserved", $reservedAt + $this->retryAfter, $uuid);
        $this->redis->exec();

        return new RedisJob($this, $jobData, $queue);
    }

    /**
     * Release delayed jobs that are now ready
     *
     * @param string $queue Queue name
     * @return void
     */
    private function releaseDelayedJobs(string $queue): void
    {
        $now = time();

        // Get all delayed jobs that are ready (score <= now)
        $readyJobs = $this->redis->zRangeByScore("queue:{$queue}:delayed", '0', (string)$now);
        if (empty($readyJobs)) {
            return;
        }

        $this->redis->multi();

        foreach ($readyJobs as $uuid) {
            // Move from delayed to immediate queue
            $this->redis->zRem("queue:{$queue}:delayed", $uuid);
            $this->redis->rPush("queue:{$queue}", $uuid);
        }

        $this->redis->exec();
    }

    /**
     * Release expired reserved jobs back to queue
     *
     * @param string $queue Queue name
     * @return void
     */
    private function releaseExpiredJobs(string $queue): void
    {
        $now = time();

        // Get expired reserved jobs
        $expiredJobs = $this->redis->zRangeByScore("queue:{$queue}:reserved", '0', (string)$now);

        if (empty($expiredJobs)) {
            return;
        }

        $this->redis->multi();

        foreach ($expiredJobs as $uuid) {
            // Remove from reserved and add back to queue
            $this->redis->zRem("queue:{$queue}:reserved", $uuid);
            $this->redis->rPush("queue:{$queue}", $uuid);

            // Reset reserved timestamp
            $this->redis->hDel("job:{$uuid}", 'reservedAt');
        }

        $this->redis->exec();
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
        if (!$job instanceof RedisJob) {
            throw new \InvalidArgumentException('Job must be a RedisJob instance');
        }

        $uuid = $job->getUuid();
        $queue = $job->getQueue();
        $availableAt = time() + $delay;

        $this->redis->multi();

        // Remove from reserved queue
        $this->redis->zRem("queue:{$queue}:reserved", $uuid);

        // Update job data
        $this->redis->hMSet("job:{$uuid}", [
            'availableAt' => $availableAt
        ]);
        $this->redis->hDel("job:{$uuid}", 'reservedAt');

        if ($delay > 0) {
            // Add to delayed queue
            $this->redis->zAdd("queue:{$queue}:delayed", $availableAt, $uuid);
        } else {
            // Add back to immediate queue
            $this->redis->rPush("queue:{$queue}", $uuid);
        }

        $this->redis->exec();
    }

    /**
     * Delete job from queue
     *
     * @param JobInterface $job Job to delete
     * @return void
     */
    public function delete(JobInterface $job): void
    {
        if (!$job instanceof RedisJob) {
            throw new \InvalidArgumentException('Job must be a RedisJob instance');
        }

        $uuid = $job->getUuid();
        $queue = $job->getQueue();

        $this->redis->multi();

        // Remove from all possible locations
        $this->redis->lRem("queue:{$queue}", $uuid, 0);
        $this->redis->zRem("queue:{$queue}:delayed", $uuid);
        $this->redis->zRem("queue:{$queue}:reserved", $uuid);

        // Delete job data
        $this->redis->del("job:{$uuid}");

        $this->redis->exec();
    }

    /**
     * Get number of jobs in queue
     *
     * @param string|null $queue Queue name
     * @return int Number of jobs
     */
    public function size(?string $queue = null): int
    {
        if ($queue === null) {
            // Count all jobs across all queues
            $total = 0;
            $queues = $this->redis->sMembers('queues');

            foreach ($queues as $queueName) {
                $total += $this->redis->lLen("queue:{$queueName}");
                $total += $this->redis->zCard("queue:{$queueName}:delayed");
                $total += $this->redis->zCard("queue:{$queueName}:reserved");
            }

            return $total;
        }

        return $this->redis->lLen("queue:{$queue}") +
               $this->redis->zCard("queue:{$queue}:delayed") +
               $this->redis->zCard("queue:{$queue}:reserved");
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
        $queue = $queue ?? 'default';
        $now = time();

        $this->redis->multi();

        foreach ($jobs as $jobDef) {
            $uuid = Utils::generateNanoID();
            $uuids[] = $uuid;

            $delay = $jobDef['delay'] ?? 0;
            $availableAt = $now + $delay;

            $jobData = [
                'uuid' => $uuid,
                'displayName' => $jobDef['job'],
                'job' => $jobDef['job'],
                'data' => $jobDef['data'] ?? [],
                'attempts' => 0,
                'maxAttempts' => $jobDef['data']['maxAttempts'] ?? 3,
                'timeout' => $jobDef['data']['timeout'] ?? 60,
                'pushedAt' => $now,
                'availableAt' => $availableAt,
                'priority' => $jobDef['data']['priority'] ?? 0,
                'batchUuid' => $jobDef['batch_uuid'] ?? null,
                'queue' => $queue
            ];

            // Store job data
            $this->redis->hMSet("job:{$uuid}", $jobData);
            $this->redis->expire("job:{$uuid}", $this->jobExpiration);

            // Add to appropriate queue
            if ($delay > 0) {
                $this->redis->zAdd("queue:{$queue}:delayed", $availableAt, $uuid);
            } else {
                $this->redis->rPush("queue:{$queue}", $uuid);
            }
        }

        // Add to queue registry
        $this->redis->sAdd('queues', $queue);

        $this->redis->exec();

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
        if ($queue === null) {
            // Purge all queues
            $count = 0;
            $queues = $this->redis->sMembers('queues');

            foreach ($queues as $queueName) {
                $count += $this->purge($queueName);
            }

            // Clear queue registry
            $this->redis->del('queues');

            return $count;
        }

        // Count jobs before deletion
        $count = $this->size($queue);

        // Get all job UUIDs to delete their data
        $immediate = $this->redis->lRange("queue:{$queue}", 0, -1);
        $delayed = $this->redis->zRange("queue:{$queue}:delayed", 0, -1);
        $reserved = $this->redis->zRange("queue:{$queue}:reserved", 0, -1);

        $allJobs = array_merge($immediate, $delayed, $reserved);

        $this->redis->multi();

        // Delete all job data
        foreach ($allJobs as $uuid) {
            $this->redis->del("job:{$uuid}");
        }

        // Delete queue structures
        $this->redis->del("queue:{$queue}");
        $this->redis->del("queue:{$queue}:delayed");
        $this->redis->del("queue:{$queue}:reserved");

        // Remove from queue registry
        $this->redis->sRem('queues', $queue);

        $this->redis->exec();

        return $count;
    }

    /**
     * Get queue statistics
     *
     * @param string|null $queue Queue name
     * @return array Statistics
     */
    public function getStats(?string $queue = null): array
    {
        if ($queue === null) {
            // Get stats for all queues
            $queues = $this->redis->sMembers('queues');
            $totalStats = [
                'total' => 0,
                'pending' => 0,
                'delayed' => 0,
                'reserved' => 0,
                'failed' => 0,
                'queues' => $queues
            ];

            foreach ($queues as $queueName) {
                $queueStats = $this->getStats($queueName);
                $totalStats['total'] += $queueStats['total'];
                $totalStats['pending'] += $queueStats['pending'];
                $totalStats['delayed'] += $queueStats['delayed'];
                $totalStats['reserved'] += $queueStats['reserved'];
                $totalStats['failed'] += $queueStats['failed'];
            }

            return $totalStats;
        }

        $pending = $this->redis->lLen("queue:{$queue}");
        $delayed = $this->redis->zCard("queue:{$queue}:delayed");
        $reserved = $this->redis->zCard("queue:{$queue}:reserved");
        $failed = $this->redis->lLen("queue:{$queue}:failed");

        return [
            'total' => $pending + $delayed + $reserved,
            'pending' => $pending,
            'delayed' => $delayed,
            'reserved' => $reserved,
            'failed' => $failed,
            'queues' => [$queue]
        ];
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
            'host' => [
                'type' => 'string',
                'required' => false,
                'default' => '127.0.0.1',
                'description' => 'Redis server hostname'
            ],
            'port' => [
                'type' => 'port',
                'required' => false,
                'default' => 6379,
                'description' => 'Redis server port'
            ],
            'password' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Redis authentication password'
            ],
            'database' => [
                'type' => 'int',
                'required' => false,
                'default' => 0,
                'description' => 'Redis database number'
            ],
            'timeout' => [
                'type' => 'int',
                'required' => false,
                'default' => 5,
                'description' => 'Connection timeout in seconds'
            ],
            'persistent' => [
                'type' => 'bool',
                'required' => false,
                'default' => false,
                'description' => 'Use persistent connections'
            ],
            'prefix' => [
                'type' => 'string',
                'required' => false,
                'default' => 'glueful:queue:',
                'description' => 'Redis key prefix'
            ],
            'retry_after' => [
                'type' => 'int',
                'required' => false,
                'default' => 90,
                'description' => 'Seconds before retrying reserved jobs'
            ],
            'job_expiration' => [
                'type' => 'int',
                'required' => false,
                'default' => 3600,
                'description' => 'Job data expiration in seconds'
            ]
        ];
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
        $queue = $job->getQueue();

        $failedData = [
            'uuid' => Utils::generateNanoID(),
            'connection' => 'redis',
            'queue' => $queue,
            'payload' => json_encode($job->getPayload()),
            'exception' => $exception->getMessage() . "\n\n" . $exception->getTraceAsString(),
            'failed_at' => time()
        ];

        $this->redis->multi();

        // Add to failed jobs
        $this->redis->rPush("queue:{$queue}:failed", json_encode($failedData));

        // Remove job from all active locations
        $this->delete($job);

        $this->redis->exec();
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
}
