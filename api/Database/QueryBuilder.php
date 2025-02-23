<?php

namespace Glueful\Database;

use PDO;
use PDOException;
use Exception;
use Glueful\Database\Driver\DatabaseDriver;

/**
 * Database Query Builder
 * 
 * Provides a fluent interface for building and executing database queries.
 * Supports multiple database drivers, transaction management, and common CRUD operations.
 */
class QueryBuilder
{
    /** @var PDO Active database connection */
    protected PDO $pdo;
    
    /** @var DatabaseDriver Database-specific driver implementation */
    protected DatabaseDriver $driver;
    
    /** @var int Current transaction nesting level */
    protected int $transactionLevel = 0;
    
    /** @var int Maximum retry attempts for deadlocked transactions */
    protected int $maxRetries = 3;
    
    /** @var bool Whether to use soft deletes */
    protected bool $softDeletes = true;

    /** @var array Group by clauses */
    protected array $groupBy = [];
    
    /** @var array Order by clauses */
    protected array $orderBy = [];

    /**
     * Initialize query builder
     * 
     * @param PDO $pdo Active database connection
     * @param DatabaseDriver $driver Database-specific driver
     */
    public function __construct(PDO $pdo, DatabaseDriver $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    /**
     * Execute callback within transaction
     * 
     * Handles automatic retries on deadlocks and proper nesting.
     * 
     * @param callable $callback Code to execute in transaction
     * @return mixed Result of callback
     * @throws Exception On max retries exceeded or other errors
     */
    public function transaction(callable $callback)
    {
        $retryCount = 0;

        while ($retryCount < $this->maxRetries) {
            $this->beginTransaction();
            try {
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (Exception $e) {
                if ($this->isDeadlock($e)) {
                    $this->rollback();
                    $retryCount++;
                    usleep(500000);
                } else {
                    $this->rollback();
                    throw $e;
                }
            }
        }
        throw new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.");
    }

    /**
     * Check if exception is a deadlock
     * 
     * @param Exception $e Exception to check
     * @return bool True if deadlock detected
     */
    protected function isDeadlock(Exception $e): bool
    {
        return in_array($e->getCode(), ['1213', '40001']);
    }

    /**
     * Begin new transaction or savepoint
     * 
     * Creates new transaction or savepoint based on nesting level.
     */
    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
    }

    /**
     * Commit current transaction level
     * 
     * Commits transaction or releases savepoint based on nesting.
     */
    public function commit(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo->commit();
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    /**
     * Rollback current transaction level
     * 
     * Rolls back transaction or to savepoint based on nesting.
     */
    public function rollback(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    /**
     * Insert new record
     * 
     * @param string $table Target table
     * @param array $data Column data to insert
     * @return int Number of affected rows
     */
    public function insert(string $table, array $data): int
    {
        $keys = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $columns = implode(', ', array_map([$this->driver, 'wrapIdentifier'], $keys));
        
        $sql = "INSERT INTO {$this->driver->wrapIdentifier($table)} ($columns) VALUES ($placeholders)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array_values($data)) ? $stmt->rowCount() : 0;
    }
    
    /**
     * Insert or update record
     * 
     * @param string $table Target table
     * @param array $data Records to insert/update
     * @param array $updateColumns Columns to update on duplicate
     * @return int Number of affected rows
     */
    public function upsert(string $table, array $data, array $updateColumns): int
    {
        if (empty($data)) {
            return 0;
        }

        $keys = array_keys($data[0]);
        $sql = $this->driver->upsert($table, $keys, $updateColumns);
        $stmt = $this->pdo->prepare($sql);

        $insertCount = 0;
        foreach ($data as $row) {
            if ($stmt->execute(array_values($row))) {
                $insertCount++;
            }
        }

        return $insertCount;
    }

    /**
     * Select records from database
     * 
     * @param string $table Target table
     * @param array $columns Columns to select
     * @param array $conditions WHERE conditions
     * @param bool $withTrashed Include soft deleted records
     * @param array $orderBy Sorting options
     * @param int|null $limit Maximum records to return
     * @return array Query results
     */
    public function select(
        string $table,
        array $columns = ['*'],
        array $conditions = [],
        bool $withTrashed = false,
        array $orderBy = [], // Supports both ['batch DESC', 'id DESC'] and ['column' => 'DESC']
        ?int $limit = null
    ): array {
        $columnList = implode(", ", array_map([$this->driver, 'wrapIdentifier'], $columns));
        $sql = "SELECT $columnList FROM " . $this->driver->wrapIdentifier($table);
    
        $whereClauses = [];
        foreach ($conditions as $col => $value) {
            $whereClauses[] = "{$this->driver->wrapIdentifier($col)} = ?";
        }
    
        if ($this->softDeletes && !$withTrashed) {
            $whereClauses[] = "deleted_at IS NULL";
        }
    
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
    
        if (!empty($orderBy)) {
            $orderByClauses = [];
            foreach ($orderBy as $key => $value) {
                if (is_int($key)) {
                    // Raw orderBy string e.g. ['batch DESC', 'id DESC']
                    $orderByClauses[] = $value;
                } else {
                    // Key-value orderBy e.g. ['created_at' => 'DESC']
                    $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                    $orderByClauses[] = "{$this->driver->wrapIdentifier($key)} $direction";
                }
            }
            $sql .= " ORDER BY " . implode(", ", $orderByClauses);
        }
    
        if (!is_null($limit)) {
            $sql .= " LIMIT ?";
        }
    
        $params = array_values($conditions);
        if (!is_null($limit)) {
            $params[] = $limit;
        }
    
        return $this->rawQuery($sql, $params);
    }

    /**
     * Delete records from database
     * 
     * @param string $table Target table
     * @param array $conditions WHERE conditions
     * @param bool $softDelete Use soft delete if available
     * @return bool True if operation succeeded
     */
    public function delete(string $table, array $conditions, bool $softDelete = true): bool
    {
        $sql = $softDelete ? 
            "UPDATE " . $this->driver->wrapIdentifier($table) . " SET deleted_at = CURRENT_TIMESTAMP WHERE " :
            "DELETE FROM " . $this->driver->wrapIdentifier($table) . " WHERE ";

        $sql .= implode(" AND ", array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions)));

        return $this->executeQuery($sql, array_values($conditions))->rowCount() > 0;
    }

    /**
     * Restore soft-deleted records
     * 
     * @param string $table Target table
     * @param array $conditions WHERE conditions
     * @return bool True if operation succeeded
     */
    public function restore(string $table, array $conditions): bool
    {
        $sql = "UPDATE " . $this->driver->wrapIdentifier($table) . " SET deleted_at = NULL WHERE " .
               implode(" AND ", array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions)));
        return $this->executeQuery($sql, array_values($conditions))->rowCount() > 0;
    }

    /**
     * Count records in table
     * 
     * @param string $table Target table
     * @param array $conditions WHERE conditions
     * @return int Number of matching records
     */
    public function count(string $table, array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) FROM " . $this->driver->wrapIdentifier($table);
        if (!empty($conditions)) {
            $whereClauses = array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions));
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $stmt = $this->executeQuery($sql, array_values($conditions));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Execute raw SQL query
     * 
     * @param string $sql Raw SQL query
     * @param array $params Query parameters
     * @return array Query results
     */
    public function rawQuery(string $sql, array $params = []): array
    {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Execute prepared statement
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \PDOStatement Executed statement
     */
    public function executeQuery(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Get paginated results
     * 
     * @param string $table Target table
     * @param array $columns Columns to select
     * @param array $conditions WHERE conditions
     * @param int $page Page number
     * @param int $perPage Records per page
     * @return array Paginated results with metadata
     */
    public function paginate(string $table, array $columns = ['*'], array $conditions = [], int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;
        $columnList = implode(", ", array_map([$this->driver, 'wrapIdentifier'], $columns));
        $sql = "SELECT $columnList FROM " . $this->driver->wrapIdentifier($table);

        if (!empty($conditions)) {
            $whereClauses = array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions));
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...array_values($conditions), $perPage, $offset]);

        $totalRecords = $this->count($table, $conditions);
        $lastPage = (int) ceil($totalRecords / $perPage);
        $from = $totalRecords > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $totalRecords);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalRecords,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Get identifier of last inserted record
     * 
     * Retrieves the specified column value (usually UUID) for the most recently
     * inserted record. Uses LAST_INSERT_ID() to find the record and returns
     * the requested column value.
     * 
     * @param string $table The table where the record was inserted
     * @param string $column The column to retrieve (defaults to 'uuid')
     * @return string The column value from the last inserted record
     * @throws \RuntimeException If the record or column value cannot be found
     * 
     * @example
     * ```php
     * $uuid = $queryBuilder->lastInsertId('users', 'uuid');
     * $customId = $queryBuilder->lastInsertId('orders', 'order_number');
     * ```
     */
    public function lastInsertId(string $table, string $column = 'uuid'): string 
    {
        $result = $this->select(
            $table,
            [$column],
            ['id' => $this->rawQuery('SELECT LAST_INSERT_ID()')[0]['LAST_INSERT_ID()']]
        );

        if (empty($result) || !isset($result[0][$column])) {
            throw new \RuntimeException("Failed to retrieve $column for new record");
        }

        return $result[0][$column];
    }
}
