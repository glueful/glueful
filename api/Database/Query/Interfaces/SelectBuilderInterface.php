<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * SelectBuilder Interface
 *
 * Defines the contract for SELECT query construction.
 * This interface ensures consistent SELECT query building
 * across different implementations.
 */
interface SelectBuilderInterface
{
    /**
     * Build the complete SELECT query
     */
    public function build(): string;

    /**
     * Set the columns to select
     */
    public function setColumns(array $columns): void;

    /**
     * Get the current columns
     */
    public function getColumns(): array;

    /**
     * Set distinct flag
     */
    public function setDistinct(bool $distinct): void;

    /**
     * Check if query is distinct
     */
    public function isDistinct(): bool;

    /**
     * Build the column list portion of SELECT
     */
    public function buildColumnList(): string;

    /**
     * Get parameter bindings for the SELECT clause
     */
    public function getBindings(): array;

    /**
     * Reset the builder state
     */
    public function reset(): void;

    /**
     * Build complete SELECT clause with table
     */
    public function buildSelectClause(\Glueful\Database\Query\Interfaces\QueryStateInterface $state): string;
}
