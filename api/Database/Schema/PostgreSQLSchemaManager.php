<?php

namespace Glueful\Database\Schema;

use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Connection;
use PDO;
use Exception;

class PostgreSQLSchemaManager extends SchemaManager
{
    protected PostgreSQLDriver $driver;
    protected PDO $pdo;

    public function __construct(PostgreSQLDriver $driver)
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

            $optionsSql = implode(' ', $options);
            $sql = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $columnsSql) . ") {$optionsSql};";

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
            $sql = "DROP TABLE IF EXISTS {$table} CASCADE;";
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
     * @param string $table
     * @param string $column
     * @return bool
     * @throws Exception
     */
    public function dropColumn(string $table, string $column): bool
    {
        try {
            $sql = "ALTER TABLE {$table} DROP COLUMN IF EXISTS {$column} CASCADE;";
            return $this->pdo->exec($sql) !== false;
        } catch (Exception $e) {
            throw new Exception("Error dropping column '{$column}' from table '{$table}': " . $e->getMessage());
        }
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
     * Get all tables in the database.
     *
     * @return array
     * @throws Exception
     */
    public function getTables(): array
    {
        try {
            $query = "
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public'
                ORDER BY table_name;
            ";

            $stmt = $this->pdo->query($query);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            throw new Exception("Error fetching tables: " . $e->getMessage());
        }
    }

    /**
     * Get columns for a given table.
     *
     * @param string $tableName
     * @return array
     * @throws Exception
     */
    public function getTableColumns(string $tableName): array
    {
        try {
            $query = "
                SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = :table
                ORDER BY ordinal_position;
            ";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute(['table' => $tableName]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching columns for table '{$tableName}': " . $e->getMessage());
        }
    }
}