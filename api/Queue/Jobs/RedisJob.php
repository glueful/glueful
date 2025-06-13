<?php

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\QueueDriverInterface;

/**
 * Redis Job Implementation
 *
 * Represents a job stored in Redis queue.
 * Handles job execution, retry logic, and failure handling for Redis-based jobs.
 *
 * Features:
 * - Redis-optimized job payload management
 * - Efficient attempt tracking with Redis operations
 * - Fast retry and release logic
 * - Memory-efficient failure handling
 * - Batch support with Redis transactions
 *
 * @package Glueful\Queue\Jobs
 */
class RedisJob implements JobInterface
{
    /** @var QueueDriverInterface Redis queue driver instance */
    private QueueDriverInterface $driver;

    /** @var array Raw job data from Redis */
    private array $rawData;

    /** @var array Decoded payload data */
    private array $payload;

    /** @var string Queue name */
    private string $queue;

    /** @var bool Whether job has been deleted */
    private bool $deleted = false;

    /** @var bool Whether job has been released */
    private bool $released = false;

    /**
     * Create new Redis job instance
     *
     * @param QueueDriverInterface $driver Redis queue driver
     * @param array $rawData Raw job data from Redis
     * @param string $queue Queue name
     */
    public function __construct(QueueDriverInterface $driver, array $rawData, string $queue)
    {
        $this->driver = $driver;
        $this->rawData = $rawData;
        $this->queue = $queue;
        $this->payload = $rawData; // In Redis, the raw data is already the payload
    }

    /**
     * Get job UUID
     *
     * @return string Job UUID
     */
    public function getUuid(): string
    {
        return $this->rawData['uuid'];
    }

    /**
     * Get queue name
     *
     * @return string|null Queue name
     */
    public function getQueue(): ?string
    {
        return $this->queue;
    }

    /**
     * Get number of attempts
     *
     * @return int Attempt count
     */
    public function getAttempts(): int
    {
        return (int) ($this->rawData['attempts'] ?? 0);
    }

    /**
     * Get job payload
     *
     * @return array Job data
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Get raw Redis data
     *
     * @return array Raw data
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * Execute the job
     *
     * @return void
     * @throws \Exception On job failure
     */
    public function fire(): void
    {
        $jobClass = $this->payload['job'] ?? null;

        if (!$jobClass || !class_exists($jobClass)) {
            throw new \RuntimeException("Job class '{$jobClass}' not found");
        }

        // Create job instance
        $job = $this->resolve($jobClass);

        // Execute job with data
        if (method_exists($job, 'handle')) {
            $job->handle($this->payload['data'] ?? []);
        } else {
            throw new \RuntimeException("Job class '{$jobClass}' must have a 'handle' method");
        }

        // Delete job after successful execution
        if (!$this->deleted && !$this->released) {
            $this->delete();
        }
    }

    /**
     * Resolve job class instance
     *
     * @param string $class Job class name
     * @return object Job instance
     */
    private function resolve(string $class): object
    {
        // Simple instantiation - can be enhanced with dependency injection
        return new $class();
    }

    /**
     * Release job back to queue
     *
     * @param int $delay Delay in seconds
     * @return void
     */
    public function release(int $delay = 0): void
    {
        $this->driver->release($this, $delay);
        $this->released = true;
    }

    /**
     * Delete job from queue
     *
     * @return void
     */
    public function delete(): void
    {
        $this->driver->delete($this);
        $this->deleted = true;
    }

    /**
     * Handle job failure
     *
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(\Exception $exception): void
    {
        // Mark job as failed in Redis
        $this->driver->failed($this, $exception);

        // Call failed method on job class if exists
        $jobClass = $this->payload['job'] ?? null;
        if ($jobClass && class_exists($jobClass)) {
            try {
                $job = $this->resolve($jobClass);
                if (method_exists($job, 'failed')) {
                    $job->failed($this->payload['data'] ?? [], $exception);
                }
            } catch (\Exception $e) {
                // Log but don't throw - job is already failed
                error_log("Error in job failed handler: " . $e->getMessage());
            }
        }
    }

    /**
     * Get maximum attempts allowed
     *
     * @return int Max attempts
     */
    public function getMaxAttempts(): int
    {
        return (int) ($this->payload['maxAttempts'] ?? 3);
    }

    /**
     * Get job timeout
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        return (int) ($this->payload['timeout'] ?? 60);
    }

    /**
     * Get batch UUID if part of batch
     *
     * @return string|null Batch UUID
     */
    public function getBatchUuid(): ?string
    {
        return $this->rawData['batchUuid'] ?? null;
    }

    /**
     * Check if job should be retried
     *
     * @return bool True if should retry
     */
    public function shouldRetry(): bool
    {
        return $this->getAttempts() < $this->getMaxAttempts();
    }

    /**
     * Get job priority
     *
     * @return int Priority level
     */
    public function getPriority(): int
    {
        return (int) ($this->rawData['priority'] ?? 0);
    }

    /**
     * Get job name for display
     *
     * @return string Display name
     */
    public function getName(): string
    {
        return $this->payload['displayName'] ?? $this->payload['job'] ?? 'Unknown';
    }

    /**
     * Check if job has been deleted
     *
     * @return bool True if deleted
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Check if job has been released
     *
     * @return bool True if released
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Get job creation timestamp
     *
     * @return int Unix timestamp
     */
    public function getCreatedAt(): int
    {
        return (int) ($this->rawData['pushedAt'] ?? time());
    }

    /**
     * Get when job was pushed
     *
     * @return int Unix timestamp
     */
    public function getPushedAt(): int
    {
        return (int) ($this->payload['pushedAt'] ?? $this->getCreatedAt());
    }

    /**
     * Get when job becomes available
     *
     * @return int Unix timestamp
     */
    public function getAvailableAt(): int
    {
        return (int) ($this->rawData['availableAt'] ?? time());
    }

    /**
     * Get when job was reserved
     *
     * @return int|null Unix timestamp or null if not reserved
     */
    public function getReservedAt(): ?int
    {
        $reservedAt = $this->rawData['reservedAt'] ?? null;
        return $reservedAt ? (int) $reservedAt : null;
    }

    /**
     * Check if job is delayed
     *
     * @return bool True if job is delayed
     */
    public function isDelayed(): bool
    {
        return $this->getAvailableAt() > time();
    }

    /**
     * Check if job is reserved
     *
     * @return bool True if job is reserved
     */
    public function isReserved(): bool
    {
        return $this->getReservedAt() !== null;
    }

    /**
     * Get job age in seconds
     *
     * @return int Age in seconds
     */
    public function getAge(): int
    {
        return time() - $this->getCreatedAt();
    }

    /**
     * Get time until job becomes available
     *
     * @return int Seconds until available (0 if already available)
     */
    public function getDelayRemaining(): int
    {
        return max(0, $this->getAvailableAt() - time());
    }

    /**
     * Set number of attempts
     *
     * @param int $attempts Number of attempts
     * @return void
     */
    public function setAttempts(int $attempts): void
    {
        $this->rawData['attempts'] = $attempts;
    }

    /**
     * Get job description
     *
     * @return string Job description
     */
    public function getDescription(): string
    {
        return $this->payload['description'] ?? $this->getName();
    }

    /**
     * Get queue driver
     *
     * @return QueueDriverInterface|null Queue driver instance
     */
    public function getDriver(): ?QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * Set queue driver
     *
     * @param QueueDriverInterface $driver Queue driver instance
     * @return void
     */
    public function setDriver(QueueDriverInterface $driver): void
    {
        $this->driver = $driver;
    }
}
