<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction\Interfaces;

/**
 * TransactionManager Interface
 *
 * Defines the contract for database transaction management.
 * This interface ensures consistent transaction handling across
 * different implementations.
 */
interface TransactionManagerInterface
{
    /**
     * Execute callback within a transaction
     */
    public function transaction(callable $callback);

    /**
     * Begin a new transaction or create savepoint
     */
    public function begin(): void;

    /**
     * Commit current transaction level
     */
    public function commit(): void;

    /**
     * Rollback current transaction level
     */
    public function rollback(): void;

    /**
     * Check if a transaction is currently active
     */
    public function isActive(): bool;

    /**
     * Get current transaction nesting level
     */
    public function getLevel(): int;

    /**
     * Set maximum retry attempts for deadlocked transactions
     */
    public function setMaxRetries(int $retries): void;

    /**
     * Get current max retry attempts
     */
    public function getMaxRetries(): int;
}
