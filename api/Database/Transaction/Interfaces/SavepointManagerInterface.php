<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction\Interfaces;

/**
 * SavepointManager Interface
 *
 * Defines the contract for managing database savepoints in nested transactions.
 * This interface ensures consistent savepoint handling across different implementations.
 */
interface SavepointManagerInterface
{
    /**
     * Create a savepoint
     */
    public function create(int $level): void;

    /**
     * Release a savepoint
     */
    public function release(int $level): void;

    /**
     * Rollback to a savepoint
     */
    public function rollbackTo(int $level): void;

    /**
     * Generate savepoint name for level
     */
    public function generateName(int $level): string;

    /**
     * Check if savepoints are supported
     */
    public function isSupported(): bool;
}
