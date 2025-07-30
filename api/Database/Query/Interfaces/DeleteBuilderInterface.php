<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * DeleteBuilder Interface
 *
 * Defines the contract for DELETE query construction.
 * This interface ensures consistent DELETE query building
 * across different implementations.
 */
interface DeleteBuilderInterface
{
    /**
     * Delete records
     */
    public function delete(string $table, array $conditions, bool $softDelete = true): int;

    /**
     * Restore soft-deleted records
     */
    public function restore(string $table, array $conditions): int;

    /**
     * Hard delete records (bypass soft delete)
     */
    public function forceDelete(string $table, array $conditions): int;

    /**
     * Build DELETE SQL query
     */
    public function buildDeleteQuery(string $table, array $conditions, bool $softDelete): string;

    /**
     * Build RESTORE SQL query
     */
    public function buildRestoreQuery(string $table, array $conditions): string;

    /**
     * Build WHERE clause for DELETE
     */
    public function buildWhereClause(array $conditions): string;

    /**
     * Get parameter bindings for DELETE query
     */
    public function getBindings(array $conditions): array;

    /**
     * Validate delete conditions
     */
    public function validateConditions(array $conditions): void;

    /**
     * Check if soft deletes are enabled
     */
    public function isSoftDeleteEnabled(): bool;

    /**
     * Enable or disable soft deletes
     */
    public function setSoftDeleteEnabled(bool $enabled): void;
}
