<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * WhereClause Interface
 *
 * Defines the contract for WHERE clause building functionality.
 * This interface ensures consistent WHERE clause handling across
 * different implementations.
 */
interface WhereClauseInterface
{
    /**
     * Add a WHERE condition
     *
     * Supports multiple call patterns:
     * - add(['column' => 'value'])         - Array format
     * - add('column', 'value')             - Simple format
     * - add('column', '>', 'value')        - Operator format
     * - add(callable $callback)            - Closure format
     */
    public function add($column, $operator = null, $value = null): void;

    /**
     * Add an OR WHERE condition
     */
    public function addOr($column, $operator = null, $value = null): void;

    /**
     * Add WHERE IN condition
     */
    public function whereIn(string $column, array $values): void;

    /**
     * Add WHERE NOT IN condition
     */
    public function whereNotIn(string $column, array $values): void;

    /**
     * Add WHERE NULL condition
     */
    public function whereNull(string $column): void;

    /**
     * Add WHERE NOT NULL condition
     */
    public function whereNotNull(string $column): void;

    /**
     * Add OR WHERE NULL condition
     */
    public function orWhereNull(string $column): self;

    /**
     * Add OR WHERE NOT NULL condition
     */
    public function orWhereNotNull(string $column): self;

    /**
     * Add WHERE BETWEEN condition
     */
    public function whereBetween(string $column, $min, $max): void;

    /**
     * Add WHERE LIKE condition
     */
    public function whereLike(string $column, string $pattern): void;

    /**
     * Add raw WHERE condition
     */
    public function whereRaw(string $condition, array $bindings = []): void;

    /**
     * Add JSON contains WHERE condition (database-agnostic)
     */
    public function whereJsonContains(string $column, string $searchValue, ?string $path = null): void;

    /**
     * Build database-agnostic JSON condition string for use in raw SQL
     */
    public function buildJsonCondition(string $column, string $searchValue, ?string $path = null): array;

    /**
     * Build database-agnostic aggregation query with JSON conditions
     */
    public function buildAggregationQuery(
        string $table,
        string $selectColumns,
        string $groupByColumn,
        string $orderByColumn,
        string $orderDirection = 'DESC',
        int $limit = 10,
        array $jsonConditions = []
    ): array;

    /**
     * Build the WHERE clause SQL
     */
    public function toSql(): string;

    /**
     * Get all parameter bindings
     */
    public function getBindings(): array;

    /**
     * Check if there are any conditions
     */
    public function hasConditions(): bool;

    /**
     * Reset all conditions
     */
    public function reset(): void;

    /**
     * Add OR WHERE condition
     */
    public function orWhere($column, $operator = null, $value = null): self;

    /**
     * Get conditions as array format for update/delete operations
     */
    public function getConditionsArray(): array;
}
