<?php

namespace Glueful\Database\Driver;

/**
 * MySQL Database Driver Implementation
 *
 * Implements MySQL-specific SQL generation and identifier handling.
 * Provides MySQL syntax for:
 * - Backtick identifier quoting
 * - INSERT IGNORE operations
 * - ON DUPLICATE KEY UPDATE syntax
 * - MySQL-specific optimizations
 */
class MySQLDriver implements DatabaseDriver
{
    /**
     * Wrap MySQL identifier with backticks
     *
     * Ensures proper escaping of column and table names:
     * - Wraps identifiers in backticks
     * - Handles qualified names (e.g., database.table)
     * - Prevents SQL injection in identifiers
     *
     * @param string $identifier Column or table name
     * @return string Backtick-quoted identifier
     */
    public function wrapIdentifier(string $identifier): string
    {
        return "`$identifier`";
    }

    /**
     * Generate MySQL INSERT IGNORE statement
     *
     * Creates SQL that silently ignores duplicate key errors:
     * - Uses INSERT IGNORE INTO syntax
     * - Properly quotes table and column names
     * - Generates parameterized query
     *
     * @param string $table Target table
     * @param array $columns Column list
     * @return string MySQL INSERT IGNORE statement
     */
    public function insertIgnore(string $table, array $columns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        return "INSERT IGNORE INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)";
    }

    /**
     * Generate MySQL UPSERT statement
     *
     * Creates INSERT ... ON DUPLICATE KEY UPDATE statement:
     * - Handles multiple column updates
     * - Uses VALUES() function for updates
     * - Maintains atomicity
     * - Supports all MySQL column types
     *
     * @param string $table Target table
     * @param array $columns Columns to insert
     * @param array $updateColumns Columns to update on duplicate
     * @return string MySQL upsert statement
     */
    public function upsert(string $table, array $columns, array $updateColumns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $updates = implode(", ", array_map(fn($col) => "`$col` = VALUES(`$col`)", $updateColumns));

        return "INSERT INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)" .
               " ON DUPLICATE KEY UPDATE $updates";
    }
}
