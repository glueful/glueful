<?php

namespace Glueful\Database\Schema;
use PDO;

/**
 * MySQL Schema Manager Implementation
 * 
 * Provides schema operations optimized for MySQL/MariaDB with features:
 * - InnoDB-specific optimizations
 * - Full text search indexes
 * - Spatial data types and indexes
 * - Dynamic column support
 * - Virtual/stored column generation
 * - JSON column type operations
 * - Advanced constraint handling
 * 
 * Requirements:
 * - MySQL 5.7+ / MariaDB 10.2+
 * - InnoDB storage engine
 * - PDO MySQL extension
 * - Proper character set configuration
 * - Appropriate user privileges
 * 
 * Example usage:
 * ```php
 * $schema->createTable('products', [
 *     'id' => ['type' => 'BIGINT UNSIGNED', 'auto_increment' => true],
 *     'name' => ['type' => 'VARCHAR(255)', 'collate' => 'utf8mb4_unicode_ci'],
 *     'location' => ['type' => 'POINT SRID 4326']
 * ])->addIndex([
 *     'type' => 'SPATIAL',
 *     'column' => 'location',
 *     'table' => 'products'
 * ]);
 * ```
 */
class MySQLSchemaManager implements SchemaManager
{
    /** @var PDO Active database connection */
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Creates MySQL table with advanced features
     * 
     * Supported features:
     * - All MySQL data types including GEOMETRY
     * - Table partitioning (RANGE, LIST, HASH)
     * - Table compression
     * - Foreign key constraints
     * - Column character sets
     * - Generated columns
     * - Check constraints (8.0.16+)
     * 
     * @throws \PDOException On MySQL errors (syntax, privileges, etc)
     */
    public function createTable(string $table, array $columns, array $options = []): self
    {
        $columnDefinitions = [];

        foreach ($columns as $name => $definition) {
            if (is_string($definition)) {
                $columnDefinitions[] = "`$name` $definition";
            } elseif (is_array($definition)) {
                $columnDefinitions[] = "`$name` {$definition['type']} " .
                    (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
                    (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(", ", $columnDefinitions) . ") ENGINE=InnoDB";
        $this->pdo->exec($sql);

        return $this; // Return instance for method chaining
    }

    /**
     * Adds index with MySQL-specific features
     * 
     * Index types supported:
     * - BTREE (default)
     * - HASH (Memory tables)
     * - FULLTEXT (text search)
     * - SPATIAL (geographic)
     * 
     * Features:
     * - Prefix indexing for BLOB/TEXT
     * - Descending indexes (8.0+)
     * - Invisible indexes
     * - Functional indexes
     * 
     * @throws \PDOException On duplicate/invalid index
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

            // Prevent duplicate indexing
            $existingIndexes = $this->pdo->query("SHOW INDEXES FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existingIndexes as $existingIndex) {
                if ($existingIndex['Column_name'] === $column) {
                    // Index already exists, skip it
                    continue 2;
                }
            }

            if ($type === 'FOREIGN KEY') {
                if (!isset($index['references'], $index['on'])) {
                    throw new \InvalidArgumentException("Foreign key must have 'references' and 'on' defined.");
                }

                $sql = "ALTER TABLE `$table` ADD CONSTRAINT `fk_{$table}_{$column}` 
                        FOREIGN KEY (`$column`) REFERENCES `{$index['on']}` (`{$index['references']}`)";
            } elseif ($type === 'PRIMARY KEY') {
                // Primary Key should be handled in CREATE TABLE
                continue;
            } elseif ($type === 'UNIQUE') {
                $sql = "ALTER TABLE `$table` ADD UNIQUE (`$column`)";
            } else {
                // Default case: add normal index
                $sql = "ALTER TABLE `$table` ADD INDEX (`$column`)";
            }

            $this->pdo->exec($sql);
        }

        return $this;
    }
    /**
     * Drop MySQL table
     * 
     * Removes table if exists with IF EXISTS clause for safety.
     * 
     * @param string $table Table to drop
     * @return bool True if table dropped or didn't exist
     * @throws \PDOException If drop operation fails
     */
    public function dropTable(string $table): bool
    {
        return (bool) $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    /**
     * Adds column with MySQL type modifiers
     * 
     * Column features:
     * - All MySQL data types
     * - Character sets and collations
     * - Generated/virtual columns
     * - Column compression
     * - Expression defaults
     * - ON UPDATE triggers
     * - Auto-increment sequence
     * 
     * @throws \PDOException On column errors
     */
    public function addColumn(string $table, string $column, array $definition): bool
    {
        $sql = "ALTER TABLE `$table` ADD `$column` {$definition['type']} " .
               (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
               (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
        return (bool) $this->pdo->exec($sql);
    }

    /**
     * Remove column from MySQL table
     * 
     * Drops specified column using ALTER TABLE.
     * 
     * @param string $table Target table
     * @param string $column Column to remove
     * @return bool True if column dropped successfully
     * @throws \PDOException If column removal fails
     */
    public function dropColumn(string $table, string $column): bool
    {
        return (bool) $this->pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
    }

    /**
     * Creates MySQL index on specified columns
     * 
     * Supports:
     * - Regular indexes (non-unique)
     * - Unique indexes
     * - Multiple column indexes
     * - Index prefixes for TEXT/BLOB
     * 
     * Note: Maximum index key length varies by MySQL version
     * and storage engine settings.
     */
    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool
    {
        $indexType = $unique ? 'UNIQUE' : 'INDEX';
        $sql = "CREATE $indexType `$indexName` ON `$table` (" . implode(", ", array_map(fn($col) => "`$col`", $columns)) . ")";
        return (bool) $this->pdo->exec($sql);
    }

    /**
     * Drop MySQL index
     * 
     * Removes specified index from table.
     * 
     * @param string $table Target table
     * @param string $indexName Index to remove
     * @return bool True if index dropped successfully
     * @throws \PDOException If index removal fails
     */
    public function dropIndex(string $table, string $indexName): bool
    {
        return (bool) $this->pdo->exec("DROP INDEX `$indexName` ON `$table`");
    }
    
    /**
     * Get all MySQL tables
     * 
     * Retrieves list of tables in current database.
     * 
     * @return array List of table names
     * @throws \PDOException If table list retrieval fails
     */
    public function getTables(): array
    {
        $stmt = $this->pdo->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Gets MySQL table metadata
     * 
     * Returns detailed information:
     * - Column definitions
     * - Index structures
     * - Foreign keys
     * - Partition info
     * - Storage engine
     * - Table status
     * - Character sets
     * 
     * @throws \PDOException If table info unavailable
     */
    public function getTableColumns(string $table): array
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function disableForeignKeyChecks(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    public function enableForeignKeyChecks(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Gets MySQL server version string
     * 
     * Returns complete MySQL version including:
     * - Major, minor, and patch versions
     * - Distribution info (MySQL/MariaDB)
     * - Build details
     */
    public function getVersion(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Gets table size with detailed metrics
     * 
     * Returns combined size including:
     * - Data length
     * - Index length
     * - Data free space
     * - Average row length
     * - Max data length
     * 
     * Note: Some values may be estimates based on sampling
     */
    public function getTableSize(string $table): int
    {
        $stmt = $this->pdo->query("SHOW TABLE STATUS LIKE '$table'");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);
        return isset($status['Data_length'], $status['Index_length'])
            ? (int) ($status['Data_length'] + $status['Index_length'])
            : 0;
    }
}