<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Database\Query\Interfaces\QueryBuilderInterface;
use Glueful\Database\Query\Interfaces\QueryStateInterface;
use Glueful\Database\Query\Interfaces\WhereClauseInterface;
use Glueful\Database\Query\Interfaces\SelectBuilderInterface;
use Glueful\Database\Query\Interfaces\InsertBuilderInterface;
use Glueful\Database\Query\Interfaces\UpdateBuilderInterface;
use Glueful\Database\Query\Interfaces\DeleteBuilderInterface;
use Glueful\Database\Query\Interfaces\JoinClauseInterface;
use Glueful\Database\Query\Interfaces\QueryModifiersInterface;
use Glueful\Database\Transaction\Interfaces\TransactionManagerInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;
use Glueful\Database\Execution\Interfaces\ResultProcessorInterface;
use Glueful\Database\Features\Interfaces\PaginationBuilderInterface;
use Glueful\Database\Features\Interfaces\SoftDeleteHandlerInterface;
use Glueful\Database\Features\Interfaces\QueryValidatorInterface;
use Glueful\Database\Features\Interfaces\QueryPurposeInterface;
use Glueful\Database\RawExpression;

/**
 * Modular QueryBuilder - Orchestrator Pattern
 *
 * This is the main QueryBuilder that coordinates all modular components
 * to provide a fluent interface while maintaining enterprise-level features.
 *
 * Replaces the monolithic 2,184-line QueryBuilder with a lightweight
 * orchestrator that delegates to focused components.
 *
 */
class QueryBuilder implements QueryBuilderInterface
{
    private bool $cacheEnabled = false;
    private ?int $cacheTtl = null;
    private bool $optimizeEnabled = false;
    private bool $debugEnabled = false;

    public function __construct(
        private QueryStateInterface $state,
        private WhereClauseInterface $whereClause,
        private SelectBuilderInterface $selectBuilder,
        private InsertBuilderInterface $insertBuilder,
        private UpdateBuilderInterface $updateBuilder,
        private DeleteBuilderInterface $deleteBuilder,
        private JoinClauseInterface $joinClause,
        private QueryModifiersInterface $queryModifiers,
        private TransactionManagerInterface $transactionManager,
        private QueryExecutorInterface $queryExecutor,
        private ResultProcessorInterface $resultProcessor,
        private PaginationBuilderInterface $paginationBuilder,
        private SoftDeleteHandlerInterface $softDeleteHandler,
        private QueryValidatorInterface $queryValidator,
        private QueryPurposeInterface $queryPurpose
    ) {
    }

