<?php

namespace Glueful\Database\Schema;

/**
 * Database Schema Manager Interface
 * 
 * Core contract for database schema operations with comprehensive features:
 * 
 * Core Capabilities:
 * - Schema structure management
 * - Table/column operations
 * - Index/constraint handling
 * - Transaction support
 * - Schema information retrieval
 * 
 * Security Features:
 * - Prepared statements
 * - Identifier quoting
 * - Transaction isolation
 * - Permission validation
 * 
 * Design Principles:
 * - Engine agnostic interface
 * - Fluent method chaining
 * - Consistent error handling
 * - Type safety
 * 
 * Example usage:
 * ```php
 * $schema
 *     ->createTable('users', [
 *         'id' => ['type' => 'INTEGER', 'autoIncrement' => true],
 *         'email' => ['type' => 'VARCHAR(255)', 'unique' => true]
 *     ])
 *     ->addIndex([
 *         'type' => 'UNIQUE',
 *         'column' => 'email',
 *         'table' => 'users'
 *     ]);
 * ```
 */
interface SchemaManager
{
    /**
     * Create database table with fluent interface
     * 
     * Structural Options:
     * - Column types and modifiers
     * - Table constraints
     * - Storage parameters
     * - Character sets
     * - Collations
     * 
     * Advanced Features:
     * - Auto-increment sequences
     * - Computed columns
     * - Check constraints
     * - Triggers
     * - Partitioning
     * 
     * @return self For method chaining
     * @throws \RuntimeException On creation failure
     */
    public function createTable(string $table, array $columns, array $options = []): self;

    /**
     * Add database index or constraint
     * 
     * Supported Types:
     * - Regular indexes
     * - Unique constraints
     * - Foreign keys
     * - Spatial indexes
     * - Partial indexes
     * - Expression indexes
     * 
     * @return self For method chaining
     * @throws \RuntimeException On index creation failure
     */
    public function addIndex(array $indexes): self;

    /**
     * Drops (removes) an existing database table
     * 
     * @param string $table Name of the table to drop
     * @return bool True on successful table removal
     * @throws \RuntimeException When table drop fails or if table doesn't exist
     */
    public function dropTable(string $table): bool;

    /**
     * Adds new column to existing table
     * 
     * Position Options:
     * - FIRST: Add as first column
     * - AFTER column: Add after specific column
     * - Default: Add as last column
     * 
     * Constraints:
     * - NOT NULL
     * - UNIQUE
     * - CHECK constraints
     * - Foreign keys
     * - Default values
     * 
     * @throws \RuntimeException On invalid type, duplicate column
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
     * Creates database index with specified configuration
     * 
     * Index Types:
     * - BTREE (default)
     * - HASH (if supported)
     * - FULLTEXT (text columns)
     * - SPATIAL (geometric)
     * 
     * Options:
     * - Partial indexes
     * - Covering indexes
     * - Expression indexes
     * - Descending indexes
     * 
     * @throws \RuntimeException On invalid columns, duplicate index
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
     * Get table metadata
     * 
     * Returns Information About:
     * - Column definitions
     * - Constraints
     * - Indexes
     * - Storage parameters
     * - Statistics
     * - Dependencies
     * 
     * Format varies by engine but includes:
     * - Name and ordinal position
     * - Type and modifiers
     * - Default values
     * - Constraints
     * - Comments
     * 
     * @throws \RuntimeException When table info unavailable
     */
    public function getTableColumns(string $table): array;

    /**
     * Manage foreign key constraint checking
     * 
     * Use Cases:
     * - Bulk data loading
     * - Schema modifications
     * - Data migration
     * - Testing setup
     * 
     * Note: Some engines may not support this feature
     */
    public function disableForeignKeyChecks(): void;

    /**
     * Enable foreign key constraint checking
     * 
     * Re-enables foreign key constraints after they were disabled.
     * Should be called after completing operations that required
     * constraint checks to be disabled.
     * 
     * Engine-specific behavior:
     * - MySQL: Sets FOREIGN_KEY_CHECKS = 1
     * - PostgreSQL: Sets session_replication_role = 'origin'
     * - SQLite: Sets PRAGMA foreign_keys = ON
     * 
     * Important:
     * - Should be called even if operation fails
     * - Preferably in a finally block
     * - Verifies data integrity after bulk operations
     * - May trigger constraint validation
     * 
     * @throws \RuntimeException If constraints cannot be re-enabled
     */
    public function enableForeignKeyChecks(): void;

    /**
     * Get database engine version
     * 
     * Returns comprehensive version info:
     * - Version numbers
     * - Build details
     * - Platform info
     * - Configuration
     * 
     * Examples:
     * MySQL: "5.7.31-log"
     * PostgreSQL: "12.3 (Ubuntu 12.3-1)"
     * SQLite: "3.32.3"
     */
    public function getVersion(): string;

    /**
     * Calculate table storage metrics
     * 
     * Returns size information including:
     * - Data storage
     * - Index overhead
     * - TOAST/overflow
     * - Free space
     * - Fragmentation
     * 
     * Note: Accuracy varies by engine
     */
    public function getTableSize(string $table): int;
}