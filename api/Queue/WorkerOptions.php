<?php

namespace Glueful\Queue;

/**
 * Worker Configuration Options
 *
 * Configuration class for queue worker behavior and limits.
 * Provides sensible defaults for production use while allowing
 * fine-tuning for specific environments.
 *
 * Features:
 * - Memory management and limits
 * - Sleep intervals and timeout handling
 * - Job processing limits
 * - Worker behavior configuration
 *
 * @package Glueful\Queue
 */
class WorkerOptions
{
    /** @var int Seconds to sleep when no jobs available */
    public readonly int $sleep;

    /** @var int Memory limit in MB before worker restarts */
    public readonly int $memory;

    /** @var int Job timeout in seconds */
    public readonly int $timeout;

    /** @var int Maximum jobs to process before restart */
    public readonly int $maxJobs;

    /** @var bool Stop worker when queue is empty */
    public readonly bool $stopWhenEmpty;

    /** @var int Maximum retry attempts for failed jobs */
    public readonly int $maxAttempts;

    /** @var int Worker heartbeat interval in seconds */
    public readonly int $heartbeat;

    /** @var bool Enable detailed worker logging */
    public readonly bool $verbose;

    /** @var int Maximum execution time before force kill (seconds) */
    public readonly int $maxRuntime;

    /** @var string Worker name for identification */
    public readonly string $name;

    /** @var array Queue priorities (higher number = higher priority) */
    public readonly array $queuePriorities;

    /**
     * Create worker options
     *
     * @param int $sleep Seconds to sleep when no jobs (default: 3)
     * @param int $memory Memory limit in MB (default: 128)
     * @param int $timeout Job timeout in seconds (default: 60)
     * @param int $maxJobs Max jobs before restart (default: 1000)
     * @param bool $stopWhenEmpty Stop when queue empty (default: false)
     * @param int $maxAttempts Max retry attempts (default: 3)
     * @param int $heartbeat Heartbeat interval seconds (default: 30)
     * @param bool $verbose Enable verbose logging (default: false)
     * @param int $maxRuntime Max runtime before restart seconds (default: 3600)
     * @param string $name Worker name (default: auto-generated)
     * @param array $queuePriorities Queue priorities (default: [])
     */
    public function __construct(
        int $sleep = 3,
        int $memory = 128,
        int $timeout = 60,
        int $maxJobs = 1000,
        bool $stopWhenEmpty = false,
        int $maxAttempts = 3,
        int $heartbeat = 30,
        bool $verbose = false,
        int $maxRuntime = 3600,
        string $name = '',
        array $queuePriorities = []
    ) {
        $this->sleep = max(1, $sleep);
        $this->memory = max(32, $memory);
        $this->timeout = max(1, $timeout);
        $this->maxJobs = max(1, $maxJobs);
        $this->stopWhenEmpty = $stopWhenEmpty;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->heartbeat = max(5, $heartbeat);
        $this->verbose = $verbose;
        $this->maxRuntime = max(60, $maxRuntime);
        $this->name = $name ?: 'worker-' . uniqid();
        $this->queuePriorities = $queuePriorities;
    }

    /**
     * Create options from array configuration
     *
     * @param array $config Configuration array
     * @return self Worker options instance
     */
    public static function fromArray(array $config): self
    {
        return new self(
            sleep: $config['sleep'] ?? 3,
            memory: $config['memory'] ?? 128,
            timeout: $config['timeout'] ?? 60,
            maxJobs: $config['max_jobs'] ?? 1000,
            stopWhenEmpty: $config['stop_when_empty'] ?? false,
            maxAttempts: $config['max_attempts'] ?? 3,
            heartbeat: $config['heartbeat'] ?? 30,
            verbose: $config['verbose'] ?? false,
            maxRuntime: $config['max_runtime'] ?? 3600,
            name: $config['name'] ?? '',
            queuePriorities: $config['queue_priorities'] ?? []
        );
    }

