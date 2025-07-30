<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * JoinClause Interface
 *
 * Defines the contract for JOIN clause building functionality.
 * This interface ensures consistent JOIN clause handling across
 * different implementations.
 */
interface JoinClauseInterface
{
    /**
     * Add INNER JOIN
     */
    public function inner(string $table, string $first, string $operator, string $second): void;

    /**
     * Add LEFT JOIN
     */
    public function left(string $table, string $first, string $operator, string $second): void;

    /**
     * Add RIGHT JOIN
     */
    public function right(string $table, string $first, string $operator, string $second): void;

    /**
     * Add FULL OUTER JOIN
     */
    public function fullOuter(string $table, string $first, string $operator, string $second): void;

    /**
     * Add custom JOIN with specified type
     */
    public function custom(string $type, string $table, string $first, string $operator, string $second): void;

    /**
     * Build all JOIN clauses as SQL
     */
    public function toSql(): string;

    /**
     * Get all join data
     */
    public function getJoins(): array;

    /**
     * Check if there are any joins
     */
    public function hasJoins(): bool;

    /**
     * Reset all joins
     */
    public function reset(): void;

    /**
     * Add a join clause
     */
    public function add(string $table, string $first, string $operator, string $second, string $type = 'INNER'): void;

    /**
     * Get parameter bindings for joins
     */
    public function getBindings(): array;
}
