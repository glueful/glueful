<?php

namespace Glueful\Database\Driver;

/**
 * Database Driver Interface
 *
 * Core interface defining the contract for database-specific operations and SQL generation.
 * Implementations of this interface handle the variations in SQL syntax across different
 * database management systems (DBMS).
 *
 * Key responsibilities:
 * - Safe identifier quoting to prevent SQL injection
 * - Handle database-specific syntax for INSERT IGNORE operations
 * - Manage UPSERT (INSERT/UPDATE) operations across different DBMS
 * - Generate optimized, database-specific SQL statements
 *
 * Supported databases should implement this interface according to their specific
 * SQL dialect and optimization requirements.
 */
interface DatabaseDriver
{
    /**
     * Wrap an identifier with database-specific quotes
     *
     * Ensures proper escaping of identifiers (table names, column names) according
     * to the specific database's requirements.
     *
     * Database-specific examples:
     * - MySQL:      `table_name`
     * - PostgreSQL: "table_name"
     * - SQLite:     "table_name"
     *
     * @param string $identifier The raw identifier (table/column name) to be quoted
     * @return string The properly quoted identifier safe for SQL queries
     * @throws \InvalidArgumentException If identifier contains invalid characters
     */
    public function wrapIdentifier(string $identifier): string;

    /**
     * Generate an INSERT IGNORE statement for the target database
     *
     * Creates a database-specific SQL statement that will insert records while
     * silently handling duplicate key conflicts.
     *
     * Implementation examples:
     * - MySQL:      INSERT IGNORE INTO table (col1, col2) VALUES (?, ?)
     * - PostgreSQL: INSERT INTO table (col1, col2) ON CONFLICT DO NOTHING
     * - SQLite:     INSERT OR IGNORE INTO table (col1, col2) VALUES (?, ?)
     *
     * @param string $table The target table name (unquoted)
     * @param array $columns Array of column definitions
     * @return string Complete SQL statement with proper syntax for target database
     * @throws \InvalidArgumentException If table name or columns are invalid
     */
    public function insertIgnore(string $table, array $columns): string;

    /**
     * Generate an UPSERT (INSERT or UPDATE) statement
     *
     * Creates a database-specific SQL statement that will either insert a new record
     * or update an existing one based on a key constraint violation.
     *
     * Implementation examples:
     * - MySQL:      INSERT INTO table (col1, col2) VALUES (?, ?)
     *              ON DUPLICATE KEY UPDATE col2 = VALUES(col2)
     * - PostgreSQL: INSERT INTO table (col1, col2) VALUES (?, ?)
     *              ON CONFLICT (col1) DO UPDATE SET col2 = EXCLUDED.col2
     * - SQLite:     INSERT OR REPLACE INTO table (col1, col2) VALUES (?, ?)
     *
     * @param string $table The target table name (unquoted)
     * @param array $columns Columns to insert in the format [name => value]
     * @param array $updateColumns Columns to update on conflict in format [name => value]
     * @return string Complete SQL statement with proper syntax for target database
     * @throws \InvalidArgumentException If parameters are invalid
     */
    public function upsert(string $table, array $columns, array $updateColumns): string;

    /**
     * Get query to retrieve table column information
     *
     * Returns a database-specific SQL query to retrieve column names for a given table.
     * Used for expanding table.* wildcard patterns in SELECT statements.
     *
     * Implementation examples:
     * - MySQL:      SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     *               WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()
     * - PostgreSQL: SELECT column_name FROM information_schema.columns
     *               WHERE table_name = ? AND table_schema = current_schema()
     * - SQLite:     PRAGMA table_info(table_name)
     *
     * @param string $table The target table name (unquoted)
     * @return string SQL query to retrieve column information
     */
    public function getTableColumnsQuery(string $table): string;

    /**
     * Format current datetime for database storage
     *
     * Returns database-agnostic datetime formatting that ensures consistent
     * datetime storage across different database engines while maintaining
     * proper timezone handling and precision.
     *
     * Implementation examples:
     * - MySQL:      Returns datetime in 'Y-m-d H:i:s' format
     * - PostgreSQL: Returns datetime in 'Y-m-d H:i:s' format with timezone awareness
     * - SQLite:     Returns datetime in 'Y-m-d H:i:s' format (stored as TEXT)
     *
     * @param \DateTime|string|null $datetime Optional datetime to format (defaults to current time)
     * @return string Formatted datetime string ready for database storage
     * @throws \InvalidArgumentException If provided datetime string is invalid
     */
    public function formatDateTime($datetime = null): string;

    /**
     * Get database-specific ping query for health checks
     *
     * Returns an optimized, lightweight query that can be used to verify
     * database connectivity and responsiveness during connection health checks.
     *
     * Implementation examples:
     * - MySQL:      SELECT 1
     * - PostgreSQL: SELECT 1
     * - SQLite:     SELECT 1
     *
     * @return string Lightweight SQL query for connectivity testing
     */
    public function getPingQuery(): string;
}
