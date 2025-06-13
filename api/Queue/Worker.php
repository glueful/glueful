<?php

namespace Glueful\Queue;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Glueful\Helpers\Utils;

/**
 * Queue Worker
 *
 * Daemon process that continuously processes jobs from queues.
 * Supports graceful shutdown, memory management, and job monitoring.
 *
 * Features:
 * - Daemon processing with graceful shutdown
 * - Memory limit monitoring and auto-restart
 * - Job timeout handling with PCNTL signals
 * - Worker registration and heartbeat
 * - Comprehensive error handling and job retries
 * - Performance monitoring and metrics
 *
 * Usage:
 * ```php
 * $worker = new Worker($queueManager);
 * $worker->daemon('database', 'default', $options);
 * ```
 *
 * @package Glueful\Queue
 */
class Worker
{
    /** @var QueueManager Queue manager instance */
    private QueueManager $manager;

    /** @var WorkerOptions Worker configuration options */
    private WorkerOptions $options;

    /** @var bool Whether worker should quit */
    private bool $shouldQuit = false;

    /** @var string Unique worker identifier */
    private string $workerUuid;

    /** @var WorkerMonitor Worker monitoring instance */
    private WorkerMonitor $monitor;

    /** @var int Jobs processed counter */
    private int $jobsProcessed = 0;

    /** @var int Worker start time */
    private int $startTime;

    /** @var array Worker statistics */
    private array $stats = [
        'jobs_processed' => 0,
        'jobs_failed' => 0,
        'total_runtime' => 0,
        'memory_peak' => 0
    ];

    /**
     * Create new worker instance
     *
     * @param QueueManager $manager Queue manager
     * @param WorkerMonitor|null $monitor Worker monitor (optional)
     */
    public function __construct(QueueManager $manager, ?WorkerMonitor $monitor = null)
    {
        $this->manager = $manager;
        $this->workerUuid = Utils::generateNanoID();
        $this->monitor = $monitor ?? new WorkerMonitor();
        $this->startTime = time();
    }

    /**
     * Start daemon worker
     *
     * @param string $connection Connection name
     * @param string $queue Queue name
     * @param WorkerOptions $options Worker options
     * @return void
     */
    public function daemon(string $connection, string $queue, WorkerOptions $options): void
    {
        $this->options = $options;
        $this->listenForSignals();
        $this->registerWorker($connection, $queue);

        while (!$this->shouldQuit) {
            $job = $this->getNextJob($connection, $queue);

            if ($job) {
                $this->process($connection, $job);
                $this->jobsProcessed++;
                $this->stats['jobs_processed']++;

                // Check if max jobs reached
                if ($this->options->maxJobs > 0 && $this->jobsProcessed >= $this->options->maxJobs) {
                    $this->logInfo("Max jobs ({$this->options->maxJobs}) reached. Stopping worker.");
                    $this->stop();
                    break;
                }
            } else {
                // No jobs available
                if ($this->options->stopWhenEmpty) {
                    $this->logInfo("Queue is empty. Stopping worker as requested.");
                    $this->stop();
                    break;
                }

                $this->sleep($this->options->sleep);
            }

            $this->updateHeartbeat();

            // Check memory limit
            if ($this->memoryExceeded($this->options->memory)) {
                $this->logWarning("Memory limit ({$this->options->memory}MB) exceeded. Stopping worker.");
                $this->stop();
                break;
            }

            // Check max runtime
            if ($this->options->maxRuntime > 0 && (time() - $this->startTime) >= $this->options->maxRuntime) {
                $this->logWarning("Max runtime ({$this->options->maxRuntime}s) reached. Stopping worker.");
                $this->stop();
                break;
            }

            // Handle signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->unregisterWorker();
        $this->logInfo("Worker stopped gracefully.");
    }

    /**
     * Process a single job
     *
     * @param string $connection Connection name
     * @param JobInterface $job Job to process
     * @return void
     */
    protected function process(string $connection, JobInterface $job): void
    {
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);

        try {
            // Check if job exceeded max attempts
            if ($job->getAttempts() >= $job->getMaxAttempts()) {
                $this->markJobAsFailed($job, new \Exception('Max attempts exceeded'));
                return;
            }

            $this->logInfo("Processing job: " . $job->getDescription());
            $this->monitor->recordJobStart($job);

            // Set job timeout
            if (function_exists('pcntl_alarm') && $job->getTimeout() > 0) {
                pcntl_alarm($job->getTimeout());
            }

            // Execute the job
            $job->fire();

            // Clear timeout
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            $processingTime = microtime(true) - $startTime;
            $this->monitor->recordJobSuccess($job, $processingTime);
            $this->logInfo("Job completed successfully in " . round($processingTime, 3) . "s");
        } catch (\Exception $e) {
            // Clear timeout
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            $processingTime = microtime(true) - $startTime;
            $this->handleJobException($connection, $job, $e);
            $this->monitor->recordJobFailure($job, $e, $processingTime);
            $this->stats['jobs_failed']++;
        } finally {
            // Memory management and leak prevention
            $this->performMemoryCleanup($memoryBefore);
        }

        // Update memory peak
        $this->stats['memory_peak'] = max($this->stats['memory_peak'], memory_get_peak_usage(true));
    }

