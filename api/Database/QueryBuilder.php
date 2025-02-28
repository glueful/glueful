<?php

namespace Glueful\Database;

use PDO;
use PDOException;
use Exception;
use Glueful\Database\Driver\DatabaseDriver;

/**
 * Database Query Builder
 * 
 * Provides fluent interface for SQL query construction with features:
 * - Database-agnostic query building
 * - Prepared statement support
 * - Transaction management with savepoints
 * - Automatic deadlock handling
 * - Soft delete integration
 * - Pagination support
 * 
 * Design patterns:
 * - Fluent interface for method chaining
 * - Strategy pattern for database operations
 * - Template method for query construction
 * 
 * Security features:
 * - Automatic parameter binding
 * - Identifier escaping
 * - Transaction isolation
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

    /** @var array Stores join clauses */
    protected array $joins = [];

    /** @var array Stores query parameter bindings */
    protected array $bindings = [];

    private string $query = '';

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
     * Execute callback within database transaction
     * 
     * Features:
     * - Automatic deadlock detection and retry
     * - Nested transaction support via savepoints
     * - Proper cleanup on failure
     * - Progressive backoff between retries
     * 
     * @param callable $callback Function to execute in transaction
     * @return mixed Result of callback execution
     * @throws Exception After max retries or on unhandled error
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
    public function rollback(): bool {
        if ($this->transactionLevel <= 0) {
            return false; // No active transaction
        }
        
        if ($this->transactionLevel === 1) {
            $this->pdo->rollBack();
        } else if ($this->transactionLevel > 1) {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
        }
        
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        return true;
    }

    /**
     * Insert new database record
     * 
     * Features:
     * - Automatic column escaping
     * - Bulk insert support
     * - Generated column handling
     * - Last insert ID retrieval
     * 
     * @param string $table Target table name
     * @param array $data Column data key-value pairs
     * @return int Number of affected rows
     * @throws PDOException On insert failure
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
     * Select records with advanced filtering
     * 
     * Supports:
     * - Column selection
     * - WHERE conditions
     * - JOIN clauses
     * - ORDER BY
     * - LIMIT/OFFSET
     * - Soft delete filtering
     * 
     * @param string $table Base table for query
     * @param array $columns Columns to select
     * @param array $conditions WHERE conditions
     * @param bool $withTrashed Include soft-deleted records
     * @param array $orderBy Sorting specification
     * @param int|null $limit Maximum rows to return
     * @return self Builder instance for chaining
     */
    public function select(
        string $table,
        array $columns = ['*'],
        array $conditions = [],
        bool $withTrashed = false,
        array $orderBy = [],
        ?int $limit = null,
        bool $applySoftDeletes = false
    ): self {
        $this->bindings = []; // Reset bindings
        $columnList = implode(", ", array_map(function ($column) {
            if ($column instanceof RawExpression) {
                return (string) $column; // Keep raw SQL expressions as-is
            }
            return $column === '*' ? '*' : $this->driver->wrapIdentifier($column);
        }, $columns));
        $sql = "SELECT $columnList FROM " . $this->driver->wrapIdentifier($table);

        // Add JOIN clauses if any
        if (!empty($this->joins)) {
            $sql .= " " . implode(" ", $this->joins);
        }

        $whereClauses = [];
        foreach ($conditions as $col => $value) {
            $whereClauses[] = "{$this->driver->wrapIdentifier($col)} = ?";
            $this->bindings[] = $value;
        }

        if ($this->softDeletes && !$withTrashed && $applySoftDeletes) {
            $whereClauses[] = "deleted_at IS NULL";
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        if (!empty($orderBy)) {
            $orderByClauses = [];
            foreach ($orderBy as $key => $value) {
                $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                $orderByClauses[] = "{$this->driver->wrapIdentifier($key)} $direction";
            }
            $sql .= " ORDER BY " . implode(", ", $orderByClauses);
        }

        $this->query = $sql;

        if ($limit !== null) {
            $this->query .= " LIMIT ?";
            $this->bindings[] = $limit;
        }

        return $this;
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
    public function count(string $table, array $conditions = []): int {
        $this->bindings = []; // Reset bindings
        $sql = "SELECT COUNT(*) as total FROM " . $this->driver->wrapIdentifier($table);
        
        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $col => $value) {
                $whereClauses[] = "{$this->driver->wrapIdentifier($col)} = ?";
                $this->bindings[] = $value;
            }
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
    
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
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
     * Paginate query results
     * 
     * Returns structured pagination data:
     * - Result subset for current page
     * - Total record count
     * - Page information
     * - Navigation metadata
     * 
     * @param int $page Current page number
     * @param int $perPage Records per page
     * @return array Pagination result set
     */
    public function paginate(int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;

        // Modify query to include pagination
        $paginatedQuery = $this->query . " LIMIT ? OFFSET ?";
        $bindings = [...$this->bindings, $perPage, $offset];

        // Execute the paginated query
        $stmt = $this->pdo->prepare($paginatedQuery);
        $stmt->execute($bindings);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM (" . $this->query . ") as subquery";
        $countStmt = $this->pdo->prepare($countQuery);
        $countStmt->execute($this->bindings);
        $totalRecords = $countStmt->fetchColumn();
        $lastPage = (int) ceil($totalRecords / $perPage);
        $from = $totalRecords > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $totalRecords);

        return [
            'data' => $data,
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

    /**
     * Join additional table to query
     * 
     * Supports:
     * - INNER, LEFT, RIGHT, FULL joins
     * - Custom join conditions
     * - Multiple joins
     * - Aliased tables
     * 
     * @param string $table Table to join
     * @param string $on Join condition
     * @param string $type Join type (INNER, LEFT, etc)
     * @return self Builder instance for chaining
     */
    public function join(string $table, string $on, string $type = 'INNER'): self {
        $joinClause = strtoupper($type) . " JOIN " . $this->driver->wrapIdentifier($table) . " ON $on";
        $this->joins[] = $joinClause;
        return $this;
    }

    /**
     * Add WHERE conditions to query
     * 
     * Features:
     * - Multiple condition support
     * - Automatic parameter binding
     * - Complex condition building
     * - Chain-safe condition addition
     * 
     * @param array $conditions Column-value pairs
     * @return self Builder instance for chaining
     */
    public function where(array $conditions): self {
        foreach ($conditions as $col => $value) {
            $this->query .= (strpos($this->query, 'WHERE') === false ? " WHERE " : " AND ") . "{$this->driver->wrapIdentifier($col)} = ?";
            $this->bindings[] = $value;
        }
        return $this;
    }

    public function orderBy(array $orderBy): self {
        $orderByClauses = [];
        foreach ($orderBy as $key => $value) {
            $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
            $orderByClauses[] = "{$this->driver->wrapIdentifier($key)} $direction";
        }
        $this->query .= " ORDER BY " . implode(", ", $orderByClauses);
        return $this;
    }

    public function limit(int $limit): self {
        $this->query .= " LIMIT ?";
        $this->bindings[] = $limit;
        return $this;
    }

    /**
     * Execute and retrieve query results
     * 
     * Features:
     * - Automatic statement preparation
     * - Parameter binding
     * - Result set fetching
     * - Error handling
     * 
     * @return array Query result set
     * @throws PDOException On query execution failure
     */
    public function get(): array {
        $stmt = $this->pdo->prepare($this->query);
        $stmt->execute($this->bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a raw SQL expression that will be injected into the query without escaping
     * 
     * WARNING: Only use this method with trusted input as it can lead to SQL injection if misused
     * 
     * Use cases:
     * - Complex SQL functions: MAX(), COUNT(), etc
     * - Database-specific features
     * - Custom SQL expressions
     * 
     * @param string $expression Raw SQL expression
     * @return RawExpression Wrapper for raw SQL
     * 
     * @example
     * ```php
     * $query->select('users', [$query->raw('COUNT(*) as total')]);
     * $query->select('orders', [$query->raw('DATE_FORMAT(created_at, "%Y-%m-%d") as date')]);
     * ```
     */
    public function raw(string $expression): RawExpression {
        return new RawExpression($expression);
    }
}

/**
 * Raw SQL Expression Container
 * 
 * Wraps raw SQL expressions to prevent automatic escaping when used in queries.
 * This class helps distinguish between regular strings that should be escaped
 * and raw SQL expressions that should be used as-is.
 * 
 * Security note:
 * Only use with trusted input as raw SQL expressions bypass normal escaping
 * 
 * @internal Used internally by QueryBuilder
 */
class RawExpression {
    /** @var string The raw SQL expression */
    protected string $expression;

    /**
     * Create new raw SQL expression
     * 
     * @param string $expression Raw SQL to be used without escaping
     */
    public function __construct(string $expression) {
        $this->expression = $expression;
    }

    /**
     * Get the raw expression string
     * 
     * @return string The raw SQL expression
     */
    public function getExpression(): string {
        return $this->expression;
    }

    /**
     * Convert to string when used in string context
     * 
     * @return string The raw SQL expression
     */
    public function __toString(): string {
        return $this->expression;
    }
}