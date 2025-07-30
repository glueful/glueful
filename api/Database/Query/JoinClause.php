<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Query\Interfaces\JoinClauseInterface;

/**
 * JoinClause
 *
 * Handles JOIN clause construction and management.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class JoinClause implements JoinClauseInterface
{
    protected array $joins = [];
    protected DatabaseDriver $driver;

    public function __construct(DatabaseDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Add INNER JOIN
     */
    public function inner(string $table, string $first, string $operator, string $second): void
    {
        $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    /**
     * Add LEFT JOIN
     */
    public function left(string $table, string $first, string $operator, string $second): void
    {
        $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    /**
     * Add RIGHT JOIN
     */
    public function right(string $table, string $first, string $operator, string $second): void
    {
        $this->addJoin('RIGHT', $table, $first, $operator, $second);
    }

    /**
     * Add FULL OUTER JOIN
     */
    public function fullOuter(string $table, string $first, string $operator, string $second): void
    {
        $this->addJoin('FULL OUTER', $table, $first, $operator, $second);
    }

    /**
     * Add custom JOIN with specified type
     */
    public function custom(string $type, string $table, string $first, string $operator, string $second): void
    {
        $this->addJoin(strtoupper($type), $table, $first, $operator, $second);
    }

    /**
     * Build all JOIN clauses as SQL
     */
    public function toSql(): string
    {
        if (empty($this->joins)) {
            return '';
        }

        return ' ' . implode(' ', array_map([$this, 'buildJoinClause'], $this->joins));
    }

    /**
     * Get all join data
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    /**
     * Check if there are any joins
     */
    public function hasJoins(): bool
    {
        return !empty($this->joins);
    }

    /**
     * Reset all joins
     */
    public function reset(): void
    {
        $this->joins = [];
    }

    /**
     * Add a join clause
     */
    public function add(string $table, string $first, string $operator, string $second, string $type = 'INNER'): void
    {
        $this->addJoin(strtoupper($type), $table, $first, $operator, $second);
    }

    /**
     * Get parameter bindings for joins
     */
    public function getBindings(): array
    {
        // JOIN clauses typically don't have parameter bindings
        // as they use column references, but this method is required by the interface
        return [];
    }

    /**
     * Add a join to the collection
     */
    protected function addJoin(string $type, string $table, string $first, string $operator, string $second): void
    {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
    }

    /**
     * Build a single JOIN clause
     */
    protected function buildJoinClause(array $join): string
    {
        $type = $join['type'];
        $table = $this->driver->wrapIdentifier($join['table']);
        $first = $this->formatJoinColumn($join['first']);
        $operator = $join['operator'];
        $second = $this->formatJoinColumn($join['second']);

        return "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
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
