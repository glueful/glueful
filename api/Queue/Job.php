<?php

namespace Glueful\Queue;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Helpers\Utils;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\DatabaseException;

/**
 * Base Job Class
 *
 * Abstract base class for all queue jobs. Provides common functionality
 * for job execution, serialization, and error handling.
 *
 * Features:
 * - Automatic UUID generation
 * - Payload management
 * - Serialization support
 * - Default error handling
 * - Configurable timeouts and retry limits
 *
 * Usage:
 * ```php
 * class MyJob extends Job
 * {
 *     public function handle(): void
 *     {
 *         // Your job logic here
 *     }
 * }
 * ```
 *
 * @package Glueful\Queue
 */
abstract class Job implements JobInterface
{
    /** @var string Job UUID */
    protected string $uuid;

    /** @var array Job payload data */
    protected array $payload;

    /** @var int Number of attempts */
    protected int $attempts = 0;

    /** @var string|null Queue name */
    protected ?string $queue = null;

    /** @var QueueDriverInterface|null Queue driver instance */
    protected ?QueueDriverInterface $driver = null;

    /** @var bool Whether job has been deleted */
    protected bool $deleted = false;

    /** @var bool Whether job has been released */
    protected bool $released = false;

    /**
     * Create new job instance
     *
     * @param array $data Job data
     */
    public function __construct(array $data = [])
    {
        $this->payload = ['data' => $data];
        $this->uuid = Utils::generateNanoID();
    }

    /**
     * Execute the job
     *
     * This method must be implemented by concrete job classes
     *
     * @return void
     * @throws \Exception On job failure
     */
    abstract public function handle(): void;

