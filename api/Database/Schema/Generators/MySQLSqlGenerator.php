<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Generators;

use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * MySQL SQL Generator Implementation
 *
 * Generates MySQL-specific SQL statements from schema definitions.
 * Handles MySQL syntax, data types, and engine-specific features.
 *
 * Features:
 * - MySQL data type mapping
 * - Engine and charset support
 * - AUTO_INCREMENT handling
 * - Index and constraint management
 * - Proper identifier quoting
 * - MySQL-specific options
 *
 * Example output:
 * ```sql
 * CREATE TABLE `users` (
 *   `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `email` VARCHAR(255) NOT NULL,
 *   PRIMARY KEY (`id`),
 *   UNIQUE KEY `users_email_unique` (`email`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ```
 */
class MySQLSqlGenerator implements SqlGeneratorInterface
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

        // Add primary key
        if (!empty($table->primaryKey)) {
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $table->primaryKey);
            $parts[] = '  PRIMARY KEY (' . implode(', ', $quotedColumns) . ')';
        }

        // Add indexes
        foreach ($table->indexes as $index) {
            if ($index->type !== 'primary') {
                $parts[] = '  ' . $this->buildIndexDefinition($index);
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
                $positioning = $this->buildColumnPositioning($column);
                $statements[] = "ALTER TABLE {$tableName} ADD COLUMN {$columnDef}{$positioning};";
            }
        }

        // Modify columns
        if (!empty($changes['modify_columns'])) {
            foreach ($changes['modify_columns'] as $column) {
                $columnDef = $this->buildColumnDefinition($column);
                $statements[] = "ALTER TABLE {$tableName} MODIFY COLUMN {$columnDef};";
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
        return 'RENAME TABLE ' . $this->quoteIdentifier($from) .
               ' TO ' . $this->quoteIdentifier($to) . ';';
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
        $positioning = $this->buildColumnPositioning($column);

        return "ALTER TABLE {$tableName} ADD COLUMN {$columnDef}{$positioning};";
    }

    /**
     * Generate MODIFY COLUMN statement
     *
     * @param string $table Table name
     * @param ColumnDefinition $column New column definition
     * @return string SQL MODIFY COLUMN statement
     */
    public function modifyColumn(string $table, ColumnDefinition $column): string
    {
        $tableName = $this->quoteIdentifier($table);
        $columnDef = $this->buildColumnDefinition($column);

        return "ALTER TABLE {$tableName} MODIFY COLUMN {$columnDef};";
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
        } elseif ($index->type === 'fulltext') {
            $sql = "CREATE FULLTEXT INDEX ";
        } else {
            $sql = "CREATE INDEX ";
        }

        $sql .= $this->quoteIdentifier($index->name);
        $sql .= " ON {$tableName}";

        // Add columns
        $columns = [];
        foreach ($index->columns as $column) {
            $quotedColumn = $this->quoteIdentifier($column);

            // Add length if specified
            if ($index->hasColumnLength($column)) {
                $quotedColumn .= '(' . $index->getColumnLength($column) . ')';
            }

            // Add sort order
            $order = $index->getColumnOrder($column);
            if ($order !== 'ASC') {
                $quotedColumn .= ' ' . $order;
            }

            $columns[] = $quotedColumn;
        }

        $sql .= ' (' . implode(', ', $columns) . ')';

        // Add algorithm if specified
        if ($index->algorithm) {
            $sql .= ' USING ' . strtoupper($index->algorithm);
        }

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
        $tableName = $this->quoteIdentifier($table);
        $indexName = $this->quoteIdentifier($index);

        return "DROP INDEX {$indexName} ON {$tableName};";
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

        return $sql . ';';
    }

    /**
     * Generate DROP FOREIGN KEY statement
     *
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @return string SQL DROP FOREIGN KEY statement
     */
    public function dropForeignKey(string $table, string $constraint): string
    {
        $tableName = $this->quoteIdentifier($table);
        $constraintName = $this->quoteIdentifier($constraint);

        return "ALTER TABLE {$tableName} DROP FOREIGN KEY {$constraintName};";
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

        if (isset($options['charset'])) {
            $sql .= ' DEFAULT CHARACTER SET ' . $options['charset'];
        }

        if (isset($options['collation'])) {
            $sql .= ' DEFAULT COLLATE ' . $options['collation'];
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
     * Map abstract column type to MySQL-specific type
     *
     * @param string $type Abstract type (string, integer, etc.)
     * @param array $options Type options (length, precision, etc.)
     * @return string MySQL-specific type
     */
    public function mapColumnType(string $type, array $options = []): string
    {
        return match ($type) {
            'id' => 'BIGINT UNSIGNED',
            'foreignId' => 'BIGINT UNSIGNED',
            'string', 'varchar' => 'VARCHAR(' . ($options['length'] ?? 255) . ')',
            'char' => 'CHAR(' . ($options['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'longText' => 'LONGTEXT',
            'mediumText' => 'MEDIUMTEXT',
            'tinyText' => 'TINYTEXT',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'smallInteger' => 'SMALLINT',
            'tinyInteger' => 'TINYINT',
            'decimal', 'numeric' => 'DECIMAL(' . ($options['precision'] ?? 8) . ',' . ($options['scale'] ?? 2) . ')',
            'float' => isset($options['precision']) && isset($options['scale'])
                ? 'FLOAT(' . $options['precision'] . ',' . $options['scale'] . ')'
                : 'FLOAT',
            'double' => isset($options['precision']) && isset($options['scale'])
                ? 'DOUBLE(' . $options['precision'] . ',' . $options['scale'] . ')'
                : 'DOUBLE',
            'boolean' => 'TINYINT(1)',
            'timestamp' => 'TIMESTAMP',
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'year' => 'YEAR',
            'json' => 'JSON',
            'uuid' => 'CHAR(36)',
            'enum' => 'ENUM(' . implode(',', array_map([$this, 'quoteValue'], $options['values'] ?? [])) . ')',
            'binary' => 'BINARY(' . ($options['length'] ?? 255) . ')',
            'varbinary' => 'VARBINARY(' . ($options['length'] ?? 255) . ')',
            'blob' => 'BLOB',
            'longBlob' => 'LONGBLOB',
            'mediumBlob' => 'MEDIUMBLOB',
            'tinyBlob' => 'TINYBLOB',
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
        return '`' . str_replace('`', '``', $identifier) . '`';
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

        return "'" . addslashes((string) $value) . "'";
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
            stripos($value, 'UUID()') !== false
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
        return 'SET FOREIGN_KEY_CHECKS = ' . ($enabled ? '1' : '0') . ';';
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
               "WHERE table_schema = DATABASE() AND table_name = " . $this->quoteValue($table);
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
               "WHERE table_schema = DATABASE() AND table_name = " . $this->quoteValue($table) .
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
               "WHERE table_schema = DATABASE() AND table_name = " . $this->quoteValue($table) .
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
               "WHERE table_schema = DATABASE() ORDER BY table_name";
    }

    // ===========================================
    // Private Helper Methods
    // ===========================================

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

        // Unsigned modifier
        if ($column->unsigned && $column->isNumeric()) {
            $parts[] = 'UNSIGNED';
        }

        // Zerofill modifier
        if ($column->zerofill && $column->isNumeric()) {
            $parts[] = 'ZEROFILL';
        }

        // Binary modifier
        if ($column->binary && $column->isString()) {
            $parts[] = 'BINARY';
        }

        // Charset and collation
        if ($column->charset && $column->isString()) {
            $parts[] = 'CHARACTER SET ' . $column->charset;
        }

        if ($column->collation && $column->isString()) {
            $parts[] = 'COLLATE ' . $column->collation;
        }

        // Nullability
        if (!$column->nullable) {
            $parts[] = 'NOT NULL';
        } else {
            $parts[] = 'NULL';
        }

        // Default value
        if ($column->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->formatDefaultValue($column->getDefaultValue(), $column->type);
        }

        // Auto increment
        if ($column->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        // Comment
        if ($column->comment) {
            $parts[] = 'COMMENT ' . $this->quoteValue($column->comment);
        }

        return implode(' ', $parts);
    }

    /**
     * Build column positioning clause
     *
     * @param ColumnDefinition $column Column definition
     * @return string Positioning clause
     */
    private function buildColumnPositioning(ColumnDefinition $column): string
    {
        if ($column->first) {
            return ' FIRST';
        }

        if ($column->after) {
            return ' AFTER ' . $this->quoteIdentifier($column->after);
        }

        return '';
    }

    /**
     * Build index definition string
     *
     * @param IndexDefinition $index Index definition
     * @return string Index definition SQL
     */
    private function buildIndexDefinition(IndexDefinition $index): string
    {
        $parts = [];

        // Index type
        if ($index->type === 'unique') {
            $parts[] = 'UNIQUE KEY';
        } elseif ($index->type === 'fulltext') {
            $parts[] = 'FULLTEXT KEY';
        } else {
            $parts[] = 'KEY';
        }

        // Index name
        $parts[] = $this->quoteIdentifier($index->name);

        // Columns
        $columns = [];
        foreach ($index->columns as $column) {
            $quotedColumn = $this->quoteIdentifier($column);

            if ($index->hasColumnLength($column)) {
                $quotedColumn .= '(' . $index->getColumnLength($column) . ')';
            }

            $order = $index->getColumnOrder($column);
            if ($order !== 'ASC') {
                $quotedColumn .= ' ' . $order;
            }

            $columns[] = $quotedColumn;
        }

        $parts[] = '(' . implode(', ', $columns) . ')';

        // Algorithm
        if ($index->algorithm) {
            $parts[] = 'USING ' . strtoupper($index->algorithm);
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
        $options = [];

        // Engine
        $engine = $table->options['engine'] ?? 'InnoDB';
        $options[] = 'ENGINE=' . $engine;

        // Charset
        $charset = $table->options['charset'] ?? 'utf8mb4';
        $options[] = 'DEFAULT CHARSET=' . $charset;

        // Collation
        if (isset($table->options['collation'])) {
            $options[] = 'COLLATE=' . $table->options['collation'];
        }

        // Comment
        if ($table->comment) {
            $options[] = 'COMMENT=' . $this->quoteValue($table->comment);
        }

        return ' ' . implode(' ', $options);
    }

    /**
     * Generate query to get table size in bytes
     *
     * @param string $table Table name
     * @return string SQL query to get table size
     */
    public function getTableSizeQuery(string $table): string
    {
        return "SELECT (data_length + index_length) as size 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = " . $this->quoteValue($table);
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
        // Get basic column information
        $stmt = $pdo->prepare("SHOW COLUMNS FROM " . $this->quoteIdentifier($table));
        $stmt->execute();
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Format columns into a more usable structure with column name as key
        $formattedColumns = [];
        foreach ($columns as $column) {
            $columnName = $column['Field'];
            $formattedColumns[$columnName] = [
                'name' => $columnName,
                'type' => $column['Type'],
                'nullable' => $column['Null'] === 'YES',
                'default' => $column['Default'],
                'extra' => $column['Extra'],
                'is_primary' => strpos($column['Key'], 'PRI') !== false,
                'is_unique' => strpos($column['Key'], 'UNI') !== false,
                'is_indexed' => strpos($column['Key'], 'MUL') !== false,
                'relationships' => [],
                'indexes' => []
            ];
        }

        // Get index information
        try {
            $indexStmt = $pdo->prepare("SHOW INDEXES FROM " . $this->quoteIdentifier($table));
            $indexStmt->execute();
            $indexes = $indexStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Group indexes by column
            foreach ($indexes as $index) {
                $columnName = $index['Column_name'];
                if (isset($formattedColumns[$columnName])) {
                    $formattedColumns[$columnName]['indexes'][] = [
                        'name' => $index['Key_name'],
                        'type' => $index['Key_name'] === 'PRIMARY' ? 'PRIMARY KEY' :
                                 ($index['Non_unique'] == 0 ? 'UNIQUE' : 'INDEX'),
                        'sequence' => $index['Seq_in_index'],
                        'cardinality' => $index['Cardinality']
                    ];
                }
            }
        } catch (\PDOException $e) {
            // If indexes can't be retrieved, continue without them
        }

        // Get foreign key information (requires INFORMATION_SCHEMA privilege)
        try {
            $fkQuery = "SELECT 
                            k.COLUMN_NAME as column_name,
                            k.REFERENCED_TABLE_NAME as ref_table,
                            k.REFERENCED_COLUMN_NAME as ref_column,
                            c.UPDATE_RULE as on_update,
                            c.DELETE_RULE as on_delete,
                            c.CONSTRAINT_NAME as constraint_name
                        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS c
                            ON k.CONSTRAINT_NAME = c.CONSTRAINT_NAME
                        WHERE k.TABLE_SCHEMA = DATABASE()
                            AND k.TABLE_NAME = :table
                            AND k.REFERENCED_TABLE_NAME IS NOT NULL";

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
        } catch (\PDOException $e) {
            // If foreign key information can't be retrieved, continue without it
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
                    $sql[] = "ALTER TABLE " . $this->quoteIdentifier($table) .
                           " DROP INDEX " . $this->quoteIdentifier($indexName);
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
                    'engine' => 'mysql',
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
                    if (empty($column['COLUMN_NAME'] ?? $column['name'])) {
                        $errors[] = 'Column name is required';
                    }
                    if (empty($column['DATA_TYPE'] ?? $column['type'])) {
                        $errors[] = 'Column type is required';
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Generate revert operations for a change
     *
     * @param array $change Original change
     * @return array Revert operations
     */
    public function generateRevertOperations(array $change): array
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
                    $indexes = [];
                    $foreignKeys = [];

                    foreach ($schema['columns'] as $column) {
                        $columnName = $column['COLUMN_NAME'] ?? $column['name'];
                        $columnType = $column['DATA_TYPE'] ?? $column['type'] ?? 'VARCHAR(255)';
                        $nullable = ($column['IS_NULLABLE'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
                        $default = !empty($column['COLUMN_DEFAULT']) ? " DEFAULT {$column['COLUMN_DEFAULT']}" : '';
                        $extra = !empty($column['EXTRA']) && $column['EXTRA'] === 'auto_increment'
                            ? ' AUTO_INCREMENT'
                            : '';

                        $columns[] = $this->quoteIdentifier($columnName) .
                                   " {$columnType}{$nullable}{$default}{$extra}";

                        // Handle primary key
                        if (($column['COLUMN_KEY'] ?? '') === 'PRI') {
                            $indexes[] = "PRIMARY KEY (" . $this->quoteIdentifier($columnName) . ")";
                        } elseif (($column['COLUMN_KEY'] ?? '') === 'UNI') {
                            $indexName = "{$table}_{$columnName}_unique";
                            $indexes[] = "UNIQUE KEY " . $this->quoteIdentifier($indexName) .
                                       " (" . $this->quoteIdentifier($columnName) . ")";
                        }
                    }

                    // Build CREATE TABLE statement
                    $createTable = "CREATE TABLE " . $this->quoteIdentifier($table) . " (\n  " .
                                 implode(",\n  ", array_merge($columns, $indexes)) .
                                 "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

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
                'format' => $format
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()],
                'sql' => []
            ];
        }
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
            $columnName = $column['COLUMN_NAME'] ?? $column['name'];
            $columnType = $column['DATA_TYPE'] ?? $column['type'];
            $nullable = ($column['IS_NULLABLE'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
            $default = !empty($column['COLUMN_DEFAULT']) ? " DEFAULT {$column['COLUMN_DEFAULT']}" : '';

            $columns[] = $this->quoteIdentifier($columnName) . " {$columnType}{$nullable}{$default}";
        }

        return "CREATE TABLE " . $this->quoteIdentifier($table) . " (\n  " .
               implode(",\n  ", $columns) .
               "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }
}
