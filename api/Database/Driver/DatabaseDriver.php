<?php

namespace Glueful\Database\Driver;

/**
 * Database Driver Interface
 * 
 * Defines contract for database-specific operations and SQL generation.
 * Each supported database engine (MySQL, PostgreSQL, SQLite) must implement
 * these methods according to their specific SQL syntax and requirements.
 * 
 * Handles:
 * - Identifier quoting
 * - INSERT IGNORE operations
 * - UPSERT (INSERT/UPDATE) operations
 * - Database-specific SQL generation
 */
interface DatabaseDriver
{
    /**
     * Wrap identifier with database-specific quotes
     * 
     * Examples:
     * - MySQL: `identifier`
     * - PostgreSQL: "identifier"
     * - SQLite: "identifier"
     * 
     * @param string $identifier Column or table name
     * @return string Quoted identifier
     */
    public function wrapIdentifier(string $identifier): string;

    /**
     * Generate INSERT IGNORE statement
     * 
     * Creates SQL for inserting records while ignoring duplicates:
     * - MySQL: INSERT IGNORE INTO
     * - PostgreSQL: INSERT INTO ... ON CONFLICT DO NOTHING
     * - SQLite: INSERT OR IGNORE INTO
     * 
     * @param string $table Target table
     * @param array $columns Column definitions
     * @return string Generated SQL statement
     */
    public function insertIgnore(string $table, array $columns): string;

    /**
     * Generate UPSERT statement
     * 
     * Creates SQL for insert-or-update operations:
     * - MySQL: INSERT ... ON DUPLICATE KEY UPDATE
     * - PostgreSQL: INSERT ... ON CONFLICT DO UPDATE
     * - SQLite: INSERT OR REPLACE INTO
     * 
     * @param string $table Target table
     * @param array $columns Columns to insert
     * @param array $updateColumns Columns to update on conflict
     * @return string Generated SQL statement
     */
    public function upsert(string $table, array $columns, array $updateColumns): string;
}