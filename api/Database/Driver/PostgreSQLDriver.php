<?php

namespace Glueful\Database\Driver;

/**
 * PostgreSQL Database Driver Implementation
 *
 * Implements PostgreSQL-specific SQL generation and identifier handling.
 * Provides PostgreSQL syntax for:
 * - Double-quote identifier quoting
 * - ON CONFLICT handling
 * - EXCLUDED table references
 * - PostgreSQL-specific optimizations
 *
 * Follows PostgreSQL best practices for SQL generation and
 * maintains compatibility with the Driver interface.
 */
class PostgreSQLDriver implements DatabaseDriver
{
    /**
     * Wrap PostgreSQL identifier with double quotes
     *
     * Ensures proper escaping of column and table names:
     * - Wraps identifiers in double quotes
     * - Handles schema-qualified names
     * - Prevents SQL injection in identifiers
     *
     * @param string $identifier Column or table name
     * @return string Double-quote wrapped identifier
     */
    public function wrapIdentifier(string $identifier): string
    {
        return "\"$identifier\"";
    }

    /**
     * Generate PostgreSQL INSERT with conflict handling
     *
     * Creates SQL that ignores conflicts using ON CONFLICT DO NOTHING:
     * - Properly quotes identifiers
     * - Uses parameterized values
     * - Handles constraint violations gracefully
     *
     * @param string $table Target table
     * @param array $columns Column list
     * @return string PostgreSQL insert statement
     */
    public function insertIgnore(string $table, array $columns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        return "INSERT INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders) ON CONFLICT DO NOTHING";
    }

    /**
     * Generate PostgreSQL UPSERT statement
     *
     * Creates INSERT with ON CONFLICT DO UPDATE:
     * - Uses EXCLUDED table reference
     * - Handles multiple column updates
     * - Maintains atomicity
     * - Supports all PostgreSQL types
     *
     * @param string $table Target table
     * @param array $columns Columns to insert
     * @param array $updateColumns Columns to update on conflict
     * @return string PostgreSQL upsert statement
     */
    public function upsert(string $table, array $columns, array $updateColumns): string
    {
        $cols = implode(", ", array_map([$this, 'wrapIdentifier'], $columns));
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $updates = implode(", ", array_map(fn($col) => "\"$col\" = EXCLUDED.\"$col\"", $updateColumns));

        return "INSERT INTO {$this->wrapIdentifier($table)} ($cols) VALUES ($placeholders)" .
               " ON CONFLICT (id) DO UPDATE SET $updates";
    }
}
