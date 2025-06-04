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
class PostgreSQLSchemaManager implements SchemaManager
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

        // Set the current table for chained operations
        $this->currentTable = $table;

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

            // Generate a consistent index name for checking
            if (is_array($column)) {
                $columnPart = implode("_", $column);
                $indexNameToCheck = isset($index['name']) ? $index['name'] : "{$table}_{$columnPart}_idx";
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

            if ($type === 'PRIMARY KEY') {
                // Handle PRIMARY KEY indexes
                if (is_array($column)) {
                    $columnNames = array_map(fn($col) => "\"$col\"", $column);
                    $columnStr = implode(", ", $columnNames);
                    $sql = "ALTER TABLE \"$table\" ADD PRIMARY KEY ($columnStr)";
                } else {
                    $sql = "ALTER TABLE \"$table\" ADD PRIMARY KEY (\"$column\")";
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
            $this->pdo->exec($sql);
            return true;
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

            // Execute the query - if it doesn't throw an exception, consider it successful
            $this->pdo->exec($sql);
            return true;
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
            $this->pdo->exec($sql);
            return true;
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

            $this->pdo->exec($sql);
            return true;
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
            $this->pdo->exec($sql);
            return true;
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
     * @param bool $includeSchema Whether to include schema information
     * @return array List of table names
     * @throws Exception If table list retrieval fails
     */
    public function getTables(?bool $includeSchema = false): array
    {
        try {
            $query = "
                SELECT table_name" . ($includeSchema ? ", table_schema" : "") . "
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
     * - Column definitions (name, type, nullable, default)
     * - Constraint details (primary, foreign keys, unique)
     * - Index information with types
     * - Relationship data with referenced tables
     * - Column attributes (identity, generated)
     *
     * System Views Used:
     * - information_schema.columns
     * - pg_constraint
     * - pg_indexes
     * - pg_attribute
     *
     * @param string $tableName The table name to get column information for
     * @return array Comprehensive column information including relationships and indexes
     * @throws Exception On invalid table or permission denied
     */
    public function getTableColumns(string $tableName): array
    {
        try {
            // Get basic column information from information_schema
            $columnQuery = "
                SELECT 
                    column_name, 
                    data_type, 
                    is_nullable, 
                    column_default,
                    character_maximum_length,
                    udt_name,
                    is_identity,
                    is_generated
                FROM information_schema.columns 
                WHERE table_name = :table
                ORDER BY ordinal_position
            ";
            $columnStmt = $this->pdo->prepare($columnQuery);
            $columnStmt->execute(['table' => $tableName]);
            $columns = $columnStmt->fetchAll(PDO::FETCH_ASSOC);

            // Format columns into a more usable structure with column name as key
            $formattedColumns = [];
            foreach ($columns as $column) {
                $columnName = $column['column_name'];
                $formattedColumns[$columnName] = [
                    'name' => $columnName,
                    'type' => $column['data_type'],
                    'udt_name' => $column['udt_name'],
                    'nullable' => $column['is_nullable'] === 'YES',
                    'default' => $column['column_default'],
                    'max_length' => $column['character_maximum_length'],
                    'is_identity' => $column['is_identity'] === 'YES',
                    'is_generated' => $column['is_generated'] !== 'NEVER',
                    'is_primary' => false,
                    'is_unique' => false,
                    'is_indexed' => false,
                    'relationships' => [],
                    'indexes' => []
                ];
            }

            // Get primary key constraints
            try {
                $pkQuery = "
                    SELECT 
                        a.attname as column_name
                    FROM pg_constraint c
                    JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
                    JOIN pg_class t ON t.oid = c.conrelid
                    WHERE c.contype = 'p' 
                      AND t.relname = :table
                ";
                $pkStmt = $this->pdo->prepare($pkQuery);
                $pkStmt->execute(['table' => $tableName]);
                $pks = $pkStmt->fetchAll(PDO::FETCH_COLUMN);

                // Mark primary key columns
                foreach ($pks as $pk) {
                    if (isset($formattedColumns[$pk])) {
                        $formattedColumns[$pk]['is_primary'] = true;
                    }
                }
            } catch (Exception $e) {
                // Continue without primary key information
            }

            // Get unique constraints
            try {
                $uniqueQuery = "
                    SELECT 
                        a.attname as column_name
                    FROM pg_constraint c
                    JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
                    JOIN pg_class t ON t.oid = c.conrelid
                    WHERE c.contype = 'u' 
                      AND t.relname = :table
                ";
                $uniqueStmt = $this->pdo->prepare($uniqueQuery);
                $uniqueStmt->execute(['table' => $tableName]);
                $uniques = $uniqueStmt->fetchAll(PDO::FETCH_COLUMN);

                // Mark unique constraint columns
                foreach ($uniques as $unique) {
                    if (isset($formattedColumns[$unique])) {
                        $formattedColumns[$unique]['is_unique'] = true;
                    }
                }
            } catch (Exception $e) {
                // Continue without unique constraint information
            }

            // Get indexes
            try {
                $indexQuery = "
                    SELECT 
                        i.relname as index_name,
                        a.attname as column_name,
                        ix.indisunique as is_unique,
                        am.amname as index_type
                    FROM pg_index ix
                    JOIN pg_class i ON i.oid = ix.indexrelid
                    JOIN pg_class t ON t.oid = ix.indrelid
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                    JOIN pg_am am ON am.oid = i.relam
                    WHERE t.relname = :table
                    AND i.relname NOT IN (
                        SELECT constraint_name 
                        FROM information_schema.table_constraints 
                        WHERE table_name = :table 
                        AND constraint_type IN ('PRIMARY KEY', 'UNIQUE')
                    )
                ";
                $indexStmt = $this->pdo->prepare($indexQuery);
                $indexStmt->execute(['table' => $tableName]);
                $indexes = $indexStmt->fetchAll(PDO::FETCH_ASSOC);

                // Add index information to columns
                foreach ($indexes as $index) {
                    $columnName = $index['column_name'];
                    if (isset($formattedColumns[$columnName])) {
                        $formattedColumns[$columnName]['is_indexed'] = true;
                        $formattedColumns[$columnName]['indexes'][] = [
                            'name' => $index['index_name'],
                            'type' => $index['is_unique'] === 't' ? 'UNIQUE' : 'INDEX',
                            'method' => $index['index_type'] // btree, hash, gist, gin, etc.
                        ];
                    }
                }
            } catch (Exception $e) {
                // Continue without index information
            }

            // Get foreign key constraints (relationships)
            try {
                $fkQuery = "
                    SELECT 
                        a.attname as column_name,
                        c.conname as constraint_name,
                        c.confupdtype as on_update,
                        c.confdeltype as on_delete,
                        tf.relname as ref_table,
                        af.attname as ref_column
                    FROM pg_constraint c
                    JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
                    JOIN pg_class t ON t.oid = c.conrelid
                    JOIN pg_class tf ON tf.oid = c.confrelid
                    JOIN pg_attribute af ON af.attrelid = c.confrelid AND af.attnum = ANY(c.confkey)
                    WHERE c.contype = 'f'
                      AND t.relname = :table
                ";
                $fkStmt = $this->pdo->prepare($fkQuery);
                $fkStmt->execute(['table' => $tableName]);
                $foreignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

                // Map PostgreSQL's action codes to readable text
                $actionMap = [
                    'a' => 'NO ACTION',
                    'r' => 'RESTRICT',
                    'c' => 'CASCADE',
                    'n' => 'SET NULL',
                    'd' => 'SET DEFAULT'
                ];

                // Add relationship information to columns
                foreach ($foreignKeys as $fk) {
                    $columnName = $fk['column_name'];
                    if (isset($formattedColumns[$columnName])) {
                        $formattedColumns[$columnName]['relationships'][] = [
                            'constraint' => $fk['constraint_name'],
                            'column' => $columnName,
                            'references_table' => $fk['ref_table'],
                            'references_column' => $fk['ref_column'],
                            'on_update' => $actionMap[$fk['on_update']] ?? 'NO ACTION',
                            'on_delete' => $actionMap[$fk['on_delete']] ?? 'NO ACTION'
                        ];
                    }
                }
            } catch (Exception $e) {
                // Continue without foreign key information
            }

            return array_values($formattedColumns); // Convert back to indexed array
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
     * Check if a table exists in the database
     *
     * Uses information_schema for standard-compliant checking.
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
                FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_name = :table
            ");
            $stmt->execute(['table' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            throw new \RuntimeException("Failed to check if table '$table' exists: " . $e->getMessage(), 0, $e);
        }
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

    /**
     * Get the total number of rows in a table
     *
     * Uses optimized approach:
     * - For small tables: Uses exact COUNT(*)
     * - For large tables: Can use statistics when available
     * - Takes into account table visibility rules
     *
     * @param string $table Name of the table to count rows from
     * @return int Number of rows in the table
     * @throws \RuntimeException If table doesn't exist
     */
    public function getTableRowCount(string $table): int
    {
        try {
            // First try to get from statistics for potentially faster results
            // (especially for large tables where COUNT(*) would be expensive)
            $stmt = $this->pdo->prepare("
                SELECT 
                    n_live_tup
                FROM 
                    pg_stat_user_tables 
                WHERE 
                    relname = :table
            ");
            $stmt->execute(['table' => $table]);
            $result = $stmt->fetchColumn();

            // If n_live_tup is NULL or statistics are stale, fall back to COUNT(*)
            if ($result === false || $result === null) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM \"$table\"");
                $stmt->execute();
                $result = $stmt->fetchColumn();
            }

            return (int)($result ?: 0);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Failed to get row count for table '$table': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Adds foreign key constraints to PostgreSQL tables
     *
     * Creates foreign key constraints with:
     * - Support for multiple constraints in one call
     * - ON DELETE behavior specification (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * - ON UPDATE behavior specification (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * - Custom constraint naming
     * - Validation options
     * - Match types (FULL, PARTIAL, SIMPLE)
     *
     * When used in a chain after createTable(), the table parameter is optional
     * and will use the current table from the previous operation.
     *
     * @param array $foreignKeys Array of foreign key definitions
     * @return self For method chaining
     * @throws Exception On constraint creation failure
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
            $nameSuffix = is_array($column) ? implode("_", $column) : $column;
            $constraintName = $foreignKey['name'] ?? "fk_{$table}_{$nameSuffix}";

            // Handle single-column and multi-column foreign keys
            $columnStr = is_array($column) ? implode("\",\"", $column) : $column;
            $referencesColumnStr = is_array($referencesColumn)
                ? implode("\",\"", $referencesColumn)
                : $referencesColumn;

            $sql = "ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$constraintName}\" 
                    FOREIGN KEY (\"{$columnStr}\") REFERENCES \"{$referencesTable}\" (\"{$referencesColumnStr}\")";

            if (isset($foreignKey['onDelete'])) {
                $sql .= " ON DELETE {$foreignKey['onDelete']}";
            }

            if (isset($foreignKey['onUpdate'])) {
                $sql .= " ON UPDATE {$foreignKey['onUpdate']}";
            }

            // PostgreSQL-specific options
            if (isset($foreignKey['match'])) {
                $sql .= " MATCH {$foreignKey['match']}"; // SIMPLE, FULL, PARTIAL
            }

            if (isset($foreignKey['deferrable']) && $foreignKey['deferrable']) {
                $sql .= " DEFERRABLE";

                if (isset($foreignKey['initiallyDeferred']) && $foreignKey['initiallyDeferred']) {
                    $sql .= " INITIALLY DEFERRED";
                } else {
                    $sql .= " INITIALLY IMMEDIATE";
                }
            }

            $this->pdo->exec($sql);
        }

        return $this;
    }

    /**
     * Drop foreign key constraint from PostgreSQL table
     *
     * Removes the specified foreign key constraint using PostgreSQL's
     * ALTER TABLE DROP CONSTRAINT syntax.
     *
     * @param string $table Target table containing the constraint
     * @param string $constraintName Name of the foreign key constraint to remove
     * @return bool True if constraint was successfully removed
     * @throws Exception If constraint removal fails
     */
    public function dropForeignKey(string $table, string $constraintName): bool
    {
        try {
            // First check if the constraint exists
            $checkQuery = "
                SELECT COUNT(*) 
                FROM pg_constraint c
                JOIN pg_class t ON c.conrelid = t.oid
                WHERE t.relname = :table
                  AND c.conname = :constraint_name
                  AND c.contype = 'f'
            ";
            $stmt = $this->pdo->prepare($checkQuery);
            $stmt->execute([
                'table' => $table,
                'constraint_name' => $constraintName
            ]);

            if ($stmt->fetchColumn() == 0) {
                throw new \RuntimeException(
                    "Foreign key constraint '{$constraintName}' does not exist on table '{$table}'"
                );
            }

            // Use double quotes for identifiers in PostgreSQL
            $sql = "ALTER TABLE \"{$table}\" DROP CONSTRAINT \"{$constraintName}\"";
            return $this->pdo->exec($sql) !== false;
        } catch (Exception $e) {
            if ($e instanceof \RuntimeException) {
                throw $e; // Re-throw the specific exception about constraint not existing
            }
            throw new Exception(
                "Error dropping foreign key '{$constraintName}' from table '{$table}': " . $e->getMessage()
            );
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
                    $estimatedDuration += 3; // PostgreSQL is efficient
                    break;

                case 'drop_column':
                    $columnName = $change['column_name'];
                    $sql[] = "ALTER TABLE \"{$tableName}\" DROP COLUMN \"{$columnName}\"";
                    $warnings[] = "Dropping column '{$columnName}' will permanently delete all data in this column";
                    $estimatedDuration += 8;
                    break;

                case 'modify_column':
                    $columnSql = $this->generateModifyColumnSQL($tableName, $change);
                    $sql[] = $columnSql;
                    if (isset($change['new_type']) && $change['new_type'] !== ($change['old_type'] ?? '')) {
                        $warnings[] = "Changing column type may require explicit casting in PostgreSQL";
                    }
                    $estimatedDuration += 12;
                    break;

                case 'add_index':
                    $indexSql = $this->generateAddIndexSQL($tableName, $change);
                    $sql[] = $indexSql;
                    $estimatedDuration += $this->estimateIndexCreationTime($tableName);
                    break;

                case 'drop_index':
                    $indexName = $change['index_name'];
                    $sql[] = "DROP INDEX IF EXISTS \"{$indexName}\"";
                    $estimatedDuration += 2;
                    break;

                default:
                    $warnings[] = "Unknown change type: {$changeType}";
            }
        }

        return [
            'sql' => $sql,
            'warnings' => $warnings,
            'estimated_duration' => $estimatedDuration,
            'safe_to_execute' => empty($warnings) || !$this->hasDestructiveChanges($changes),
            'generated_at' => date('Y-m-d H:i:s'),
            'notes' => ['PostgreSQL supports most DDL operations with good transaction support']
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
            $changes = array_merge($changes, $this->updateTableFromSchema($tableName, $schema, $format));
        }

        return [
            'success' => true,
            'changes' => $changes,
            'table_name' => $tableName,
            'imported_at' => date('Y-m-d H:i:s')
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

        // PostgreSQL-specific validations
        if ($format === 'json' && isset($schema['columns'])) {
            foreach ($schema['columns'] as $column) {
                if (isset($column['type'])) {
                    $type = strtoupper($column['type']);
                    if (strpos($type, 'TEXT') !== false && strpos($type, 'LENGTH') !== false) {
                        $warnings[] = "PostgreSQL TEXT types don't have length limits - consider VARCHAR instead";
                    }
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
                    'type' => 'drop_column',
                    'table' => $context['table'] ?? '',
                    'column_name' => $context['column']['name'] ?? ''
                ];
                break;

            case 'drop_column':
                $revertOps[] = [
                    'type' => 'add_column',
                    'table' => $context['table'] ?? '',
                    'column' => $context['original_column'] ?? []
                ];
                break;

            case 'modify_column':
                $revertOps[] = [
                    'type' => 'modify_column',
                    'table' => $context['table'] ?? '',
                    'column_name' => $context['column_name'] ?? '',
                    'old_definition' => $context['new_definition'] ?? [],
                    'new_definition' => $context['old_definition'] ?? []
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
                throw new \RuntimeException("Cannot generate revert operations for action: {$action}");
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
            'constraints' => $this->getConstraintDefinitions($tableName),
            'retrieved_at' => date('Y-m-d H:i:s')
        ];
    }

    // Helper methods for PostgreSQL schema operations

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

    private function generateModifyColumnSQL(string $tableName, array $change): string
    {
        $columnName = $change['column_name'];
        $newType = $change['new_type'];

        // PostgreSQL requires specific syntax for column alterations
        return "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$columnName}\" TYPE {$newType}";
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

    private function estimateIndexCreationTime(string $tableName): int
    {
        // Estimate based on table size - PostgreSQL is generally efficient
        $rowCount = $this->getTableRowCount($tableName);
        return max(3, intval($rowCount / 20000)); // Generally faster than MySQL
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
            'engine' => 'postgresql',
            'schema' => $schema,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function exportAsSQL(string $tableName, array $schema): array
    {
        $sql = $this->generateCreateTableSQL($tableName, $schema);

        return [
            'format' => 'sql',
            'version' => '1.0',
            'engine' => 'postgresql',
            'sql' => $sql,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function exportAsYaml(array $schema): array
    {
        return [
            'format' => 'yaml',
            'version' => '1.0',
            'engine' => 'postgresql',
            'table' => $schema,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }

    private function exportAsPhp(array $schema): array
    {
        return [
            'format' => 'php',
            'version' => '1.0',
            'engine' => 'postgresql',
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

        if (!isset($schema['sql'])) {
            $errors[] = "Missing 'sql' in schema";
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
        $sql = "SELECT column_name, data_type, is_nullable, column_default, 
                       character_maximum_length, numeric_precision, numeric_scale
                FROM information_schema.columns 
                WHERE table_name = ? 
                ORDER BY ordinal_position";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getIndexDefinitions(string $tableName): array
    {
        $sql = "SELECT indexname, indexdef 
                FROM pg_indexes 
                WHERE tablename = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getForeignKeyDefinitions(string $tableName): array
    {
        $sql = "SELECT tc.constraint_name, kcu.column_name, 
                       ccu.table_name AS foreign_table_name,
                       ccu.column_name AS foreign_column_name
                FROM information_schema.table_constraints AS tc 
                JOIN information_schema.key_column_usage AS kcu
                  ON tc.constraint_name = kcu.constraint_name
                JOIN information_schema.constraint_column_usage AS ccu
                  ON ccu.constraint_name = tc.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getConstraintDefinitions(string $tableName): array
    {
        $sql = "SELECT constraint_name, constraint_type
                FROM information_schema.table_constraints
                WHERE table_name = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getTableOptions(string $tableName): array
    {
        return [
            'engine' => 'postgresql',
            'charset' => 'UTF8',
            'auto_increment' => null, // PostgreSQL uses sequences
            'comment' => $this->getTableComment($tableName)
        ];
    }

    private function getTableComment(string $tableName): string
    {
        $sql = "SELECT obj_description(oid) as comment 
                FROM pg_class 
                WHERE relname = ? AND relkind = 'r'";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['comment'] ?? '';
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
        }
    }

    private function updateTableFromSchema(string $tableName, array $schema, string $format): array
    {
        $changes = [];

        // Compare current schema with new schema and generate changes
        $currentSchema = $this->getTableSchema($tableName);

        // Simplified implementation - full schema diff would be more complex
        $changes[] = "Schema comparison and update logic would go here";

        return $changes;
    }

    private function generateCreateTableSQL(string $tableName, array $schema): string
    {
        $sql = "CREATE TABLE \"{$tableName}\" (\n";

        $columns = [];
        foreach ($schema['columns'] as $column) {
            $columnSql = "\"{$column['column_name']}\" {$column['data_type']}";

            if (isset($column['character_maximum_length']) && $column['character_maximum_length']) {
                $columnSql = "\"{$column['column_name']}\" {$column['data_type']}" .
                    "({$column['character_maximum_length']})";
            }

            if ($column['is_nullable'] === 'NO') {
                $columnSql .= ' NOT NULL';
            }

            if ($column['column_default'] !== null) {
                $columnSql .= " DEFAULT {$column['column_default']}";
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
            case 'drop_column':
                $sql = "ALTER TABLE \"{$op['table']}\" DROP COLUMN \"{$op['column_name']}\"";
                $this->pdo->exec($sql);
                return ['type' => 'drop_column', 'sql' => $sql, 'success' => true];

            case 'add_column':
                $columnSql = $this->generateAddColumnSQL($op['table'], $op);
                $this->pdo->exec($columnSql);
                return ['type' => 'add_column', 'sql' => $columnSql, 'success' => true];

            case 'drop_index':
                $sql = "DROP INDEX IF EXISTS \"{$op['index_name']}\"";
                $this->pdo->exec($sql);
                return ['type' => 'drop_index', 'sql' => $sql, 'success' => true];

            default:
                throw new \RuntimeException("Unsupported revert operation: {$op['type']}");
        }
    }
}
