<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Query\Interfaces\QueryStateInterface;

/**
 * QueryState
 *
 * Manages the state of a query builder instance.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class QueryState implements QueryStateInterface
{
    protected ?string $table = null;
    protected array $selectColumns = ['*'];
    protected bool $distinct = false;
    protected array $joins = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $groupBy = [];
    protected array $orderBy = [];

    /**
     * Set the primary table for the query
     */
    public function setTable(string $table): void
    {
        $this->table = $table;
    }

    /**
     * Get the current table name
     */
    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * Get the table name or throw exception if not set
     *
     * @throws \InvalidArgumentException When no table is set
     */
    public function getTableOrFail(): string
    {
        if ($this->table === null) {
            throw new \InvalidArgumentException(
                'No table specified. Use from(\'table_name\') or table(\'table_name\') to set the table.'
            );
        }

        return $this->table;
    }

    /**
     * Set columns to select
     */
    public function setSelectColumns(array $columns): void
    {
        $this->selectColumns = empty($columns) ? ['*'] : $columns;
    }

    /**
     * Get select columns
     */
    public function getSelectColumns(): array
    {
        return $this->selectColumns;
    }

    /**
     * Set distinct flag
     */
    public function setDistinct(bool $distinct = true): void
    {
        $this->distinct = $distinct;
    }

    /**
     * Check if query should be distinct
     */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    /**
     * Add join information
     */
    public function addJoin(array $joinData): void
    {
        $this->joins[] = $joinData;
    }

    /**
     * Get all joins
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    /**
     * Set limit
     */
    public function setLimit(?int $limit): void
    {
        $this->limit = $limit;
    }

    /**
     * Get limit
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Set offset
     */
    public function setOffset(?int $offset): void
    {
        $this->offset = $offset;
    }

    /**
     * Get offset
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Set GROUP BY columns
     */
    public function setGroupBy(array $columns): void
    {
        $this->groupBy = $columns;
    }

    /**
     * Get GROUP BY columns
     */
    public function getGroupBy(): array
    {
        return $this->groupBy;
    }

    /**
     * Set ORDER BY clauses
     */
    public function setOrderBy(array $orderBy): void
    {
        $this->orderBy = $orderBy;
    }

    /**
     * Get ORDER BY clauses
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * Reset all state
     */
    public function reset(): void
    {
        $this->table = null;
        $this->selectColumns = ['*'];
        $this->distinct = false;
        $this->joins = [];
        $this->limit = null;
        $this->offset = null;
        $this->groupBy = [];
        $this->orderBy = [];
    }

    /**
     * Clone the current state
     */
    public function clone(): QueryStateInterface
    {
        $clone = new self();
        $clone->table = $this->table;
        $clone->selectColumns = $this->selectColumns;
        $clone->distinct = $this->distinct;
        $clone->joins = $this->joins;
        $clone->limit = $this->limit;
        $clone->offset = $this->offset;
        $clone->groupBy = $this->groupBy;
        $clone->orderBy = $this->orderBy;

        return $clone;
    }
}