    /**
     * Convert options to array
     *
     * @return array Configuration array
     */
    public function toArray(): array
    {
        return [
            'sleep' => $this->sleep,
            'memory' => $this->memory,
            'timeout' => $this->timeout,
            'max_jobs' => $this->maxJobs,
            'stop_when_empty' => $this->stopWhenEmpty,
            'max_attempts' => $this->maxAttempts,
            'heartbeat' => $this->heartbeat,
            'verbose' => $this->verbose,
            'max_runtime' => $this->maxRuntime,
            'name' => $this->name,
            'queue_priorities' => $this->queuePriorities
        ];
    }

    /**
     * Create development-friendly options
     *
     * @return self Development worker options
     */
    public static function development(): self
    {
        return new self(
            sleep: 1,
            memory: 64,
            timeout: 30,
            maxJobs: 100,
            stopWhenEmpty: true,
            maxAttempts: 2,
            heartbeat: 10,
            verbose: true,
            maxRuntime: 300,
            name: 'dev-worker'
        );
    }

    /**
     * Create production-optimized options
     *
     * @return self Production worker options
     */
    public static function production(): self
    {
        return new self(
            sleep: 3,
            memory: 256,
            timeout: 120,
            maxJobs: 5000,
            stopWhenEmpty: false,
            maxAttempts: 5,
            heartbeat: 60,
            verbose: false,
            maxRuntime: 7200,
            name: 'prod-worker'
        );
    }

    /**
     * Check if memory limit is exceeded
     *
     * @return bool True if memory limit exceeded
     */
    public function memoryExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $this->memory;
    }

    /**
     * Get formatted memory usage
     *
     * @return string Human-readable memory usage
     */
    public function getMemoryUsage(): string
    {
        $usage = memory_get_usage(true) / 1024 / 1024;
        return sprintf('%.1f MB / %d MB', $usage, $this->memory);
    }

    /**
     * Check if runtime limit is exceeded
     *
     * @param int $startTime Worker start timestamp
     * @return bool True if runtime limit exceeded
     */
    public function runtimeExceeded(int $startTime): bool
    {
        return (time() - $startTime) >= $this->maxRuntime;
    }

    /**
     * Get queue priority for given queue name
     *
     * @param string $queue Queue name
     * @return int Priority (higher = more important)
     */
    public function getQueuePriority(string $queue): int
    {
        return $this->queuePriorities[$queue] ?? 0;
    }

    /**
     * Sort queues by priority (highest first)
     *
     * @param array $queues Queue names
     * @return array Sorted queue names
     */
    public function sortQueuesByPriority(array $queues): array
    {
        usort($queues, function ($a, $b) {
            return $this->getQueuePriority($b) <=> $this->getQueuePriority($a);
        });

        return $queues;
    }

    /**
     * Create options with overrides
     *
     * @param array $overrides Options to override
     * @return self New worker options instance
     */
    public function with(array $overrides): self
    {
        $config = $this->toArray();
        return self::fromArray(array_merge($config, $overrides));
    }

    /**
     * Validate options for consistency
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->sleep < 1) {
            $errors[] = 'Sleep interval must be at least 1 second';
        }

        if ($this->memory < 32) {
            $errors[] = 'Memory limit must be at least 32 MB';
        }

        if ($this->timeout < 1) {
            $errors[] = 'Timeout must be at least 1 second';
        }

        if ($this->maxJobs < 1) {
            $errors[] = 'Max jobs must be at least 1';
        }

        if ($this->maxAttempts < 1) {
            $errors[] = 'Max attempts must be at least 1';
        }

        if ($this->heartbeat < 5) {
            $errors[] = 'Heartbeat interval must be at least 5 seconds';
        }

        if ($this->maxRuntime < 60) {
            $errors[] = 'Max runtime must be at least 60 seconds';
        }

        return $errors;
    }

    /**
     * Check if options are valid
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }
}