    /**
     * Get next job from queue
     *
     * @param string $connection Connection name
     * @param string $queue Queue name
     * @return JobInterface|null Next job or null if none available
     */
    protected function getNextJob(string $connection, string $queue): ?JobInterface
    {
        try {
            $driver = $this->manager->connection($connection);
            return $driver->pop($queue);
        } catch (\Exception $e) {
            $this->logError("Failed to get next job: " . $e->getMessage());
            $this->sleep($this->options->sleep);
            return null;
        }
    }

    /**
     * Handle job exception
     *
     * @param string $connectionName Connection name (unused but kept for API compatibility)
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that occurred
     * @return void
     */
    protected function handleJobException(string $connectionName, JobInterface $job, \Exception $exception): void
    {
        $this->logError("Job failed: " . $job->getDescription() . " - " . $exception->getMessage());

        try {
            // Increment attempts
            $job->setAttempts($job->getAttempts() + 1);

            if ($job->shouldRetry()) {
                // Release job back to queue with delay
                $delay = $this->calculateRetryDelay($job->getAttempts());
                $job->release($delay);
                $this->logInfo(
                    "Job released for retry in {$delay}s (attempt {$job->getAttempts()}/{$job->getMaxAttempts()})"
                );
            } else {
                // Mark as failed
                $this->markJobAsFailed($job, $exception);
            }
        } catch (\Exception $e) {
            $this->logError("Failed to handle job exception: " . $e->getMessage());
        }
    }

    /**
     * Mark job as failed
     *
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    protected function markJobAsFailed(JobInterface $job, \Exception $exception): void
    {
        try {
            $driver = $job->getDriver() ?? $this->manager->connection();
            $driver->failed($job, $exception);

            // Call job's failed method
            $job->failed($exception);

            $this->logError("Job marked as failed: " . $job->getDescription());
        } catch (\Exception $e) {
            $this->logError("Failed to mark job as failed: " . $e->getMessage());
        }
    }

    /**
     * Calculate retry delay based on attempt number
     *
     * @param int $attempts Number of attempts
     * @return int Delay in seconds
     */
    protected function calculateRetryDelay(int $attempts): int
    {
        // Exponential backoff: 2^attempts seconds, capped at 300 seconds (5 minutes)
        return min(300, pow(2, $attempts));
    }

    /**
     * Sleep for specified duration
     *
     * @param int $seconds Sleep duration
     * @return void
     */
    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * Check if memory limit exceeded
     *
     * @param int $memoryLimit Memory limit in MB
     * @return bool True if exceeded
     */
    protected function memoryExceeded(int $memoryLimit): bool
    {
        $currentUsage = memory_get_usage(true) / 1024 / 1024; // Convert to MB
        return $currentUsage >= $memoryLimit;
    }

