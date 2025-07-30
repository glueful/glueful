<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

/**
 * SQL Generator Interface
 *
 * Database-agnostic interface for generating SQL statements from schema definitions.
 * Each database driver implements this interface to provide engine-specific SQL.
 *
 * Features:
 * - Table creation and modification
 * - Column operations (add, modify, drop)
 * - Index management
 * - Foreign key constraints
 * - Database-specific type mapping
 * - Proper SQL escaping and quoting
 *
 * Implementations:
 * - MySQLSqlGenerator: MySQL-specific SQL generation
 * - PostgreSQLSqlGenerator: PostgreSQL-specific SQL generation
 * - SQLiteSqlGenerator: SQLite-specific SQL generation
 */
interface SqlGeneratorInterface
{
    // ===========================================
    // Table Operations
    // ===========================================

    /**
     * Generate CREATE TABLE statement
     *
     * @param \Glueful\Database\Schema\DTOs\TableDefinition $table Table definition
     * @return string SQL CREATE TABLE statement
     */
    public function createTable(\Glueful\Database\Schema\DTOs\TableDefinition $table): string;

    /**
     * Generate ALTER TABLE statements for table modifications
     *
     * @param \Glueful\Database\Schema\DTOs\TableDefinition $table Current table definition
     * @param array $changes Array of changes to apply
     * @return array Array of SQL statements
     */
    public function alterTable(\Glueful\Database\Schema\DTOs\TableDefinition $table, array $changes): array;

    /**
     * Generate DROP TABLE statement
     *
     * @param string $table Table name
     * @param bool $ifExists Add IF EXISTS clause
     * @return string SQL DROP TABLE statement
     */
    public function dropTable(string $table, bool $ifExists = false): string;

    /**
     * Generate RENAME TABLE statement
     *
     * @param string $from Current table name
     * @param string $to New table name
     * @return string SQL RENAME TABLE statement
     */
    public function renameTable(string $from, string $to): string;

    // ===========================================
    // Column Operations
    // ===========================================

    /**
     * Generate ADD COLUMN statement
     *
     * @param string $table Table name
     * @param \Glueful\Database\Schema\DTOs\ColumnDefinition $column Column definition
     * @return string SQL ADD COLUMN statement
     */
    public function addColumn(string $table, \Glueful\Database\Schema\DTOs\ColumnDefinition $column): string;

    /**
     * Generate MODIFY/ALTER COLUMN statement
     *
     * @param string $table Table name
     * @param \Glueful\Database\Schema\DTOs\ColumnDefinition $column New column definition
     * @return string SQL MODIFY COLUMN statement
     */
    public function modifyColumn(string $table, \Glueful\Database\Schema\DTOs\ColumnDefinition $column): string;

    /**
     * Generate DROP COLUMN statement
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return string SQL DROP COLUMN statement
     */
    public function dropColumn(string $table, string $column): string;

    /**
     * Generate RENAME COLUMN statement
     *
     * @param string $table Table name
     * @param string $from Current column name
     * @param string $to New column name
     * @return string SQL RENAME COLUMN statement
     */
    public function renameColumn(string $table, string $from, string $to): string;

    // ===========================================
    // Index Operations
    // ===========================================

    /**
     * Generate CREATE INDEX statement
     *
     * @param string $table Table name
     * @param \Glueful\Database\Schema\DTOs\IndexDefinition $index Index definition
     * @return string SQL CREATE INDEX statement
     */
    public function createIndex(string $table, \Glueful\Database\Schema\DTOs\IndexDefinition $index): string;

    /**
     * Generate DROP INDEX statement
     *
     * @param string $table Table name
     * @param string $index Index name
     * @return string SQL DROP INDEX statement
     */
    public function dropIndex(string $table, string $index): string;

    // ===========================================
    // Foreign Key Operations
    // ===========================================

    /**
     * Generate ADD FOREIGN KEY statement
     *
     * @param string $table Table name
     * @param \Glueful\Database\Schema\DTOs\ForeignKeyDefinition $foreignKey Foreign key definition
     * @return string SQL ADD FOREIGN KEY statement
     */
    public function addForeignKey(
        string $table,
        \Glueful\Database\Schema\DTOs\ForeignKeyDefinition $foreignKey
    ): string;

