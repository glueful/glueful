<?php

declare(strict_types=1);

namespace Glueful\Database\Features;

use Glueful\Database\Features\Interfaces\SoftDeleteHandlerInterface;
use Glueful\Database\Query\Interfaces\WhereClauseInterface;
use Glueful\Database\Query\Interfaces\UpdateBuilderInterface;
use Glueful\Database\Driver\DatabaseDriver;
use PDO;

/**
 * Handles soft delete functionality for database queries
 *
 * This component manages the application of soft delete filters to queries,
 * performs soft delete operations, and handles restoration of soft-deleted records.
 */
class SoftDeleteHandler implements SoftDeleteHandlerInterface
{
    private bool $enabled = true;
    private bool $withTrashed = false;
    private bool $onlyTrashed = false;
    private string $deletedAtColumn = 'deleted_at';

    public function __construct(
        private PDO $pdo,
        private DatabaseDriver $driver,
        private UpdateBuilderInterface $updateBuilder
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function withTrashed(): void
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;
    }

    /**
     * {@inheritdoc}
     */
    public function onlyTrashed(): void
    {
        $this->onlyTrashed = true;
        $this->withTrashed = false;
    }

    /**
     * {@inheritdoc}
     */
    public function applyToWhereClause(WhereClauseInterface $whereClause, ?string $table = null): void
    {
        if (!$this->enabled) {
            return;
        }

        // Check if table has the deleted_at column before applying filters
        if ($table && !$this->tableHasDeletedAtColumn($table)) {
            return;
        }


        if ($this->onlyTrashed) {
            // Only show soft-deleted records
            $whereClause->whereNotNull($this->deletedAtColumn);
        } elseif (!$this->withTrashed) {
            // Exclude soft-deleted records (default behavior)
            $whereClause->whereNull($this->deletedAtColumn);
        }
        // If withTrashed is true, don't add any filter (show all records)
    }

    /**
     * {@inheritdoc}
     */
    public function softDelete(string $table, array $conditions, string $deletedAtColumn = 'deleted_at'): int
    {
        if (!$this->enabled) {
            throw new \RuntimeException('Soft delete is not enabled');
        }

        $data = [
            $deletedAtColumn => date('Y-m-d H:i:s')
        ];

        // Add condition to only update non-deleted records
        $conditions[$deletedAtColumn] = null;

        return $this->updateBuilder->update($table, $data, $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $table, array $conditions, string $deletedAtColumn = 'deleted_at'): int
    {
        if (!$this->enabled) {
            throw new \RuntimeException('Soft delete is not enabled');
        }

        $data = [
            $deletedAtColumn => null
        ];

        // Ensure we're only restoring soft-deleted records
        // Since UpdateBuilder only accepts 3 params, we need to modify conditions
        $restoreConditions = $conditions;

        // Only restore records that are actually soft-deleted
        // We check if deleted_at is already in conditions to avoid overwriting
        if (!array_key_exists($deletedAtColumn, $restoreConditions)) {
            // We can't use IS NOT NULL in a simple array condition
            // This would need to be handled by the WhereClause in the QueryBuilder
            // For now, we'll document this limitation
        }

        return $this->updateBuilder->update($table, $data, $restoreConditions);
    }

    /**
     * {@inheritdoc}
     */
    public function getDeletedAtColumn(): string
    {
        return $this->deletedAtColumn;
    }

    /**
     * {@inheritdoc}
     */
    public function setDeletedAtColumn(string $column): void
    {
        $this->deletedAtColumn = $column;
    }

    /**
     * Reset the soft delete state
     */
    public function reset(): void
    {
        $this->withTrashed = false;
        $this->onlyTrashed = false;
    }

    /**
     * Check if the handler is including trashed records
     */
    public function isWithTrashed(): bool
    {
        return $this->withTrashed;
    }

    /**
     * Check if the handler is only showing trashed records
     */
    public function isOnlyTrashed(): bool
    {
        return $this->onlyTrashed;
    }

    /**
     * Force delete records (permanent deletion)
     *
     * @param string $table The table to delete from
     * @param array $conditions The WHERE conditions
     * @return int Number of deleted rows
     */
    public function forceDelete(string $table, array $conditions): int
    {
        $sql = "DELETE FROM {$this->driver->wrapIdentifier($table)}";
        $bindings = [];

        if (!empty($conditions)) {
            $whereClause = $this->buildWhereClause($conditions, $bindings);
            $sql .= " WHERE $whereClause";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Check if a table has the deleted_at column
     */
    private function tableHasDeletedAtColumn(string $table): bool
    {
        try {
            // Cache the column check to avoid repeated queries
            static $columnCache = [];

            if (isset($columnCache[$table])) {
                return $columnCache[$table];
            }

            // Use database information schema to check if column exists
            $driverName = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            switch ($driverName) {
                case 'mysql':
                    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                           WHERE table_name = ? AND column_name = ? AND table_schema = DATABASE()";
                    $params = [$table, $this->deletedAtColumn];
                    break;

                case 'pgsql':
                    $sql = "SELECT COUNT(*) FROM information_schema.columns 
                           WHERE table_name = ? AND column_name = ?";
                    $params = [$table, $this->deletedAtColumn];
                    break;

                case 'sqlite':
                    // SQLite: Use PRAGMA table_info to get column information
                    $sql = "PRAGMA table_info(" . $this->driver->wrapIdentifier($table) . ")";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute();
                    $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    foreach ($columns as $column) {
                        if ($column['name'] === $this->deletedAtColumn) {
                            $columnCache[$table] = true;
                            return true;
                        }
                    }
                    $columnCache[$table] = false;
                    return false;

                default:
                    // Fallback to the original method for unknown drivers
                    $sql = "SELECT 1 FROM " . $this->driver->wrapIdentifier($table) .
                           " WHERE " . $this->driver->wrapIdentifier($this->deletedAtColumn) . " IS NULL LIMIT 0";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute();
                    $columnCache[$table] = true;
                    return true;
            }

            // For MySQL and PostgreSQL
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->fetchColumn();
            $hasColumn = $count > 0;
            $columnCache[$table] = $hasColumn;
            return $hasColumn;
        } catch (\PDOException) {
            // If query fails, assume column doesn't exist
            $columnCache[$table] = false;
            return false;
        }
    }

    /**
     * Build WHERE clause from conditions
     */
    private function buildWhereClause(array $conditions, array &$bindings): string
    {
        $clauses = [];

        foreach ($conditions as $column => $value) {
            $wrappedColumn = $this->driver->wrapIdentifier($column);

            if ($value === null) {
                $clauses[] = "$wrappedColumn IS NULL";
            } else {
                $clauses[] = "$wrappedColumn = ?";
                $bindings[] = $value;
            }
        }

        return implode(' AND ', $clauses);
    }
}
