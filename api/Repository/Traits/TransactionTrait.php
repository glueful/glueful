<?php

declare(strict_types=1);

namespace Glueful\Repository\Traits;

use Glueful\Exceptions\DatabaseException;

/**
 * Transaction management trait for repositories
 *
 * Provides consistent transaction handling patterns across all repositories.
 * Ensures proper transaction boundaries and error handling.
 *
 * @package Glueful\Repository\Traits
 */
trait TransactionTrait
{
    /**
     * Execute a callable within a transaction
     *
     * Automatically handles transaction begin/commit/rollback logic.
     * Ensures all database operations are atomic.
     *
     * @param callable $operation The operation to execute
     * @return mixed The result of the operation
     * @throws DatabaseException If the operation fails
     */
    protected function executeInTransaction(callable $operation)
    {
        $this->db->beginTransaction();

        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Exception $e) {
            $this->db->rollBack();

            // Re-throw as DatabaseException for consistency
            if ($e instanceof DatabaseException) {
                throw $e;
            }

            throw new DatabaseException(
                'Transaction failed: ' . $e->getMessage(),
                (int)$e->getCode()
            );
        }
    }

    /**
     * Execute multiple operations atomically
     *
     * Takes an array of callables and executes them all within a single transaction.
     * If any operation fails, all operations are rolled back.
     *
     * @param array $operations Array of callable operations
     * @return array Results of all operations
     * @throws DatabaseException If any operation fails
     */
    protected function executeMultipleInTransaction(array $operations): array
    {
        return $this->executeInTransaction(function () use ($operations) {
            $results = [];

            foreach ($operations as $key => $operation) {
                if (!is_callable($operation)) {
                    throw new \InvalidArgumentException("Operation at index {$key} is not callable");
                }

                $results[$key] = $operation();
            }

            return $results;
        });
    }

    /**
     * Execute operation with conditional transaction
     *
     * Only starts a new transaction if one is not already active.
     * Useful for methods that can be called independently or as part of a larger transaction.
     *
     * @param callable $operation The operation to execute
     * @param bool $forceNewTransaction Force a new transaction even if one is active
     * @return mixed The result of the operation
     * @throws DatabaseException If the operation fails
     */
    protected function executeWithConditionalTransaction(callable $operation, bool $forceNewTransaction = false)
    {
        // Check if a transaction is already active
        $transactionActive = $this->db->isTransactionActive();

        if ($transactionActive && !$forceNewTransaction) {
            // Already in a transaction, just execute the operation
            return $operation();
        }

        // No active transaction or forced new transaction, wrap in transaction
        return $this->executeInTransaction($operation);
    }

    /**
     * Get current transaction status
     *
     * @return bool True if a transaction is currently active
     */
    protected function isInTransaction(): bool
    {
        return $this->db->isTransactionActive();
    }

    /**
     * Execute bulk operations with transaction and batch processing
     *
     * Processes large datasets in chunks within transactions for optimal performance.
     * Each chunk is processed in its own transaction to avoid long-running transactions.
     *
     * @param array $data Data to process
     * @param callable $operation Operation to perform on each chunk
     * @param int $chunkSize Size of each processing chunk
     * @return array Results from all chunks
     * @throws DatabaseException If any chunk fails
     */
    protected function executeBulkInTransaction(array $data, callable $operation, int $chunkSize = 1000): array
    {
        if (empty($data)) {
            return [];
        }

        $chunks = array_chunk($data, $chunkSize);
        $allResults = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $chunkResult = $this->executeInTransaction(function () use ($operation, $chunk) {
                    return $operation($chunk);
                });

                $allResults[] = $chunkResult;
            } catch (\Exception $e) {
                throw new DatabaseException(
                    "Bulk operation failed at chunk {$chunkIndex}: " . $e->getMessage(),
                    (int)$e->getCode()
                );
            }
        }

        return $allResults;
    }
}
