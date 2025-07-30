<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

/**
 * Schema Builder Interface
 *
 * Provides a fluent, chainable interface for database schema operations.
 * Designed to be database-agnostic with support for MySQL, PostgreSQL, and SQLite.
 *
 * Features:
 * - Method chaining for better developer experience
 * - Database-agnostic column types and constraints
 * - Batch operations with transaction support
 * - Schema validation and preview capabilities
 * - Separation of definition and execution
 *
 * Example usage:
 * ```php
 * $schema->table('users')
 *     ->id()
 *     ->string('username', 50)->unique()
 *     ->string('email')->unique()->index()
 *     ->timestamps()
 *     ->create();
 * ```
 */
interface SchemaBuilderInterface
{
    /**
     * Start building a new table
     *
     * @param string $name Table name
     * @return TableBuilderInterface Fluent table builder
     */
    public function table(string $name): TableBuilderInterface;

    /**
     * Create a new table
     *
     * When called with only a name, returns a fluent table builder (alias for table())
     * When called with a callback, creates the table immediately and auto-executes
     *
     * @param string $name Table name
     * @param callable|null $callback Optional table definition callback
     * @return TableBuilderInterface|self Fluent table builder or self for chaining
     */
    public function createTable(string $name, ?callable $callback = null): TableBuilderInterface|self;

    /**
     * Alter an existing table
     *
     * @param string $name Table name
     * @return TableBuilderInterface Fluent table builder for alterations
     */
    public function alterTable(string $name): TableBuilderInterface;

    /**
     * Drop a table
     *
     * @param string $name Table name
     * @return self For method chaining
     */
    public function dropTable(string $name): self;

    /**
     * Drop a table if it exists
     *
     * @param string $name Table name
     * @return self For method chaining
     */
    public function dropTableIfExists(string $name): self;

    /**
     * Create a database
     *
     * @param string $name Database name
     * @return self For method chaining
     */
    public function createDatabase(string $name): self;

    /**
     * Drop a database
     *
     * @param string $name Database name
     * @return self For method chaining
     */
    public function dropDatabase(string $name): self;

    /**
     * Execute all pending operations within a transaction
     *
     * @param callable $callback Operations to execute
     * @return self For method chaining
     * @throws \RuntimeException If transaction fails
     */
    public function transaction(callable $callback): self;

    /**
     * Execute all pending schema operations
     *
     * @return array Results of executed operations
     * @throws \RuntimeException If execution fails
     */
    public function execute(): array;

    /**
     * Preview what SQL would be executed without running it
     *
     * @return array Array of SQL statements that would be executed
     */
    public function preview(): array;

    /**
     * Validate all pending operations without executing
     *
     * @return array Validation results with errors and warnings
     */
    public function validate(): array;

    /**
     * Clear all pending operations
     *
     * @return self For method chaining
     */
    public function reset(): self;

    /**
     * Check if a table exists
     *
     * @param string $table Table name
     * @return bool True if table exists
     */
    public function hasTable(string $table): bool;

    /**
     * Check if a column exists in a table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return bool True if column exists
     */
    public function hasColumn(string $table, string $column): bool;

    /**
     * Get list of all tables
     *
     * @return array Array of table names
     */
    public function getTables(): array;

    /**
     * Get complete table schema information
     *
     * @param string $table Table name
     * @return array Complete schema information
     */
    public function getTableSchema(string $table): array;

    /**
     * Get table columns information
     *
     * @param string $table Table name
     * @return array Array of column definitions with 'name' field
     */
    public function getTableColumns(string $table): array;

    /**
     * Get the size of a table in bytes
     *
     * @param string $table Table name
     * @return int Table size in bytes
     */
    public function getTableSize(string $table): int;

    /**
     * Get the number of rows in a table
     *
     * @param string $table Table name
     * @return int Number of rows
     */
    public function getTableRowCount(string $table): int;

    /**
     * Disable foreign key checks
     *
     * @return self For method chaining
     */
    public function disableForeignKeyChecks(): self;

    /**
     * Enable foreign key checks
     *
     * @return self For method chaining
     */
    public function enableForeignKeyChecks(): self;

    /**
     * Add a pending SQL operation (for internal use by builders)
     *
     * @param string $sql SQL statement to add
     * @return void
     */
    public function addPendingOperation(string $sql): void;

    /**
     * Get the database connection
     *
     * @return \Glueful\Database\Connection Database connection
     */
    public function getConnection(): \Glueful\Database\Connection;

    // ===========================================
    // Convenience Methods for Backward Compatibility
    // ===========================================

    /**
     * Add a column to an existing table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param array $definition Column definition
     * @return array Result with success status
     */
    public function addColumn(string $table, string $column, array $definition): array;

    /**
     * Drop a column from a table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return array Result with success status
     */
    public function dropColumn(string $table, string $column): array;

    /**
     * Add an index to a table
     *
     * @param array $indexes Index definitions
     * @return self For method chaining
     */
    public function addIndex(array $indexes): self;

    /**
     * Drop an index from a table
     *
     * @param string $table Table name
     * @param string $index Index name
     * @return bool Success status
     */
    public function dropIndex(string $table, string $index): bool;

    /**
     * Add foreign key constraints
     *
     * @param array $foreignKeys Foreign key definitions
     * @return self For method chaining
     */
    public function addForeignKey(array $foreignKeys): self;

    /**
     * Drop a foreign key constraint
     *
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @return bool Success status
     */
    public function dropForeignKey(string $table, string $constraint): bool;

    // ===========================================
    // Advanced Schema Management Methods (Stubs)
    // ===========================================

    /**
     * Generate preview of schema changes
     *
     * @param string $table Table name
     * @param array $changes Changes to preview
     * @return array Preview information
     */
    public function generateChangePreview(string $table, array $changes): array;

    /**
     * Export table schema in specified format
     *
     * @param string $table Table name
     * @param string $format Export format
     * @return array Exported schema
     */
    public function exportTableSchema(string $table, string $format): array;

    /**
     * Validate schema definition
     *
     * @param array $schema Schema to validate
     * @param string $format Schema format
     * @return array Validation result
     */
    public function validateSchema(array $schema, string $format): array;

    /**
     * Import table schema from definition
     *
     * @param string $table Table name
     * @param array $schema Schema definition
     * @param string $format Schema format
     * @param array $options Import options
     * @return array Import result
     */
    public function importTableSchema(string $table, array $schema, string $format, array $options): array;

    /**
     * Generate revert operations for a change
     *
     * @param array $change Original change
     * @return array Revert operations
     */
    public function generateRevertOperations(array $change): array;

    /**
     * Execute revert operations
     *
     * @param array $operations Revert operations
     * @return array Execution result
     */
    public function executeRevert(array $operations): array;
}