    /**
     * Set the primary table for the query
     *
     * @param string $table The name of the table to query
     * @return $this Returns this QueryBuilder instance for method chaining
     */
    public function from(string $table): static
    {
        $this->queryValidator->validateTableName($table);
        $this->state->setTable($table);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function select(array $columns = ['*']): static
    {
        $this->queryValidator->validateColumnNames($columns);
        $this->state->setSelectColumns($columns);
        return $this;
    }

    /**
     * Add raw SELECT expression
     */
    public function selectRaw(string $expression): static
    {
        $columns = $this->state->getSelectColumns();
        $columns[] = new RawExpression($expression);
        $this->state->setSelectColumns($columns);
        return $this;
    }

    /**
     * Make the query SELECT DISTINCT
     *
     * @param bool $distinct Whether to use DISTINCT (default: true)
     * @return $this Returns this QueryBuilder instance for method chaining
     */
    public function distinct(bool $distinct = true): static
    {
        $this->selectBuilder->setDistinct($distinct);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function where($column, $operator = null, $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->whereClause->add($key, '=', $val);
            }
        } else {
            // Pass all parameters to whereClause, including callables
            $this->whereClause->add($column, $operator, $value);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function orWhere($column, $operator = null, $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->whereClause->orWhere($key, '=', $val);
            }
        } else {
            $this->whereClause->orWhere($column, $operator, $value);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereIn(string $column, array $values): static
    {
        $this->whereClause->whereIn($column, $values);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->whereClause->whereNotIn($column, $values);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereNull(string $column): static
    {
        $this->whereClause->whereNull($column);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereNotNull(string $column): static
    {
        $this->whereClause->whereNotNull($column);
        return $this;
    }

    /**
     * Add OR WHERE NULL condition
     */
    public function orWhereNull(string $column): static
    {
        $this->whereClause->orWhereNull($column);
        return $this;
    }

    /**
     * Add OR WHERE NOT NULL condition
     */
    public function orWhereNotNull(string $column): static
    {
        $this->whereClause->orWhereNotNull($column);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereBetween(string $column, $min, $max): static
    {
        $this->whereClause->whereBetween($column, $min, $max);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereLike(string $column, string $pattern): static
    {
        $this->whereClause->whereLike($column, $pattern);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function whereRaw(string $condition, array $bindings = []): static
    {
        $this->whereClause->whereRaw($condition, $bindings);
        return $this;
    }

    /**
     * Add JSON contains WHERE condition (database-agnostic)
     */
    public function whereJsonContains(string $column, string $searchValue, ?string $path = null): static
    {
        $this->whereClause->whereJsonContains($column, $searchValue, $path);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $this->queryValidator->validateTableName($table);
        $this->joinClause->add($table, $first, $operator, $second, $type);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * {@inheritdoc}
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * {@inheritdoc}
     */
    public function groupBy($columns): static
    {
        $columnArray = is_array($columns) ? $columns : [$columns];
        $this->queryValidator->validateColumnNames($columnArray);
        $this->queryModifiers->groupBy($columnArray);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function having(array $conditions): static
    {
        foreach ($conditions as $column => $value) {
            $this->queryModifiers->having($column, '=', $value);
        }
        return $this;
    }

    /**
     * Add raw HAVING condition
     */
    public function havingRaw(string $condition, array $bindings = []): static
    {
        $this->queryModifiers->havingRaw($condition, $bindings);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function orderBy($column, string $direction = 'ASC'): static
    {
        if (is_array($column)) {
            $this->queryModifiers->orderBy($column, $direction);
        } else {
            $this->queryValidator->validateColumnNames([$column]);
            $this->queryModifiers->orderBy($column, $direction);
        }
        return $this;
    }

    /**
     * Add raw ORDER BY expression
     */
    public function orderByRaw(string $expression): static
    {
        $this->queryModifiers->orderByRaw($expression);
        return $this;
    }

    /**
     * Order by random
     */
    public function orderByRandom(): static
    {
        $this->queryModifiers->orderByRandom();
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function limit(int $count): static
    {
        $this->queryValidator->validatePagination($count, null);
        $this->state->setLimit($count);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function offset(int $count): static
    {
        $this->queryValidator->validatePagination($this->state->getLimit(), $count);
        $this->state->setOffset($count);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): array
    {
        $this->queryValidator->validateSelect($this->state);

        $this->applySoftDeleteFilters();

        // Build complete SQL using components
        $sql = $this->buildSelectQuery();
        $bindings = $this->getAllBindings();

        $result = $this->queryExecutor->executeQuery($sql, $bindings);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function first(): ?array
    {
        $originalLimit = $this->state->getLimit();
        $this->state->setLimit(1);

        $results = $this->get();

        // Restore original limit
        $this->state->setLimit($originalLimit);

        return empty($results) ? null : $results[0];
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        $this->applySoftDeleteFilters();

        // Build count query
        $countSql = $this->buildCountQuery();
        $bindings = $this->getWhereBindings();

        $result = $this->queryExecutor->executeQuery($countSql, $bindings);
        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * Check if any results exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get a flat array of column values
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $this->queryValidator->validateColumnNames([$column]);
        if ($key !== null) {
            $this->queryValidator->validateColumnNames([$key]);
        }

        $results = $this->get();
        $plucked = [];

        foreach ($results as $row) {
            if ($key !== null && isset($row[$key])) {
                $plucked[$row[$key]] = $row[$column] ?? null;
            } else {
                $plucked[] = $row[$column] ?? null;
            }
        }

        return $plucked;
    }

    /**
     * {@inheritdoc}
     */
    public function paginate(int $page = 1, int $perPage = 10): array
    {
        $this->queryValidator->validatePagination($perPage, ($page - 1) * $perPage);

        // Build the SQL query and get bindings
        $sql = $this->toSql();
        $bindings = $this->getBindings();

        // Use paginateQuery with the built SQL and bindings
        return $this->paginationBuilder->paginateQuery($sql, $bindings, $page, $perPage);
    }

    /**
     * {@inheritdoc}
     */
    public function insert(array $data): int
    {
        $table = $this->state->getTableOrFail();
        $this->queryValidator->validateInsert($table, $data);

        return $this->insertBuilder->insert($table, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function insertBatch(array $rows): int
    {
        $table = $this->state->getTableOrFail();

        if (empty($rows)) {
            throw new \InvalidArgumentException('No rows provided for batch insert');
        }

        return $this->insertBuilder->insertBatch($table, $rows);
    }

    /**
     * Insert or update on duplicate key
     */
    public function upsert(array $data, array $updateColumns): int
    {
        $table = $this->state->getTableOrFail();
        $this->queryValidator->validateInsert($table, $data);

        return $this->insertBuilder->upsert($table, $data, $updateColumns);
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $data): int
    {
        $table = $this->state->getTableOrFail();
        $conditions = $this->whereClause->getConditionsArray();

        $this->queryValidator->validateUpdate($table, $data, $conditions);

        return $this->updateBuilder->update($table, $data, $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(): int
    {
        $table = $this->state->getTableOrFail();
        $conditions = $this->whereClause->getConditionsArray();

        $this->queryValidator->validateDelete($table, $conditions);

        if ($this->softDeleteHandler->isEnabled()) {
            return $this->softDeleteHandler->softDelete($table, $conditions);
        }

        return $this->deleteBuilder->delete($table, $conditions);
    }

    /**
     * Restore soft-deleted records
     */
    public function restore(): int
    {
        $table = $this->state->getTableOrFail();
        $conditions = $this->whereClause->getConditionsArray();

        return $this->softDeleteHandler->restore($table, $conditions);
    }

    /**
     * Include soft-deleted records
     */
    public function withTrashed(): static
    {
        $this->softDeleteHandler->withTrashed();
        return $this;
    }

    /**
     * Only show soft-deleted records
     */
    public function onlyTrashed(): static
    {
        $this->softDeleteHandler->onlyTrashed();
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback)
    {
        return $this->transactionManager->transaction($callback);
    }

    /**
     * {@inheritdoc}
     */
    public function cache(?int $ttl = null): static
    {
        $this->cacheEnabled = true;
        $this->cacheTtl = $ttl;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(): static
    {
        $this->optimizeEnabled = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function withPurpose(string $purpose): static
    {
        $this->queryPurpose->setPurpose($purpose);
        return $this;
    }

    /**
     * Enable debug mode
     */
    public function enableDebug(bool $debug = true): static
    {
        $this->debugEnabled = $debug;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toSql(): string
    {
        return $this->buildSelectQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function getBindings(): array
    {
        return $this->getAllBindings();
    }

    /**
     * Get query execution plan
     */
    public function explain(): array
    {
        $sql = 'EXPLAIN ' . $this->buildSelectQuery();
        $bindings = $this->getAllBindings();

        return $this->queryExecutor->executeQuery($sql, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function raw(string $expression): RawExpression
    {
        return new RawExpression($expression);
    }

    /**
     * Execute a raw SQL query and return results
     */
    public function executeRaw(string $sql, array $bindings = []): array
    {
        return $this->queryExecutor->executeQuery($sql, $bindings);
    }

    /**
     * Execute a raw SQL query and return first result
     */
    public function executeRawFirst(string $sql, array $bindings = []): ?array
    {
        $results = $this->queryExecutor->executeQuery($sql, $bindings);
        return empty($results) ? null : $results[0];
    }

    /**
     * Execute a raw modification query (INSERT, UPDATE, DELETE, DDL)
     */
    public function executeModification(string $sql, array $bindings = []): int
    {
        return $this->queryExecutor->executeModification($sql, $bindings);
    }

    /**
     * Clone the query builder
     */
    public function clone(): self
    {
        $clone = new self(
            $this->state->clone(),
            clone $this->whereClause,
            $this->selectBuilder,
            $this->insertBuilder,
            $this->updateBuilder,
            $this->deleteBuilder,
            clone $this->joinClause,
            clone $this->queryModifiers,
            $this->transactionManager,
            $this->queryExecutor,
            $this->resultProcessor,
            $this->paginationBuilder,
            $this->softDeleteHandler,
            $this->queryValidator,
            clone $this->queryPurpose
        );

        // Copy private properties using reflection or setter methods
        $cloneReflection = new \ReflectionObject($clone);
        $cacheEnabledProp = $cloneReflection->getProperty('cacheEnabled');
        $cacheTtlProp = $cloneReflection->getProperty('cacheTtl');
        $optimizeEnabledProp = $cloneReflection->getProperty('optimizeEnabled');
        $debugEnabledProp = $cloneReflection->getProperty('debugEnabled');

        $cacheEnabledProp->setAccessible(true);
        $cacheTtlProp->setAccessible(true);
        $optimizeEnabledProp->setAccessible(true);
        $debugEnabledProp->setAccessible(true);

        $cacheEnabledProp->setValue($clone, $this->cacheEnabled);
        $cacheTtlProp->setValue($clone, $this->cacheTtl);
        $optimizeEnabledProp->setValue($clone, $this->optimizeEnabled);
        $debugEnabledProp->setValue($clone, $this->debugEnabled);

        return $clone;
    }

    /**
     * Apply soft delete filters if enabled
     */
    private function applySoftDeleteFilters(): void
    {
        $table = $this->state->getTable();
        if ($this->softDeleteHandler->isEnabled()) {
            $this->softDeleteHandler->applyToWhereClause($this->whereClause, $table);
        }
    }

    /**
     * Build complete SELECT query using all components
     */
    private function buildSelectQuery(): string
    {
        $sql = $this->selectBuilder->buildSelectClause($this->state);
        $sql .= $this->joinClause->toSql();
        $sql .= $this->whereClause->toSql();
        $sql .= $this->queryModifiers->buildGroupByClause();
        $sql .= $this->queryModifiers->buildHavingClause();
        $sql .= $this->queryModifiers->buildOrderByClause();

        if ($limit = $this->state->getLimit()) {
            $sql .= " LIMIT {$limit}";
        }

        if ($offset = $this->state->getOffset()) {
            $sql .= " OFFSET {$offset}";
        }

        return $sql;
    }

    /**
     * Build COUNT query
     */
    private function buildCountQuery(): string
    {
        $table = $this->state->getTableOrFail();
        $sql = "SELECT COUNT(*) as count FROM {$table}";
        $sql .= $this->joinClause->toSql();
        $sql .= $this->whereClause->toSql();

        return $sql;
    }

    /**
     * Get all parameter bindings from components
     */
    private function getAllBindings(): array
    {
        $bindings = [];
        $bindings = array_merge($bindings, $this->whereClause->getBindings());
        $bindings = array_merge($bindings, $this->joinClause->getBindings());
        $bindings = array_merge($bindings, $this->queryModifiers->getHavingBindings());

        return $bindings;
    }

    /**
     * Get WHERE clause bindings only
     */
    private function getWhereBindings(): array
    {
        return $this->whereClause->getBindings();
    }
}
