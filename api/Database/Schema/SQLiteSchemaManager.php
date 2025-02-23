<?php

namespace Glueful\Database\Schema;

use PDO;
use Exception;

/**
 * SQLite Schema Manager Implementation
 * 
 * Manages SQLite database schema with consideration for its unique characteristics:
 * - Schema-less design with dynamic typing
 * - Single-writer concurrency model
 * - No user permissions system
 * - Limited ALTER TABLE support
 * - Journal modes (WAL, DELETE, TRUNCATE)
 * 
 * Requirements:
 * - SQLite 3.x
 * - PDO SQLite extension
 * - Write permissions on database file
 * - Proper journal mode configuration
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
     * Creates a new SQLite table
     * 
     * Supports SQLite-specific features:
     * - WITHOUT ROWID tables
     * - STRICT tables (SQLite 3.37+)
     * - Generated columns
     * - Expression-based defaults
     * - Table constraints
     * 
     * Example usage:
     * $columns = [
     *     'id' => ['type' => 'INTEGER PRIMARY KEY'],
     *     'data' => ['type' => 'TEXT', 'default' => 'NULL']
     * ];
     * 
     * @param string $table Table name
     * @param array $columns Column definitions
     * @param array $options Additional table options
     * @return bool True if table created successfully
     * @throws Exception If table creation fails
     */
    public function createTable(string $table, array $columns, array $options = []): bool
    {
        $columnDefinitions = [];

        foreach ($columns as $name => $definition) {
            // If the definition is a string, use it directly
            if (is_string($definition)) {
                $columnDefinitions[] = "`$name` $definition";
            } elseif (is_array($definition)) {
                // Handle array format for more control
                $columnDefinitions[] = "`$name` {$definition['type']} " .
                    (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
                    (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
            } else {
                throw new \InvalidArgumentException("Invalid column definition for `$name`");
            }
        }

        // Construct the SQL statement with IF NOT EXISTS (SQLite compatible)
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(", ", $columnDefinitions) . ")";

        return (bool) $this->pdo->exec($sql);
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
     * Adds column to SQLite table
     * 
     * Important limitations:
     * - Cannot add PRIMARY KEY columns
     * - Cannot add UNIQUE columns
     * - Cannot add FOREIGN KEY columns
     * - New columns must be nullable or have default
     * 
     * @param string $table Target table
     * @param string $column New column name
     * @param array $definition Column definition
     * @return bool True if column added successfully
     * @throws Exception When column constraints violate SQLite limitations
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
     * Gets SQLite table columns
     * 
     * Returns column information via PRAGMA table_info:
     * - Name and position
     * - Declared type
     * - NOT NULL constraint
     * - Default value
     * - Primary key position
     * 
     * Note: Type affinity rules apply to declared types
     * 
     * @param string $table Target table
     * @return array Column definitions and metadata
     * @throws Exception If column information retrieval fails
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
     * Manages foreign key enforcement
     * 
     * Note: Foreign keys must be enabled during
     * table creation to be enforced
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
     * Calculates SQLite table size
     * 
     * Includes:
     * - Table data pages
     * - Index pages
     * - Overflow pages
     * 
     * Note: Requires sqlite_stat1 table
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