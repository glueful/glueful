<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Query Executed Event
 *
 * Dispatched when a database query is executed.
 * Used for query logging, performance monitoring, and debugging.
 *
 * @package Glueful\Events\Database
 */
class QueryExecutedEvent extends Event
{
    /**
     * @param string $sql SQL query
     * @param array $bindings Query bindings
     * @param float $executionTime Execution time in seconds
     * @param string $connectionName Database connection name
     * @param array $metadata Additional metadata
     */
    public function __construct(
        private readonly string $sql,
        private readonly array $bindings = [],
        private readonly float $executionTime = 0.0,
        private readonly string $connectionName = 'default',
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get SQL query
     *
     * @return string SQL query
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get query bindings
     *
     * @return array Bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get execution time
     *
     * @return float Time in seconds
     */
    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    /**
     * Get connection name
     *
     * @return string Connection name
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get full query with bindings interpolated
     *
     * @return string Full query
     */
    public function getFullQuery(): string
    {
        $query = $this->sql;

        foreach ($this->bindings as $binding) {
            $value = is_string($binding) ? "'{$binding}'" : (string)$binding;
            $query = preg_replace('/\?/', $value, $query, 1);
        }

        return $query;
    }

    /**
     * Check if query is slow
     *
     * @param float $threshold Threshold in seconds
     * @return bool True if slow
     */
    public function isSlow(float $threshold = 1.0): bool
    {
        return $this->executionTime > $threshold;
    }

    /**
     * Get query type (SELECT, INSERT, UPDATE, DELETE)
     *
     * @return string Query type
     */
    public function getQueryType(): string
    {
        $sql = trim(strtoupper($this->sql));

        if (str_starts_with($sql, 'SELECT')) {
            return 'SELECT';
        } elseif (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        } elseif (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        } elseif (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        }

        return 'OTHER';
    }

    /**
     * Check if query modifies data
     *
     * @return bool True if modifying
     */
    public function isModifying(): bool
    {
        return in_array($this->getQueryType(), ['INSERT', 'UPDATE', 'DELETE']);
    }
}
