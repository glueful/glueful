<?php

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\QueueDriverInterface;

/**
 * Database Job Implementation
 *
 * Represents a job stored in the database queue.
 * Handles job execution, retry logic, and failure handling.
 *
 * Features:
 * - Job payload management
 * - Attempt tracking
 * - Retry and release logic
 * - Failure handling
 * - Batch support
 *
 * @package Glueful\Queue\Jobs
 */
class DatabaseJob implements JobInterface
{
    /** @var QueueDriverInterface Queue driver instance */
    private QueueDriverInterface $driver;

    /** @var array Raw job data from database */
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
     * Create new database job instance
     *
     * @param QueueDriverInterface $driver Database queue driver
     * @param array $rawData Raw job data from database
     * @param string $queue Queue name
     */
    public function __construct(QueueDriverInterface $driver, array $rawData, string $queue)
    {
        $this->driver = $driver;
        $this->rawData = $rawData;
        $this->queue = $queue;
        $this->payload = json_decode($rawData['payload'], true) ?: [];
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
        return (int) $this->rawData['attempts'];
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
     * Get raw database data
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
        // Mark job as failed in database
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
        return $this->payload['maxAttempts'] ?? 3;
    }

    /**
     * Get job timeout
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        return $this->payload['timeout'] ?? 60;
    }

    /**
     * Get batch UUID if part of batch
     *
     * @return string|null Batch UUID
     */
    public function getBatchUuid(): ?string
    {
        return $this->rawData['batch_uuid'] ?? null;
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
        return strtotime($this->rawData['created_at']);
    }

    /**
     * Get when job was pushed
     *
     * @return int Unix timestamp
     */
    public function getPushedAt(): int
    {
        return $this->payload['pushedAt'] ?? $this->getCreatedAt();
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
