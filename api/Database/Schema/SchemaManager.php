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
     * Check if a table exists in the database
     *
     * @param string $table Name of the table to check
     * @return bool True if the table exists, false otherwise
     * @throws \RuntimeException If the check cannot be completed due to database errors
     */
    public function tableExists(string $table): bool;

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

    /**
     * Get the total number of rows in a table
     *
     * Returns the count of records in the specified table.
     * Implementation may use:
     * - COUNT(*) query
     * - Table statistics when available
     * - Engine-specific optimizations
     *
     * Note: For large tables, this operation may be expensive
     *
     * @param string $table Name of the table to count rows from
     * @return int Number of rows in the table
     * @throws \RuntimeException If table doesn't exist or access denied
     */
    public function getTableRowCount(string $table): int;

    /**
     * Drops (removes) a foreign key constraint from a table
     *
     * Removes the specified foreign key constraint while preserving the data.
     * Different databases handle this operation differently:
     * - MySQL: Uses ALTER TABLE DROP FOREIGN KEY syntax
     * - PostgreSQL: Uses ALTER TABLE DROP CONSTRAINT syntax
     * - SQLite: Requires table recreation (more complex)
     *
     * @param string $table Target table containing the constraint
     * @param string $constraintName Name of the foreign key constraint to remove
     * @return bool True if the constraint was successfully removed
     * @throws \RuntimeException If constraint removal fails or constraint doesn't exist
     */
    public function dropForeignKey(string $table, string $constraintName): bool;

    /**
     * Adds foreign key constraints to tables
     *
     * Creates foreign key constraints with:
     * - Support for multiple constraints in one call
     * - ON DELETE behavior specification
     * - ON UPDATE behavior specification
     * - Custom constraint naming
     *
     * Example:
     * ```php
     * $schema->addForeignKey([
     *     [
     *         'table' => 'products',
     *         'column' => 'category_id',
     *         'references' => 'id',
     *         'on' => 'categories',
     *         'onDelete' => 'CASCADE',
     *         'onUpdate' => 'CASCADE',
     *         'name' => 'fk_products_category'
     *     ],
     *     [
     *         'table' => 'products',
     *         'column' => 'user_id',
     *         'references' => 'id',
     *         'on' => 'users',
     *         'onDelete' => 'RESTRICT'
     *     ]
     * ]);
     * ```
     *
     * @param array $foreignKeys Array of foreign key definitions
     * @return self For method chaining
     * @throws \RuntimeException On constraint creation failure
     */
    public function addForeignKey(array $foreignKeys): self;

    /**
     * Generate preview of schema changes
     *
     * Analyzes proposed changes and generates:
     * - SQL statements that would be executed
     * - Potential warnings and risks
     * - Estimated execution time
     * - Impact assessment
     *
     * @param string $tableName Target table name
     * @param array $changes Array of proposed changes
     * @return array Preview data with SQL, warnings, and metadata
     * @throws \RuntimeException If preview cannot be generated
     */
    public function generateChangePreview(string $tableName, array $changes): array;

    /**
     * Export table schema in specified format
     *
     * Supported formats:
     * - 'json': JSON representation with full metadata
     * - 'sql': SQL CREATE statements
     * - 'yaml': YAML format for configuration files
     * - 'php': PHP array format for migrations
     *
     * @param string $tableName Table to export
     * @param string $format Export format (json|sql|yaml|php)
     * @return array Schema representation with metadata
     * @throws \RuntimeException If table doesn't exist or export fails
     */
    public function exportTableSchema(string $tableName, string $format = 'json'): array;

    /**
     * Import and apply table schema
     *
     * Validates and applies schema definition to create or modify tables.
     * Supports incremental updates and full table recreation.
     *
     * @param string $tableName Target table name
     * @param array $schema Schema definition
     * @param string $format Schema format
     * @param array $options Import options (recreate, validate_only, etc.)
     * @return array Import results with applied changes
     * @throws \RuntimeException If import fails or validation errors
     */
    public function importTableSchema(
        string $tableName,
        array $schema,
        string $format = 'json',
        array $options = []
    ): array;

    /**
     * Validate schema definition
     *
     * Performs comprehensive validation including:
     * - Format compliance
     * - Data type validation
     * - Constraint verification
     * - Naming convention checks
     * - Cross-reference validation
     *
     * @param array $schema Schema to validate
     * @param string $format Schema format
     * @return array Validation results with errors and warnings
     */
    public function validateSchema(array $schema, string $format = 'json'): array;

    /**
     * Generate revert operations for a schema change
     *
     * Analyzes audit log entry and generates operations to undo changes:
     * - Column additions -> DROP COLUMN
     * - Column modifications -> Restore original definition
     * - Index changes -> Reverse operations
     * - Constraint changes -> Restore original constraints
     *
     * @param array $originalChange Audit log entry of the original change
     * @return array Array of revert operations
     * @throws \RuntimeException If revert cannot be generated
     */
    public function generateRevertOperations(array $originalChange): array;

    /**
     * Execute revert operations
     *
     * Applies revert operations with full transaction support and audit logging.
     *
     * @param array $revertOps Array of revert operations
     * @return array Execution results
     * @throws \RuntimeException If revert execution fails
     */
    public function executeRevert(array $revertOps): array;

    /**
     * Get complete table schema information
     *
     * Returns comprehensive schema data including:
     * - Column definitions with types and constraints
     * - Index information
     * - Foreign key relationships
     * - Table options and metadata
     *
     * @param string $tableName Table to analyze
     * @return array Complete schema information
     * @throws \RuntimeException If table doesn't exist
     */
    public function getTableSchema(string $tableName): array;

    /**
     * Convert SQL statements to engine-specific syntax
     *
     * Transforms generic or other database engine SQL statements
     * to be compatible with the current database engine.
     *
     * Common conversions:
     * - Data type mappings (BIGINT -> INTEGER for SQLite)
     * - AUTO_INCREMENT syntax variations
     * - Engine-specific features
     * - Character set and collation handling
     *
     * @param string $sql Original SQL statement
     * @return string Converted SQL statement compatible with current engine
     * @throws \RuntimeException If conversion fails or SQL is invalid
     */
    public function convertSQLToEngineFormat(string $sql): string;
}
