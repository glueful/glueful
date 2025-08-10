<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Generators;

use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * PostgreSQL SQL Generator Implementation
 *
 * Generates PostgreSQL-specific SQL statements from schema definitions.
 * Handles PostgreSQL syntax, data types, and engine-specific features.
 *
 * Features:
 * - PostgreSQL data type mapping
 * - SERIAL and BIGSERIAL support
 * - Schema and namespace handling
 * - Advanced constraint options (deferrable)
 * - Proper identifier quoting
 * - JSONB and array type support
 *
 * Example output:
 * ```sql
 * CREATE TABLE "users" (
 *   "id" BIGSERIAL PRIMARY KEY,
 *   "email" VARCHAR(255) NOT NULL,
 *   CONSTRAINT "users_email_unique" UNIQUE ("email")
 * );
 * ```
 */
class PostgreSQLSqlGenerator implements SqlGeneratorInterface
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

        // Add indexes (as constraints in table definition)
        foreach ($table->indexes as $index) {
            if ($index->type === 'unique') {
                $quotedColumns = array_map([$this, 'quoteIdentifier'], $index->columns);
                $constraintName = $this->quoteIdentifier($index->name);
                $parts[] = '  CONSTRAINT ' . $constraintName . ' UNIQUE (' . implode(', ', $quotedColumns) . ')';
            }
        }

        // Add foreign keys
        foreach ($table->foreignKeys as $foreignKey) {
            $parts[] = '  ' . $this->buildForeignKeyDefinition($foreignKey);
        }

        $sql .= implode(",\n", $parts) . "\n";
        $sql .= ')';

        // Add table options
        $sql .= $this->buildTableOptions($table);

        return $sql . ';';
    }

    /**
     * Generate ALTER TABLE statements for table modifications
     *
     * @param TableDefinition $table Current table definition
     * @param array $changes Array of changes to apply
     * @return array Array of SQL statements
     */
    public function alterTable(TableDefinition $table, array $changes): array
    {
        $statements = [];
        $tableName = $this->quoteIdentifier($table->name);

        // Add columns
        if (!empty($changes['add_columns'])) {
            foreach ($changes['add_columns'] as $column) {
                $columnDef = $this->buildColumnDefinition($column);
                $statements[] = "ALTER TABLE {$tableName} ADD COLUMN {$columnDef};";
            }
        }

        // Modify columns
        if (!empty($changes['modify_columns'])) {
            foreach ($changes['modify_columns'] as $column) {
                $statements = array_merge($statements, $this->buildModifyColumnStatements($table->name, $column));
            }
        }

        // Drop columns
        if (!empty($changes['drop_columns'])) {
            foreach ($changes['drop_columns'] as $columnName) {
                $quotedColumn = $this->quoteIdentifier($columnName);
                $statements[] = "ALTER TABLE {$tableName} DROP COLUMN {$quotedColumn};";
            }
        }

        // Add indexes
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

        // Add foreign keys
        if (!empty($changes['add_foreign_keys'])) {
            foreach ($changes['add_foreign_keys'] as $foreignKey) {
                $statements[] = $this->addForeignKey($table->name, $foreignKey);
            }
        }

        // Drop foreign keys
        if (!empty($changes['drop_foreign_keys'])) {
            foreach ($changes['drop_foreign_keys'] as $constraintName) {
                $statements[] = $this->dropForeignKey($table->name, $constraintName);
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
     * Generate ALTER COLUMN statements (PostgreSQL requires multiple statements)
     *
     * @param string $table Table name
     * @param ColumnDefinition $column New column definition
     * @return string SQL ALTER COLUMN statement
     */
    public function modifyColumn(string $table, ColumnDefinition $column): string
    {
        $statements = $this->buildModifyColumnStatements($table, $column);
        return implode("\n", $statements);
    }

    /**
     * Generate DROP COLUMN statement
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return string SQL DROP COLUMN statement
     */
    public function dropColumn(string $table, string $column): string
    {
        $tableName = $this->quoteIdentifier($table);
        $columnName = $this->quoteIdentifier($column);

        return "ALTER TABLE {$tableName} DROP COLUMN {$columnName};";
    }

    /**
     * Generate RENAME COLUMN statement
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

        // Add method if specified
        if ($index->algorithm) {
            $method = strtolower($index->algorithm);
            $sql .= " USING {$method}";
        }

        // Add columns
        $columns = [];
        foreach ($index->columns as $column) {
            $quotedColumn = $this->quoteIdentifier($column);

            // PostgreSQL supports expressions in indexes
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
     * @param string $table Table name
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
     * Generate ADD FOREIGN KEY statement
     *
     * @param string $table Table name
     * @param ForeignKeyDefinition $foreignKey Foreign key definition
     * @return string SQL ADD FOREIGN KEY statement
     */
    public function addForeignKey(string $table, ForeignKeyDefinition $foreignKey): string
    {
        $tableName = $this->quoteIdentifier($table);
        $constraintName = $this->quoteIdentifier($foreignKey->name);
        $localColumn = $this->quoteIdentifier($foreignKey->localColumn);
        $referencedTable = $this->quoteIdentifier($foreignKey->referencedTable);
        $referencedColumn = $this->quoteIdentifier($foreignKey->referencedColumn);

        $sql = "ALTER TABLE {$tableName} ";
        $sql .= "ADD CONSTRAINT {$constraintName} ";
        $sql .= "FOREIGN KEY ({$localColumn}) ";
        $sql .= "REFERENCES {$referencedTable} ({$referencedColumn})";

        if ($foreignKey->onDelete) {
            $sql .= ' ON DELETE ' . strtoupper($foreignKey->onDelete);
        }

        if ($foreignKey->onUpdate) {
            $sql .= ' ON UPDATE ' . strtoupper($foreignKey->onUpdate);
        }

        // PostgreSQL-specific options
        if ($foreignKey->deferrable) {
            $sql .= ' DEFERRABLE';

            if ($foreignKey->initiallyDeferred) {
                $sql .= ' INITIALLY DEFERRED';
            } else {
                $sql .= ' INITIALLY IMMEDIATE';
            }
        }

        return $sql . ';';
    }

    /**
     * Generate DROP CONSTRAINT statement
     *
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @return string SQL DROP CONSTRAINT statement
     */
    public function dropForeignKey(string $table, string $constraint): string
    {
        $tableName = $this->quoteIdentifier($table);
        $constraintName = $this->quoteIdentifier($constraint);

        return "ALTER TABLE {$tableName} DROP CONSTRAINT {$constraintName};";
    }

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
    public function createDatabase(string $database, array $options = []): string
    {
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($database);

        if (isset($options['owner'])) {
            $sql .= ' OWNER ' . $this->quoteIdentifier($options['owner']);
        }

        if (isset($options['encoding'])) {
            $sql .= ' ENCODING ' . $this->quoteValue($options['encoding']);
        }

        if (isset($options['template'])) {
            $sql .= ' TEMPLATE ' . $this->quoteIdentifier($options['template']);
        }

        return $sql . ';';
    }

    /**
     * Generate DROP DATABASE statement
     *
     * @param string $database Database name
     * @param bool $ifExists Add IF EXISTS clause
     * @return string SQL DROP DATABASE statement
     */
    public function dropDatabase(string $database, bool $ifExists = false): string
    {
        $sql = 'DROP DATABASE ';
        if ($ifExists) {
            $sql .= 'IF EXISTS ';
        }
        $sql .= $this->quoteIdentifier($database);
        return $sql . ';';
    }

    // ===========================================
    // Utility Methods
    // ===========================================

    /**
     * Map abstract column type to PostgreSQL-specific type
     *
     * @param string $type Abstract type (string, integer, etc.)
     * @param array $options Type options (length, precision, etc.)
     * @return string PostgreSQL-specific type
     */
    public function mapColumnType(string $type, array $options = []): string
    {
        return match ($type) {
            'id' => 'BIGSERIAL',
            'foreignId' => 'BIGINT',
            'string', 'varchar' => 'VARCHAR(' . ($options['length'] ?? 255) . ')',
            'char' => 'CHAR(' . ($options['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'longText' => 'TEXT',
            'mediumText' => 'TEXT',
            'tinyText' => 'TEXT',
            'integer' => ($options['autoIncrement'] ?? false) ? 'SERIAL' : 'INTEGER',
            'bigInteger' => ($options['autoIncrement'] ?? false) ? 'BIGSERIAL' : 'BIGINT',
            'smallInteger' => ($options['autoIncrement'] ?? false) ? 'SMALLSERIAL' : 'SMALLINT',
            'tinyInteger' => 'SMALLINT',
            'decimal', 'numeric' => 'DECIMAL(' . ($options['precision'] ?? 8) . ',' . ($options['scale'] ?? 2) . ')',
            'float' => isset($options['precision']) && isset($options['scale'])
                ? 'DECIMAL(' . $options['precision'] . ',' . $options['scale'] . ')'
                : 'REAL',
            'double' => isset($options['precision']) && isset($options['scale'])
                ? 'DECIMAL(' . $options['precision'] . ',' . $options['scale'] . ')'
                : 'DOUBLE PRECISION',
            'boolean' => 'BOOLEAN',
            'timestamp' => 'TIMESTAMP',
            'datetime' => 'TIMESTAMP',
            'date' => 'DATE',
            'time' => 'TIME',
            'year' => 'INTEGER',
            'json' => 'JSONB',
            'uuid' => 'UUID',
            'enum' => 'VARCHAR(255)',
            'binary' => 'BYTEA',
            'varbinary' => 'BYTEA',
            'blob' => 'BYTEA',
            'longBlob' => 'BYTEA',
            'mediumBlob' => 'BYTEA',
            'tinyBlob' => 'BYTEA',
            default => strtoupper($type)
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
            return $value ? 'TRUE' : 'FALSE';
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
            stripos($value, 'NOW()') !== false ||
            stripos($value, 'uuid_generate_v4()') !== false
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
        // PostgreSQL doesn't have a global foreign key check toggle like MySQL
        // This would typically be handled at the session level
        return $enabled ?
            "SET session_replication_role = 'origin';" :
            "SET session_replication_role = 'replica';";
    }

    /**
     * Generate table exists check query
     *
     * @param string $table Table name
     * @return string SQL query to check if table exists
     */
    public function tableExistsQuery(string $table): string
    {
        return "SELECT COUNT(*) FROM information_schema.tables " .
               "WHERE table_schema = 'public' AND table_name = " . $this->quoteValue($table);
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
        return "SELECT COUNT(*) FROM information_schema.columns " .
               "WHERE table_schema = 'public' AND table_name = " . $this->quoteValue($table) .
               " AND column_name = " . $this->quoteValue($column);
    }

    /**
     * Generate query to get table schema information
     *
     * @param string $table Table name
     * @return string SQL query to get table schema
     */
    public function getTableSchemaQuery(string $table): string
    {
        return "SELECT * FROM information_schema.columns " .
               "WHERE table_schema = 'public' AND table_name = " . $this->quoteValue($table) .
               " ORDER BY ordinal_position";
    }

    /**
     * Generate query to list all tables
     *
     * @return string SQL query to list tables
     */
    public function getTablesQuery(): string
    {
        return "SELECT table_name FROM information_schema.tables " .
               "WHERE table_schema = 'public' ORDER BY table_name";
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
            'autoIncrement' => $column->autoIncrement,
            'values' => $column->options['values'] ?? [],
            ...$column->options
        ]);

        // Nullability
        if (!$column->nullable) {
            $parts[] = 'NOT NULL';
        }

        // Default value
        if ($column->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->formatDefaultValue($column->getDefaultValue(), $column->type);
        }

        // Primary key (for single column)
        if ($column->primary) {
            $parts[] = 'PRIMARY KEY';
        }

        // Check constraint
        if ($column->check) {
            $parts[] = 'CHECK (' . $column->check . ')';
        }

        // Enum check constraint for PostgreSQL
        if ($column->type === 'enum' && !empty($column->options['values'])) {
            $quotedValues = array_map([$this, 'quoteValue'], $column->options['values']);
            $enumCheck = $this->quoteIdentifier($column->name) . ' IN (' . implode(', ', $quotedValues) . ')';
            $parts[] = 'CHECK (' . $enumCheck . ')';
        }

        return implode(' ', $parts);
    }

    /**
     * Build multiple ALTER COLUMN statements for PostgreSQL
     *
     * @param string $table Table name
     * @param ColumnDefinition $column Column definition
     * @return array Array of SQL statements
     */
    private function buildModifyColumnStatements(string $table, ColumnDefinition $column): array
    {
        $tableName = $this->quoteIdentifier($table);
        $columnName = $this->quoteIdentifier($column->name);
        $statements = [];

        // Change data type
        $newType = $this->mapColumnType($column->type, [
            'length' => $column->length,
            'precision' => $column->precision,
            'scale' => $column->scale,
            'autoIncrement' => $column->autoIncrement,
            'values' => $column->options['values'] ?? [],
            ...$column->options
        ]);
        $statements[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} TYPE {$newType};";

        // Change nullability
        if ($column->nullable) {
            $statements[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} DROP NOT NULL;";
        } else {
            $statements[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} SET NOT NULL;";
        }

        // Change default
        if ($column->hasDefault()) {
            $default = $this->formatDefaultValue($column->getDefaultValue(), $column->type);
            $statements[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} SET DEFAULT {$default};";
        } else {
            $statements[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} DROP DEFAULT;";
        }

        return $statements;
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

        $parts[] = 'CONSTRAINT';
        $parts[] = $this->quoteIdentifier($foreignKey->name);
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

        if ($foreignKey->deferrable) {
            $parts[] = 'DEFERRABLE';

            if ($foreignKey->initiallyDeferred) {
                $parts[] = 'INITIALLY DEFERRED';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Build table options string
     *
     * @param TableDefinition $table Table definition
     * @return string Table options SQL
     */
    private function buildTableOptions(TableDefinition $table): string
    {
        // PostgreSQL has fewer table-level options compared to MySQL
        // Most configuration is done at the database or tablespace level
        return '';
    }

    /**
     * Generate query to get table size in bytes
     *
     * @param string $table Table name
     * @return string SQL query to get table size
     */
    public function getTableSizeQuery(string $table): string
    {
        return "SELECT pg_total_relation_size(" . $this->quoteValue($table) . ") as size";
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
        // Get basic column information from information_schema
        $columnQuery = "
            SELECT 
                column_name, 
                data_type, 
                is_nullable, 
                column_default,
                character_maximum_length,
                udt_name,
                is_identity,
                is_generated
            FROM information_schema.columns 
            WHERE table_name = :table
            ORDER BY ordinal_position
        ";
        $columnStmt = $pdo->prepare($columnQuery);
        $columnStmt->execute(['table' => $table]);
        $columns = $columnStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Format columns into a more usable structure with column name as key
        $formattedColumns = [];
        foreach ($columns as $column) {
            $columnName = $column['column_name'];
            $formattedColumns[$columnName] = [
                'name' => $columnName,
                'type' => $column['data_type'],
                'udt_name' => $column['udt_name'],
                'nullable' => $column['is_nullable'] === 'YES',
                'default' => $column['column_default'],
                'max_length' => $column['character_maximum_length'],
                'is_identity' => $column['is_identity'] === 'YES',
                'is_generated' => $column['is_generated'] !== 'NEVER',
                'is_primary' => false,
                'is_unique' => false,
                'is_indexed' => false,
                'relationships' => [],
                'indexes' => []
            ];
        }

        // Get primary key constraints
        try {
            $pkQuery = "
                SELECT 
                    a.attname as column_name
                FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
                JOIN pg_class t ON t.oid = c.conrelid
                WHERE c.contype = 'p' 
                  AND t.relname = :table
            ";
            $pkStmt = $pdo->prepare($pkQuery);
            $pkStmt->execute(['table' => $table]);
            $pks = $pkStmt->fetchAll(\PDO::FETCH_COLUMN);

            // Mark primary key columns
            foreach ($pks as $pk) {
                if (isset($formattedColumns[$pk])) {
                    $formattedColumns[$pk]['is_primary'] = true;
                }
            }
        } catch (\Exception $e) {
            // Continue without primary key information
        }

        // Get unique constraints
        try {
            $uniqueQuery = "
                SELECT 
                    a.attname as column_name
                FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
                JOIN pg_class t ON t.oid = c.conrelid
                WHERE c.contype = 'u' 
                  AND t.relname = :table
            ";
            $uniqueStmt = $pdo->prepare($uniqueQuery);
            $uniqueStmt->execute(['table' => $table]);
            $uniques = $uniqueStmt->fetchAll(\PDO::FETCH_COLUMN);

            // Mark unique columns
            foreach ($uniques as $unique) {
                if (isset($formattedColumns[$unique])) {
                    $formattedColumns[$unique]['is_unique'] = true;
                }
            }
        } catch (\Exception $e) {
            // Continue without unique constraint information
        }

        // Get index information
        try {
            $indexQuery = "
                SELECT 
                    i.relname as index_name,
                    a.attname as column_name,
                    ix.indisunique as is_unique
                FROM pg_index ix
                JOIN pg_class t ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                WHERE t.relname = :table
                  AND NOT ix.indisprimary
            ";
            $indexStmt = $pdo->prepare($indexQuery);
            $indexStmt->execute(['table' => $table]);
            $indexes = $indexStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Add index information to columns
            foreach ($indexes as $index) {
                $columnName = $index['column_name'];
                if (isset($formattedColumns[$columnName])) {
                    $formattedColumns[$columnName]['is_indexed'] = true;
                    $formattedColumns[$columnName]['indexes'][] = [
                        'name' => $index['index_name'],
                        'type' => $index['is_unique'] ? 'UNIQUE' : 'INDEX'
                    ];
                }
            }
        } catch (\Exception $e) {
            // Continue without index information
        }

        // Get foreign key relationships
        try {
            $fkQuery = "
                SELECT 
                    kcu.column_name,
                    ccu.table_name AS ref_table,
                    ccu.column_name AS ref_column,
                    rc.update_rule AS on_update,
                    rc.delete_rule AS on_delete,
                    rc.constraint_name
                FROM information_schema.key_column_usage kcu
                JOIN information_schema.referential_constraints rc 
                    ON kcu.constraint_name = rc.constraint_name
                JOIN information_schema.constraint_column_usage ccu 
                    ON rc.unique_constraint_name = ccu.constraint_name
                WHERE kcu.table_name = :table
                  AND kcu.constraint_name IN (
                      SELECT constraint_name 
                      FROM information_schema.table_constraints 
                      WHERE constraint_type = 'FOREIGN KEY'
                  )
            ";
            $fkStmt = $pdo->prepare($fkQuery);
            $fkStmt->execute(['table' => $table]);
            $foreignKeys = $fkStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Add relationships to columns
            foreach ($foreignKeys as $fk) {
                $columnName = $fk['column_name'];
                if (isset($formattedColumns[$columnName])) {
                    $formattedColumns[$columnName]['relationships'][] = [
                        'constraint' => $fk['constraint_name'],
                        'column' => $columnName,
                        'references_table' => $fk['ref_table'],
                        'references_column' => $fk['ref_column'],
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
                    $definition = $change['definition'] ?? 'VARCHAR(255)';
                    $sql[] = "ALTER TABLE " . $this->quoteIdentifier($table) .
                           " ADD COLUMN " . $this->quoteIdentifier($columnName) . " {$definition}";
                    $estimatedDuration += 5;
                    break;

                case 'drop_column':
                    $columnName = $change['column_name'];
                    $sql[] = "ALTER TABLE " . $this->quoteIdentifier($table) .
                           " DROP COLUMN " . $this->quoteIdentifier($columnName);
                    $warnings[] = "Dropping column '{$columnName}' will permanently delete all data in this column";
                    $estimatedDuration += 10;
                    break;

                case 'add_index':
                    $indexName = $change['index_name'] ?? "{$table}_{$change['column']}_idx";
                    $column = $change['column'];
                    $unique = ($change['unique'] ?? false) ? 'UNIQUE ' : '';
                    $sql[] = "CREATE {$unique}INDEX " . $this->quoteIdentifier($indexName) .
                           " ON " . $this->quoteIdentifier($table) . " (" . $this->quoteIdentifier($column) . ")";
                    $estimatedDuration += 15;
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
            'safe_to_execute' => empty($warnings),
            'generated_at' => date('Y-m-d H:i:s')
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
                    'engine' => 'postgresql',
                    'columns' => $schema,
                    'format' => 'json',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'version' => '1.0'
                ];

            case 'sql':
                return [
                    'table' => $table,
                    'sql' => $this->generateCreateTableFromSchema($table, $schema),
                    'format' => 'sql',
                    'exported_at' => date('Y-m-d H:i:s')
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
            $columnName = $column['column_name'] ?? $column['name'];
            $columnType = $column['data_type'] ?? $column['type'];
            $nullable = ($column['is_nullable'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
            $default = !empty($column['column_default']) ? " DEFAULT {$column['column_default']}" : '';

            $columns[] = $this->quoteIdentifier($columnName) . " {$columnType}{$nullable}{$default}";
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
            }
        }

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
                    'column_name' => $change['column_name']
                ];
                break;

            case 'drop_column':
                $revertOps[] = [
                    'type' => 'add_column',
                    'table' => $change['table'],
                    'column_name' => $change['column_name'],
                    'definition' => $change['original_definition'] ?? 'VARCHAR(255)'
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
                    $constraints = [];

                    foreach ($schema['columns'] as $column) {
                        $columnName = $column['column_name'] ?? $column['name'];
                        $columnType = $column['data_type'] ?? $column['type'] ?? 'VARCHAR(255)';
                        $nullable = ($column['is_nullable'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
                        $default = !empty($column['column_default']) ? " DEFAULT {$column['column_default']}" : '';

                        $columns[] = $this->quoteIdentifier($columnName) . " {$columnType}{$nullable}{$default}";

                        // Handle constraints that need to be added separately
                        if (!empty($column['is_primary'])) {
                            $constraints[] = "PRIMARY KEY (" . $this->quoteIdentifier($columnName) . ")";
                        } elseif (!empty($column['is_unique'])) {
                            $constraintName = "{$table}_{$columnName}_unique";
                            $constraints[] = "CONSTRAINT " . $this->quoteIdentifier($constraintName) .
                                           " UNIQUE (" . $this->quoteIdentifier($columnName) . ")";
                        }
                    }

                    // Build CREATE TABLE statement
                    $createTable = "CREATE TABLE " . $this->quoteIdentifier($table) . " (\n  " .
                                 implode(",\n  ", array_merge($columns, $constraints)) .
                                 "\n)";

                    $sql[] = $createTable;
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
                'database_engine' => 'postgresql'
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
