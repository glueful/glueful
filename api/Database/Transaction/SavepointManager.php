<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction;

use PDO;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;

/**
 * SavepointManager
 *
 * Handles database savepoint management for nested transactions.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class SavepointManager implements SavepointManagerInterface
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a savepoint
     */
    public function create(int $level): void
    {
        $savepointName = $this->generateName($level);
        $this->pdo->exec("SAVEPOINT {$savepointName}");
    }

    /**
     * Release a savepoint
     */
    public function release(int $level): void
    {
        $savepointName = $this->generateName($level);
        $this->pdo->exec("RELEASE SAVEPOINT {$savepointName}");
    }

    /**
     * Rollback to a savepoint
     */
    public function rollbackTo(int $level): void
    {
        $savepointName = $this->generateName($level);
        $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
    }

    /**
     * Generate savepoint name for level
     */
    public function generateName(int $level): string
    {
        return "trans_{$level}";
    }

    /**
     * Check if savepoints are supported
     */
    public function isSupported(): bool
    {
        // Most databases support savepoints, but this can be extended
        // for database-specific implementations
        return true;
    }
}
