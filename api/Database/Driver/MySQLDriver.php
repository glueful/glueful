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

    /**
     * Get MySQL table columns query
     *
     * Returns INFORMATION_SCHEMA query to retrieve column names for a table.
     * This query works with the current database and properly handles MySQL's
     * INFORMATION_SCHEMA structure.
     *
     * @param string $table Target table name
     * @return string MySQL query to get column information
     */
    public function getTableColumnsQuery(string $table): string
    {
        return "SELECT COLUMN_NAME as column_name FROM INFORMATION_SCHEMA.COLUMNS " .
               "WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() " .
               "ORDER BY ORDINAL_POSITION";
    }

    /**
     * Format datetime for MySQL storage
     *
     * MySQL stores datetime values in 'Y-m-d H:i:s' format in the server's timezone.
     * This method ensures consistent datetime formatting for MySQL DATETIME columns.
     *
     * @param \DateTime|string|null $datetime Datetime to format (defaults to current time)
     * @return string MySQL-compatible datetime string
     * @throws \InvalidArgumentException If provided datetime string is invalid
     */
    public function formatDateTime($datetime = null): string
    {
        if ($datetime === null) {
            return date('Y-m-d H:i:s');
        }

        if ($datetime instanceof \DateTime) {
            return $datetime->format('Y-m-d H:i:s');
        }

        if (is_string($datetime)) {
            $parsedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
            if ($parsedDate === false) {
                // Try to parse as a general date string
                try {
                    $parsedDate = new \DateTime($datetime);
                } catch (\Exception) {
                    throw new \InvalidArgumentException("Invalid datetime string: {$datetime}");
                }
            }
            return $parsedDate->format('Y-m-d H:i:s');
        }

        throw new \InvalidArgumentException('Datetime must be null, DateTime object, or string');
    }

    /**
     * {@inheritdoc}
     */
    public function getPingQuery(): string
    {
        return 'SELECT 1';
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'mysql';
    }
}
