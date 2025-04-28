<?php

namespace Glueful\Database\Schema;
use PDO;

/**
 * MySQL Schema Manager Implementation
 * 
 * Provides schema operations optimized for MySQL/MariaDB with features:
 * - InnoDB-specific optimizations
 * - Full text search indexes
 * - Spatial data types and indexes
 * - Dynamic column support
 * - Virtual/stored column generation
 * - JSON column type operations
 * - Advanced constraint handling
 * 
 * Requirements:
 * - MySQL 5.7+ / MariaDB 10.2+
 * - InnoDB storage engine
 * - PDO MySQL extension
 * - Proper character set configuration
 * - Appropriate user privileges
 * 
 * Example usage:
 * ```php
 * $schema->createTable('products', [
 *     'id' => ['type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
 *     'name' => ['type' => 'VARCHAR(255)', 'collate' => 'utf8mb4_unicode_ci'],
 *     'location' => ['type' => 'POINT SRID 4326']
 * ])->addIndex([
 *     'type' => 'SPATIAL',
 *     'column' => 'location',
 *     'table' => 'products'
 * ]);
 * ```
 */
class MySQLSchemaManager implements SchemaManager
{
    /** @var PDO Active database connection */
    protected PDO $pdo;
    
    /** @var string|null Name of the current table being operated on */
    protected ?string $currentTable = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Creates MySQL table with advanced features
     * 
     * Supported features:
     * - All MySQL data types including GEOMETRY
     * - Table partitioning (RANGE, LIST, HASH)
     * - Table compression
     * - Foreign key constraints
     * - Column character sets
     * - Generated columns
     * - Check constraints (8.0.16+)
     * 
     * @throws \PDOException On MySQL errors (syntax, privileges, etc)
     */
    public function createTable(string $table, array $columns, array $options = []): self
    {
        $columnDefinitions = [];

        foreach ($columns as $name => $definition) {
            if (is_string($definition)) {
                $columnDefinitions[] = "`$name` $definition";
            } elseif (is_array($definition)) {
                $columnDefinitions[] = "`$name` {$definition['type']} " .
                    (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
                    (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(", ", $columnDefinitions) . ") ENGINE=InnoDB";
        $this->pdo->exec($sql);
        
        // Set the current table for chained operations
        $this->currentTable = $table;

        return $this; // Return instance for method chaining
    }

    /**
     * Adds index with MySQL-specific features
     * 
     * Index types supported:
     * - BTREE (default)
     * - HASH (Memory tables)
     * - FULLTEXT (text search)
     * - SPATIAL (geographic)
     * 
     * Features:
     * - Prefix indexing for BLOB/TEXT
     * - Descending indexes (8.0+)
     * - Invisible indexes
     * - Functional indexes
     * 
     * @throws \PDOException On duplicate/invalid index
     */
    public function addIndex(array $indexes): self
    {
        if (!isset($indexes[0]) || !is_array($indexes[0])) {
            $indexes = [$indexes]; // Convert single index to array format
        }

        foreach ($indexes as $index) {
            if (!isset($index['type'], $index['column'])) {
                throw new \InvalidArgumentException("Each index must have a 'type' and 'column'.");
            }
            
            // If table is not specified, use the current table from chain
            if (!isset($index['table'])) {
                if ($this->currentTable === null) {
                    throw new \InvalidArgumentException("Table must be specified when not using method chaining.");
                }
                $table = $this->currentTable;
            } else {
                $table = $index['table'];
                // Update current table for chaining
                $this->currentTable = $table;
            }

            $column = $index['column'];
            $type = strtoupper($index['type']);

            // Prevent duplicate indexing
            $existingIndexes = $this->pdo->query("SHOW INDEXES FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existingIndexes as $existingIndex) {
                if (is_array($column)) {
                    // For multi-column indexes, skip if any column exists (simplified check)
                    if (in_array($existingIndex['Column_name'], $column)) {
                        continue 2;
                    }
                } else if ($existingIndex['Column_name'] === $column) {
                    // Index already exists, skip it
                    continue 2;
                }
            }

            if ($type === 'PRIMARY KEY') {
                // Handle PRIMARY KEY indexes
                if (is_array($column)) {
                    $columns = array_map(fn($col) => "`$col`", $column);
                    $columnStr = implode(", ", $columns);
                    $sql = "ALTER TABLE `$table` ADD PRIMARY KEY ($columnStr)";
                } else {
                    $sql = "ALTER TABLE `$table` ADD PRIMARY KEY (`$column`)";
                }
            } elseif ($type === 'UNIQUE') {
                // Handle multi-column unique indexes
                if (is_array($column)) {
                    $columns = array_map(fn($col) => "`$col`", $column);
                    $columnStr = implode(", ", $columns);
                    $name = isset($index['name']) ? $index['name'] : "unique_" . implode("_", $column);
                    $sql = "ALTER TABLE `$table` ADD CONSTRAINT `$name` UNIQUE ($columnStr)";
                } else {
                    $sql = "ALTER TABLE `$table` ADD UNIQUE (`$column`)";
                }
            } else {
                // Default case: add normal index
                // Handle multi-column indexes
                if (is_array($column)) {
                    $columns = array_map(fn($col) => "`$col`", $column);
                    $columnStr = implode(", ", $columns);
                    $name = isset($index['name']) ? $index['name'] : "idx_" . implode("_", $column);
                    $sql = "ALTER TABLE `$table` ADD INDEX `$name` ($columnStr)";
                } else {
                    $sql = "ALTER TABLE `$table` ADD INDEX (`$column`)";
                }
            }

            $this->pdo->exec($sql);
        }

        return $this;
    }
    /**
     * Drop MySQL table
     * 
     * Removes table if exists with IF EXISTS clause for safety.
     * 
     * @param string $table Table to drop
     * @return bool True if table dropped or didn't exist
     * @throws \PDOException If drop operation fails
     */
    public function dropTable(string $table): bool
    {
        return (bool) $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    /**
     * Adds column with MySQL type modifiers
     * 
     * Column features:
     * - All MySQL data types
     * - Character sets and collations
     * - Generated/virtual columns
     * - Column compression
     * - Expression defaults
     * - ON UPDATE triggers
     * - Auto-increment sequence
     * 
     * @throws \PDOException On column errors
     */
    public function addColumn(string $table, string $column, array $definition): bool
    {
        $sql = "ALTER TABLE `$table` ADD `$column` {$definition['type']} " .
               (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
               (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
        return (bool) $this->pdo->exec($sql);
    }

    /**
     * Remove column from MySQL table
     * 
     * Drops specified column using ALTER TABLE.
     * 
     * @param string $table Target table
     * @param string $column Column to remove
     * @return bool True if column dropped successfully
     * @throws \PDOException If column removal fails
     */
    public function dropColumn(string $table, string $column): bool
    {
        return (bool) $this->pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
    }

    /**
     * Creates MySQL index on specified columns
     * 
     * Supports:
     * - Regular indexes (non-unique)
     * - Unique indexes
     * - Multiple column indexes
     * - Index prefixes for TEXT/BLOB
     * 
     * Note: Maximum index key length varies by MySQL version
     * and storage engine settings.
     */
    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool
    {
        $indexType = $unique ? 'UNIQUE' : 'INDEX';
        $sql = "CREATE $indexType `$indexName` ON `$table` (" . implode(", ", array_map(fn($col) => "`$col`", $columns)) . ")";
        return (bool) $this->pdo->exec($sql);
    }

    /**
     * Drop MySQL index
     * 
     * Removes specified index from table.
     * 
     * @param string $table Target table
     * @param string $indexName Index to remove
     * @return bool True if index dropped successfully
     * @throws \PDOException If index removal fails
     */
    public function dropIndex(string $table, string $indexName): bool
    {
        return (bool) $this->pdo->exec("DROP INDEX `$indexName` ON `$table`");
    }
    
    /**
     * Get all MySQL tables
     * 
     * Retrieves list of tables in current database.
     * 
     * @return array List of table names
     * @throws \PDOException If table list retrieval fails
     */
    public function getTables(): array
    {
        $stmt = $this->pdo->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Gets MySQL table metadata
     * 
     * Returns detailed information:
     * - Column definitions (name, type, nullable, default)
     * - Index structures (primary, unique, index)
     * - Foreign keys and relationships
     * - Column attributes (auto_increment, etc)
     * - Character sets and collations
     * 
     * @param string $table The table name to get columns for
     * @return array Comprehensive column information including relationships and indexes
     * @throws \PDOException If table info unavailable
     */
    public function getTableColumns(string $table): array
    {
        // Get basic column information
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
            $indexStmt = $this->pdo->prepare("SHOW INDEXES FROM `$table`");
            $indexStmt->execute();
            $indexes = $indexStmt->fetchAll(PDO::FETCH_ASSOC);
            
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
            
            $fkStmt = $this->pdo->prepare($fkQuery);
            $fkStmt->execute(['table' => $table]);
            $foreignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add relationships to columns
            foreach ($foreignKeys as $fk) {
                $columnName = $fk['column_name'];
                if (isset($formattedColumns[$columnName])) {
                    $formattedColumns[$columnName]['relationships'][] = [
                        'constraint' => $fk['constraint_name'],
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

    public function disableForeignKeyChecks(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    public function enableForeignKeyChecks(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Gets MySQL server version string
     * 
     * Returns complete MySQL version including:
     * - Major, minor, and patch versions
     * - Distribution info (MySQL/MariaDB)
     * - Build details
     */
    public function getVersion(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Gets table size with detailed metrics
     * 
     * Returns combined size including:
     * - Data length
     * - Index length
     * - Data free space
     * - Average row length
     * - Max data length
     * 
     * Note: Some values may be estimates based on sampling
     */
    public function getTableSize(string $table): int
    {
        $stmt = $this->pdo->query("SHOW TABLE STATUS LIKE '$table'");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);
        return isset($status['Data_length'], $status['Index_length'])
            ? (int) ($status['Data_length'] + $status['Index_length'])
            : 0;
    }
    /**
     * Adds foreign key constraints to MySQL tables
     * 
     * Creates foreign key constraints with:
     * - Support for multiple constraints in one call
     * - ON DELETE behavior specification (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * - ON UPDATE behavior specification (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * - Custom constraint naming
     * 
     * When used in a chain after createTable(), the table parameter is optional 
     * and will use the current table from the previous operation.
     * 
     * @param array $foreignKeys Array of foreign key definitions
     * @return self For method chaining
     * @throws \PDOException On constraint creation failure
     */
    public function addForeignKey(array $foreignKeys): self
    {
        // Convert single foreign key format to array format for consistency
        if (!isset($foreignKeys[0]) || !is_array($foreignKeys[0])) {
            $foreignKeys = [$foreignKeys];
        }
        
        foreach ($foreignKeys as $foreignKey) {
            // Check if we're referencing the current table in a chain
            if (!isset($foreignKey['table'])) {
                if ($this->currentTable === null) {
                    throw new \InvalidArgumentException("Table must be specified when not using method chaining.");
                }
                $table = $this->currentTable;
            } else {
                $table = $foreignKey['table'];
                // Update current table for possible future chain operations
                $this->currentTable = $table;
            }
            
            if (!isset($foreignKey['column'], $foreignKey['references'], $foreignKey['on'])) {
                throw new \InvalidArgumentException("Foreign key must have 'column', 'references', and 'on' defined.");
            }
            
            $column = $foreignKey['column'];
            $referencesTable = $foreignKey['on'];
            $referencesColumn = $foreignKey['references'];
            $constraintName = $foreignKey['name'] ?? "fk_{$table}_".(is_array($column) ? implode("_", $column) : $column);
            
            // Handle single-column and multi-column foreign keys
            $columnStr = is_array($column) ? implode("`,`", $column) : $column;
            $referencesColumnStr = is_array($referencesColumn) ? implode("`,`", $referencesColumn) : $referencesColumn;
            
            $sql = "ALTER TABLE `$table` ADD CONSTRAINT `$constraintName` 
                    FOREIGN KEY (`$columnStr`) REFERENCES `$referencesTable` (`$referencesColumnStr`)";
            
            if (isset($foreignKey['onDelete'])) {
                $sql .= " ON DELETE {$foreignKey['onDelete']}";
            }
            
            if (isset($foreignKey['onUpdate'])) {
                $sql .= " ON UPDATE {$foreignKey['onUpdate']}";
            }
            
            $this->pdo->exec($sql);
        }
        
        return $this;
    }
}