    /**
     * Stop the worker
     *
     * @return void
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
        $this->logInfo("Worker stop requested.");
    }

    /**
     * Register worker with monitoring system
     *
     * @param string $connection Connection name
     * @param string $queue Queue name
     * @return void
     */
    protected function registerWorker(string $connection, string $queue): void
    {
        try {
            $this->monitor->registerWorker($this->workerUuid, [
                'connection' => $connection,
                'queue' => $queue,
                'options' => $this->options->toArray(),
                'started_at' => time(),
                'pid' => getmypid(),
                'hostname' => gethostname() ?: 'unknown'
            ]);

            $this->logInfo("Worker registered: {$this->workerUuid}");
        } catch (\Exception $e) {
            $this->logError("Failed to register worker: " . $e->getMessage());
        }
    }

    /**
     * Update worker heartbeat
     *
     * @return void
     */
    protected function updateHeartbeat(): void
    {
        try {
            $this->monitor->updateWorkerHeartbeat($this->workerUuid, [
                'last_seen' => time(),
                'jobs_processed' => $this->stats['jobs_processed'],
                'jobs_failed' => $this->stats['jobs_failed'],
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => $this->stats['memory_peak']
            ]);
        } catch (\Exception $e) {
            // Don't log heartbeat failures unless verbose mode
            if ($this->options->verbose) {
                $this->logError("Failed to update heartbeat: " . $e->getMessage());
            }
        }
    }

    /**
     * Unregister worker from monitoring system
     *
     * @return void
     */
    protected function unregisterWorker(): void
    {
        try {
            $this->stats['total_runtime'] = time() - $this->startTime;
            $this->monitor->unregisterWorker($this->workerUuid, $this->stats);
            $this->logInfo("Worker unregistered: {$this->workerUuid}");
        } catch (\Exception $e) {
            $this->logError("Failed to unregister worker: " . $e->getMessage());
        }
    }

