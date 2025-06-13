<?php

namespace Glueful\Queue\Contracts;

/**
 * Queue Driver Interface
 *
 * Core contract for all queue driver implementations with comprehensive features:
 *
 * Core Capabilities:
 * - Job pushing and popping operations
 * - Delayed job scheduling
 * - Job retry and failure handling
 * - Queue statistics and monitoring
 * - Health checking and diagnostics
 *
 * Advanced Features:
 * - Bulk operations for performance
 * - Driver-specific feature detection
 * - Configuration schema validation
 * - Plugin extensibility support
 *
 * Design Principles:
 * - Driver agnostic interface
 * - Consistent error handling
 * - Performance optimized operations
 * - Type safety and validation
 *
 * @package Glueful\Queue\Contracts
 */
interface QueueDriverInterface
{
    /**
     * Get driver metadata and information
     *
     * @return DriverInfo Driver information object
     */
    public function getDriverInfo(): DriverInfo;

    /**
     * Initialize driver with configuration
     *
     * @param array $config Driver configuration options
     * @return void
     * @throws \RuntimeException On initialization failure
     */
    public function initialize(array $config): void;

    /**
     * Perform health check on driver connection
     *
     * @return HealthStatus Health status object
     * @throws \RuntimeException On health check failure
     */
    public function healthCheck(): HealthStatus;

    /**
     * Push job to queue for immediate processing
     *
     * @param string $job Job class name or identifier
     * @param array $data Job payload data
     * @param string|null $queue Target queue name
     * @return string Job UUID for tracking
     * @throws \RuntimeException On push failure
     */
    public function push(string $job, array $data = [], ?string $queue = null): string;

    /**
     * Schedule job for delayed execution
     *
     * @param int $delay Delay in seconds
     * @param string $job Job class name or identifier
     * @param array $data Job payload data
     * @param string|null $queue Target queue name
     * @return string Job UUID for tracking
     * @throws \RuntimeException On scheduling failure
     */
    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string;

    /**
     * Pop next available job from queue
     *
     * @param string|null $queue Queue name to pop from
     * @return JobInterface|null Job instance or null if empty
     * @throws \RuntimeException On pop failure
     */
    public function pop(?string $queue = null): ?JobInterface;

    /**
     * Release job back to queue with optional delay
     *
     * @param JobInterface $job Job to release
     * @param int $delay Delay before retry in seconds
     * @return void
     * @throws \RuntimeException On release failure
     */
    public function release(JobInterface $job, int $delay = 0): void;

    /**
     * Delete job from queue permanently
     *
     * @param JobInterface $job Job to delete
     * @return void
     * @throws \RuntimeException On deletion failure
     */
    public function delete(JobInterface $job): void;

    /**
     * Get number of pending jobs in queue
     *
     * @param string|null $queue Queue name to check
     * @return int Number of pending jobs
     * @throws \RuntimeException On size check failure
     */
    public function size(?string $queue = null): int;

    /**
     * Get list of supported driver features
     *
     * @return array List of feature names
     */
    public function getFeatures(): array;

    /**
     * Get configuration schema for validation
     *
     * @return array Configuration schema definition
     */
    public function getConfigSchema(): array;

    /**
     * Push multiple jobs in bulk operation
     *
     * @param array $jobs Array of job definitions
     * @param string|null $queue Target queue name
     * @return array Array of job UUIDs
     * @throws \RuntimeException On bulk operation failure
     */
    public function bulk(array $jobs, ?string $queue = null): array;

    /**
     * Remove all jobs from queue
     *
     * @param string|null $queue Queue name to purge
     * @return int Number of jobs purged
     * @throws \RuntimeException On purge failure
     */
    public function purge(?string $queue = null): int;

    /**
     * Get queue statistics and metrics
     *
     * @param string|null $queue Queue name to get stats for
     * @return array Statistics data
     * @throws \RuntimeException On stats retrieval failure
     */
    public function getStats(?string $queue = null): array;

    /**
     * Handle failed job
     *
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that caused failure
     * @return void
     * @throws \RuntimeException On failed job handling failure
     */
    public function failed(JobInterface $job, \Exception $exception): void;
}
