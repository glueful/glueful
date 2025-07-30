<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Query\Interfaces\UpdateBuilderInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;

/**
 * UpdateBuilder
 *
 * Handles UPDATE query construction and execution.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class UpdateBuilder implements UpdateBuilderInterface
{
    protected DatabaseDriver $driver;
    protected QueryExecutorInterface $executor;

    public function __construct(DatabaseDriver $driver, QueryExecutorInterface $executor)
    {
        $this->driver = $driver;
        $this->executor = $executor;
    }

    /**
     * Update records
     */
    public function update(string $table, array $data, array $conditions): int
    {
        $this->validateData($data);
        $this->validateConditions($conditions);

        $sql = $this->buildUpdateQuery($table, $data, $conditions);
        $bindings = $this->getBindings($data, $conditions);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Build UPDATE SQL query
     */
    public function buildUpdateQuery(string $table, array $data, array $conditions): string
    {
        $sql = "UPDATE {$this->driver->wrapIdentifier($table)} SET ";
        $sql .= $this->buildSetClause($data);

        if (!empty($conditions)) {
            $sql .= " WHERE " . $this->buildWhereClause($conditions);
        }

        return $sql;
    }

    /**
     * Build SET clause for UPDATE
     */
    public function buildSetClause(array $data): string
    {
        $setClauses = [];

        foreach (array_keys($data) as $column) {
            $setClauses[] = "{$this->driver->wrapIdentifier($column)} = ?";
        }

        return implode(', ', $setClauses);
    }

    /**
     * Build WHERE clause for UPDATE
     */
    public function buildWhereClause(array $conditions): string
    {
        $whereClauses = [];

        foreach (array_keys($conditions) as $column) {
            $whereClauses[] = "{$this->driver->wrapIdentifier($column)} = ?";
        }

        return implode(' AND ', $whereClauses);
    }

    /**
     * Get parameter bindings for UPDATE query
     */
    public function getBindings(array $data, array $conditions): array
    {
        return array_merge(array_values($data), array_values($conditions));
    }

    /**
     * Validate update data
     */
    public function validateData(array $data): void
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot update with empty data array');
        }

        if (!$this->isAssociativeArray($data)) {
            throw new \InvalidArgumentException('Update data must be an associative array');
        }
    }

    /**
     * Validate update conditions
     */
    public function validateConditions(array $conditions): void
    {
        if (empty($conditions)) {
            throw new \InvalidArgumentException('Update conditions cannot be empty. This would update all rows.');
        }

        if (!$this->isAssociativeArray($conditions)) {
            throw new \InvalidArgumentException('Update conditions must be an associative array');
        }
    }

    /**
     * Check if array is associative
     */
    protected function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
