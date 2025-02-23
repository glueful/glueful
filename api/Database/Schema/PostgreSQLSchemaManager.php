<?php

namespace Glueful\Database\Schema;

use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Connection;
use PDO;
use Exception;

/**
 * PostgreSQL Schema Manager Implementation
 * 
 * Handles PostgreSQL-specific schema operations including:
 * - Table creation and deletion
 * - Column management with PostgreSQL types
 * - Index operations including concurrent indexing
 * - Schema information retrieval
 * - Cascade operations handling
 * 
 * Implements database-specific features while maintaining
 * compatibility with the SchemaManager interface.
 */
class PostgreSQLSchemaManager extends SchemaManager
{
    /** @var PostgreSQLDriver Database-specific driver */
    protected PostgreSQLDriver $driver;

    /** @var PDO Active database connection */
    protected PDO $pdo;

    /**
     * Initialize PostgreSQL schema manager
     * 
     * @param PostgreSQLDriver $driver PostgreSQL-specific driver
     * @throws Exception If connection fails
     */
    public function __construct(PostgreSQLDriver $driver)
    {
        $connection = new Connection();
        $this->pdo = $connection->getPDO();
    }

    /**
     * Create new PostgreSQL table
     * 
     * Creates table with specified columns and constraints.
     * Supports PostgreSQL-specific column types and options.
     * 
     * @param string $table Table name
     * @param array $columns Column definitions
     * @param array $options Table options (like INHERITS, TABLESPACE)
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

            $optionsSql = implode(' ', $options);
            $sql = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $columnsSql) . ") {$optionsSql};";

            return $this->pdo->exec($sql) !== false;
        } catch (Exception $e) {
            throw new Exception("Error creating table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Drop PostgreSQL table
     * 
     * Removes table with CASCADE option for dependent objects.
     * 
     * @param string $table Table to drop
     * @return bool True if table dropped successfully
     * @throws Exception If table drop fails
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
     * Add column to PostgreSQL table
     * 
     * Adds new column with full PostgreSQL type support.
     * 
     * @param string $table Target table
     * @param string $column New column name
     * @param array $definition Column definition including type and constraints
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
     * Drop column from PostgreSQL table
     * 
     * Removes column with CASCADE option for dependencies.
     * 
     * @param string $table Target table
     * @param string $column Column to remove
     * @return bool True if column dropped successfully
     * @throws Exception If column removal fails
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
     * Create PostgreSQL index
     * 
     * Creates index with support for:
     * - Concurrent creation
     * - Partial indexes
     * - Custom operators
     * - Index types (btree, hash, gist, etc)
     * 
     * @param string $table Target table
     * @param string $indexName Name for new index
     * @param array $columns Columns to index
     * @param bool $unique Whether to create unique index
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
     * Drop PostgreSQL index
     * 
     * Removes index with CONCURRENTLY option when possible.
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
     * Get PostgreSQL tables
     * 
     * Retrieves tables from information_schema with:
     * - Schema filtering
     * - System table exclusion
     * - Proper escaping
     * 
     * @return array List of table names
     * @throws Exception If table list retrieval fails
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
     * Get PostgreSQL table columns
     * 
     * Retrieves detailed column information including:
     * - Data types
     * - Default values
     * - Constraints
     * - Comments
     * 
     * @param string $tableName Target table
     * @return array Column definitions and metadata
     * @throws Exception If column information retrieval fails
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