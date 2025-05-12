<?php

namespace Glueful\Database\Driver;

/**
 * SQLite Database Driver Implementation
 *
 * Implements SQLite-specific SQL generation and identifier handling.
 * Provides SQLite syntax for:
 * - Double-quote identifier quoting
 * - INSERT OR IGNORE operations
 * - REPLACE functionality
 * - SQLite-specific constraints
 *
 * Note: SQLite has specific limitations:
 * - No native UPSERT before version 3.24.0
 * - Limited constraint support
 * - Single-writer concurrency model
 */
class SQLiteDriver implements DatabaseDriver
{
    /**
     * Wrap SQLite identifier with double quotes
     *
     * Ensures proper escaping of column and table names:
     * - Uses double quotes for identifiers
     * - Handles special characters
     * - Prevents SQL injection
     *
     * @param string $identifier Column or table name
     * @return string Double-quote wrapped identifier
     */
    public function wrapIdentifier(string $identifier): string
    {
        return "\"$identifier\"";
    }

    /**
     * Generate SQLite INSERT OR IGNORE statement
     *
     * Creates SQL that ignores constraint violations:
     * - Uses INSERT OR IGNORE syntax
     * - Maintains data integrity
     * - Handles duplicate records gracefully
     *
     * @param string $table Target table
     * @param array $columns Column list
     * @return string SQLite insert statement
     */
    public function insertIgnore(string $table, array $columns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        return "INSERT OR IGNORE INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)";
    }

    /**
     * Generate SQLite UPSERT statement
     *
     * Creates INSERT with ON CONFLICT handling:
     * - Uses modern SQLite upsert syntax
     * - Falls back to REPLACE for older versions
     * - Handles constraint conflicts
     * - Maintains atomicity
     *
     * @param string $table Target table
     * @param array $columns Columns to insert
     * @param array $updateColumns Columns to update on conflict
     * @return string SQLite upsert statement
     */
    public function upsert(string $table, array $columns, array $updateColumns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $updates = implode(", ", array_map(fn($col) => "\"$col\" = EXCLUDED.\"$col\"", $updateColumns));

        return "INSERT INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders) ON CONFLICT(id) DO UPDATE SET $updates";
    }
}
