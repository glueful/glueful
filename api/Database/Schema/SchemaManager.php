<?php

namespace Glueful\Database\Schema;

/**
 * Database Schema Manager Interface
 * 
 * A comprehensive interface for managing database schema operations across different database systems.
 * This interface provides a standardized way to handle common database structure operations including
 * table management, column modifications, index handling, and schema information retrieval.
 * 
 * Key features:
 * - Table creation, modification, and removal
 * - Column management (add, modify, remove)
 * - Index operations (create, remove)
 * - Schema information retrieval
 * - Foreign key constraint management
 * 
 * Implementations should ensure database-specific optimizations while maintaining
 * consistent behavior across different database engines.
 */
interface SchemaManager
{
    /**
     * Creates a new database table with specified columns and options
     * 
     * @param string $table Name of the table to create (without prefixes)
     * @param array $columns Associative array of column definitions where:
     *                      - key: column name
     *                      - value: array of column attributes (type, length, nullable, default, etc.)
     * @param array $options Additional table options including:
     *                      - engine: Storage engine (e.g., InnoDB, MyISAM)
     *                      - charset: Character set
     *                      - collation: Collation rules
     *                      - indexes: Array of index definitions
     *                      - foreignKeys: Array of foreign key constraints
     * @return bool True on successful table creation
     * @throws \RuntimeException When table creation fails or if table already exists
     */
    public function createTable(string $table, array $columns, array $options = []): bool;

    /**
     * Drops (removes) an existing database table
     * 
     * @param string $table Name of the table to drop
     * @return bool True on successful table removal
     * @throws \RuntimeException When table drop fails or if table doesn't exist
     */
    public function dropTable(string $table): bool;

    /**
     * Adds a new column to an existing table
     * 
     * @param string $table Name of the target table
     * @param string $column Name of the new column
     * @param array $definition Column attributes including:
     *                         - type: Data type (VARCHAR, INTEGER, etc.)
     *                         - length: Column length/precision
     *                         - nullable: Whether NULL values are allowed
     *                         - default: Default value
     *                         - after: Column after which to add new column
     * @return bool True on successful column addition
     * @throws \RuntimeException When column addition fails
     */
    public function addColumn(string $table, string $column, array $definition): bool;

    /**
     * Remove column from table
     * 
     * @param string $table Target table name
     * @param string $column Column to remove
     * @return bool True if column dropped successfully
     * @throws \RuntimeException If column removal fails
     */
    public function dropColumn(string $table, string $column): bool;

    /**
     * Creates an index on specified table columns
     * 
     * @param string $table Name of the target table
     * @param string $indexName Name for the new index (should be unique within table)
     * @param array $columns Array of column names to include in index
     * @param bool $unique Whether to create a unique index (prevents duplicate values)
     * @return bool True on successful index creation
     * @throws \RuntimeException When index creation fails or if invalid columns specified
     */
    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool;

    /**
     * Remove index from table
     * 
     * @param string $table Target table name
     * @param string $indexName Index to remove
     * @return bool True if index dropped successfully
     * @throws \RuntimeException If index removal fails
     */
    public function dropIndex(string $table, string $indexName): bool;

    /**
     * Get list of database tables
     * 
     * @return array List of table names
     * @throws \RuntimeException If table list retrieval fails
     */
    public function getTables(): array;

    /**
     * Retrieves detailed information about table columns
     * 
     * @param string $table Name of the target table
     * @return array Associative array of column information including:
     *               - name: Column name
     *               - type: Data type
     *               - length: Column length/precision
     *               - nullable: Whether NULL is allowed
     *               - default: Default value
     *               - extra: Additional attributes (auto_increment, etc.)
     * @throws \RuntimeException When table doesn't exist or information cannot be retrieved
     */
    public function getTableColumns(string $table): array;

    /**
     * Disable foreign key checks (if supported by the database)
     */
    public function disableForeignKeyChecks(): void;

    /**
     * Enable foreign key checks (reverting to default behavior)
     */
    public function enableForeignKeyChecks(): void;

    /**
     * Retrieves the database server version information
     *
     * @return string Formatted version string (e.g., "5.7.31-log", "8.0.23")
     */
    public function getVersion(): string;

    /**
     * Calculates and returns the size of a specific table
     *
     * @param string $table Name of the target table
     * @return int Size of the table in bytes (including indexes and overhead)
     * @throws \RuntimeException When table doesn't exist or size cannot be determined
     */
    public function getTableSize(string $table): int;
}