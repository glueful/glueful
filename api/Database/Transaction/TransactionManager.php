<?php

declare(strict_types=1);

namespace Glueful\Database\Transaction;

use PDO;
use Exception;
use Glueful\Database\Transaction\Interfaces\TransactionManagerInterface;
use Glueful\Database\Transaction\Interfaces\SavepointManagerInterface;
use Glueful\Database\QueryLogger;

/**
 * TransactionManager
 *
 * Handles database transaction management with deadlock retry and nested transaction support.
 * Extracted from the monolithic QueryBuilder to follow Single Responsibility Principle.
 */
class TransactionManager implements TransactionManagerInterface
{
    protected PDO $pdo;
    protected SavepointManagerInterface $savepointManager;
    protected QueryLogger $logger;
    protected int $transactionLevel = 0;
    protected int $maxRetries = 3;

    public function __construct(
        PDO $pdo,
        SavepointManagerInterface $savepointManager,
        QueryLogger $logger
    ) {
        $this->pdo = $pdo;
        $this->savepointManager = $savepointManager;
        $this->logger = $logger;
    }

    /**
     * Execute callback within a transaction
     */
    public function transaction(callable $callback)
    {
        $retryCount = 0;

        $this->logger->logEvent("Starting transaction", ['retries_allowed' => $this->maxRetries]);

        while ($retryCount < $this->maxRetries) {
            $this->begin();
            try {
                $result = $callback($this);
                $this->commit();

                // Log successful transaction
                $this->logger->logEvent("Transaction completed successfully", [
                    'retries' => $retryCount,
                    'level' => $this->transactionLevel
                ], 'info');

                return $result;
            } catch (Exception $e) {
                if ($this->isDeadlock($e)) {
                    $this->rollback();
                    $retryCount++;

                    // Log deadlock and retry
                    $this->logger->logEvent("Transaction deadlock detected, retrying", [
                        'retry' => $retryCount,
                        'max_retries' => $this->maxRetries,
                        'error' => $e->getMessage()
                    ], 'warning');

                    // Progressive backoff
                    usleep(500000 * $retryCount);
                } else {
                    $this->rollback();

                    // Log transaction failure
                    $this->logger->logEvent("Transaction failed", [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'level' => $this->transactionLevel
                    ], 'error');

                    throw $e;
                }
            }
        }

        $this->logger->logEvent("Transaction failed after maximum retries", [
            'max_retries' => $this->maxRetries
        ], 'error');

        throw new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.");
    }

    /**
     * Begin a new transaction or create savepoint
     */
    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
            $this->logger->logEvent("Transaction started", ['level' => 1], 'debug');
        } else {
            $this->savepointManager->create($this->transactionLevel);
            $this->logger->logEvent("Savepoint created", ['level' => $this->transactionLevel + 1], 'debug');
        }
        $this->transactionLevel++;
    }

    /**
     * Commit current transaction level
     */
    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->logger->logEvent("Attempted to commit with no active transaction", [], 'warning');
            return;
        }

        if ($this->transactionLevel === 1) {
            $this->pdo->commit();
            $this->logger->logEvent("Transaction committed", ['level' => 1], 'debug');
        } else {
            // For savepoints, we don't need to explicitly release them
            // They are automatically released when the parent transaction commits
            $this->logger->logEvent("Savepoint committed", ['level' => $this->transactionLevel], 'debug');
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    /**
     * Rollback current transaction level
     */
    public function rollback(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->logger->logEvent("Attempted to rollback with no active transaction", [], 'warning');
            return;
        }

        if ($this->transactionLevel === 1) {
            $this->pdo->rollBack();
            $this->logger->logEvent("Transaction rolled back", ['level' => 1], 'debug');
        } else {
            // Rollback to the previous savepoint
            $this->savepointManager->rollbackTo($this->transactionLevel - 1);
            $this->logger->logEvent("Rolled back to savepoint", ['level' => $this->transactionLevel - 1], 'debug');
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    /**
     * Check if a transaction is currently active
     */
    public function isActive(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Get current transaction nesting level
     */
    public function getLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Set maximum retry attempts for deadlocked transactions
     */
    public function setMaxRetries(int $retries): void
    {
        $this->maxRetries = max(0, $retries);
    }

    /**
     * Get current max retry attempts
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Check if exception is a deadlock
     */
    protected function isDeadlock(Exception $e): bool
    {
        // MySQL deadlock error codes: 1213, 1205
        // PostgreSQL deadlock error code: 40001
        $deadlockCodes = ['1213', '1205', '40001'];

        return in_array((string) $e->getCode(), $deadlockCodes, true);
    }
}
