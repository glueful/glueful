<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\RawExpression;
use Glueful\Database\Query\Interfaces\SelectBuilderInterface;
use Glueful\Database\Query\Interfaces\QueryStateInterface;

/**
 * SelectBuilder
 *
 * Handles SELECT query construction.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class SelectBuilder implements SelectBuilderInterface
{
    protected DatabaseDriver $driver;
    protected QueryStateInterface $state;
    protected array $bindings = [];

    public function __construct(DatabaseDriver $driver, QueryStateInterface $state)
    {
        $this->driver = $driver;
        $this->state = $state;
    }

    /**
     * Build the complete SELECT query
     */
    public function build(): string
    {
        $table = $this->state->getTableOrFail();
        $columns = $this->buildColumnList();

        $sql = ($this->state->isDistinct() ? 'SELECT DISTINCT ' : 'SELECT ') . $columns;
        $sql .= ' FROM ' . $this->driver->wrapIdentifier($table);

        // Add JOINs
        foreach ($this->state->getJoins() as $join) {
            $sql .= $this->buildJoinClause($join);
        }

        return $sql;
    }

    /**
     * Set the columns to select
     */
    public function setColumns(array $columns): void
    {
        $this->state->setSelectColumns($columns);
    }

    /**
     * Get the current columns
     */
    public function getColumns(): array
    {
        return $this->state->getSelectColumns();
    }

    /**
     * Set distinct flag
     */
    public function setDistinct(bool $distinct): void
    {
        $this->state->setDistinct($distinct);
    }

    /**
     * Check if query is distinct
     */
    public function isDistinct(): bool
    {
        return $this->state->isDistinct();
    }

    /**
     * Build the column list portion of SELECT
     */
    public function buildColumnList(): string
    {
        $columns = $this->state->getSelectColumns();

        return implode(', ', array_map(function ($column) {
            return $this->formatColumn($column);
        }, $columns));
    }

    /**
     * Get parameter bindings for the SELECT clause
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Reset the builder state
     */
    public function reset(): void
    {
        $this->bindings = [];
    }

    /**
     * Build complete SELECT clause with table
     */
    public function buildSelectClause(\Glueful\Database\Query\Interfaces\QueryStateInterface $state): string
    {
        $table = $state->getTableOrFail();
        $columns = $this->buildColumnList();

        $sql = ($state->isDistinct() ? 'SELECT DISTINCT ' : 'SELECT ') . $columns;
        $sql .= ' FROM ' . $this->driver->wrapIdentifier($table);

        return $sql;
    }

    /**
     * Format a single column for the SELECT clause
     */
    protected function formatColumn($column): string
    {
        // Handle raw SQL expressions
        if ($column instanceof RawExpression) {
            return (string) $column;
        }

        // Handle column aliasing (e.g., "users.name AS user_name")
        if (strpos($column, ' AS ') !== false) {
            return $this->formatAliasedColumn($column);
        }

        // Handle table.column format
        if (strpos($column, '.') !== false) {
            return $this->formatTableColumn($column);
        }

        // Handle simple column or wildcard
        return $column === '*' ? '*' : $this->driver->wrapIdentifier($column);
    }

    /**
     * Format aliased column (e.g., "users.name AS user_name")
     */
    protected function formatAliasedColumn(string $column): string
    {
        [$columnName, $alias] = explode(' AS ', $column, 2);

        // Handle table.column AS alias
        if (strpos($columnName, '.') !== false) {
            [$table, $col] = explode('.', $columnName, 2);
            $wrappedTable = $this->driver->wrapIdentifier($table);
            $wrappedCol = $this->driver->wrapIdentifier($col);
            $wrappedAlias = $this->driver->wrapIdentifier($alias);
            return "$wrappedTable.$wrappedCol AS $wrappedAlias";
        }

        // Handle simple column AS alias
        $wrappedColumn = $this->driver->wrapIdentifier($columnName);
        $wrappedAlias = $this->driver->wrapIdentifier($alias);
        return "$wrappedColumn AS $wrappedAlias";
    }

    /**
     * Format table.column format
     */
    protected function formatTableColumn(string $column): string
    {
        [$table, $col] = explode('.', $column, 2);

        // Handle table.* specially - don't wrap the asterisk
        if ($col === '*') {
            return $this->driver->wrapIdentifier($table) . '.*';
        }

        return $this->driver->wrapIdentifier($table) . '.' . $this->driver->wrapIdentifier($col);
    }

    /**
     * Build JOIN clause from join data
     */
    protected function buildJoinClause(array $join): string
    {
        $type = $join['type'] ?? 'INNER';
        $table = $this->driver->wrapIdentifier($join['table']);
        $first = $this->formatJoinColumn($join['first']);
        $operator = $join['operator'] ?? '=';
        $second = $this->formatJoinColumn($join['second']);

        return " {$type} JOIN {$table} ON {$first} {$operator} {$second}";
    }

    /**
     * Format column name in JOIN condition
     */
    protected function formatJoinColumn(string $column): string
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            return $this->driver->wrapIdentifier($table) . '.' . $this->driver->wrapIdentifier($col);
        }

        return $this->driver->wrapIdentifier($column);
    }
}
