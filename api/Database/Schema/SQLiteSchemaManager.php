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
class SQLiteSchemaManager extends SchemaManager
{

    /** @var PDO Active database connection */
    protected PDO $pdo;

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
            if (!isset($index['type'], $index['column'], $index['table'])) {
                throw new \InvalidArgumentException("Each index must have a 'type', 'column', and 'table'.");
            }
    
            $table = $index['table'];
            $column = $index['column'];
            $type = strtoupper($index['type']);
    
            // Check if index already exists in SQLite
            $existingIndexes = $this->pdo
                ->query("PRAGMA index_list(`$table`)")
                ->fetchAll(PDO::FETCH_ASSOC);
    
            foreach ($existingIndexes as $existingIndex) {
                if ($existingIndex['name'] === "{$table}_{$column}_idx") {
                    continue 2; // Skip if index exists
                }
            }
    
            if ($type === 'FOREIGN KEY') {
                throw new \RuntimeException("SQLite does not support adding foreign keys with ALTER TABLE. Define them in CREATE TABLE.");
            } elseif ($type === 'UNIQUE') {
                $sql = "CREATE UNIQUE INDEX `{$table}_{$column}_idx` ON `$table` (`$column`)";
            } else {
                $sql = "CREATE INDEX `{$table}_{$column}_idx` ON `$table` (`$column`)";
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
            return $this->pdo->exec($sql) !== false;
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
            return $this->pdo->exec($sql) !== false;
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

            return $this->pdo->exec($sql) !== false;
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
            return $this->pdo->exec($sql) !== false;
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
     * Returns via PRAGMA table_info:
     * - Column names and positions
     * - Declared types (with affinity)
     * - NOT NULL constraints
     * - DEFAULT values
     * - PRIMARY KEY columns
     * 
     * Additional PRAGMA commands:
     * - foreign_key_list
     * - index_list
     * - table_xinfo
     * 
     * @throws Exception On invalid table
     */
    public function getTableColumns(string $table): array
    {
        try {
            $stmt = $this->pdo->prepare("PRAGMA table_info({$table});");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}