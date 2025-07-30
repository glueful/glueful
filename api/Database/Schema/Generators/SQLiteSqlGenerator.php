<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Generators;

use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * SQLite SQL Generator Implementation
 *
 * Generates SQLite-specific SQL statements from schema definitions.
 * Handles SQLite syntax, data types, and engine-specific limitations.
 *
 * Features:
 * - SQLite data type mapping (limited types)
 * - INTEGER PRIMARY KEY AUTOINCREMENT support
 * - Foreign key constraint support (when enabled)
 * - Proper identifier quoting with square brackets
 * - Handles SQLite's ALTER TABLE limitations
 *
 * SQLite Limitations:
 * - Limited ALTER TABLE support (cannot modify columns easily)
 * - Simple data type system (TEXT, INTEGER, REAL, BLOB)
 * - Foreign keys must be enabled explicitly
 *
 * Example output:
 * ```sql
 * CREATE TABLE "users" (
 *   "id" INTEGER PRIMARY KEY AUTOINCREMENT,
 *   "email" TEXT NOT NULL,
 *   UNIQUE("email")
 * );
 * ```
 */
class SQLiteSqlGenerator implements SqlGeneratorInterface
{
    // ===========================================
    // Table Operations
    // ===========================================

    /**
     * Generate CREATE TABLE statement
     *
     * @param TableDefinition $table Table definition
     * @return string SQL CREATE TABLE statement
     */
    public function createTable(TableDefinition $table): string
    {
        $sql = "CREATE TABLE " . $this->quoteIdentifier($table->name) . " (\n";

        $parts = [];

        // Add columns
        foreach ($table->columns as $column) {
            $parts[] = '  ' . $this->buildColumnDefinition($column);
        }

        // Add primary key (if not already defined by column)
        if (!empty($table->primaryKey) && !$this->hasPrimaryKeyColumn($table->columns)) {
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $table->primaryKey);
            $parts[] = '  PRIMARY KEY (' . implode(', ', $quotedColumns) . ')';
        }

        // Add unique constraints
        foreach ($table->indexes as $index) {
            if ($index->type === 'unique') {
                $quotedColumns = array_map([$this, 'quoteIdentifier'], $index->columns);
                $parts[] = '  UNIQUE (' . implode(', ', $quotedColumns) . ')';
            }
        }

        // Add foreign keys (if enabled)
        foreach ($table->foreignKeys as $foreignKey) {
            $parts[] = '  ' . $this->buildForeignKeyDefinition($foreignKey);
        }

        $sql .= implode(",\n", $parts) . "\n";
        $sql .= ')';

