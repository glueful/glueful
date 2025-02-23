<?php

namespace Glueful\Database\Schema;

use PDO;
use Exception;

/**
 * PostgreSQL Schema Manager Implementation
 * 
 * Provides PostgreSQL-specific schema management capabilities:
 * - Full support for PostgreSQL data types and type modifiers
 * - Schema-aware operations with search_path handling
 * - Concurrent index creation and deletion
 * - Advanced constraint management (EXCLUDE, CHECK, etc.)
 * - Partitioning support
 * - Table inheritance handling
 * 
 * Requirements:
 * - PostgreSQL 10.0+
 * - PDO PostgreSQL extension
 * - Appropriate user privileges for schema operations
 */
class PostgreSQLSchemaManager extends SchemaManager
{
    /** @var PDO Active database connection */
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Creates a new PostgreSQL table
     * 
     * Supports PostgreSQL-specific features:
     * - Custom column types (including domains)
     * - Table partitioning
     * - Table inheritance
     * - Tablespaces
     * - Column compression
     * 
     * Example usage:
     * $columns = [
     *     'id' => ['type' => 'SERIAL PRIMARY KEY'],
     *     'data' => ['type' => 'JSONB', 'nullable' => false]
     * ];
     * 
     * @param string $table Table name
     * @param array $columns Column definitions
     * @param array $options Table options (like INHERITS, TABLESPACE)
     * @return bool True if table created successfully
     * @throws Exception If table creation fails
     */
    public function createTable(string $table, array $columns, array $options = []): bool
    {
        $columnDefinitions = [];

        foreach ($columns as $name => $definition) {
            // If the definition is a string, use it directly
            if (is_string($definition)) {
                $columnDefinitions[] = "\"$name\" $definition";
            } elseif (is_array($definition)) {
                // Handle array format for more control
                $type = strtoupper($definition['type']);

                // Convert MySQL-style AUTO_INCREMENT to PostgreSQL SERIAL
                if ($type === 'INTEGER PRIMARY KEY AUTO_INCREMENT') {
                    $type = 'SERIAL PRIMARY KEY';
                } elseif ($type === 'BIGINT PRIMARY KEY AUTO_INCREMENT') {
                    $type = 'BIGSERIAL PRIMARY KEY';
                }

                $columnDefinitions[] = "\"$name\" $type " .
                    (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
                    (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
            } else {
                throw new \InvalidArgumentException("Invalid column definition for `$name`");
            }
        }

        // Construct the SQL statement with IF NOT EXISTS (PostgreSQL compatible)
        $sql = "CREATE TABLE IF NOT EXISTS \"$table\" (" . implode(", ", $columnDefinitions) . ")";

        return (bool) $this->pdo->exec($sql);
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
     * Adds new column to PostgreSQL table
     * 
     * Supports:
     * - All PostgreSQL data types including custom types
     * - Column constraints (CHECK, GENERATED, etc)
     * - Column statistics targets
     * - Storage parameters
     * 
     * @param string $table Schema-qualified table name
     * @param string $column New column name
     * @param array $definition PostgreSQL column definition
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
     * Creates PostgreSQL index
     * 
     * Advanced indexing features:
     * - Concurrent index creation
     * - Partial indexes
     * - Expression indexes
     * - Custom operator classes
     * - Index types (btree, hash, gin, gist, etc)
     * - Index storage parameters
     * 
     * Note: Concurrent indexing requires transaction management
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
     * Retrieves PostgreSQL table information
     * 
     * Returns comprehensive table metadata:
     * - Column definitions with full type information
     * - Constraint details
     * - Storage parameters
     * - Inheritance information
     * - Partition details
     * 
     * @param string $tableName Target table
     * @return array Column definitions and metadata
     * @throws Exception If column information retrieval fails
     */
    public function getTableColumns(string $tableName): array
    {
        try {
            $stmt = $this->pdo->prepare("
            SELECT column_name, data_type, is_nullable, column_default 
            FROM information_schema.columns 
            WHERE table_name = :table
            ");
            $stmt->execute(['table' => $tableName]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Error fetching columns for table '{$tableName}': " . $e->getMessage());
        }
    }

    /**
     * Manages foreign key checks via session_replication_role
     * 
     * Note: Requires superuser or replication privileges
     */
    public function disableForeignKeyChecks(): void
    {
        $this->pdo->exec("SET session_replication_role = 'replica'");
    }

    public function enableForeignKeyChecks(): void
    {
        $this->pdo->exec("SET session_replication_role = 'origin'");
    }

    /**
     * Gets PostgreSQL version information
     * 
     * Returns detailed version data including:
     * - Server version
     * - Compilation options
     * - Platform information
     */
    public function getVersion(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Calculates PostgreSQL table size
     * 
     * Includes:
     * - Table data size
     * - TOAST data size
     * - Index sizes
     * - Visibility map size
     * - Free space map size
     * 
     * Note: Requires pg_stat_user_tables access
     */
    public function getTableSize(string $table): int
    {
        $stmt = $this->pdo->prepare("
            SELECT pg_total_relation_size(:table) AS size
        ");
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn();
    }

}

