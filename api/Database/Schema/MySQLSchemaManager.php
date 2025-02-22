<?php

namespace Glueful\Database\Schema;

use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Connection;
use PDO;

class MySQLSchemaManager implements SchemaManager
{
    protected PDO $pdo;

    public function __construct(MySQLDriver $driver)
    {
        $connection = new Connection();
        $this->pdo = $connection->getPDO();
    }

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

    public function dropTable(string $table): bool
    {
        return (bool) $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    public function addColumn(string $table, string $column, array $definition): bool
    {
        $sql = "ALTER TABLE `$table` ADD `$column` {$definition['type']} " .
               (!empty($definition['nullable']) ? 'NULL' : 'NOT NULL') .
               (!empty($definition['default']) ? " DEFAULT '{$definition['default']}'" : '');
        return (bool) $this->pdo->exec($sql);
    }

    public function dropColumn(string $table, string $column): bool
    {
        return (bool) $this->pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
    }

    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool
    {
        $indexType = $unique ? 'UNIQUE' : 'INDEX';
        $sql = "CREATE $indexType `$indexName` ON `$table` (" . implode(", ", array_map(fn($col) => "`$col`", $columns)) . ")";
        return (bool) $this->pdo->exec($sql);
    }

    public function dropIndex(string $table, string $indexName): bool
    {
        return (bool) $this->pdo->exec("DROP INDEX `$indexName` ON `$table`");
    }
    
    public function getTables(): array
    {
        $stmt = $this->pdo->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getTableColumns(string $table): array
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}