    /**
     * Handle job failure
     *
     * Called when the job fails after all retry attempts.
     * Can be overridden by concrete job classes.
     *
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(\Exception $exception): void
    {
        // Default implementation - can be overridden
        error_log("Job {$this->uuid} failed: " . $exception->getMessage());
    }

    /**
     * Get job UUID
     *
     * @return string Job UUID
     */
    public function getUuid(): string
    {
        return $this->uuid;
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
     * Set queue name
     *
     * @param string|null $queue Queue name
     * @return void
     */
    public function setQueue(?string $queue): void
    {
        $this->queue = $queue;
    }

    /**
     * Get number of attempts
     *
     * @return int Attempt count
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Set number of attempts
     *
     * @param int $attempts Attempt count
     * @return void
     */
    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
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
     * Get raw job data
     *
     * @return array Raw data
     */
    public function getRawData(): array
    {
        return $this->payload;
    }

    /**
     * Get job data
     *
     * @return array Job data
     */
    public function getData(): array
    {
        return $this->payload['data'] ?? [];
    }

    /**
     * Set job data
     *
     * @param array $data Job data
     * @return void
     */
    public function setData(array $data): void
    {
        $this->payload['data'] = $data;
    }

    /**
     * Execute the job (fire method for compatibility)
     *
     * @return void
     * @throws \Exception On job failure
     */
    public function fire(): void
    {
        $this->handle();

        // Delete job after successful execution
        if (!$this->deleted && !$this->released) {
            $this->delete();
        }
    }

    /**
     * Release job back to queue
     *
     * @param int $delay Delay in seconds
     * @return void
     */
    public function release(int $delay = 0): void
    {
        if ($this->driver) {
            $this->driver->release($this, $delay);
        }
        $this->released = true;
    }

    /**
     * Delete job from queue
     *
     * @return void
     */
    public function delete(): void
    {
        if ($this->driver) {
            $this->driver->delete($this);
        }
        $this->deleted = true;
    }

    /**
     * Get maximum attempts allowed
     *
     * Can be overridden by concrete job classes
     *
     * @return int Max attempts
     */
    public function getMaxAttempts(): int
    {
        return 3;
    }

    /**
     * Get job timeout in seconds
     *
     * Can be overridden by concrete job classes
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        return 60;
    }

    /**
     * Get batch UUID if part of batch
     *
     * @return string|null Batch UUID
     */
    public function getBatchUuid(): ?string
    {
        return $this->payload['batchUuid'] ?? null;
    }

    /**
     * Set batch UUID
     *
     * @param string|null $batchUuid Batch UUID
     * @return void
     */
    public function setBatchUuid(?string $batchUuid): void
    {
        $this->payload['batchUuid'] = $batchUuid;
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
        return $this->payload['priority'] ?? 0;
    }

    /**
     * Set job priority
     *
     * @param int $priority Priority level
     * @return void
     */
    public function setPriority(int $priority): void
    {
        $this->payload['priority'] = $priority;
    }

    /**
     * Get job name for display
     *
     * @return string Display name
     */
    public function getName(): string
    {
        return $this->payload['displayName'] ?? static::class;
    }

    /**
     * Set job display name
     *
     * @param string $name Display name
     * @return void
     */
    public function setName(string $name): void
    {
        $this->payload['displayName'] = $name;
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
        return $this->payload['pushedAt'] ?? time();
    }

    /**
     * Get when job was pushed
     *
     * @return int Unix timestamp
     */
    public function getPushedAt(): int
    {
        return $this->getCreatedAt();
    }

    /**
     * Set queue driver
     *
     * @param QueueDriverInterface $driver Queue driver
     * @return void
     */
    public function setDriver(QueueDriverInterface $driver): void
    {
        $this->driver = $driver;
    }

    /**
     * Get queue driver
     *
     * @return QueueDriverInterface|null Queue driver
     */
    public function getDriver(): ?QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * Serialize job to string
     *
     * @return string Serialized job data
     */
    public function serialize(): string
    {
        return serialize([
            'class' => static::class,
            'uuid' => $this->uuid,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'queue' => $this->queue
        ]);
    }

    /**
     * Unserialize job from string
     *
     * @param string $data Serialized job data
     * @return self Job instance
     * @throws \Exception If unserialization fails
     */
    public static function unserialize(string $data): self
    {
        $props = unserialize($data);

        if (!is_array($props) || !isset($props['class'])) {
            throw BusinessLogicException::operationNotAllowed(
                'job_deserialization',
                'Invalid serialized job data'
            );
        }

        if (!class_exists($props['class'])) {
            throw BusinessLogicException::operationNotAllowed(
                'job_instantiation',
                "Job class '{$props['class']}' not found"
            );
        }

        $instance = new $props['class']($props['payload']['data'] ?? []);
        $instance->uuid = $props['uuid'];
        $instance->payload = $props['payload'];
        $instance->attempts = $props['attempts'];
        $instance->queue = $props['queue'];

        return $instance;
    }

    /**
     * Create job from array data
     *
     * @param array $data Job data
     * @return self Job instance
     * @throws \Exception If job creation fails
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['job']) || !class_exists($data['job'])) {
            throw BusinessLogicException::operationNotAllowed(
                'job_instantiation',
                "Job class '{$data['job']}' not found"
            );
        }

        $instance = new $data['job']($data['data'] ?? []);

        if (isset($data['uuid'])) {
            $instance->uuid = $data['uuid'];
        }

        if (isset($data['attempts'])) {
            $instance->attempts = $data['attempts'];
        }

        if (isset($data['queue'])) {
            $instance->queue = $data['queue'];
        }

        // Merge additional payload data
        if (isset($data['priority'])) {
            $instance->setPriority($data['priority']);
        }

        if (isset($data['batchUuid'])) {
            $instance->setBatchUuid($data['batchUuid']);
        }

        return $instance;
    }

    /**
     * Convert job to array
     *
     * @return array Job data as array
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'job' => static::class,
            'data' => $this->getData(),
            'attempts' => $this->attempts,
            'queue' => $this->queue,
            'priority' => $this->getPriority(),
            'batchUuid' => $this->getBatchUuid(),
            'maxAttempts' => $this->getMaxAttempts(),
            'timeout' => $this->getTimeout(),
            'displayName' => $this->getName()
        ];
    }

    /**
     * Convert job to JSON string
     *
     * @return string JSON representation
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Get job description for logging
     *
     * @return string Job description
     */
    public function getDescription(): string
    {
        return sprintf(
            '%s (UUID: %s, Attempts: %d/%d)',
            $this->getName(),
            $this->getUuid(),
            $this->getAttempts(),
            $this->getMaxAttempts()
        );
    }
}
