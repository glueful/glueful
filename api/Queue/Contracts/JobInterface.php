<?php

namespace Glueful\Queue\Contracts;

/**
 * Job Interface
 *
 * Core contract for all queue job implementations.
 * Defines the behavior and lifecycle of jobs in the queue system.
 *
 * Job Lifecycle:
 * 1. Created and pushed to queue
 * 2. Popped by worker for processing
 * 3. Executed via fire() method
 * 4. Completed (delete) or failed (release/fail)
 *
 * Features:
 * - Job identification and tracking
 * - Retry logic and attempt counting
 * - Timeout and failure handling
 * - Queue assignment and payload access
 * - Driver-agnostic interface
 *
 * @package Glueful\Queue\Contracts
 */
interface JobInterface
{
    /**
     * Get job unique identifier
     *
     * @return string Job UUID
     */
    public function getUuid(): string;

    /**
     * Get queue name this job belongs to
     *
     * @return string|null Queue name or null for default
     */
    public function getQueue(): ?string;

    /**
     * Get number of attempts made to process this job
     *
     * @return int Number of attempts
     */
    public function getAttempts(): int;

    /**
     * Get job payload data
     *
     * @return array Job data and parameters
     */
    public function getPayload(): array;

    /**
     * Execute the job
     *
     * This is the main method that contains the job logic.
     * Should be implemented by concrete job classes.
     *
     * @return void
     * @throws \Exception On job execution failure
     */
    public function fire(): void;

    /**
     * Release job back to queue for retry
     *
     * @param int $delay Delay in seconds before retry
     * @return void
     */
    public function release(int $delay = 0): void;

    /**
     * Delete job from queue (mark as completed)
     *
     * @return void
     */
    public function delete(): void;

    /**
     * Handle job failure
     *
     * Called when job execution fails or max attempts exceeded.
     * Can be overridden for custom failure handling.
     *
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(\Exception $exception): void;

    /**
     * Get maximum number of attempts allowed
     *
     * @return int Maximum attempts (default: 3)
     */
    public function getMaxAttempts(): int;

    /**
     * Get job timeout in seconds
     *
     * @return int Timeout in seconds (default: 60)
     */
    public function getTimeout(): int;

    /**
     * Get batch UUID if job is part of a batch
     *
     * @return string|null Batch UUID or null if not batched
     */
    public function getBatchUuid(): ?string;

    /**
     * Check if job should be retried after failure
     *
     * @return bool True if job should be retried
     */
    public function shouldRetry(): bool;

    /**
     * Get job priority
     *
     * @return int Priority level (higher = more priority)
     */
    public function getPriority(): int;

    /**
     * Set number of attempts
     *
     * @param int $attempts Number of attempts
     * @return void
     */
    public function setAttempts(int $attempts): void;

    /**
     * Get job description for logging/display
     *
     * @return string Job description
     */
    public function getDescription(): string;

    /**
     * Get queue driver instance
     *
     * @return QueueDriverInterface|null Queue driver
     */
    public function getDriver(): ?QueueDriverInterface;

    /**
     * Set queue driver instance
     *
     * @param QueueDriverInterface $driver Queue driver
     * @return void
     */
    public function setDriver(QueueDriverInterface $driver): void;
}