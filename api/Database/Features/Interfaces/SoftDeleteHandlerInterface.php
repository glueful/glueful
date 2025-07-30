<?php

declare(strict_types=1);

namespace Glueful\Database\Features\Interfaces;

use Glueful\Database\Query\Interfaces\WhereClauseInterface;

/**
 * Interface for handling soft delete functionality in queries
 *
 * Provides methods to apply soft delete filters, perform soft deletes,
 * and restore soft-deleted records.
 */
interface SoftDeleteHandlerInterface
{
    /**
     * Enable or disable soft delete handling
     */
    public function setEnabled(bool $enabled): void;

    /**
     * Check if soft delete handling is enabled
     */
    public function isEnabled(): bool;

    /**
     * Include soft-deleted records in queries
     */
    public function withTrashed(): void;

    /**
     * Only include soft-deleted records in queries
     */
    public function onlyTrashed(): void;

    /**
     * Apply soft delete filters to a WHERE clause
     */
    public function applyToWhereClause(WhereClauseInterface $whereClause, ?string $table = null): void;

    /**
     * Perform a soft delete on records
     *
     * @param string $table The table to soft delete from
     * @param array $conditions The WHERE conditions
     * @param string $deletedAtColumn The column name for soft delete timestamp
     * @return int Number of affected rows
     */
    public function softDelete(string $table, array $conditions, string $deletedAtColumn = 'deleted_at'): int;

    /**
     * Restore soft-deleted records
     *
     * @param string $table The table to restore from
     * @param array $conditions The WHERE conditions
     * @param string $deletedAtColumn The column name for soft delete timestamp
     * @return int Number of restored rows
     */
    public function restore(string $table, array $conditions, string $deletedAtColumn = 'deleted_at'): int;

    /**
     * Get the soft delete column name
     */
    public function getDeletedAtColumn(): string;

    /**
     * Set the soft delete column name
     */
    public function setDeletedAtColumn(string $column): void;
}