        return $sql . ';';
    }

    /**
     * Generate ALTER TABLE statements for table modifications
     * Note: SQLite has very limited ALTER TABLE support
     *
     * @param TableDefinition $table Current table definition
     * @param array $changes Array of changes to apply
     * @return array Array of SQL statements
     */
    public function alterTable(TableDefinition $table, array $changes): array
    {
        $statements = [];
        $tableName = $this->quoteIdentifier($table->name);

        // Add columns (supported in SQLite)
        if (!empty($changes['add_columns'])) {
            foreach ($changes['add_columns'] as $column) {
                $columnDef = $this->buildColumnDefinition($column);
                $statements[] = "ALTER TABLE {$tableName} ADD COLUMN {$columnDef};";
            }
        }

        // Column modifications are very limited in SQLite
        // Most changes require recreating the table
        if (!empty($changes['modify_columns']) || !empty($changes['drop_columns'])) {
            $statements[] = "-- WARNING: SQLite does not support modifying/dropping columns directly.";
            $statements[] = "-- These operations require recreating the table.";
        }

        // Rename table (supported)
        if (!empty($changes['rename_table'])) {
            $newName = $this->quoteIdentifier($changes['rename_table']);
            $statements[] = "ALTER TABLE {$tableName} RENAME TO {$newName};";
        }

        // Add indexes (create separate statements)
        if (!empty($changes['add_indexes'])) {
            foreach ($changes['add_indexes'] as $index) {
                $statements[] = $this->createIndex($table->name, $index);
            }
        }

        // Drop indexes
        if (!empty($changes['drop_indexes'])) {
            foreach ($changes['drop_indexes'] as $indexName) {
                $statements[] = $this->dropIndex($table->name, $indexName);
            }
        }

        return $statements;
    }

    /**
     * Generate DROP TABLE statement
     *
     * @param string $table Table name
     * @param bool $ifExists Add IF EXISTS clause
     * @return string SQL DROP TABLE statement
     */
    public function dropTable(string $table, bool $ifExists = false): string
    {
        $sql = 'DROP TABLE ';
        if ($ifExists) {
            $sql .= 'IF EXISTS ';
        }
        $sql .= $this->quoteIdentifier($table);
        return $sql . ';';
    }

    /**
     * Generate RENAME TABLE statement
     *
     * @param string $from Current table name
     * @param string $to New table name
     * @return string SQL RENAME TABLE statement
     */
    public function renameTable(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($from) .
               ' RENAME TO ' . $this->quoteIdentifier($to) . ';';
    }

    // ===========================================
    // Column Operations
    // ===========================================

    /**
     * Generate ADD COLUMN statement
     *
     * @param string $table Table name
     * @param ColumnDefinition $column Column definition
     * @return string SQL ADD COLUMN statement
     */
    public function addColumn(string $table, ColumnDefinition $column): string
    {
        $tableName = $this->quoteIdentifier($table);
        $columnDef = $this->buildColumnDefinition($column);

        return "ALTER TABLE {$tableName} ADD COLUMN {$columnDef};";
    }

    /**
     * Generate column modification (not supported in SQLite)
     *
     * @param string $table Table name
     * @param ColumnDefinition $column New column definition
     * @return string SQL statement with warning
     */
    public function modifyColumn(string $table, ColumnDefinition $column): string
    {
        return "-- SQLite does not support modifying columns. Table recreation required.";
    }

    /**
     * Generate DROP COLUMN (not supported in SQLite)
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return string SQL statement with warning
     */
    public function dropColumn(string $table, string $column): string
    {
        return "-- SQLite does not support dropping columns. Table recreation required.";
    }

    /**
     * Generate RENAME COLUMN statement (SQLite 3.25.0+)
     *
     * @param string $table Table name
     * @param string $from Current column name
     * @param string $to New column name
     * @return string SQL RENAME COLUMN statement
     */
    public function renameColumn(string $table, string $from, string $to): string
    {
        $tableName = $this->quoteIdentifier($table);
        $fromColumn = $this->quoteIdentifier($from);
        $toColumn = $this->quoteIdentifier($to);

        return "ALTER TABLE {$tableName} RENAME COLUMN {$fromColumn} TO {$toColumn};";
    }

    // ===========================================
    // Index Operations
    // ===========================================

    /**
     * Generate CREATE INDEX statement
     *
     * @param string $table Table name
     * @param IndexDefinition $index Index definition
     * @return string SQL CREATE INDEX statement
     */
    public function createIndex(string $table, IndexDefinition $index): string
    {
        $tableName = $this->quoteIdentifier($table);

        if ($index->type === 'unique') {
            $sql = "CREATE UNIQUE INDEX ";
        } else {
            $sql = "CREATE INDEX ";
        }

        $sql .= $this->quoteIdentifier($index->name);
        $sql .= " ON {$tableName}";

        // Add columns
        $columns = [];
        foreach ($index->columns as $column) {
            $quotedColumn = $this->quoteIdentifier($column);

            // SQLite supports collation in indexes
            $order = $index->getColumnOrder($column);
            if ($order !== 'ASC') {
                $quotedColumn .= ' ' . $order;
            }

            $columns[] = $quotedColumn;
        }

        $sql .= ' (' . implode(', ', $columns) . ')';

        return $sql . ';';
    }

    /**
     * Generate DROP INDEX statement
     *
     * @param string $table Table name (not used in SQLite)
     * @param string $index Index name
     * @return string SQL DROP INDEX statement
     */
    public function dropIndex(string $table, string $index): string
    {
        $indexName = $this->quoteIdentifier($index);
        return "DROP INDEX {$indexName};";
    }

    // ===========================================
    // Foreign Key Operations
    // ===========================================

    /**
     * Generate ADD FOREIGN KEY (not supported after table creation in SQLite)
     *
     * @param string $table Table name
     * @param ForeignKeyDefinition $foreignKey Foreign key definition
     * @return string SQL statement with warning
     */
    public function addForeignKey(string $table, ForeignKeyDefinition $foreignKey): string
    {
        return "-- SQLite does not support adding foreign keys after table creation.";
    }

    /**
     * Generate DROP FOREIGN KEY (not supported in SQLite)
     *
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @return string SQL statement with warning
     */
    public function dropForeignKey(string $table, string $constraint): string
    {
        return "-- SQLite does not support dropping foreign key constraints.";
    }

    // ===========================================
    // Database Operations
    // ===========================================

    /**
     * Generate CREATE DATABASE statement (not applicable to SQLite)
     *
     * @param string $database Database name
     * @param array $options Database options
     * @return string Comment explaining SQLite behavior
     */
    public function createDatabase(string $database, array $options = []): string
    {
        return "-- SQLite creates database files automatically when first accessed.";
    }

    /**
     * Generate DROP DATABASE statement (not applicable to SQLite)
     *
     * @param string $database Database name
     * @param bool $ifExists Add IF EXISTS clause
     * @return string Comment explaining SQLite behavior
     */
    public function dropDatabase(string $database, bool $ifExists = false): string
    {
        return "-- To drop an SQLite database, delete the database file from the filesystem.";
    }

    // ===========================================
    // Utility Methods
    // ===========================================

    /**
     * Map abstract column type to SQLite-specific type
     * SQLite has a simple type system: TEXT, INTEGER, REAL, BLOB, NULL
     *
     * @param string $type Abstract type (string, integer, etc.)
     * @param array $options Type options (length, precision, etc.)
     * @return string SQLite-specific type
     */
    public function mapColumnType(string $type, array $options = []): string
    {
        return match ($type) {
            'id' => 'INTEGER',
            'foreignId' => 'INTEGER',
            'string', 'varchar', 'char' => 'TEXT',
            'text', 'longText', 'mediumText', 'tinyText' => 'TEXT',
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger' => 'INTEGER',
            'decimal', 'numeric', 'float', 'double' => 'REAL',
            'boolean' => 'INTEGER',
            'timestamp', 'datetime', 'date', 'time', 'year' => 'TEXT',
            'json' => 'TEXT',
            'uuid' => 'TEXT',
            'enum' => 'TEXT',
            'binary', 'varbinary', 'blob', 'longBlob', 'mediumBlob', 'tinyBlob' => 'BLOB',
            default => 'TEXT'
        };
    }

    /**
     * Quote identifier (table name, column name, etc.)
     *
     * @param string $identifier Identifier to quote
     * @return string Quoted identifier
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Quote and escape string value
     *
     * @param mixed $value Value to quote
     * @return string Quoted value
     */
    public function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Format default value for column definition
     *
     * @param mixed $value Default value
     * @param string $type Column type
     * @return string Formatted default value
     */
    public function formatDefaultValue(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        // Handle raw expressions
        if (
            is_string($value) && (
            stripos($value, 'CURRENT_TIMESTAMP') !== false ||
            stripos($value, 'datetime(') !== false ||
            stripos($value, 'date(') !== false
            )
        ) {
            return $value;
        }

        return $this->quoteValue($value);
    }

    /**
     * Get foreign key constraint checking SQL
     *
     * @param bool $enabled Whether to enable or disable checks
     * @return string SQL statement to enable/disable foreign key checks
     */
    public function foreignKeyChecks(bool $enabled): string
    {
        return 'PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF') . ';';
    }

    /**
     * Generate table exists check query
     *
     * @param string $table Table name
     * @return string SQL query to check if table exists
     */
    public function tableExistsQuery(string $table): string
    {
        return "SELECT COUNT(*) FROM sqlite_master " .
               "WHERE type='table' AND name = " . $this->quoteValue($table);
    }

    /**
     * Generate column exists check query
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return string SQL query to check if column exists
     */
    public function columnExistsQuery(string $table, string $column): string
    {
        return "SELECT COUNT(*) FROM pragma_table_info(" . $this->quoteValue($table) . ") " .
               "WHERE name = " . $this->quoteValue($column);
    }

    /**
     * Generate query to get table schema information
     *
     * @param string $table Table name
     * @return string SQL query to get table schema
     */
    public function getTableSchemaQuery(string $table): string
    {
        return "PRAGMA table_info(" . $this->quoteValue($table) . ")";
    }

    /**
     * Generate query to list all tables
     *
     * @return string SQL query to list tables
     */
    public function getTablesQuery(): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name";
    }

    // ===========================================
    // Private Helper Methods
    // ===========================================

    /**
     * Check if any column is already defined as primary key
     *
     * @param array $columns Array of ColumnDefinition objects
     * @return bool True if a primary key column exists
     */
    private function hasPrimaryKeyColumn(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column->primary || $column->type === 'id') {
                return true;
            }
        }
        return false;
    }

    /**
     * Build column definition string
     *
     * @param ColumnDefinition $column Column definition
     * @return string Column definition SQL
     */
    private function buildColumnDefinition(ColumnDefinition $column): string
    {
        $parts = [];

        // Column name and type
        $parts[] = $this->quoteIdentifier($column->name);
        $parts[] = $this->mapColumnType($column->type, [
            'length' => $column->length,
            'precision' => $column->precision,
            'scale' => $column->scale,
            'values' => $column->options['values'] ?? [],
            ...$column->options
        ]);

        // Primary key and auto increment
        if ($column->primary || $column->type === 'id') {
            $parts[] = 'PRIMARY KEY';

            if ($column->autoIncrement) {
                $parts[] = 'AUTOINCREMENT';
            }
        }

        // Nullability
        if (!$column->nullable && !$column->primary && $column->type !== 'id') {
            $parts[] = 'NOT NULL';
        }

        // Default value
        if ($column->hasDefault() && !$column->autoIncrement) {
            $parts[] = 'DEFAULT ' . $this->formatDefaultValue($column->getDefaultValue(), $column->type);
        }

        // Unique constraint
        if ($column->unique && !$column->primary) {
            $parts[] = 'UNIQUE';
        }

        // Check constraint
        if ($column->check) {
            $parts[] = 'CHECK (' . $column->check . ')';
        }

        // Enum check constraint for SQLite
        if ($column->type === 'enum' && !empty($column->options['values'])) {
            $quotedValues = array_map([$this, 'quoteValue'], $column->options['values']);
            $enumCheck = $this->quoteIdentifier($column->name) . ' IN (' . implode(', ', $quotedValues) . ')';
            $parts[] = 'CHECK (' . $enumCheck . ')';
        }

        return implode(' ', $parts);
    }

    /**
     * Build foreign key definition string
     *
     * @param ForeignKeyDefinition $foreignKey Foreign key definition
     * @return string Foreign key definition SQL
     */
    private function buildForeignKeyDefinition(ForeignKeyDefinition $foreignKey): string
    {
        $parts = [];

        $parts[] = 'FOREIGN KEY';
        $parts[] = '(' . $this->quoteIdentifier($foreignKey->localColumn) . ')';
        $parts[] = 'REFERENCES';
        $parts[] = $this->quoteIdentifier($foreignKey->referencedTable);
        $parts[] = '(' . $this->quoteIdentifier($foreignKey->referencedColumn) . ')';

        if ($foreignKey->onDelete) {
            $parts[] = 'ON DELETE ' . strtoupper($foreignKey->onDelete);
        }

        if ($foreignKey->onUpdate) {
            $parts[] = 'ON UPDATE ' . strtoupper($foreignKey->onUpdate);
        }

        return implode(' ', $parts);
    }

    /**
     * Generate query to get table size in bytes
     *
     * @param string $table Table name
     * @return string SQL query to get table size
     */
    public function getTableSizeQuery(string $table): string
    {
        // SQLite doesn't have a direct way to get table size
        // We'll use page_size * page_count as an approximation
        return "SELECT (SELECT COUNT(*) FROM pragma_page_count()) * (SELECT * FROM pragma_page_size()) as size";
    }

    /**
     * Generate query to get table row count
     *
     * @param string $table Table name
     * @return string SQL query to get row count
     */
    public function getTableRowCountQuery(string $table): string
    {
        return "SELECT COUNT(*) as count FROM " . $this->quoteIdentifier($table);
    }

    /**
     * Get table columns with comprehensive information
     *
     * @param string $table Table name
     * @param \PDO $pdo PDO connection for executing queries
     * @return array Array of column information with standardized format
     */
    public function getTableColumns(string $table, \PDO $pdo): array
    {
        // Get basic column information from PRAGMA table_info
        $stmt = $pdo->prepare("PRAGMA table_info(" . $this->quoteIdentifier($table) . ");");
        $stmt->execute();
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Format columns into a more usable structure with column name as key
        $formattedColumns = [];
        foreach ($columns as $column) {
            $columnName = $column['name'];
            $formattedColumns[$columnName] = [
                'name' => $columnName,
                'type' => $column['type'],
                'nullable' => $column['notnull'] == 0,
                'default' => $column['dflt_value'],
                'is_primary' => $column['pk'] == 1,
                'is_unique' => false, // Will be populated later
                'is_indexed' => false, // Will be populated later
                'relationships' => [],
                'indexes' => []
            ];
        }

        // Get index information
        try {
            // First get all indexes for the table
            $indexListStmt = $pdo->prepare("PRAGMA index_list(" . $this->quoteIdentifier($table) . ");");
            $indexListStmt->execute();
            $indexes = $indexListStmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($indexes as $index) {
                $indexName = $index['name'];
                $isUnique = $index['unique'] == 1;

                // Skip SQLite's auto-generated indexes for PRIMARY KEY
                if (preg_match('/^sqlite_autoindex_/', $indexName)) {
                    continue;
                }

                // Get columns in this index
                $indexInfoStmt = $pdo->prepare("PRAGMA index_info(" . $this->quoteIdentifier($indexName) . ");");
                $indexInfoStmt->execute();
                $indexedColumns = $indexInfoStmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($indexedColumns as $indexedColumn) {
                    $columnName = $indexedColumn['name'];
                    if (isset($formattedColumns[$columnName])) {
                        $formattedColumns[$columnName]['is_indexed'] = true;
                        if ($isUnique) {
                            $formattedColumns[$columnName]['is_unique'] = true;
                        }
                        $formattedColumns[$columnName]['indexes'][] = [
                            'name' => $indexName,
                            'type' => $isUnique ? 'UNIQUE' : 'INDEX',
                            'sequence' => $indexedColumn['seqno']
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue without index information
        }

        // Get foreign key relationships
        try {
            $fkStmt = $pdo->prepare("PRAGMA foreign_key_list(" . $this->quoteIdentifier($table) . ");");
            $fkStmt->execute();
            $foreignKeys = $fkStmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($foreignKeys as $fk) {
                $columnName = $fk['from'];
                if (isset($formattedColumns[$columnName])) {
                    // SQLite doesn't name constraints, create synthetic name
                    $constraintName = "fk_{$table}_{$columnName}_{$fk['id']}";

                    $formattedColumns[$columnName]['relationships'][] = [
                        'constraint' => $constraintName,
                        'column' => $columnName,
                        'references_table' => $fk['table'],
                        'references_column' => $fk['to'],
                        'on_update' => $fk['on_update'],
                        'on_delete' => $fk['on_delete']
                    ];
                }
            }
        } catch (\Exception $e) {
            // Continue without foreign key information
        }

        return array_values($formattedColumns); // Convert back to indexed array
    }

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
    public function generateChangePreview(string $table, array $changes): array
    {
        $sql = [];
        $warnings = [];
        $estimatedDuration = 0;

        foreach ($changes as $change) {
            $changeType = $change['type'] ?? 'unknown';

            switch ($changeType) {
                case 'add_column':
                    $columnName = $change['column_name'];
                    $definition = $change['definition'] ?? 'TEXT';
                    $sql[] = "ALTER TABLE " . $this->quoteIdentifier($table) .
                           " ADD COLUMN " . $this->quoteIdentifier($columnName) . " {$definition}";
                    $estimatedDuration += 3;
                    break;

                case 'drop_column':
                    $columnName = $change['column_name'];
                    $sql[] = "-- SQLite: Cannot drop column '{$columnName}' directly. Table recreation required.";
                    $warnings[] = "SQLite does not support dropping columns. This operation requires " .
                                "recreating the table with all data migration";
                    $estimatedDuration += 30;
                    break;

                case 'modify_column':
                    $columnName = $change['column_name'];
                    $sql[] = "-- SQLite: Cannot modify column '{$columnName}' directly. Table recreation required.";
                    $warnings[] = "SQLite does not support modifying columns. This operation requires " .
                                "recreating the table with all data migration";
                    $estimatedDuration += 30;
                    break;

                case 'add_index':
                    $indexName = $change['index_name'] ?? "{$table}_{$change['column']}_idx";
                    $column = $change['column'];
                    $unique = ($change['unique'] ?? false) ? 'UNIQUE ' : '';
                    $sql[] = "CREATE {$unique}INDEX " . $this->quoteIdentifier($indexName) .
                           " ON " . $this->quoteIdentifier($table) . " (" . $this->quoteIdentifier($column) . ")";
                    $estimatedDuration += 10;
                    break;

                case 'drop_index':
                    $indexName = $change['index_name'];
                    $sql[] = "DROP INDEX " . $this->quoteIdentifier($indexName);
                    $estimatedDuration += 2;
                    break;

                default:
                    $warnings[] = "Unknown change type: {$changeType}";
            }
        }

        return [
            'sql' => $sql,
            'warnings' => $warnings,
            'estimated_duration' => $estimatedDuration,
            'safe_to_execute' => count($warnings) === 0,
            'generated_at' => date('Y-m-d H:i:s'),
            'database_engine' => 'sqlite',
            'limitations' => [
                'Column modifications require table recreation',
                'Dropping columns requires table recreation',
                'Foreign key constraints cannot be added after table creation'
            ]
        ];
    }

    /**
     * Export table schema in specified format
     *
     * @param string $table Table name
     * @param string $format Export format
     * @param array $schema Table schema data
     * @return array Exported schema
     */
    public function exportTableSchema(string $table, string $format, array $schema): array
    {
        switch ($format) {
            case 'json':
                return [
                    'table' => $table,
                    'engine' => 'sqlite',
                    'columns' => $schema,
                    'format' => 'json',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'version' => '1.0',
                    'limitations' => [
                        'Simple type system (TEXT, INTEGER, REAL, BLOB)',
                        'Limited ALTER TABLE support',
                        'Foreign keys must be enabled with PRAGMA'
                    ]
                ];

            case 'sql':
                return [
                    'table' => $table,
                    'sql' => $this->generateCreateTableFromSchema($table, $schema),
                    'format' => 'sql',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'database_engine' => 'sqlite'
                ];

            default:
                throw new \InvalidArgumentException("Unsupported export format: {$format}");
        }
    }

    /**
     * Validate schema definition
     *
     * @param array $schema Schema to validate
     * @param string $format Schema format
     * @return array Validation result
     */
    public function validateSchema(array $schema, string $format): array
    {
        return $this->commonValidateSchema($schema, $format);
    }

    /**
     * Generate revert operations for a change
     *
     * @param array $change Original change
     * @return array Revert operations
     */
    public function generateRevertOperations(array $change): array
    {
        return $this->commonGenerateRevertOperations($change);
    }

    /**
     * Generate CREATE TABLE SQL from schema
     *
     * @param string $table Table name
     * @param array $schema Schema definition
     * @return string CREATE TABLE SQL
     */
    private function generateCreateTableFromSchema(string $table, array $schema): string
    {
        $columns = [];

        foreach ($schema as $column) {
            $columnName = $column['name'] ?? $column['column_name'] ?? '';
            $columnType = $this->mapColumnType($column['type'] ?? $column['data_type'] ?? 'TEXT');
            $nullable = ($column['notnull'] ?? $column['nullable'] ?? true) ? '' : ' NOT NULL';
            $default = !empty($column['dflt_value']) ? " DEFAULT {$column['dflt_value']}" : '';
            $pk = ($column['pk'] ?? false) ? ' PRIMARY KEY' : '';

            $columns[] = $this->quoteIdentifier($columnName) . " {$columnType}{$nullable}{$default}{$pk}";
        }

        return "CREATE TABLE " . $this->quoteIdentifier($table) . " (\n  " . implode(",\n  ", $columns) . "\n);";
    }

    /**
     * Common validation logic
     */
    private function commonValidateSchema(array $schema, string $format): array
    {
        $errors = [];
        $warnings = [];

        if (empty($schema)) {
            $errors[] = 'Schema cannot be empty';
        }

        if ($format === 'json') {
            if (!isset($schema['table'])) {
                $errors[] = 'Table name is required';
            }
            if (!isset($schema['columns']) || !is_array($schema['columns'])) {
                $errors[] = 'Columns definition is required and must be an array';
            } else {
                foreach ($schema['columns'] as $column) {
                    if (empty($column['name'] ?? $column['column_name'])) {
                        $errors[] = 'Column name is required';
                    }
                }
            }
        }

        // SQLite-specific warnings
        $warnings[] = 'SQLite uses a simple type system - all types are mapped to TEXT, INTEGER, REAL, or BLOB';

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Common revert operations logic
     */
    private function commonGenerateRevertOperations(array $change): array
    {
        $revertOps = [];
        $changeType = $change['action'] ?? $change['type'] ?? 'unknown';

        switch ($changeType) {
            case 'add_column':
                $revertOps[] = [
                    'type' => 'drop_column',
                    'table' => $change['table'],
                    'column_name' => $change['column_name'],
                    'warning' => 'SQLite requires table recreation to drop columns'
                ];
                break;

            case 'drop_column':
                $revertOps[] = [
                    'type' => 'add_column',
                    'table' => $change['table'],
                    'column_name' => $change['column_name'],
                    'definition' => $change['original_definition'] ?? 'TEXT',
                    'warning' => 'Original column data will be lost in SQLite'
                ];
                break;

            case 'add_index':
                $revertOps[] = [
                    'type' => 'drop_index',
                    'table' => $change['table'],
                    'index_name' => $change['index_name']
                ];
                break;

            case 'drop_index':
                $revertOps[] = [
                    'type' => 'add_index',
                    'table' => $change['table'],
                    'index_name' => $change['index_name'],
                    'column' => $change['column'] ?? 'id'
                ];
                break;

            default:
                $revertOps[] = [
                    'type' => 'unknown',
                    'message' => "Cannot generate revert operation for: {$changeType}"
                ];
        }

        return $revertOps;
    }

    /**
     * Import table schema from definition
     *
     * @param string $table Table name
     * @param array $schema Schema definition
     * @param string $format Schema format
     * @param array $options Import options
     * @return array Import result with SQL statements
     */
    public function importTableSchema(string $table, array $schema, string $format, array $options): array
    {
        $sql = [];
        $warnings = [];

        try {
            switch ($format) {
                case 'json':
                    if (!isset($schema['columns'])) {
                        return [
                            'success' => false,
                            'errors' => ['Columns definition is required'],
                            'sql' => []
                        ];
                    }

                    // Generate CREATE TABLE statement
                    $columns = [];

                    foreach ($schema['columns'] as $column) {
                        $columnName = $column['name'] ?? $column['column_name'] ?? '';
                        $columnType = $this->mapColumnType($column['type'] ?? $column['data_type'] ?? 'TEXT');
                        $nullable = ($column['notnull'] ?? !($column['nullable'] ?? true)) ? ' NOT NULL' : '';
                        $default = !empty($column['dflt_value']) ? " DEFAULT {$column['dflt_value']}" : '';
                        $pk = ($column['pk'] ?? false) ? ' PRIMARY KEY' : '';
                        $unique = ($column['unique'] ?? false) && !($column['pk'] ?? false) ? ' UNIQUE' : '';

                        $columns[] = $this->quoteIdentifier($columnName) .
                                   " {$columnType}{$nullable}{$default}{$pk}{$unique}";
                    }

                    // Build CREATE TABLE statement
                    $createTable = "CREATE TABLE " . $this->quoteIdentifier($table) .
                                 " (\n  " . implode(",\n  ", $columns) . "\n)";

                    $sql[] = $createTable;

                    // Add warning about SQLite limitations
                    $warnings[] = 'SQLite has limited ALTER TABLE support - ' .
                                'modifications may require table recreation';
                    break;

                case 'sql':
                    // For SQL format, we expect the schema to contain the SQL directly
                    if (isset($schema['sql'])) {
                        $sql[] = $schema['sql'];
                    } else {
                        return [
                            'success' => false,
                            'errors' => ['SQL statement is required for SQL format'],
                            'sql' => []
                        ];
                    }
                    break;

                default:
                    return [
                        'success' => false,
                        'errors' => ["Unsupported import format: {$format}"],
                        'sql' => []
                    ];
            }

            return [
                'success' => true,
                'sql' => $sql,
                'warnings' => $warnings,
                'table' => $table,
                'format' => $format,
                'database_engine' => 'sqlite',
                'limitations' => [
                    'Simple type system (TEXT, INTEGER, REAL, BLOB)',
                    'Limited ALTER TABLE support',
                    'Foreign keys must be enabled with PRAGMA'
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()],
                'sql' => []
            ];
        }
    }
}
