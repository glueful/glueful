<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * Interface for query modifiers (GROUP BY, HAVING, ORDER BY)
 *
 * Provides methods to add and manage query modifiers that affect
 * result grouping, filtering, and ordering.
 */
interface QueryModifiersInterface
{
    /**
     * Add GROUP BY columns
     *
     * @param string|array $columns Column(s) to group by
     */
    public function groupBy(string|array $columns): void;

    /**
     * Get GROUP BY columns
     *
     * @return array The columns to group by
     */
    public function getGroupBy(): array;

    /**
     * Add HAVING condition
     *
     * @param string $column The column or expression
     * @param mixed $operator The operator or value
     * @param mixed $value The value (optional)
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): void;

    /**
     * Add raw HAVING condition
     *
     * @param string $expression The raw SQL expression
     * @param array $bindings Parameter bindings
     */
    public function havingRaw(string $expression, array $bindings = []): void;

    /**
     * Get HAVING conditions
     *
     * @return array The having conditions
     */
    public function getHaving(): array;

    /**
     * Add ORDER BY clause
     *
     * @param string|array $column Column(s) to order by
     * @param string $direction Sort direction (ASC or DESC)
     */
    public function orderBy(string|array $column, string $direction = 'ASC'): void;

    /**
     * Add raw ORDER BY expression
     *
     * @param string $expression The raw SQL expression
     */
    public function orderByRaw(string $expression): void;

    /**
     * Order by random
     */
    public function orderByRandom(): void;

    /**
     * Get ORDER BY clauses
     *
     * @return array The order by clauses
     */
    public function getOrderBy(): array;

    /**
     * Clear all GROUP BY columns
     */
    public function clearGroupBy(): void;

    /**
     * Clear all HAVING conditions
     */
    public function clearHaving(): void;

    /**
     * Clear all ORDER BY clauses
     */
    public function clearOrderBy(): void;

    /**
     * Build GROUP BY SQL clause
     *
     * @return string The GROUP BY clause or empty string
     */
    public function buildGroupByClause(): string;

    /**
     * Build HAVING SQL clause
     *
     * @return string The HAVING clause or empty string
     */
    public function buildHavingClause(): string;

    /**
     * Build ORDER BY SQL clause
     *
     * @return string The ORDER BY clause or empty string
     */
    public function buildOrderByClause(): string;

    /**
     * Get all bindings for HAVING conditions
     *
     * @return array The parameter bindings
     */
    public function getHavingBindings(): array;
}
