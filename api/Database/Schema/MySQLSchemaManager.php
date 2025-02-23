<?php

namespace Glueful\Database\Schema;

use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Connection;
use PDO;

/**
 * MySQL Schema Manager Implementation
 * 
 * Handles MySQL-specific schema operations including:
 * - Table and column management
 * - Index creation/deletion
 * - Schema information retrieval
 * - MySQL-specific SQL generation
 * 
 * Uses PDO for database operations and follows MySQL
 * best practices for schema modifications.
 */
class MySQLSchemaManager implements SchemaManager
{
    /** @var PDO Active database connection */
    protected PDO $pdo;

    /**
     * Initialize MySQL schema manager
     * 
     * @param MySQLDriver $driver MySQL-specific database driver
     */
    public function __construct(MySQLDriver $driver)
    {
        $connection = new Connection();
        $this->pdo = $connection->getPDO();
    }

    /**
     * Create new MySQL table
     * 
     * Creates table with specified columns and options using InnoDB engine.
     * Supports column types, nullability, and default values.
     * 
     * @param string $table Table name
     * @param array $columns Column definitions with types and constraints
     * @param array $options Additional table options
     * @return bool True if table created successfully
     * @throws \PDOException If table creation fails
     */
    public function createTable(string $table, array $columns, array $options = []): bool
    {
        $columnDefinitions = [];
        foreach ($columns as $name => $definition) {
            $columnDefinitions[] = "`$name` {$definition['type']} " .
                (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
                (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
        }

        $sql = "CREATE TABLE `$table` (" . implode(", ", $columnDefinitions) . ") ENGINE=InnoDB";
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
     * Add column to MySQL table
     * 
     * Adds new column with specified definition using ALTER TABLE.
     * 
     * @param string $table Target table
     * @param string $column New column name
     * @param array $definition Column type and constraints
     * @return bool True if column added successfully
     * @throws \PDOException If column addition fails
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
     * Create MySQL index
     * 
     * Creates regular or unique index on specified columns.
     * 
     * @param string $table Target table
     * @param string $indexName Name for new index
     * @param array $columns Columns to index
     * @param bool $unique Whether to create unique index
     * @return bool True if index created successfully
     * @throws \PDOException If index creation fails
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
     * Get MySQL table columns
     * 
     * Retrieves detailed column information for specified table.
     * 
     * @param string $table Target table
     * @return array Column definitions including type, null, key, default and extra
     * @throws \PDOException If column information retrieval fails
     */
    public function getTableColumns(string $table): array
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}