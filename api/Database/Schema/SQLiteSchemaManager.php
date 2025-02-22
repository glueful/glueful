<?php

namespace Glueful\Database\Schema;

use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Connection;
use PDO;
use Exception;

class SQLiteSchemaManager extends SchemaManager
{
    protected SQLiteDriver $driver;
    protected PDO $pdo;

    public function __construct(SQLiteDriver $driver)
    {
        $connection = new Connection();
        $this->pdo = $connection->getPDO();
    }

    /**
     * Create a new table.
     *
     * @param string $table
     * @param array $columns
     * @param array $options
     * @return bool
     * @throws Exception
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
     * Drop a table.
     *
     * @param string $table
     * @return bool
     * @throws Exception
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
     * Add a column to a table.
     *
     * @param string $table
     * @param string $column
     * @param array $definition
     * @return bool
     * @throws Exception
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
     * Drop a column from a table.
     * 
     * ⚠️ SQLite does NOT support dropping a column directly.
     * The workaround is to create a new table without the column and migrate data.
     *
     * @param string $table
     * @param string $column
     * @return bool
     * @throws Exception
     */
    public function dropColumn(string $table, string $column): bool
    {
        throw new Exception("SQLite does not support dropping columns directly.");
    }

    /**
     * Create an index on a table.
     *
     * @param string $table
     * @param string $indexName
     * @param array $columns
     * @param bool $unique
     * @return bool
     * @throws Exception
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
     * Drop an index from a table.
     *
     * @param string $table
     * @param string $indexName
     * @return bool
     * @throws Exception
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
     * Get all table names in the database.
     *
     * @return array
     * @throws Exception
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
     * Get all columns for a specific table.
     *
     * @param string $table
     * @return array
     * @throws Exception
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