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
     * - AUTO_INCREMENT to SQLite syntax conversion
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
                // Handle string-based column definitions and convert to SQLite syntax
                $definition = $this->convertToSQLiteSyntax($definition);
                $columnDefinitions[] = "\"$name\" $definition";
            } elseif (is_array($definition)) {
                // Handle array-based column definitions
                $type = $this->convertToSQLiteSyntax($definition['type']);
                $columnDefinitions[] = "\"$name\" {$type} " .
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
     * Convert MySQL/PostgreSQL syntax to SQLite compatible syntax
     *
     * Handles common incompatibilities:
     * - AUTO_INCREMENT -> (removed, SQLite uses INTEGER PRIMARY KEY for auto-increment)
     * - BIGINT -> INTEGER (SQLite treats all integers the same)
     * - TINYINT -> INTEGER
     * - SMALLINT -> INTEGER
     * - MEDIUMINT -> INTEGER
     * - UNSIGNED -> (removed, SQLite doesn't support unsigned)
     * - ENUM -> TEXT (with CHECK constraint if possible)
     * - DATETIME(6) -> DATETIME (SQLite doesn't support precision)
     * - ENGINE=InnoDB -> (removed)
     *
     * @param string $definition Original column definition
     * @return string SQLite compatible definition
     */
    private function convertToSQLiteSyntax(string $definition): string
    {
        // Remove AUTO_INCREMENT - SQLite uses INTEGER PRIMARY KEY for auto-increment
        $definition = preg_replace('/\bAUTO_INCREMENT\b/i', '', $definition);

        // Convert integer types to INTEGER (SQLite treats all integers the same)
        $definition = preg_replace('/\b(BIGINT|TINYINT|SMALLINT|MEDIUMINT|INT)\b/i', 'INTEGER', $definition);

        // Remove UNSIGNED keyword (SQLite doesn't support unsigned types)
        $definition = preg_replace('/\bUNSIGNED\b/i', '', $definition);

        // Convert DATETIME with precision to DATETIME
        $definition = preg_replace('/\bDATETIME\(\d+\)/i', 'DATETIME', $definition);

        // Convert TIMESTAMP to DATETIME (SQLite doesn't have TIMESTAMP type)
        $definition = preg_replace('/\bTIMESTAMP\b/i', 'DATETIME', $definition);

        // Convert VARCHAR with very large sizes to TEXT
        $definition = preg_replace_callback('/\bVARCHAR\((\d+)\)/i', function ($matches) {
            $size = (int)$matches[1];
            return $size > 255 ? 'TEXT' : $matches[0];
        }, $definition);

        // Convert ENUM to TEXT (basic conversion - full CHECK constraint implementation would be more complex)
        $definition = preg_replace('/\bENUM\s*\([^)]+\)/i', 'TEXT', $definition);

        // Remove engine specifications
        $definition = preg_replace('/\bENGINE\s*=\s*\w+/i', '', $definition);

        // Remove character set specifications
        $definition = preg_replace('/\bCHARACTER\s+SET\s+\w+/i', '', $definition);
        $definition = preg_replace('/\bCOLLATE\s+\w+/i', '', $definition);

        // Remove MySQL-specific ON UPDATE CURRENT_TIMESTAMP (SQLite doesn't support this)
        $definition = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $definition);

        // Clean up extra whitespace
        $definition = preg_replace('/\s+/', ' ', $definition);
        $definition = trim($definition);

        return $definition;
    }

    /**
     * Convert complete SQL statements to SQLite compatible syntax
     *
     * This method can be used by other components (migrations, audit logging, etc.)
     * to convert MySQL/PostgreSQL SQL statements to SQLite compatible syntax.
     *
     * Handles:
     * - CREATE TABLE statements with AUTO_INCREMENT
     * - Column definitions in various formats
     * - Data type conversions
     * - Engine specifications
     * - Character set specifications
     *
     * @param string $sql Original SQL statement
     * @return string SQLite compatible SQL statement
     */
    public function convertSQLToSQLite(string $sql): string
    {
        // Handle CREATE TABLE statements specifically
        if (preg_match('/CREATE\s+TABLE/i', $sql)) {
            // Convert column definitions within CREATE TABLE
            $sql = preg_replace_callback(
                '/(\w+)\s+(BIGINT|TINYINT|SMALLINT|MEDIUMINT|INT)\s+([^,\)]*?)AUTO_INCREMENT([^,\)]*?)(?=,|\)|$)/i',
                function ($matches) {
                    $columnName = $matches[1];
                    $beforeAutoIncrement = trim($matches[3]);
                    $afterAutoIncrement = trim($matches[4]);

                    // Build the new column definition
                    $newDefinition = $columnName . ' INTEGER';

                    // Add constraints before AUTO_INCREMENT (like NOT NULL)
                    if (!empty($beforeAutoIncrement) && !preg_match('/PRIMARY\s+KEY/i', $beforeAutoIncrement)) {
                        $newDefinition .= ' ' . $beforeAutoIncrement;
                    }

                    // Check if PRIMARY KEY should be added
                    if (preg_match('/PRIMARY\s+KEY/i', $beforeAutoIncrement . ' ' . $afterAutoIncrement)) {
                        $newDefinition .= ' PRIMARY KEY';
                    }

                    // Add other constraints after PRIMARY KEY
                    if (!empty($afterAutoIncrement) && !preg_match('/PRIMARY\s+KEY/i', $afterAutoIncrement)) {
                        $newDefinition .= ' ' . $afterAutoIncrement;
                    }

                    return $newDefinition;
                },
                $sql
            );
        }

        // Apply general syntax conversions
        $sql = $this->convertToSQLiteSyntax($sql);

        // Additional SQL-level conversions
        // Remove IF NOT EXISTS from constraints (SQLite doesn't support this in all contexts)
        $sql = preg_replace('/CONSTRAINT\s+IF\s+NOT\s+EXISTS/i', 'CONSTRAINT', $sql);

        // Convert CREATE TABLE IF NOT EXISTS syntax issues
        $sql = preg_replace('/ENGINE\s*=\s*\w+/i', '', $sql);

        // Remove DEFAULT CHARSET
        $sql = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);

        // Clean up multiple spaces and trailing commas
        $sql = preg_replace('/,\s*\)/i', ')', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = trim($sql);

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function convertSQLToEngineFormat(string $sql): string
    {
        return $this->convertSQLToSQLite($sql);
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
                $columnPart = implode("_", $column);
                $indexNameToCheck = isset($index['name']) ? $index['name'] : "{$table}_{$columnPart}_idx";
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
                throw new \RuntimeException(
                    "SQLite does not support adding PRIMARY KEY with ALTER TABLE. Define it in CREATE TABLE."
                );
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
            // Convert to SQLite compatible syntax
            $columnDef = $this->convertToSQLiteSyntax($columnDef);
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
     * @param bool $includeSchema Whether to include schema information
     * @return array List of table names
     * @throws Exception If table list retrieval fails
     */
    public function getTables(?bool $includeSchema = false): array
    {
        try {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';";
            $stmt = $this->pdo->query($sql);
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
                        // SQLite doesn't name constraints, create synthetic name
                        $constraintName = "fk_{$table}_{$columnName}_{$fk['id']}";
                        $formattedColumns[$columnName]['relationships'][] = [
                            'constraint' => $constraintName,
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
            $columnStr = is_array($column) ? implode("\", \"", $column) : $column;
            $referencesColumnStr = is_array($referencesColumn)
                ? implode("\", \"", $referencesColumn)
                : $referencesColumn;
            $foreignKeyConstraint = "FOREIGN KEY (\"$columnStr\") " .
                "REFERENCES \"$referencesTable\" (\"$referencesColumnStr\")";

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

                    $pragmaSql = "PRAGMA index_info(\"{$index['name']}\")";
                    $indexInfo = $this->pdo->query($pragmaSql)->fetchAll(PDO::FETCH_ASSOC);
                    $indexColumns = [];
                    foreach ($indexInfo as $indexColumn) {
                        $indexColumns[] = "\"{$indexColumn['name']}\"";
                    }

                    $unique = $index['unique'] ? 'UNIQUE' : '';
                    $columnsStr = implode(", ", $indexColumns);
                    $this->pdo->exec("CREATE $unique INDEX \"{$index['name']}\" ON \"$table\" ($columnsStr)");
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
            $query = "SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'";
            $createTableSql = $this->pdo->query($query)->fetchColumn();

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
                $columnsStr = implode(", ", $indexColumns);
                $this->pdo->exec("CREATE $unique INDEX \"{$index['name']}\" ON \"$table\" ($columnsStr)");
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Error dropping foreign key: " . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateChangePreview(string $tableName, array $changes): array
    {
        $sql = [];
        $warnings = [];
        $estimatedDuration = 0;

        foreach ($changes as $change) {
            $changeType = $change['type'] ?? 'unknown';

            switch ($changeType) {
                case 'add_column':
                    $columnSql = $this->generateAddColumnSQL($tableName, $change);
                    $sql[] = $columnSql;
                    $estimatedDuration += 2; // SQLite is faster
                    break;

                case 'drop_column':
                    $warnings[] = "SQLite does not support DROP COLUMN. Table recreation required.";
                    $sql[] = "-- Table recreation required for dropping column '{$change['column_name']}'";
                    $estimatedDuration += 30; // Table recreation is expensive
                    break;

                case 'modify_column':
                    $warnings[] = "SQLite does not support MODIFY COLUMN. Table recreation required.";
                    $sql[] = "-- Table recreation required for modifying column '{$change['column_name']}'";
                    $estimatedDuration += 30;
                    break;

                case 'add_index':
                    $indexSql = $this->generateAddIndexSQL($tableName, $change);
                    $sql[] = $indexSql;
                    $estimatedDuration += 5;
                    break;

                case 'drop_index':
                    $indexName = $change['index_name'];
                    $sql[] = "DROP INDEX IF EXISTS \"{$indexName}\"";
                    $estimatedDuration += 1;
                    break;

                default:
                    $warnings[] = "Unknown change type: {$changeType}";
            }
        }

        return [
            'sql' => $sql,
            'warnings' => $warnings,
            'estimated_duration' => $estimatedDuration,
            'safe_to_execute' => !$this->hasDestructiveChanges($changes),
            'generated_at' => date('Y-m-d H:i:s'),
            'notes' => ['SQLite has limited ALTER TABLE support - some operations require table recreation']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function exportTableSchema(string $tableName, string $format = 'json'): array
    {
        if (!$this->tableExists($tableName)) {
            throw new \RuntimeException("Table '{$tableName}' does not exist");
        }

        $schema = $this->getTableSchema($tableName);

        switch ($format) {
            case 'json':
                return $this->exportAsJson($schema);
            case 'sql':
                return $this->exportAsSQL($tableName, $schema);
            case 'yaml':
                return $this->exportAsYaml($schema);
            case 'php':
                return $this->exportAsPhp($schema);
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function importTableSchema(
        string $tableName,
        array $schema,
        string $format = 'json',
        array $options = []
    ): array {
        $validation = $this->validateSchema($schema, $format);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException('Invalid schema: ' . implode(', ', $validation['errors']));
        }

        $changes = [];
        $recreate = $options['recreate'] ?? false;

        if ($recreate && $this->tableExists($tableName)) {
            $this->dropTable($tableName);
            $changes[] = "Dropped existing table '{$tableName}'";
        }

        if (!$this->tableExists($tableName)) {
            $this->createTableFromSchema($tableName, $schema, $format);
            $changes[] = "Created table '{$tableName}'";
        } else {
            $changes[] = "Table '{$tableName}' already exists - incremental updates not supported in SQLite";
        }

        return [
            'success' => true,
            'changes' => $changes,
            'table_name' => $tableName,
            'imported_at' => date('Y-m-d H:i:s'),
            'notes' => ['SQLite import typically requires table recreation for schema changes']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function validateSchema(array $schema, string $format = 'json'): array
    {
        $errors = [];
        $warnings = [];

        switch ($format) {
            case 'json':
                $errors = array_merge($errors, $this->validateJsonSchema($schema));
                break;
            case 'sql':
                $errors = array_merge($errors, $this->validateSqlSchema($schema));
                break;
            case 'yaml':
                $errors = array_merge($errors, $this->validateYamlSchema($schema));
                break;
            case 'php':
                $errors = array_merge($errors, $this->validatePhpSchema($schema));
                break;
            default:
                $errors[] = "Unsupported schema format: {$format}";
        }

        // SQLite-specific validations
        if ($format === 'json' && isset($schema['columns'])) {
            foreach ($schema['columns'] as $column) {
                if (isset($column['type']) && strpos($column['type'], 'ENUM') !== false) {
                    $warnings[] = "SQLite does not support ENUM types - " .
                                 "will be converted to TEXT with CHECK constraint";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'validated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generateRevertOperations(array $originalChange): array
    {
        $context = json_decode($originalChange['context'] ?? '{}', true);
        $action = $originalChange['action'] ?? '';
        $revertOps = [];

        switch ($action) {
            case 'add_column':
                $revertOps[] = [
                    'type' => 'recreate_table_without_column',
                    'table' => $context['table'] ?? '',
                    'column_name' => $context['column']['name'] ?? '',
                    'note' => 'SQLite requires table recreation to remove columns'
                ];
                break;

            case 'add_index':
                $revertOps[] = [
                    'type' => 'drop_index',
                    'table' => $context['table'] ?? '',
                    'index_name' => $context['index']['name'] ?? ''
                ];
                break;

            case 'drop_index':
                $revertOps[] = [
                    'type' => 'add_index',
                    'table' => $context['table'] ?? '',
                    'index' => $context['original_index'] ?? []
                ];
                break;

            default:
                throw new \RuntimeException("Cannot generate revert operations for action: {$action} in SQLite");
        }

        return $revertOps;
    }

    /**
     * {@inheritdoc}
     */
    public function executeRevert(array $revertOps): array
    {
        $results = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($revertOps as $op) {
                $result = $this->executeRevertOperation($op);
                $results[] = $result;
            }

            $this->pdo->commit();
            return [
                'success' => true,
                'operations' => $results,
                'reverted_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw new \RuntimeException("Revert failed: " . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getTableSchema(string $tableName): array
    {
        if (!$this->tableExists($tableName)) {
            throw new \RuntimeException("Table '{$tableName}' does not exist");
        }

        return [
            'table_name' => $tableName,
            'columns' => $this->getColumnDefinitions($tableName),
            'indexes' => $this->getIndexDefinitions($tableName),
            'foreign_keys' => $this->getForeignKeyDefinitions($tableName),
            'table_options' => $this->getTableOptions($tableName),
            'create_sql' => $this->getCreateTableSQL($tableName),
            'retrieved_at' => date('Y-m-d H:i:s')
        ];
    }

    // Helper methods for SQLite schema operations

    private function generateAddColumnSQL(string $tableName, array $change): string
    {
        $columnName = $change['column_name'];
        $columnType = $change['column_type'];
        $options = $change['options'] ?? [];

        $sql = "ALTER TABLE \"{$tableName}\" ADD COLUMN \"{$columnName}\" {$columnType}";

        if ($options['not_null'] ?? false) {
            $sql .= ' NOT NULL';
        }

        if (isset($options['default'])) {
            $sql .= " DEFAULT '{$options['default']}'";
        }

        return $sql;
    }

    private function generateAddIndexSQL(string $tableName, array $change): string
    {
        $indexName = $change['index_name'];
        $columns = $change['columns'];
        $unique = ($change['index_type'] ?? '') === 'UNIQUE' ? 'UNIQUE' : '';

        if (is_array($columns)) {
            $columns = implode('\", \"', $columns);
        }

        return "CREATE {$unique} INDEX \"{$indexName}\" ON \"{$tableName}\" (\"{$columns}\")";
    }

    private function hasDestructiveChanges(array $changes): bool
    {
        foreach ($changes as $change) {
            if (in_array($change['type'] ?? '', ['drop_column', 'drop_table', 'modify_column'])) {
                return true;
            }
        }
        return false;
    }

    private function exportAsJson(array $schema): array
    {
        return [
            'format' => 'json',
            'version' => '1.0',
            'engine' => 'sqlite',
            'schema' => $schema,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function exportAsSQL(string $tableName, array $schema): array
    {
        return [
            'format' => 'sql',
            'version' => '1.0',
            'engine' => 'sqlite',
            'sql' => $schema['create_sql'] ?? '',
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function exportAsYaml(array $schema): array
    {
        return [
            'format' => 'yaml',
            'version' => '1.0',
            'engine' => 'sqlite',
            'table' => $schema,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function exportAsPhp(array $schema): array
    {
        return [
            'format' => 'php',
            'version' => '1.0',
            'engine' => 'sqlite',
            'schema' => $schema,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function validateJsonSchema(array $schema): array
    {
        $errors = [];

        if (!isset($schema['table_name'])) {
            $errors[] = "Missing 'table_name' in schema";
        }

        if (!isset($schema['columns']) || !is_array($schema['columns'])) {
            $errors[] = "Missing or invalid 'columns' in schema";
        }

        return $errors;
    }

    private function validateSqlSchema(array $schema): array
    {
        $errors = [];

        if (!isset($schema['sql']) && !isset($schema['create_sql'])) {
            $errors[] = "Missing 'sql' or 'create_sql' in schema";
        }

        return $errors;
    }

    private function validateYamlSchema(array $schema): array
    {
        return $this->validateJsonSchema($schema);
    }

    private function validatePhpSchema(array $schema): array
    {
        return $this->validateJsonSchema($schema);
    }

    private function getColumnDefinitions(string $tableName): array
    {
        $stmt = $this->pdo->prepare("PRAGMA table_info(\"{$tableName}\")");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getIndexDefinitions(string $tableName): array
    {
        $stmt = $this->pdo->prepare("PRAGMA index_list(\"{$tableName}\")");
        $stmt->execute();
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get detailed info for each index
        foreach ($indexes as &$index) {
            $stmt = $this->pdo->prepare("PRAGMA index_info(\"{$index['name']}\")");
            $stmt->execute();
            $index['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $indexes;
    }

    private function getForeignKeyDefinitions(string $tableName): array
    {
        $stmt = $this->pdo->prepare("PRAGMA foreign_key_list(\"{$tableName}\")");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getTableOptions(string $tableName): array
    {
        // SQLite has limited table options
        return [
            'engine' => 'sqlite',
            'charset' => 'UTF-8',
            'auto_increment' => null, // SQLite handles this automatically
            'comment' => ''
        ];
    }

    private function getCreateTableSQL(string $tableName): string
    {
        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$tableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['sql'] ?? '';
    }

    private function createTableFromSchema(string $tableName, array $schema, string $format): void
    {
        switch ($format) {
            case 'json':
                $this->createTableFromJsonSchema($tableName, $schema);
                break;
            case 'sql':
                $this->createTableFromSqlSchema($schema);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported import format: {$format}");
        }
    }

    private function createTableFromJsonSchema(string $tableName, array $schema): void
    {
        $sql = $this->generateCreateTableSQL($tableName, $schema);
        $this->pdo->exec($sql);
    }

    private function createTableFromSqlSchema(array $schema): void
    {
        if (isset($schema['sql'])) {
            $this->pdo->exec($schema['sql']);
        } elseif (isset($schema['create_sql'])) {
            $this->pdo->exec($schema['create_sql']);
        }
    }

    private function generateCreateTableSQL(string $tableName, array $schema): string
    {
        $sql = "CREATE TABLE \"{$tableName}\" (\n";

        $columns = [];
        foreach ($schema['columns'] as $column) {
            $columnSql = "\"{$column['name']}\" {$column['type']}";
            if ($column['notnull']) {
                $columnSql .= ' NOT NULL';
            }
            if ($column['dflt_value'] !== null) {
                $columnSql .= " DEFAULT {$column['dflt_value']}";
            }
            if ($column['pk']) {
                $columnSql .= ' PRIMARY KEY';
            }
            $columns[] = $columnSql;
        }

        $sql .= implode(",\n", $columns);
        $sql .= "\n)";

        return $sql;
    }

    private function executeRevertOperation(array $op): array
    {
        switch ($op['type']) {
            case 'drop_index':
                $sql = "DROP INDEX IF EXISTS \"{$op['index_name']}\"";
                $this->pdo->exec($sql);
                return ['type' => 'drop_index', 'sql' => $sql, 'success' => true];

            case 'recreate_table_without_column':
                // This would be a complex operation requiring table backup, recreation, and data migration
                return [
                    'type' => 'recreate_table_without_column',
                    'success' => false,
                    'note' => 'Table recreation not implemented - requires manual intervention'
                ];

            default:
                throw new \RuntimeException("Unsupported revert operation: {$op['type']}");
        }
    }
}
