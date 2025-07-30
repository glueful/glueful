<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Query\Interfaces\InsertBuilderInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;

/**
 * InsertBuilder
 *
 * Handles INSERT query construction and execution.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class InsertBuilder implements InsertBuilderInterface
{
    protected DatabaseDriver $driver;
    protected QueryExecutorInterface $executor;

    public function __construct(DatabaseDriver $driver, QueryExecutorInterface $executor)
    {
        $this->driver = $driver;
        $this->executor = $executor;
    }

    /**
     * Insert single record
     */
    public function insert(string $table, array $data): int
    {
        $this->validateData($data);

        $sql = $this->buildInsertQuery($table, $data);
        $bindings = array_values($data);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Insert multiple records in batch
     */
    public function insertBatch(string $table, array $rows): int
    {
        $this->validateBatchData($rows);

        $sql = $this->buildBatchInsertQuery($table, $rows);
        $bindings = $this->flattenBatchData($rows);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Insert or update on duplicate key
     */
    public function upsert(string $table, array $data, array $updateColumns): int
    {
        $this->validateData($data);

        $sql = $this->buildUpsertQuery($table, $data, $updateColumns);
        $bindings = array_values($data);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Build INSERT SQL query
     */
    public function buildInsertQuery(string $table, array $data): string
    {
        $keys = array_keys($data);
        $columns = implode(', ', array_map([$this->driver, 'wrapIdentifier'], $keys));
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        return "INSERT INTO {$this->driver->wrapIdentifier($table)} ({$columns}) VALUES ({$placeholders})";
    }

    /**
     * Build batch INSERT SQL query
     */
    public function buildBatchInsertQuery(string $table, array $rows): string
    {
        $firstRow = reset($rows);
        $columns = array_keys($firstRow);
        $columnCount = count($columns);

        // Build column list
        $columnList = implode(', ', array_map([$this->driver, 'wrapIdentifier'], $columns));

        // Build placeholders for all rows
        $rowPlaceholder = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        return "INSERT INTO {$this->driver->wrapIdentifier($table)} ({$columnList}) VALUES {$allPlaceholders}";
    }

    /**
     * Build UPSERT SQL query
     */
    public function buildUpsertQuery(string $table, array $data, array $updateColumns): string
    {
        // Use driver-specific upsert implementation
        $keys = array_keys($data);
        return $this->driver->upsert($table, $keys, $updateColumns);
    }

    /**
     * Validate insert data
     */
    public function validateData(array $data): void
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot insert empty data array');
        }

        if (!$this->isAssociativeArray($data)) {
            throw new \InvalidArgumentException('Insert data must be an associative array');
        }
    }

    /**
     * Validate batch insert data
     */
    public function validateBatchData(array $rows): void
    {
        if (empty($rows)) {
            throw new \InvalidArgumentException('Cannot perform batch insert with empty rows array');
        }

        $firstRow = reset($rows);
        if (!is_array($firstRow)) {
            throw new \InvalidArgumentException('Each row must be an associative array');
        }

        $columns = array_keys($firstRow);
        $columnCount = count($columns);

        // Validate all rows have the same columns
        foreach ($rows as $index => $row) {
            if (!is_array($row) || count($row) !== $columnCount || array_keys($row) !== $columns) {
                throw new \InvalidArgumentException("Row at index {$index} has inconsistent columns");
            }
        }
    }

    /**
     * Check if array is associative
     */
    protected function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Flatten batch data into single bindings array
     */
    protected function flattenBatchData(array $rows): array
    {
        $values = [];
        $firstRow = reset($rows);
        $columns = array_keys($firstRow);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column];
            }
        }

        return $values;
    }
}
