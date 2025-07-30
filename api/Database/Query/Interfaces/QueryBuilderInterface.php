<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * QueryBuilder Interface
 *
 * Defines the contract for the main QueryBuilder class.
 * This interface ensures consistent query building functionality
 * across different implementations.
 *
 */
interface QueryBuilderInterface
{
    /**
     * Set the primary table for the query
     *
     * Specifies the primary table that will be used for the SELECT, UPDATE, or DELETE operation.
     * This method validates the table name and sets up the query state for subsequent operations.
     *
     * @param string $table The name of the table to query
     * @return static Returns the QueryBuilder instance for method chaining
     * @throws \InvalidArgumentException If table name is invalid or contains unsafe characters
     */
    public function from(string $table);

    /**
     * Set columns to select
     */
    public function select(array $columns = ['*']);

    /**
     * Add WHERE condition
     */
    public function where($column, $operator = null, $value = null);

    /**
     * Add OR WHERE condition
     */
    public function orWhere($column, $operator = null, $value = null);

    /**
     * Add WHERE IN condition
     */
    public function whereIn(string $column, array $values);

    /**
     * Add WHERE NOT IN condition
     */
    public function whereNotIn(string $column, array $values);

    /**
     * Add WHERE NULL condition
     */
    public function whereNull(string $column);

    /**
     * Add WHERE NOT NULL condition
     */
    public function whereNotNull(string $column);

    /**
     * Add OR WHERE NULL condition
     */
    public function orWhereNull(string $column);

    /**
     * Add OR WHERE NOT NULL condition
     */
    public function orWhereNotNull(string $column);

    /**
     * Add WHERE BETWEEN condition
     */
    public function whereBetween(string $column, $min, $max);

    /**
     * Add WHERE LIKE condition
     */
    public function whereLike(string $column, string $pattern);

    /**
     * Add raw WHERE condition
     */
    public function whereRaw(string $condition, array $bindings = []);

    /**
     * Add JSON contains WHERE condition (database-agnostic)
     */
    public function whereJsonContains(string $column, string $searchValue, ?string $path = null);

    /**
     * Add JOIN clause
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER');

    /**
     * Add LEFT JOIN clause
     */
    public function leftJoin(string $table, string $first, string $operator, string $second);

    /**
     * Add RIGHT JOIN clause
     */
    public function rightJoin(string $table, string $first, string $operator, string $second);

    /**
     * Add GROUP BY clause
     */
    public function groupBy($columns);

    /**
     * Add HAVING clause
     */
    public function having(array $conditions);

    /**
     * Add ORDER BY clause
     */
    public function orderBy($column, string $direction = 'ASC');

    /**
     * Add LIMIT clause
     */
    public function limit(int $count);

    /**
     * Add OFFSET clause
     */
    public function offset(int $count);

    /**
     * Execute query and return all results
     */
    public function get(): array;

    /**
     * Execute query and return first result
     */
    public function first(): ?array;

    /**
     * Execute count query
     */
    public function count(): int;

    /**
     * Execute paginated query
     */
    public function paginate(int $page = 1, int $perPage = 10): array;

    /**
     * Insert data
     */
    public function insert(array $data): int;

    /**
     * Insert multiple rows
     */
    public function insertBatch(array $rows): int;

    /**
     * Update data
     */
    public function update(array $data): int;

    /**
     * Delete records
     */
    public function delete(): int;

    /**
     * Execute in transaction
     */
    public function transaction(callable $callback);

    /**
     * Enable query caching
     */
    public function cache(?int $ttl = null);

    /**
     * Enable query optimization
     */
    public function optimize();

    /**
     * Set business purpose for the query
     */
    public function withPurpose(string $purpose);

    /**
     * Get SQL string
     */
    public function toSql(): string;

    /**
     * Get parameter bindings
     */
    public function getBindings(): array;

    /**
     * Create raw expression
     */
    public function raw(string $expression): \Glueful\Database\RawExpression;

    /**
     * Execute a raw SQL query and return results
     */
    public function executeRaw(string $sql, array $bindings = []): array;

    /**
     * Execute a raw SQL query and return first result
     */
    public function executeRawFirst(string $sql, array $bindings = []): ?array;
}
