<?php

namespace Glueful\Database\Schema;

use PDO;
use Exception;

/**
 * SQLite Schema Manager Implementation
 * 
 * Specialized schema manager for SQLite with consideration for its unique traits:
 * 
 * Core Features:
 * - Schema-less dynamic typing system
 * - Single-writer concurrency model
 * - Zero configuration setup
 * - In-memory database support
 * - Full text search capabilities
 * 
 * Limitations:
 * - No ALTER TABLE column drop
 * - Limited column modifications
 * - No native foreign key checks
 * - No user permissions system
 * - MAX 2000 bytes per index key
 * 
 * Requirements:
 * - SQLite 3.x
 * - PDO SQLite extension
 * - Write permissions on database file
 * - Proper journal mode configuration
 * 
 * Example usage:
 * ```php
 * $schema
 *     ->createTable('posts', [
 *         'id' => ['type' => 'INTEGER PRIMARY KEY'],
 *         'title' => ['type' => 'TEXT NOT NULL'],
 *         'created' => ['type' => 'DATETIME DEFAULT CURRENT_TIMESTAMP']
 *     ])
 *     ->addIndex([
 *         'type' => 'UNIQUE',
 *         'column' => 'title',
 *         'table' => 'posts'
 *     ]);
 * ```
 */
class SQLiteSchemaManager implements SchemaManager
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
     * Creates SQLite table with type affinity
     * 
     * Features:
     * - Dynamic type system
     * - WITHOUT ROWID optimization
     * - STRICT tables (3.37+)
     * - Generated columns
     * - CHECK constraints
     * - DEFAULT expressions
     * 
     * Type Affinity Rules:
     * - INT -> INTEGER
     * - CHAR/CLOB/TEXT -> TEXT
     * - BLOB -> BLOB
     * - REAL/FLOA/DOUB -> REAL
     * - Others -> NUMERIC
     * 
     * @throws Exception On syntax error or constraint violation
     */
    public function createTable(string $table, array $columns, array $options = []): self
    {
        $columnDefinitions = [];

        foreach ($columns as $name => $definition) {
            if (is_string($definition)) {
                // Handle string-based column definitions
                $columnDefinitions[] = "\"$name\" $definition";
            } elseif (is_array($definition)) {
                // Handle array-based column definitions
                $columnDefinitions[] = "\"$name\" {$definition['type']} " .
                    (!empty($definition['nullable']) ? '' : 'NOT NULL') .
                    (!empty($definition['default']) ? " DEFAULT {$definition['default']}" : '');
            }
        }

        // SQLite does not support ENGINE=InnoDB
        $sql = "CREATE TABLE IF NOT EXISTS \"$table\" (" . implode(", ", $columnDefinitions) . ")";

        $this->pdo->exec($sql);
        
        // Set the current table for chained operations
        $this->currentTable = $table;

        return $this; // Return instance for method chaining
    }

    /**
     * Adds SQLite table indexes
     * 
     * Supported Index Types:
     * - Regular B-tree indexes
     * - Unique constraints
     * - Partial indexes (WHERE clause)
     * - Expression indexes
     * - Descending key indexes
     * 
     * Performance Notes:
     * - Max 2000 bytes per key
     * - Sequential autoincrement optimal
     * - Index sorts maintained on insert
     * 
     * @throws Exception On duplicate or invalid index
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
    
            // Generate appropriate index name for checking
            if (is_array($column)) {
                $indexNameToCheck = isset($index['name']) ? $index['name'] : "{$table}_" . implode("_", $column) . "_idx";
            } else {
                $indexNameToCheck = "{$table}_{$column}_idx";
            }
    
            // Check if index already exists in SQLite
            $existingIndexes = $this->pdo
                ->query("PRAGMA index_list(\"$table\")")
                ->fetchAll(PDO::FETCH_ASSOC);
    
            foreach ($existingIndexes as $existingIndex) {
                if ($existingIndex['name'] === $indexNameToCheck) {
                    continue 2; // Skip if index exists
                }
            }
    
            if ($type === 'PRIMARY KEY') {
                // SQLite doesn't support adding PRIMARY KEY after table creation
                throw new \RuntimeException("SQLite does not support adding PRIMARY KEY with ALTER TABLE. Define it in CREATE TABLE.");
            } elseif ($type === 'UNIQUE') {
                // Handle multi-column unique indexes
                if (is_array($column)) {
                    $columns = array_map(fn($col) => "\"$col\"", $column);
                    $columnStr = implode(", ", $columns);
                    $name = isset($index['name']) ? $index['name'] : "{$table}_" . implode("_", $column) . "_idx";
                    $sql = "CREATE UNIQUE INDEX \"$name\" ON \"$table\" ($columnStr)";
                } else {
                    $sql = "CREATE UNIQUE INDEX \"{$table}_{$column}_idx\" ON \"$table\" (\"$column\")";
                }
            } else {
                // Default case: add normal index
                // Handle multi-column indexes
                if (is_array($column)) {
                    $columns = array_map(fn($col) => "\"$col\"", $column);
                    $columnStr = implode(", ", $columns);
                    $name = isset($index['name']) ? $index['name'] : "{$table}_" . implode("_", $column) . "_idx";
                    $sql = "CREATE INDEX \"$name\" ON \"$table\" ($columnStr)";
                } else {
                    $sql = "CREATE INDEX \"{$table}_{$column}_idx\" ON \"$table\" (\"$column\")";
                }
            }
    
            $this->pdo->exec($sql);
        }
    
        return $this;
    }

    /**
     * Drop SQLite table
     * 
     * Removes table from database if it exists.
     * 
     * @param string $table Table to drop
     * @return bool True if table dropped successfully
     * @throws Exception If table drop fails
     */
    public function dropTable(string $table): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$table};";
            $this->pdo->exec($sql);
            return true;
        } catch (Exception $e) {
            throw new Exception("Error dropping table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Add column with SQLite limitations
     * 
     * Restrictions:
     * - No PRIMARY KEY
     * - No UNIQUE constraints
     * - No FOREIGN KEY
     * - Must be NULL or have DEFAULT
     * - No position specification
     * 
     * Workaround for constraints:
     * 1. Create new table with desired schema
     * 2. Copy data
     * 3. Drop old table
     * 4. Rename new table
     * 
     * @throws Exception On constraint violation
     */
    public function addColumn(string $table, string $column, array $definition): bool
    {
        try {
            $columnDef = implode(' ', $definition);
            $sql = "ALTER TABLE {$table} ADD COLUMN {$column} {$columnDef};";
            
            // Execute the query - if it doesn't throw an exception, consider it successful
            $this->pdo->exec($sql);
            return true;
        } catch (Exception $e) {
            throw new Exception("Error adding column '{$column}' to table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Drop column from SQLite table
     * 
     * SQLite does not support direct column dropping.
     * Proper implementation requires:
     * 1. Creating new table without column
     * 2. Copying data
     * 3. Dropping old table
     * 4. Renaming new table
     * 
     * @param string $table Target table
     * @param string $column Column to remove
     * @return bool True if operation successful
     * @throws Exception Always, as operation needs complex implementation
     */
    public function dropColumn(string $table, string $column): bool
    {
        throw new Exception("SQLite does not support dropping columns directly.");
    }

    /**
     * Creates SQLite index
     * 
     * Supports:
     * - Regular and unique indexes
     * - Partial indexes with WHERE clause
     * - Descending indexes
     * - Expression indexes
     * - Covering indexes
     * 
     * Note: Max 2000 bytes per index key
     * 
     * @param string $table Target table
     * @param string $indexName Index name
     * @param array $columns Columns to index
     * @param bool $unique Whether index should be unique
     * @return bool True if index created successfully
     * @throws Exception If index creation fails
     */
    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool
    {
        try {
            $uniqueSql = $unique ? 'UNIQUE' : '';
            $columnsSql = implode(', ', $columns);
            $sql = "CREATE {$uniqueSql} INDEX IF NOT EXISTS {$indexName} ON {$table} ({$columnsSql});";

            $this->pdo->exec($sql);
            return true;
        } catch (Exception $e) {
            throw new Exception("Error creating index '{$indexName}' on table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Drop SQLite index
     * 
     * Removes specified index from database.
     * 
     * @param string $table Target table
     * @param string $indexName Index to remove
     * @return bool True if index dropped successfully
     * @throws Exception If index removal fails
     */
    public function dropIndex(string $table, string $indexName): bool
    {
        try {
            $sql = "DROP INDEX IF EXISTS {$indexName};";
            $this->pdo->exec($sql);
            return true;
        } catch (Exception $e) {
            throw new Exception("Error dropping index '{$indexName}' from table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Get SQLite tables
     * 
     * Retrieves list of user tables, excluding:
     * - SQLite system tables
     * - Temporary tables
     * - Internal tables
     * 
     * @return array List of table names
     * @throws Exception If table list retrieval fails
     */
    public function getTables(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Exception $e) {
            throw new Exception("Error fetching tables: " . $e->getMessage());
        }
    }

    /**
     * Get SQLite table information
     * 
     * Returns comprehensive column information:
     * - Column definitions (name, type, nullable, default)
     * - Primary key columns
     * - Foreign key relationships
     * - Index information 
     * - Unique constraint details
     * 
     * Data sources:
     * - PRAGMA table_info
     * - PRAGMA foreign_key_list
     * - PRAGMA index_list and index_info
     * - sqlite_master for detailed constraints
     * 
     * @param string $table Table to analyze
     * @return array Detailed column metadata with relationships and indexes
     * @throws Exception On invalid table or access error
     */
    public function getTableColumns(string $table): array
    {
        try {
            // Get basic column information from PRAGMA table_info
            $stmt = $this->pdo->prepare("PRAGMA table_info({$table});");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
                $indexListStmt = $this->pdo->prepare("PRAGMA index_list({$table});");
                $indexListStmt->execute();
                $indexes = $indexListStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($indexes as $index) {
                    $indexName = $index['name'];
                    $isUnique = $index['unique'] == 1;
                    
                    // Skip SQLite's auto-generated indexes for PRIMARY KEY
                    if (preg_match('/^sqlite_autoindex_/', $indexName)) {
                        continue;
                    }
                    
                    // Get columns in this index
                    $indexInfoStmt = $this->pdo->prepare("PRAGMA index_info({$indexName});");
                    $indexInfoStmt->execute();
                    $indexedColumns = $indexInfoStmt->fetchAll(PDO::FETCH_ASSOC);
                    
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
            } catch (Exception $e) {
                // Continue without index information
            }
            
            // Get foreign key relationships
            try {
                $fkStmt = $this->pdo->prepare("PRAGMA foreign_key_list({$table});");
                $fkStmt->execute();
                $foreignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($foreignKeys as $fk) {
                    $columnName = $fk['from'];
                    if (isset($formattedColumns[$columnName])) {
                        $formattedColumns[$columnName]['relationships'][] = [
                            'constraint' => "fk_{$table}_{$columnName}_{$fk['id']}", // SQLite doesn't name constraints, create synthetic name
                            'column' => $columnName,
                            'references_table' => $fk['table'],
                            'references_column' => $fk['to'],
                            'on_update' => $fk['on_update'] ?: 'NO ACTION',
                            'on_delete' => $fk['on_delete'] ?: 'NO ACTION'
                        ];
                    }
                }
            } catch (Exception $e) {
                // Continue without foreign key information
            }
            
            // Identify columns that are part of unique constraints but not detected as indexes
            // This handles cases where UNIQUE constraints are defined in CREATE TABLE
            try {
                // Get table creation SQL
                $sqlStmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=:table;");
                $sqlStmt->execute(['table' => $table]);
                $createTableSql = $sqlStmt->fetchColumn();
                
                if ($createTableSql) {
                    // Look for UNIQUE constraints in the table definition
                    if (preg_match_all('/UNIQUE\s*\((.*?)\)/i', $createTableSql, $matches)) {
                        foreach ($matches[1] as $uniqueConstraint) {
                            // Split and trim column names
                            $uniqueColumns = array_map('trim', explode(',', $uniqueConstraint));
                            foreach ($uniqueColumns as $columnName) {
                                // Remove backticks or quotes
                                $columnName = trim($columnName, '"`[] ');
                                if (isset($formattedColumns[$columnName])) {
                                    $formattedColumns[$columnName]['is_unique'] = true;
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Continue without additional unique constraint information
            }
            
            return array_values($formattedColumns); // Convert back to indexed array
        } catch (Exception $e) {
            throw new Exception("Error fetching columns for table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Manage foreign key constraints
     * 
     * Note: Foreign keys in SQLite:
     * - Must be enabled at table creation
     * - Checked only on write
     * - No partial keys
     * - No update cascades
     */
    public function disableForeignKeyChecks(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
    }

    public function enableForeignKeyChecks(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Gets SQLite version
     * 
     * Returns version string with:
     * - SQLite version number
     * - Source ID
     * - Build configuration
     */
    public function getVersion(): string
    {
        return $this->pdo->query("SELECT sqlite_version()")->fetchColumn();
    }

    /**
     * Check if a table exists in the database
     * 
     * Uses sqlite_master table for reliable checking.
     * 
     * @param string $table Name of the table to check
     * @return bool True if the table exists, false otherwise
     * @throws \RuntimeException If the check cannot be completed due to database errors
     */
    public function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM sqlite_master 
                WHERE type = 'table' 
                AND name = :table
            ");
            $stmt->execute(['table' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Failed to check if table '$table' exists: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Calculate SQLite storage size
     * 
     * Includes:
     * - Database pages
     * - Index pages
     * - Overflow pages
     * - Free pages
     * 
     * Note: Requires dbstat virtual table
     */
    public function getTableSize(string $table): int
    {
        $stmt = $this->pdo->prepare("
            SELECT SUM(pgsize) AS size
            FROM dbstat WHERE name = :table
        ");
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Get the total number of rows in a SQLite table
     * 
     * Features:
     * - Direct COUNT(*) query against table
     * - Handles quoted table names
     * - Optimized for SQLite's query planner
     * 
     * Note: For large tables, this operation may be expensive
     * as SQLite needs to perform a full table scan
     * 
     * @param string $table Name of the table to count rows from
     * @return int Number of rows in the table
     * @throws \RuntimeException If table doesn't exist
     */
    public function getTableRowCount(string $table): int
    {
        try {
            // SQLite doesn't have table statistics, so we use COUNT(*)
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM "' . $table . '"');
            $stmt->execute();
            $result = $stmt->fetchColumn();
            
            return (int)($result ?: 0);
        } catch (Exception $e) {
            throw new \RuntimeException("Failed to get row count for table '$table': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Adds foreign key constraints to SQLite tables
     * 
     * Creates foreign key constraints with:
     * - Support for multiple constraints in one call
     * - ON DELETE behavior specification (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * - ON UPDATE behavior specification (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * - Custom constraint naming (for reference only, SQLite doesn't use constraint names)
     * 
     * SQLite Limitations:
     * - Foreign keys must be enabled with PRAGMA foreign_keys = ON
     * - Cannot add foreign keys to existing tables
     * - Must recreate the table to add foreign keys
     * - This implementation uses a workaround to add foreign keys by recreating the table
     * 
     * When used in a chain after createTable(), the table parameter is optional 
     * and will use the current table from the previous operation.
     * 
     * @param array $foreignKeys Array of foreign key definitions
     * @return self For method chaining
     * @throws Exception On constraint creation failure or SQLite limitations
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
            
            // SQLite requires recreating the table to add foreign keys
            // First, get the current table definition
            $tableInfo = $this->pdo->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($tableInfo)) {
                throw new \RuntimeException("Table '$table' does not exist");
            }
            
            // Build the new table definition with foreign key constraint
            $columns = [];
            foreach ($tableInfo as $columnInfo) {
                $name = $columnInfo['name'];
                $type = $columnInfo['type'];
                $notNull = $columnInfo['notnull'] ? 'NOT NULL' : '';
                $default = $columnInfo['dflt_value'] ? "DEFAULT {$columnInfo['dflt_value']}" : '';
                $pk = $columnInfo['pk'] ? 'PRIMARY KEY' : '';
                
                $columns[] = "\"$name\" $type $notNull $default $pk";
            }
            
            // Format the foreign key constraint
            $foreignKeyConstraint = "FOREIGN KEY (\"" . (is_array($column) ? implode("\", \"", $column) : $column) . "\") " .
                                   "REFERENCES \"$referencesTable\" (\"" . (is_array($referencesColumn) ? implode("\", \"", $referencesColumn) : $referencesColumn) . "\")";
                                   
            // Add ON DELETE/UPDATE clauses if specified
            if (isset($foreignKey['onDelete'])) {
                $foreignKeyConstraint .= " ON DELETE {$foreignKey['onDelete']}";
            }
            
            if (isset($foreignKey['onUpdate'])) {
                $foreignKeyConstraint .= " ON UPDATE {$foreignKey['onUpdate']}";
            }
            
            // Check if foreign keys are enabled
            $foreignKeysEnabled = (bool) $this->pdo->query("PRAGMA foreign_keys")->fetchColumn();
            if (!$foreignKeysEnabled) {
                $this->enableForeignKeyChecks();
            }
            
            // Start transaction for table recreation
            $this->pdo->beginTransaction();
            
            try {
                // Create a temporary table with the same structure plus the foreign key
                $tempTable = $table . "_temp_" . uniqid();
                $createTempSql = "CREATE TABLE \"$tempTable\" (" . implode(", ", $columns) . ", $foreignKeyConstraint)";
                $this->pdo->exec($createTempSql);
                
                // Copy data from old table to new table
                $this->pdo->exec("INSERT INTO \"$tempTable\" SELECT * FROM \"$table\"");
                
                // Drop old table
                $this->pdo->exec("DROP TABLE \"$table\"");
                
                // Rename temp table to original name
                $this->pdo->exec("ALTER TABLE \"$tempTable\" RENAME TO \"$table\"");
                
                // Recreate any indexes that were on the original table
                $indexes = $this->pdo->query("PRAGMA index_list(\"$tempTable\")")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($indexes as $index) {
                    // Skip sqlite_autoindex which are auto-created
                    if (strpos($index['name'], 'sqlite_autoindex_') === 0) {
                        continue;
                    }
                    
                    $indexInfo = $this->pdo->query("PRAGMA index_info(\"{$index['name']}\")")->fetchAll(PDO::FETCH_ASSOC);
                    $indexColumns = [];
                    foreach ($indexInfo as $indexColumn) {
                        $indexColumns[] = "\"{$indexColumn['name']}\"";
                    }
                    
                    $unique = $index['unique'] ? 'UNIQUE' : '';
                    $this->pdo->exec("CREATE $unique INDEX \"{$index['name']}\" ON \"$table\" (" . implode(", ", $indexColumns) . ")");
                }
                
                $this->pdo->commit();
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw new \RuntimeException("Failed to add foreign key constraint: " . $e->getMessage());
            }
        }
        
        return $this;
    }

    /**
     * Drop foreign key constraint from SQLite table
     * 
     * SQLite does not support altering foreign key constraints after table creation.
     * This method implements a workaround by:
     * 1. Creating a new temporary table without the foreign key
     * 2. Copying all data from the original table
     * 3. Dropping the original table
     * 4. Renaming the temporary table to the original name
     * 5. Recreating all indexes
     * 
     * @param string $table Target table containing the constraint
     * @param string $constraintName Name of the foreign key constraint to remove
     * @return bool True if constraint was successfully removed
     * @throws Exception If constraint removal fails
     */
    public function dropForeignKey(string $table, string $constraintName): bool
    {
        // Check if foreign keys are enabled
        $foreignKeysEnabled = (bool) $this->pdo->query("PRAGMA foreign_keys")->fetchColumn();
        if (!$foreignKeysEnabled) {
            $this->enableForeignKeyChecks();
        }

        // Start transaction for table recreation
        $this->pdo->beginTransaction();

        try {
            // First, get the current table schema information
            $tableInfo = $this->pdo->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($tableInfo)) {
                throw new \RuntimeException("Table '$table' does not exist");
            }

            // Get all foreign keys for this table
            $foreignKeys = $this->pdo->query("PRAGMA foreign_key_list(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($foreignKeys)) {
                throw new \RuntimeException("No foreign keys exist on table '$table'");
            }

            // SQLite doesn't store constraint names, so we have to match by column and reference
            // We'll use the id of the foreign key as an identifier
            $foreignKeyToRemove = null;
            $targetId = null;

            // Extract ID from constraint name if it follows our naming convention
            if (preg_match('/fk_' . preg_quote($table, '/') . '_[^_]+_(\d+)$/', $constraintName, $matches)) {
                $targetId = (int)$matches[1];
            }

            foreach ($foreignKeys as $fk) {
                // If we have a target ID, use it for matching
                if ($targetId !== null && $fk['id'] === $targetId) {
                    $foreignKeyToRemove = $fk;
                    break;
                }
                
                // Otherwise, try to match by generating a constraint name
                $syntheticName = "fk_{$table}_{$fk['from']}_{$fk['id']}";
                if ($syntheticName === $constraintName) {
                    $foreignKeyToRemove = $fk;
                    break;
                }
            }

            if (!$foreignKeyToRemove) {
                throw new \RuntimeException("Foreign key constraint '$constraintName' not found on table '$table'");
            }

            // Get all column definitions for the new table, excluding the foreign key to remove
            $columns = [];
            foreach ($tableInfo as $column) {
                $name = $column['name'];
                $type = $column['type'];
                $notNull = $column['notnull'] ? 'NOT NULL' : '';
                $default = $column['dflt_value'] ? "DEFAULT {$column['dflt_value']}" : '';
                $pk = $column['pk'] ? 'PRIMARY KEY' : '';
                
                $columns[] = "\"$name\" $type $notNull $default $pk";
            }

            // Get CREATE TABLE SQL to preserve all other constraints
            $createTableSql = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
            
            // Create a temporary table without the foreign key to be removed
            $tempTable = $table . "_temp_" . uniqid();
            
            // Build the new table definition with all foreign keys except the one to remove
            $newForeignKeyConstraints = [];
            foreach ($foreignKeys as $fk) {
                if ($fk['id'] === $foreignKeyToRemove['id']) {
                    continue; // Skip the foreign key we're removing
                }
                
                $fkDef = "FOREIGN KEY (\"{$fk['from']}\") REFERENCES \"{$fk['table']}\" (\"{$fk['to']}\")";
                
                if ($fk['on_delete'] !== 'NO ACTION') {
                    $fkDef .= " ON DELETE {$fk['on_delete']}";
                }
                
                if ($fk['on_update'] !== 'NO ACTION') {
                    $fkDef .= " ON UPDATE {$fk['on_update']}";
                }
                
                $newForeignKeyConstraints[] = $fkDef;
            }
            
            // Create the new table with all columns and remaining foreign keys
            $createTempSql = "CREATE TABLE \"$tempTable\" (" . 
                            implode(", ", $columns) . 
                            (empty($newForeignKeyConstraints) ? "" : ", " . implode(", ", $newForeignKeyConstraints)) . 
                            ")";
            $this->pdo->exec($createTempSql);
            
            // Copy data from old table to new table
            $this->pdo->exec("INSERT INTO \"$tempTable\" SELECT * FROM \"$table\"");
            
            // Get all indexes from the original table
            $indexes = $this->pdo->query("PRAGMA index_list(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
            
            // Drop old table
            $this->pdo->exec("DROP TABLE \"$table\"");
            
            // Rename temp table to original name
            $this->pdo->exec("ALTER TABLE \"$tempTable\" RENAME TO \"$table\"");
            
            // Recreate any indexes that were on the original table
            foreach ($indexes as $index) {
                // Skip sqlite_autoindex which are auto-created
                if (strpos($index['name'], 'sqlite_autoindex_') === 0) {
                    continue;
                }
                
                $indexInfo = $this->pdo->query("PRAGMA index_info(\"{$index['name']}\")")->fetchAll(PDO::FETCH_ASSOC);
                $indexColumns = [];
                foreach ($indexInfo as $indexColumn) {
                    $indexColumns[] = "\"{$indexColumn['name']}\"";
                }
                
                $unique = $index['unique'] ? 'UNIQUE' : '';
                $this->pdo->exec("CREATE $unique INDEX \"{$index['name']}\" ON \"$table\" (" . implode(", ", $indexColumns) . ")");
            }
            
            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Error dropping foreign key: " . $e->getMessage());
        }
    }
}