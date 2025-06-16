<?php

declare(strict_types=1);

namespace Glueful\Database;

use PDO;

/**
 * PooledConnection
 *
 * Wrapper around PDO connection that tracks usage statistics and lifecycle
 * for connection pooling. Provides transparent proxy to PDO methods while
 * maintaining connection health and usage metrics.
 *
 * Features:
 * - Automatic usage tracking (last used time, use count)
 * - Transaction state awareness
 * - Auto-release to pool when not in transaction
 * - Connection age and idle time tracking
 * - Health status tracking
 *
 * @package Glueful\Database
 */
class PooledConnection
{
    /** @var PDO|null Underlying PDO connection */
    private ?PDO $pdo;

    /** @var ConnectionPool Parent connection pool */
    private ConnectionPool $pool;

    /** @var string Unique connection identifier */
    private string $id;

    /** @var float Timestamp when connection was created */
    private float $createdAt;

    /** @var float Timestamp when connection was last used */
    private float $lastUsedAt;

    /** @var int Number of times this connection has been used */
    private int $useCount = 0;

    /** @var bool Whether connection is currently in a transaction */
    private bool $inTransaction = false;

    /** @var bool Whether connection has been marked unhealthy */
    private bool $isHealthy = true;

    /** @var bool Whether connection has been marked for destruction */
    private bool $markedForDestruction = false;

    /**
     * Create a new pooled connection
     *
     * @param PDO $pdo PDO connection instance
     * @param ConnectionPool $pool Parent pool
     * @param string|null $id Optional connection ID
     */
    public function __construct(PDO $pdo, ConnectionPool $pool, ?string $id = null)
    {
        $this->pdo = $pdo;
        $this->pool = $pool;
        $this->id = $id ?? uniqid('conn_', true);
        $this->createdAt = microtime(true);
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Proxy all PDO methods
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed Method result
     * @throws \Exception If connection is unhealthy or method fails
     */
    public function __call($method, $args)
    {
        // Check if connection is healthy
        if (!$this->isHealthy) {
            throw new \Exception('Connection is marked as unhealthy');
        }

        // Update usage statistics
        $this->lastUsedAt = microtime(true);
        $this->useCount++;

        // Track transaction state
        if ($method === 'beginTransaction') {
            $this->inTransaction = true;
        } elseif (in_array($method, ['commit', 'rollBack'])) {
            $this->inTransaction = false;
        }

        try {
            // Call PDO method
            return call_user_func_array([$this->pdo, $method], $args);
        } catch (\Exception $e) {
            // Mark connection as unhealthy on database errors
            if ($this->isDatabaseError($e)) {
                $this->markUnhealthy();
            }
            throw $e;
        }
    }

    /**
     * Get a property from the underlying PDO object
     *
     * @param string $name Property name
     * @return mixed Property value
     */
    public function __get($name)
    {
        return $this->pdo->$name;
    }

    /**
     * Set a property on the underlying PDO object
     *
     * @param string $name Property name
     * @param mixed $value Property value
     */
    public function __set($name, $value)
    {
        $this->pdo->$name = $value;
    }

    /**
     * Auto-release on destruction if not in transaction
     */
    public function __destruct()
    {
        // Don't auto-release if marked for destruction or in transaction
        if (!$this->markedForDestruction && !$this->inTransaction) {
            try {
                $this->pool->release($this);
            } catch (\Exception $e) {
                // Log but don't throw in destructor
                error_log('Failed to release connection to pool: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get connection ID
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get connection age in seconds
     *
     * @return float
     */
    public function getAge(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /**
     * Get idle time in seconds
     *
     * @return float
     */
    public function getIdleTime(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }

    /**
     * Get use count
     *
     * @return int
     */
    public function getUseCount(): int
    {
        return $this->useCount;
    }

    /**
     * Check if connection is in transaction
     *
     * @return bool
     */
    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Mark connection as idle (called when returned to pool)
     *
     * @return void
     */
    public function markIdle(): void
    {
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Mark connection as unhealthy
     *
     * @return void
     */
    public function markUnhealthy(): void
    {
        $this->isHealthy = false;
    }

    /**
     * Check if connection is healthy
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        return $this->isHealthy;
    }

    /**
     * Destroy the connection
     *
     * @return void
     */
    public function destroy(): void
    {
        $this->markedForDestruction = true;

        // Rollback any active transaction
        if ($this->inTransaction) {
            try {
                $this->pdo->rollBack();
            } catch (\Exception $e) {
                // Ignore rollback errors during destruction
            }
        }

        // Close the connection
        $this->pdo = null;
    }

    /**
     * Get connection statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'id' => $this->id,
            'age' => $this->getAge(),
            'idle_time' => $this->getIdleTime(),
            'use_count' => $this->useCount,
            'in_transaction' => $this->inTransaction,
            'is_healthy' => $this->isHealthy,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt
        ];
    }

    /**
     * Direct PDO access for health checks
     *
     * @return PDO|null
     */
    public function getPDO(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query directly (used for health checks)
     *
     * @param string $query Query to execute
     * @return mixed Query result
     */
    public function query(string $query)
    {
        if ($this->pdo === null) {
            return false;
        }
        return $this->pdo->query($query);
    }

    /**
     * Check if exception is a database error
     *
     * @param \Exception $e Exception to check
     * @return bool
     */
    private function isDatabaseError(\Exception $e): bool
    {
        // Check for common database error indicators
        if ($e instanceof \PDOException) {
            $errorCode = $e->getCode();

            // Common connection error codes
            $connectionErrors = [
                '2002', // Can't connect to server
                '2003', // Can't connect to server
                '2006', // Server has gone away
                '2013', // Lost connection during query
                'HY000', // General error
                '08S01', // Communication link failure
            ];

            return in_array($errorCode, $connectionErrors);
        }

        return false;
    }
}