    /**
     * Set up signal handlers for graceful shutdown
     *
     * @return void
     */
    protected function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return; // PCNTL not available
        }

        // Handle SIGTERM and SIGINT for graceful shutdown
        pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        pcntl_signal(SIGINT, [$this, 'handleSignal']);

        // Handle SIGALRM for job timeouts
        pcntl_signal(SIGALRM, [$this, 'handleTimeout']);

        // Enable signal handling
        pcntl_async_signals(true);
    }

    /**
     * Handle shutdown signals
     *
     * @param int $signal Signal number
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        $signalName = match ($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            default => "Signal {$signal}"
        };

        $this->logInfo("Received {$signalName}. Shutting down gracefully...");
        $this->stop();
    }

    /**
     * Handle job timeout signal
     *
     * @param int $signal Signal number
     * @return void
     */
    public function handleTimeout(int $signal): void
    {
        throw new \Exception('Job exceeded timeout limit');
    }

    /**
     * Get worker UUID
     *
     * @return string Worker UUID
     */
    public function getWorkerUuid(): string
    {
        return $this->workerUuid;
    }

    /**
     * Get worker statistics
     *
     * @return array Worker stats
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'total_runtime' => time() - $this->startTime,
            'current_memory' => memory_get_usage(true),
            'worker_uuid' => $this->workerUuid
        ]);
    }

    /**
     * Check if worker should quit
     *
     * @return bool True if should quit
     */
    public function shouldQuit(): bool
    {
        return $this->shouldQuit;
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @return void
     */
    protected function logInfo(string $message): void
    {
        if ($this->options->verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] INFO: {$message}\n";
        }
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @return void
     */
    protected function logWarning(string $message): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] WARNING: {$message}\n";
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @return void
     */
    protected function logError(string $message): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: {$message}\n";
    }

    /**
     * Perform memory cleanup to prevent leaks
     *
     * @param int $memoryBefore Memory usage before job processing
     * @return void
     */
    protected function performMemoryCleanup(int $memoryBefore): void
    {
        $memoryAfter = memory_get_usage(true);
        $memoryDelta = $memoryAfter - $memoryBefore;
        $memoryThreshold = $this->options->memory * 1024 * 1024; // Convert MB to bytes

        // Log high memory usage
        if ($memoryDelta > 5 * 1024 * 1024) { // More than 5MB increase
            $this->logWarning("High memory usage detected: " . $this->formatBytes($memoryDelta) . " increase");
        }

        // Force garbage collection if memory usage is high
        if ($memoryAfter > $memoryThreshold * 0.8 || $this->jobsProcessed % 100 === 0) {
            if (gc_enabled()) {
                $collected = gc_collect_cycles();
                if ($this->options->verbose && $collected > 0) {
                    $this->logInfo("Garbage collector freed {$collected} cycles");
                }
            }
        }

        // Clear opcache if available and memory is high
        if ($memoryAfter > $memoryThreshold * 0.9 && function_exists('opcache_reset')) {
            opcache_reset();
            $this->logInfo("OPcache reset due to high memory usage");
        }
    }

    /**
     * Format bytes into human readable format
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Enhanced daemon with batch processing support
     *
     * @param string $connection Connection name
     * @param string $queue Queue name
     * @param WorkerOptions $options Worker options
     * @return void
     */
    public function daemonWithBatchProcessing(string $connection, string $queue, WorkerOptions $options): void
    {
        $this->options = $options;
        $this->listenForSignals();
        $this->registerWorker($connection, $queue);

        $batchSize = $options->batchSize ?? 10;
        $jobBatch = [];

        while (!$this->shouldQuit) {
            // Handle signals and check for shutdown in one place
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Collect jobs for batch processing
            $jobsCollected = 0;
            while ($jobsCollected < $batchSize && $this->shouldQuit === false) {
                $job = $this->getNextJob($connection, $queue);
                if ($job) {
                    $jobBatch[] = $job;
                    $jobsCollected++;
                } else {
                    break; // No more jobs available
                }
            }

            // Process batch if we have jobs
            if (!empty($jobBatch)) {
                $this->processBatch($connection, $jobBatch);
                $this->jobsProcessed += count($jobBatch);
                $this->stats['jobs_processed'] += count($jobBatch);
                $jobBatch = []; // Clear batch

                // Check limits after batch processing
                if ($this->options->maxJobs > 0 && $this->jobsProcessed >= $this->options->maxJobs) {
                    $this->logInfo("Max jobs ({$this->options->maxJobs}) reached. Stopping worker.");
                    $this->stop();
                    break;
                }
            } else {
                // No jobs available
                if ($this->options->stopWhenEmpty) {
                    $this->logInfo("Queue is empty. Stopping worker as requested.");
                    $this->stop();
                    break;
                }

                $this->sleep($this->options->sleep);
            }

            $this->updateHeartbeat();

            // Check memory and runtime limits
            if (
                $this->memoryExceeded($this->options->memory) ||
                ($this->options->maxRuntime > 0 && (time() - $this->startTime) >= $this->options->maxRuntime)
            ) {
                $this->stop();
                break;
            }

            // Handle signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->unregisterWorker();
        $this->logInfo("Worker stopped gracefully.");
    }

    /**
     * Process a batch of jobs for better throughput
     *
     * @param string $connection Connection name
     * @param array $jobs Array of jobs to process
     * @return void
     */
    protected function processBatch(string $connection, array $jobs): void
    {
        $startTime = microtime(true);
        $batchSize = count($jobs);

        $this->logInfo("Processing batch of {$batchSize} jobs");

        foreach ($jobs as $job) {
            try {
                $this->process($connection, $job);
            } catch (\Exception $e) {
                $this->logError("Batch processing error: " . $e->getMessage());
                $this->stats['jobs_failed']++;
            }
        }

        $batchTime = microtime(true) - $startTime;
        $avgTime = $batchTime / $batchSize;

        $this->logInfo("Batch completed in " . round($batchTime, 3) . "s (avg: " . round($avgTime, 3) . "s per job)");
    }
}
