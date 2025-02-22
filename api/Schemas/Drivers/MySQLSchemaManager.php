<?php

declare(strict_types=1);

namespace Glueful\Api\Schemas\Drivers;

use Glueful\Api\Schemas\SchemaManager;
use PDO;
use PDOException;

class MySQLSchemaManager implements SchemaManager
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
                $columnDefs[] = "`$name` $definition";
            }

            // Add indexes
            foreach ($indexes as $index) {
                $type = strtoupper($index['type'] ?? 'INDEX');
                $column = $index['column'];
                $columnDefs[] = "$type (`$column`)";
            }

            // Add foreign keys
            foreach ($foreignKeys as $fk) {
                $columnDefs[] = sprintf(
                    "CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s",
                    $fk['name'] ?? "fk_{$tableName}_{$fk['column']}",
                    $fk['column'],
                    $fk['referenceTable'],
                    $fk['referenceColumn'],
                    $fk['onDelete'] ?? 'CASCADE',
                    $fk['onUpdate'] ?? 'CASCADE'
                );
            }

            $sql = sprintf(
                "CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                $tableName,
                implode(", ", $columnDefs)
            );

            return (bool)$this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to create table: " . $e->getMessage());
            return false;
        }
    }

    public function dropTable(string $tableName): bool
    {
        try {
            return (bool)$this->db->exec("DROP TABLE IF EXISTS `$tableName`");
        } catch (PDOException $e) {
            error_log("Failed to drop table: " . $e->getMessage());
            return false;
        }
    }

    public function alterTable(string $tableName, array $modifications): bool
    {
        try {
            $statements = [];
            foreach ($modifications as $action => $columns) {
                foreach ($columns as $column => $definition) {
                    switch (strtoupper($action)) {
                        case 'ADD':
                            $statements[] = "ADD COLUMN `$column` $definition";
                            break;
                        case 'MODIFY':
                            $statements[] = "MODIFY COLUMN `$column` $definition";
                            break;
                        case 'DROP':
                            $statements[] = "DROP COLUMN `$column`";
                            break;
                        case 'RENAME':
                            [$newName, $type] = explode(':', $definition);
                            $statements[] = "CHANGE COLUMN `$column` `$newName` $type";
                            break;
                    }
                }
            }

            if (empty($statements)) {
                return false;
            }

            $sql = "ALTER TABLE `$tableName` " . implode(", ", $statements);
            return (bool)$this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to alter table: " . $e->getMessage());
            return false;
        }
    }

    public function addIndex(string $tableName, string $column, string $type = 'INDEX', string $indexName = null): bool
    {
        try {
            $indexName = $indexName ?? "idx_{$tableName}_{$column}";
            $sql = "ALTER TABLE `$tableName` ADD $type `$indexName` (`$column`)";
            return (bool)$this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to add index: " . $e->getMessage());
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
        try {
            $fkName = $fkName ?? "fk_{$tableName}_{$column}";
            $sql = sprintf(
                "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s",
                $tableName,
                $fkName,
                $column,
                $referenceTable,
                $referenceColumn,
                $onDelete,
                $onUpdate
            );
            return (bool)$this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to add foreign key: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a new column to table
     */
    public function addColumn(string $tableName, string $column, string $type): bool
    {
        return $this->alterTable($tableName, [
            'ADD' => [$column => $type]
        ]);
    }

    /**
     * Drop column from table
     */
    public function dropColumn(string $tableName, string $column): bool
    {
        return $this->alterTable($tableName, [
            'DROP' => [$column => '']
        ]);
    }

    /**
     * Rename column in table
     * 
     * @param string $tableName Table containing the column
     * @param string $oldColumn Current column name
     * @param string $newColumn New column name
     * @return bool True if column renamed successfully
     */
    public function renameColumn(string $tableName, string $oldColumn, string $newColumn): bool
    {
        // Get current column type
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM `$tableName` WHERE Field = '$oldColumn'");
            $column = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$column) {
                error_log("Column $oldColumn not found in table $tableName");
                return false;
            }
            $type = $column['Type'];

            return $this->alterTable($tableName, [
                'RENAME' => [$oldColumn => "$newColumn:$type"]
            ]);
        } catch (PDOException $e) {
            error_log("Failed to rename column: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Modify column type
     */
    public function modifyColumn(string $tableName, string $column, string $newType): bool
    {
        return $this->alterTable($tableName, [
            'MODIFY' => [$column => $newType]
        ]);
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
            $this->db->beginTransaction();

            $columns = array_keys($data);
            $values = array_values($data);
            $placeholders = array_fill(0, count($columns), '?');

            $sql = sprintf(
                "INSERT INTO `%s` (`%s`) VALUES (%s)",
                $tableName,
                implode('`, `', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($values);

            if (!$success) {
                $this->db->rollBack();
                error_log("Insert failed for table $tableName: " . json_encode($data));
                return false;
            }

            $lastId = $this->db->lastInsertId();
            $this->db->commit();

            error_log("Successfully inserted into $tableName. Last ID: $lastId");
            return $lastId;

        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Failed to insert data into $tableName: " . $e->getMessage());
            throw $e; // Re-throw to let migration manager handle it
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
            $where = array_map(fn($col) => "`$col` = ?", array_keys($conditions));
            
            $sql = sprintf(
                "DELETE FROM `%s` WHERE %s",
                $tableName,
                implode(' AND ', $where)
            );

            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($conditions));

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Failed to delete data: " . $e->getMessage());
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
                    $where[] = "`$column` = ?";
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

            $sql = "SELECT $fields FROM `$tableName` $whereClause $orderBy $limit";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("Failed to get data: " . $e->getMessage());
            return [];
        }
    }

    public function beginTransaction(): bool
    {
        if ($this->transactionActive) {
            return false; // Already in a transaction
        }
        $this->transactionActive = $this->db->beginTransaction();
        return $this->transactionActive;
    }

    public function commit(): bool
    {
        if (!$this->transactionActive) {
            return false; // No active transaction
        }
        $result = $this->db->commit();
        $this->transactionActive = false;
        return $result;
    }

    public function rollBack(): bool
    {
        if (!$this->transactionActive) {
            return false; // No active transaction
        }
        $result = $this->db->rollBack();
        $this->transactionActive = false;
        return $result;
    }
}
