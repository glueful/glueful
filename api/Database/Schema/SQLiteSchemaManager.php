<?php

namespace Glueful\Database\Schema;

use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Connection;
use PDO;
use Exception;

/**
 * SQLite Schema Manager Implementation
 * 
 * Handles SQLite-specific schema operations including:
 * - Table management
 * - Column operations (with SQLite limitations)
 * - Index handling
 * - Schema information retrieval
 * 
 * Note: SQLite has specific limitations:
 * - No direct column dropping
 * - Limited ALTER TABLE support
 * - No native foreign key constraints
 * - Single-writer concurrency model
 */
class SQLiteSchemaManager extends SchemaManager
{
    /** @var SQLiteDriver Database-specific driver */
    protected SQLiteDriver $driver;

    /** @var PDO Active database connection */
    protected PDO $pdo;

    /**
     * Initialize SQLite schema manager
     * 
     * @param SQLiteDriver $driver SQLite-specific driver
     * @throws Exception If connection fails
     */
    public function __construct(SQLiteDriver $driver)
    {
        $connection = new Connection();
        $this->pdo = $connection->getPDO();
    }

    /**
     * Create new SQLite table
     * 
     * Creates table with specified columns. Supports:
     * - Primary keys
     * - Auto-increment
     * - Column constraints
     * - Default values
     * 
     * @param string $table Table name
     * @param array $columns Column definitions
     * @param array $options Additional table options
     * @return bool True if table created successfully
     * @throws Exception If table creation fails
     */
    public function createTable(string $table, array $columns, array $options = []): bool
    {
        try {
            $columnsSql = [];
            foreach ($columns as $name => $definition) {
                $columnsSql[] = "{$name} {$definition}";
            }

            $sql = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $columnsSql) . ");";
            return $this->pdo->exec($sql) !== false;
        } catch (Exception $e) {
            throw new Exception("Error creating table '{$table}': " . $e->getMessage());
        }
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
     * Add column to SQLite table
     * 
     * Note: SQLite has limited ALTER TABLE support.
     * New columns:
     * - Cannot have NOT NULL constraint
     * - Must have default value or be nullable
     * - Cannot be primary key
     * 
     * @param string $table Target table
     * @param string $column New column name
     * @param array $definition Column definition
     * @return bool True if column added successfully
     * @throws Exception If column addition fails
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
     * Create SQLite index
     * 
     * Creates index with optional uniqueness constraint.
     * Supports:
     * - Single and multi-column indexes
     * - Unique indexes
     * - Expression indexes (SQLite 3.9+)
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
     * Get SQLite table columns
     * 
     * Retrieves column information using PRAGMA table_info.
     * Returns:
     * - Column names
     * - Data types
     * - Constraints
     * - Default values
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
}