    /**
     * Generate DROP FOREIGN KEY statement
     *
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @return string SQL DROP FOREIGN KEY statement
     */
    public function dropForeignKey(string $table, string $constraint): string;

    // ===========================================
    // Database Operations
    // ===========================================

    /**
     * Generate CREATE DATABASE statement
     *
     * @param string $database Database name
     * @param array $options Database options
     * @return string SQL CREATE DATABASE statement
     */
    public function createDatabase(string $database, array $options = []): string;

    /**
     * Generate DROP DATABASE statement
     *
     * @param string $database Database name
     * @param bool $ifExists Add IF EXISTS clause
     * @return string SQL DROP DATABASE statement
     */
    public function dropDatabase(string $database, bool $ifExists = false): string;

    // ===========================================
    // Utility Methods
    // ===========================================

    /**
     * Map abstract column type to database-specific type
     *
     * @param string $type Abstract type (string, integer, etc.)
     * @param array $options Type options (length, precision, etc.)
     * @return string Database-specific type
     */
    public function mapColumnType(string $type, array $options = []): string;

    /**
     * Quote identifier (table name, column name, etc.)
     *
     * @param string $identifier Identifier to quote
     * @return string Quoted identifier
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Quote and escape string value
     *
     * @param mixed $value Value to quote
     * @return string Quoted value
     */
    public function quoteValue(mixed $value): string;

    /**
     * Format default value for column definition
     *
     * @param mixed $value Default value
     * @param string $type Column type
     * @return string Formatted default value
     */
    public function formatDefaultValue(mixed $value, string $type): string;

    /**
     * Get foreign key constraint checking SQL
     *
     * @param bool $enabled Whether to enable or disable checks
     * @return string SQL statement to enable/disable foreign key checks
     */
    public function foreignKeyChecks(bool $enabled): string;

    /**
     * Generate table exists check query
     *
     * @param string $table Table name
     * @return string SQL query to check if table exists
     */
    public function tableExistsQuery(string $table): string;

    /**
     * Generate column exists check query
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return string SQL query to check if column exists
     */
    public function columnExistsQuery(string $table, string $column): string;

    /**
     * Generate query to get table schema information
     *
     * @param string $table Table name
     * @return string SQL query to get table schema
     */
    public function getTableSchemaQuery(string $table): string;

    /**
     * Generate query to list all tables
     *
     * @return string SQL query to list tables
     */
    public function getTablesQuery(): string;

    /**
     * Generate query to get table size in bytes
     *
     * @param string $table Table name
     * @return string SQL query to get table size
     */
    public function getTableSizeQuery(string $table): string;

    /**
     * Generate query to get table row count
     *
     * @param string $table Table name
     * @return string SQL query to get row count
     */
    public function getTableRowCountQuery(string $table): string;

    /**
     * Get table columns with comprehensive information
     *
     * Returns detailed column information including:
     * - Column metadata (name, type, nullable, default)
     * - Index information
     * - Foreign key relationships
     * - Constraints
     *
     * @param string $table Table name
     * @param \PDO $pdo PDO connection for executing queries
     * @return array Array of column information with standardized format
     */
    public function getTableColumns(string $table, \PDO $pdo): array;

    // ===========================================
    // Advanced Schema Management Methods
    // ===========================================

    /**
     * Generate preview of schema changes
     *
     * @param string $table Table name
     * @param array $changes Changes to preview
     * @return array Preview information with SQL and warnings
     */
    public function generateChangePreview(string $table, array $changes): array;

    /**
     * Export table schema in specified format
     *
     * @param string $table Table name
     * @param string $format Export format (json, sql, yaml, php)
     * @param array $schema Table schema data
     * @return array Exported schema
     */
    public function exportTableSchema(string $table, string $format, array $schema): array;

    /**
     * Validate schema definition
     *
     * @param array $schema Schema to validate
     * @param string $format Schema format
     * @return array Validation result with errors and warnings
     */
    public function validateSchema(array $schema, string $format): array;

    /**
     * Generate revert operations for a change
     *
     * @param array $change Original change
     * @return array Revert operations
     */
    public function generateRevertOperations(array $change): array;

    /**
     * Import table schema from definition
     *
     * @param string $table Table name
     * @param array $schema Schema definition
     * @param string $format Schema format
     * @param array $options Import options
     * @return array Import result with SQL statements
     */
    public function importTableSchema(string $table, array $schema, string $format, array $options): array;
}
