<?php

declare(strict_types=1);

namespace Glueful\Api\Schemas\Drivers;

use Glueful\Api\Schemas\SchemaManager;
use PDO;
use PDOException;

class SQLiteSchemaManager implements SchemaManager
{
    private PDO $db;
    private bool $transactionActive = false;

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

    /**
     * Begin a database transaction
     */
    public function beginTransaction(): bool
    {
        if ($this->transactionActive) {
            return true; // Already in transaction
        }
        $this->transactionActive = $this->db->beginTransaction();
        return $this->transactionActive;
    }

    /**
     * Commit the current transaction
     */
    public function commit(): bool
    {
        if (!$this->transactionActive) {
            return true; // No transaction to commit
        }
        $result = $this->db->commit();
        $this->transactionActive = false;
        return $result;
    }

    /**
     * Rollback the current transaction
     */
    public function rollBack(): bool
    {
        if (!$this->transactionActive) {
            return true; // No transaction to rollback
        }
        $result = $this->db->rollBack();
        $this->transactionActive = false;
        return $result;
    }

    /**
     * Insert data into a table
     * 
     * @param string $tableName Name of table to insert into
     * @param array<string,mixed> $data Associative array of column => value pairs
     * @return int|string|false Last insert ID or false on failure
     * @throws \PDOException If insert fails
     */
    public function insert(string $tableName, array $data): int|string|false
    {
        try {
            $wasInTransaction = $this->transactionActive;
            if (!$wasInTransaction) {
                $this->beginTransaction();
            }

            $columns = array_keys($data);
            $values = array_values($data);
            $placeholders = array_fill(0, count($columns), '?');

            $sql = sprintf(
                'INSERT INTO "%s" ("%s") VALUES (%s)',
                $tableName,
                implode('", "', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($values);

            if (!$success) {
                if (!$wasInTransaction) {
                    $this->rollBack();
                }
                error_log("Insert failed for table $tableName: " . json_encode($data));
                return false;
            }

            $lastId = $this->db->lastInsertId();
            
            if (!$wasInTransaction) {
                $this->commit();
            }

            error_log("Successfully inserted into $tableName. Last ID: $lastId");
            return $lastId;

        } catch (PDOException $e) {
            if (!$wasInTransaction) {
                $this->rollBack();
            }
            error_log("Failed to insert data into $tableName: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete records from a table
     * 
     * @param string $tableName Name of table to delete from
     * @param array<string,mixed> $conditions WHERE conditions as column => value pairs
     * @return int Number of affected rows
     * @throws \PDOException If delete fails
     */
    public function delete(string $tableName, array $conditions): int
    {
        try {
            $where = array_map(fn($col) => "\"$col\" = ?", array_keys($conditions));
            
            $sql = sprintf(
                'DELETE FROM "%s" WHERE %s',
                $tableName,
                implode(' AND ', $where)
            );

            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($conditions));

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("SQLite delete error: " . $e->getMessage());
            return 0;
        }
    }

    public function getData(string $tableName, array $options = []): array
    {
        try {
            $fields = $options['fields'] ?? '*';
            $where = [];
            $params = [];
            $orderBy = '';
            $limit = '';

            // Build WHERE clause
            if (isset($options['where'])) {
                foreach ($options['where'] as $column => $value) {
                    $where[] = "\"$column\" = ?";
                    $params[] = $value;
                }
            }

            // Build ORDER BY clause
            if (isset($options['order'])) {
                $orderBy = "ORDER BY " . $options['order'];
            }

            // Build LIMIT clause
            if (isset($options['limit'])) {
                $limit = "LIMIT " . (int)$options['limit'];
            }

            $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : '';

            $sql = "SELECT $fields FROM \"$tableName\" $whereClause $orderBy $limit";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Failed to get data: " . $e->getMessage());
            return [];
        }
    }

    public function getTables(): array
    {
        $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getTableColumns(string $table): array
    {
        $stmt = $this->db->query("PRAGMA table_info(`$table`)");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return array_map(function($col) {
            return [
                'name' => $col['name'],
                'type' => $col['type'],
                'nullable' => !$col['notnull'],
                'default' => $col['dflt_value'],
                'extra' => $col['pk'] ? 'PRIMARY KEY' : ''
            ];
        }, $columns);
    }

    public function isTransactionActive(): bool
    {
        return $this->transactionActive;
    }
}
