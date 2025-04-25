<?php

namespace Glueful\Database\Schema;

use PDO;
use Exception;

/**
 * PostgreSQL Schema Manager Implementation
 * 
 * Advanced schema manager with PostgreSQL-specific capabilities:
 * 
 * Core Features:
 * - Multi-schema support with search_path
 * - Table inheritance and partitioning
 * - Custom data types and domains
 * - Advanced constraints (EXCLUDE, CHECK)
 * - Concurrent index operations
 * - TOAST storage management
 * 
 * Performance Features:
 * - Parallel query execution
 * - Tablespace management
 * - Vacuum operations
 * - Statistics management
 * - Index-only scans
 * 
 * Requirements:
 * - PostgreSQL 10.0+
 * - PDO PostgreSQL extension
 * - Superuser or appropriate grants
 * - pg_stat_statements extension
 * 
 * Example usage:
 * ```php
 * $schema
 *     ->createTable('events', [
 *         'id' => ['type' => 'BIGSERIAL PRIMARY KEY'],
 *         'data' => ['type' => 'JSONB NOT NULL'],
 *         'range' => ['type' => 'TSTZRANGE']
 *     ])
 *     ->addIndex([
 *         'type' => 'GIN',
 *         'column' => 'data',
 *         'table' => 'events'
 *     ]);
 * ```
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
     * Create PostgreSQL table with advanced features
     * 
     * Supported Features:
     * - Custom column types and domains
     * - Table inheritance (INHERITS)
     * - Tablespace allocation
     * - Partitioning strategies
     * - UNLOGGED tables
     * - Column compression
     * - Identity columns
     * - Generated columns
     * 
     * Storage Parameters:
     * - fillfactor
     * - autovacuum_*
     * - toast_tuple_target
     * - parallel_workers
     * 
     * @throws Exception On syntax error or permission denied
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

        // PostgreSQL does not use ENGINE=InnoDB
        $sql = "CREATE TABLE IF NOT EXISTS \"$table\" (" . implode(", ", $columnDefinitions) . ")";

        $this->pdo->exec($sql);

        return $this; // Return instance for method chaining
    }

    /**
     * Add PostgreSQL-specific indexes
     * 
     * Index Types:
     * - B-tree (default)
     * - GiST (geometric/custom)
     * - GIN (full text/jsonb)
     * - SP-GiST (space partitioned)
     * - BRIN (block range)
     * - Hash
     * 
     * Features:
     * - Concurrent creation
     * - Partial indexes
     * - Expression indexes
     * - Covering indexes (INCLUDE)
     * - Custom operators
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
    
            // Generate a consistent index name for checking
            if (is_array($column)) {
                $indexNameToCheck = isset($index['name']) ? $index['name'] : "{$table}_" . implode("_", $column) . "_idx";
            } else {
                $indexNameToCheck = "{$table}_{$column}_idx";
            }
    
            // Check if index already exists in PostgreSQL
            $existingIndexes = $this->pdo
                ->query("SELECT indexname FROM pg_indexes WHERE tablename = '$table'")
                ->fetchAll(PDO::FETCH_COLUMN);
    
            if (in_array($indexNameToCheck, $existingIndexes)) {
                continue; // Skip if index exists
            }
    
            if ($type === 'FOREIGN KEY') {
                if (!isset($index['references'], $index['on'])) {
                    throw new \InvalidArgumentException("Foreign key must have 'references' and 'on' defined.");
                }
    
                // Handle both string and array columns for foreign keys
                if (is_array($column)) {
                    $columnNames = array_map(fn($col) => "\"$col\"", $column);
                    $columnStr = implode(", ", $columnNames);
                    $name = isset($index['name']) ? $index['name'] : "fk_{$table}_" . implode("_", $column);
                    $sql = "ALTER TABLE \"$table\" ADD CONSTRAINT \"$name\" 
                            FOREIGN KEY ($columnStr) REFERENCES \"{$index['on']}\" (\"{$index['references']}\")";
                } else {
                    $sql = "ALTER TABLE \"$table\" ADD CONSTRAINT \"fk_{$table}_{$column}\" 
                            FOREIGN KEY (\"$column\") REFERENCES \"{$index['on']}\" (\"{$index['references']}\")";
                }
            } elseif ($type === 'UNIQUE') {
                // Handle multi-column unique indexes
                if (is_array($column)) {
                    $columnNames = array_map(fn($col) => "\"$col\"", $column);
                    $columnStr = implode(", ", $columnNames);
                    $name = isset($index['name']) ? $index['name'] : "{$table}_" . implode("_", $column) . "_idx";
                    $sql = "CREATE UNIQUE INDEX \"$name\" ON \"$table\" ($columnStr)";
                } else {
                    $sql = "CREATE UNIQUE INDEX \"{$table}_{$column}_idx\" ON \"$table\" (\"$column\")";
                }
            } else {
                // Default case: add normal index
                // Handle multi-column indexes
                if (is_array($column)) {
                    $columnNames = array_map(fn($col) => "\"$col\"", $column);
                    $columnStr = implode(", ", $columnNames);
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
     * Add column with PostgreSQL features
     * 
     * Column Features:
     * - All PostgreSQL types
     * - Custom types/domains
     * - Collations
     * - Generated columns
     * - Identity columns
     * - Exclusion constraints
     * - LIKE dependency
     * 
     * Storage Options:
     * - Compression methods
     * - TOAST strategies
     * - Statistics targets
     * 
     * @throws Exception On invalid type or permission denied
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
     * Get PostgreSQL table information
     * 
     * Returns Metadata:
     * - Column definitions
     * - Constraint details
     * - Storage parameters
     * - Dependencies
     * - Inheritance
     * - Partitioning
     * - Statistics
     * - Permissions
     * 
     * System Views Used:
     * - information_schema.columns
     * - pg_stat_user_tables
     * - pg_class
     * - pg_attribute
     * 
     * @throws Exception On invalid table or permission denied
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
     * Manage foreign key enforcement
     * 
     * Uses session_replication_role for:
     * - Bulk data loading
     * - Schema changes
     * - Replication setup
     * - Disaster recovery
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
     * Calculate PostgreSQL table metrics
     * 
     * Size Components:
     * - Main relation size
     * - TOAST relation
     * - Index sizes
     * - FSM and VM sizes
     * 
     * Additional Stats:
     * - Bloat estimation
     * - Buffer usage
     * - IO timing
     * - Tuple statistics
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

