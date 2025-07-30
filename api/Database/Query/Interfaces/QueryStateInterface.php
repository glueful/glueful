<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * QueryState Interface
 *
 * Defines the contract for managing query builder state.
 * This interface ensures consistent state management across
 * different query builder implementations.
 */
interface QueryStateInterface
{
    /**
     * Set the primary table for the query
     */
    public function setTable(string $table): void;

    /**
     * Get the current table name
     */
    public function getTable(): ?string;

    /**
     * Get the table name or throw exception if not set
     *
     * @throws \InvalidArgumentException When no table is set
     */
    public function getTableOrFail(): string;

    /**
     * Set columns to select
     */
    public function setSelectColumns(array $columns): void;

    /**
     * Get select columns
     */
    public function getSelectColumns(): array;

    /**
     * Set distinct flag
     */
    public function setDistinct(bool $distinct = true): void;

    /**
     * Check if query should be distinct
     */
    public function isDistinct(): bool;

    /**
     * Add join information
     */
    public function addJoin(array $joinData): void;

    /**
     * Get all joins
     */
    public function getJoins(): array;

    /**
     * Set limit
     */
    public function setLimit(?int $limit): void;

    /**
     * Get limit
     */
    public function getLimit(): ?int;

    /**
     * Set offset
     */
    public function setOffset(?int $offset): void;

    /**
     * Get offset
     */
    public function getOffset(): ?int;

    /**
     * Set GROUP BY columns
     */
    public function setGroupBy(array $columns): void;

    /**
     * Get GROUP BY columns
     */
    public function getGroupBy(): array;

    /**
     * Set ORDER BY clauses
     */
    public function setOrderBy(array $orderBy): void;

    /**
     * Get ORDER BY clauses
     */
    public function getOrderBy(): array;

    /**
     * Reset all state
     */
    public function reset(): void;

    /**
     * Clone the current state
     */
    public function clone(): QueryStateInterface;
}
