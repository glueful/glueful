<?php

namespace Glueful\Database\Schema;

/**
 * Database Schema Manager Interface
 * 
 * Defines contract for managing database schema operations including:
 * - Table creation and deletion
 * - Column management
 * - Index operations
 * - Schema information retrieval
 * 
 * Implementations should handle database-specific schema operations
 * while maintaining a consistent interface across different engines.
 */
interface SchemaManager
{
    /**
     * Create new database table
     * 
     * @param string $table Table name
     * @param array $columns Column definitions
     * @param array $options Table options (indexes, foreign keys, etc)
     * @return bool True if table created successfully
     * @throws \RuntimeException If table creation fails
     */
    public function createTable(string $table, array $columns, array $options = []): bool;

    /**
     * Drop existing database table
     * 
     * @param string $table Table name to drop
     * @return bool True if table dropped successfully
     * @throws \RuntimeException If table drop fails
     */
    public function dropTable(string $table): bool;

    /**
     * Add new column to existing table
     * 
     * @param string $table Target table name
     * @param string $column New column name
     * @param array $definition Column definition (type, constraints, etc)
     * @return bool True if column added successfully
     * @throws \RuntimeException If column addition fails
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
     * Create index on table columns
     * 
     * @param string $table Target table name
     * @param string $indexName Name for new index
     * @param array $columns Columns to index
     * @param bool $unique Whether index should be unique
     * @return bool True if index created successfully
     * @throws \RuntimeException If index creation fails
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
     * Get column information for table
     * 
     * @param string $table Target table name
     * @return array Column definitions and metadata
     * @throws \RuntimeException If column information retrieval fails
     */
    public function getTableColumns(string $table): array;
}