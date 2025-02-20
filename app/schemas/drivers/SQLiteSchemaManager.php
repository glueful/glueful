<?php

declare(strict_types=1);

namespace Glueful\Api\Database\Schemas\Drivers;

use Glueful\App\Database\Schemas\SchemaManager;
use PDO;
use PDOException;

class SQLiteSchemaManager implements SchemaManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createTable(string $tableName, array $columns, array $indexes = [], array $foreignKeys = []): bool
    {
        try {
            $columnDefs = [];
            foreach ($columns as $name => $definition) {
                $columnDefs[] = "\"$name\" $definition";
            }

            // Add foreign keys inline
            foreach ($foreignKeys as $fk) {
                $columnDefs[] = sprintf(
                    'FOREIGN KEY ("%s") REFERENCES "%s" ("%s") ON DELETE %s ON UPDATE %s',
                    $fk['column'],
                    $fk['referenceTable'],
                    $fk['referenceColumn'],
                    $fk['onDelete'] ?? 'CASCADE',
                    $fk['onUpdate'] ?? 'CASCADE'
                );
            }

            $sql = sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" (%s)',
                $tableName,
                implode(", ", $columnDefs)
            );
            
            $this->db->exec($sql);

            // Create indexes separately
            foreach ($indexes as $index) {
                $this->addIndex(
                    $tableName,
                    $index['column'],
                    $index['type'] ?? 'INDEX'
                );
            }

            return true;
        } catch (PDOException $e) {
            error_log("SQLite create table error: " . $e->getMessage());
            return false;
        }
    }

    public function dropTable(string $tableName): bool
    {
        try {
            return (bool)$this->db->exec("DROP TABLE IF EXISTS \"$tableName\"");
        } catch (PDOException $e) {
            error_log("SQLite drop table error: " . $e->getMessage());
            return false;
        }
    }

    public function alterTable(string $tableName, array $modifications): bool
    {
        try {
            $this->db->beginTransaction();

            // Get current table info
            $stmt = $this->db->query("PRAGMA table_info(\"$tableName\")");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Create new table structure
            $newColumns = [];
            foreach ($columns as $col) {
                $name = $col['name'];
                if (!isset($modifications['DROP'][$name])) {
                    $newType = $modifications['MODIFY'][$name] ?? $col['type'];
                    $newName = $modifications['RENAME'][$name] ?? $name;
                    $newColumns[$newName] = $newType;
                }
            }

            // Add new columns
            if (isset($modifications['ADD'])) {
                foreach ($modifications['ADD'] as $name => $type) {
                    $newColumns[$name] = $type;
                }
            }

            // Create temp table and copy data
            $tempTable = $tableName . '_temp';
            $this->createTable($tempTable, $newColumns);
            
            // Copy data
            $commonColumns = array_intersect(
                array_keys($newColumns),
                array_column($columns, 'name')
            );
            
            $this->db->exec(sprintf(
                'INSERT INTO "%s" (%s) SELECT %s FROM "%s"',
                $tempTable,
                implode(',', $commonColumns),
                implode(',', $commonColumns),
                $tableName
            ));

            // Replace original table
            $this->dropTable($tableName);
            $this->db->exec(sprintf('ALTER TABLE "%s" RENAME TO "%s"', $tempTable, $tableName));

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("SQLite alter table error: " . $e->getMessage());
            return false;
        }
    }

    public function addColumn(string $tableName, string $column, string $type): bool
    {
        return $this->alterTable($tableName, [
            'ADD' => [$column => $type]
        ]);
    }

    public function dropColumn(string $tableName, string $column): bool
    {
        return $this->alterTable($tableName, [
            'DROP' => [$column => true]
        ]);
    }

    public function renameColumn(string $tableName, string $oldColumn, string $newColumn): bool
    {
        // Get current column type
        $stmt = $this->db->query("PRAGMA table_info(\"$tableName\")");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $column = array_filter($columns, fn($col) => $col['name'] === $oldColumn)[0] ?? null;
        
        if (!$column) {
            return false;
        }

        return $this->alterTable($tableName, [
            'RENAME' => [$oldColumn => "$newColumn:{$column['type']}"]
        ]);
    }

    public function modifyColumn(string $tableName, string $column, string $newType): bool
    {
        return $this->alterTable($tableName, [
            'MODIFY' => [$column => $newType]
        ]);
    }

    public function addIndex(string $tableName, string $column, string $type = 'INDEX', string $indexName = null): bool
    {
        try {
            $indexName = $indexName ?? "idx_{$tableName}_{$column}";
            $sql = sprintf(
                'CREATE %s IF NOT EXISTS "%s" ON "%s" ("%s")',
                $type,
                $indexName,
                $tableName,
                $column
            );
            return (bool)$this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("SQLite add index error: " . $e->getMessage());
            return false;
        }
    }

    public function addForeignKey(
        string $tableName,
        string $column,
        string $referenceTable,
        string $referenceColumn,
        string $onDelete = 'CASCADE',
        string $onUpdate = 'CASCADE',
        string $fkName = null
    ): bool {
        throw new PDOException("SQLite does not support adding foreign keys after table creation");
    }
}
