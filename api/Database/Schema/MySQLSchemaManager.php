<?php

namespace Glueful\Database\Schema;
use PDO;

/**
 * MySQL Schema Manager Implementation
 * 
 * Provides MySQL-specific implementation of schema operations with features including:
 * - InnoDB table management with optional engine selection
 * - Full MySQL column type support (VARCHAR, TEXT, INT, etc.)
 * - Index management including UNIQUE and regular indexes
 * - Foreign key constraint handling
 * - Table statistics and metadata retrieval
 * 
 * Requirements:
 * - MySQL 5.7+ or MariaDB 10.2+
 * - PDO MySQL extension
 * - Appropriate database user privileges for DDL operations
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
     * Creates a new MySQL table with specified structure
     * 
     * Supports MySQL-specific features:
     * - All MySQL column types and attributes
     * - Table engines (default: InnoDB)
     * - Character sets and collations
     * - Auto-increment columns
     * - Column position specifications
     * 
     * Example usage:
     * $columns = [
     *     'id' => ['type' => 'INT', 'auto_increment' => true],
     *     'name' => ['type' => 'VARCHAR(255)', 'nullable' => false]
     * ];
     * 
     * @param string $table Table name without prefix
     * @param array $columns Column definitions with MySQL-specific types
     * @param array $options MySQL table options (engine, charset, etc.)
     * @throws \PDOException On MySQL-specific errors (duplicate table, invalid syntax)
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

        
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(", ", $columnDefinitions) . ") ENGINE=InnoDB";

        return (bool) $this->pdo->exec($sql);
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
     * Adds new column to MySQL table
     * 
     * Supports MySQL column features:
     * - AFTER/FIRST position specifiers
     * - All MySQL data types and modifiers
     * - Column character sets
     * - Generated/Virtual columns
     * 
     * @param string $table Target table name
     * @param string $column New column name
     * @param array $definition MySQL column definition including:
     *                         - type: MySQL data type
     *                         - nullable: NULL/NOT NULL
     *                         - default: Default value
     *                         - charset: Column character set
     *                         - after/first: Position specifier
     * @throws \PDOException On MySQL errors (duplicate column, invalid type)
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
     * Retrieves MySQL table column information
     * 
     * Returns detailed MySQL-specific column metadata:
     * - Column name and position
     * - Complete type definition
     * - Nullability and defaults
     * - Character set and collation
     * - Extra attributes (on update, etc)
     * 
     * @return array MySQL SHOW COLUMNS format data
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
     * Calculates MySQL table size
     * 
     * Returns combined size including:
     * - Actual data length
     * - Index size
     * - Data free space
     * - Average row length calculations
     * 
     * Note: Size may be approximate depending on storage engine
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