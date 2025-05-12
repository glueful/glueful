<?php

namespace Glueful\Database;

use PDO;
use PDOException;
use Exception;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\RawExpression;

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

    /** @var array<int, string|\Glueful\Database\RawExpression> Group by clauses */
    protected array $groupBy = [];

    /** @var array Order by clauses */
    protected array $orderBy = [];

    /** @var array Stores join clauses */
    protected array $joins = [];

    /** @var array Stores query parameter bindings */
    protected array $bindings = [];

    /** @var array<int, array<string, mixed>|\Glueful\Database\RawExpression> Stores having clauses */
    protected array $having = [];

    /** @var array Stores raw where conditions */
    protected array $whereRaw = [];

    /** @var string Query string */
    private string $query = '';

    /** @var bool Enable debug mode */
    private bool $debugMode = false;  // Default: Off

    /** @var QueryLogger Logger for queries and database operations */
    protected QueryLogger $logger;

    /**
     * Initialize query builder
     *
     * @param PDO $pdo Active database connection
     * @param DatabaseDriver $driver Database-specific driver
     * @param QueryLogger|null $logger Query logger
     *
     */
    public function __construct(PDO $pdo, DatabaseDriver $driver, ?QueryLogger $logger = null)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->logger = $logger ?? new QueryLogger();
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

        $this->logger->logEvent("Starting transaction", ['retries_allowed' => $this->maxRetries]);

        while ($retryCount < $this->maxRetries) {
            $this->manageTransactionBegin();  // Use centralized method
            try {
                $result = $callback($this);
                $this->manageTransactionEnd(true);  // Commit using centralized method

                // Log successful transaction
                $this->logger->logEvent("Transaction completed successfully", [
                    'retries' => $retryCount
                ], 'info');

                return $result;
            } catch (Exception $e) {
                if ($this->isDeadlock($e)) {
                    $this->manageTransactionEnd(false);  // Rollback using centralized method
                    $retryCount++;

                     // Log deadlock and retry
                    $this->logger->logEvent("Transaction deadlock detected, retrying", [
                        'retry' => $retryCount,
                        'max_retries' => $this->maxRetries,
                        'error' => $e->getMessage()
                    ], 'warning');

                    usleep(500000);
                } else {
                    $this->manageTransactionEnd(false);  // Rollback using centralized method

                     // Log transaction failure
                    $this->logger->logEvent("Transaction failed", [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode()
                    ], 'error');

                    throw $e;
                }
            }
        }

        $this->logger->logEvent("Transaction failed after maximum retries", [
            'max_retries' => $this->maxRetries
        ], 'error');


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
        $this->manageTransactionBegin();
    }

    /**
     * Commit current transaction level
     *
     * Commits transaction or releases savepoint based on nesting.
     */
    public function commit(): void
    {
        $this->manageTransactionEnd(true);
    }

    /**
     * Rollback current transaction level
     *
     * Rolls back transaction or to savepoint based on nesting.
     */
    public function rollback(): bool
    {
        if ($this->transactionLevel <= 0) {
            return false;
        }
        $this->manageTransactionEnd(false);
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

        // This line already prepares AND executes the statement
        $stmt = $this->prepareAndExecute($sql, array_values($data));

        // We just need to return the row count, without executing again
        return $stmt->rowCount();
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

        // Use your consistent prepareAndExecute pattern for consistency and logging
        $insertCount = 0;
        foreach ($data as $row) {
            try {
                $stmt = $this->prepareAndExecute($sql, array_values($row));
                $insertCount += $stmt->rowCount();
            } catch (\PDOException $e) {
                // Your prepareAndExecute already logs errors and rethrows them
                // You might want to handle specific errors here instead of rethrowing all
                throw $e;
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
     * - GROUP BY
     * - HAVING
     * - Raw WHERE conditions
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
        $this->groupBy = []; // Reset group by
        $this->having = []; // Reset having
        $this->whereRaw = []; // Reset raw where conditions

        $columnList = implode(", ", array_map(function ($column) {
            if ($column instanceof RawExpression) {
                return (string) $column; // Keep raw SQL expressions as-is
            }
            if (strpos($column, ' AS ') !== false) {
                // Handle aliasing (e.g., roles.uuid AS role_id)
                [$columnName, $alias] = explode(' AS ', $column, 2);
                if (strpos($columnName, '.') !== false) {
                    [$table, $col] = explode('.', $columnName, 2);
                    $wrappedTable = $this->driver->wrapIdentifier($table);
                    $wrappedCol = $this->driver->wrapIdentifier($col);
                    $wrappedAlias = $this->driver->wrapIdentifier($alias);
                    return "$wrappedTable.$wrappedCol AS $wrappedAlias";
                }
                $wrappedColumn = $this->driver->wrapIdentifier($columnName);
                $wrappedAlias = $this->driver->wrapIdentifier($alias);
                return "$wrappedColumn AS $wrappedAlias";
            }
            if (strpos($column, '.') !== false) {
                [$table, $col] = explode('.', $column, 2);
                return $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
            }
            return $column === '*' ? '*' : $this->driver->wrapIdentifier($column);
        }, $columns));

        $sql = "SELECT $columnList FROM " . $this->driver->wrapIdentifier($table);

        // Add JOIN clauses if any
        if (!empty($this->joins)) {
            $sql .= " " . implode(" ", $this->joins);
        }

        // Build WHERE clause with new helper
        $whereClauses = empty($conditions) ? [] :
        [ltrim($this->buildClause('', $conditions), ' ')];

        if ($this->softDeletes && !$withTrashed && $applySoftDeletes) {
            $whereClauses[] = "deleted_at IS NULL";
        }

        // Build the complete WHERE clause combining standard and raw conditions
        $allWhereClauses = array_merge($whereClauses, array_column($this->whereRaw, 'condition'));

        if (!empty($allWhereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $allWhereClauses);
        }

        // Add GROUP BY if specified
        if (!empty($this->groupBy)) {
            $groupByColumns = array_map(function ($column) {
                if ($column instanceof RawExpression) {
                    return (string) $column;
                }
                if (strpos($column, '.') !== false) {
                    [$table, $col] = explode('.', $column, 2);
                    return $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
                }
                return $this->driver->wrapIdentifier($column);
            }, $this->groupBy);

            $sql .= " GROUP BY " . implode(", ", $groupByColumns);
        }

        // Add HAVING if specified
        if (!empty($this->having)) {
            $havingClauses = [];
            foreach ($this->having as $item) {
                if ($item instanceof RawExpression) {
                    $havingClauses[] = (string) $item;
                } else {
                    foreach ($item as $col => $value) {
                        $havingClauses[] = $this->driver->wrapIdentifier($col) . " = ?";
                        $this->bindings[] = $value;
                    }
                }
            }
            if (!empty($havingClauses)) {
                $sql .= " HAVING " . implode(" AND ", $havingClauses);
            }
        }

        if (!empty($orderBy)) {
            $orderByClauses = [];
            foreach ($orderBy as $key => $value) {
                $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                if (strpos($key, '.') !== false) {
                    [$table, $column] = explode('.', $key, 2);
                    $wrappedTable = $this->driver->wrapIdentifier($table);
                    $wrappedColumn = $this->driver->wrapIdentifier($column);
                    $orderByClauses[] = "$wrappedTable.$wrappedColumn $direction";
                } else {
                    $orderByClauses[] = "{$this->driver->wrapIdentifier($key)} $direction";
                }
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
     * Add raw WHERE condition to the query
     *
     * Allows for complex WHERE conditions like OR, LIKE, IN, etc.
     *
     * @param string $condition Raw SQL condition
     * @param array $bindings Parameter bindings for the condition
     * @return self Builder instance for chaining
     */
    public function whereRaw(string $condition, array $bindings = []): self
    {
        $this->whereRaw[] = [
            'condition' => $condition,
            'bindings' => $bindings
        ];

        // Add bindings to the main bindings array
        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }

        // If query is already built, append the raw condition
        if (!empty($this->query)) {
            $this->query .= (strpos($this->query, 'WHERE') === false ? " WHERE " : " AND ") . $condition;
        }

        return $this;
    }

    /**
     * Add GROUP BY clause to the query
     *
     * @param array $columns Columns to group by
     * @return self Builder instance for chaining
     */
    public function groupBy(array $columns): self
    {
        $this->groupBy = array_merge($this->groupBy, $columns);

        // If query is already built, append the GROUP BY clause
        if (!empty($this->query) && strpos($this->query, 'GROUP BY') === false) {
            $groupByColumns = array_map(function ($column) {
                if ($column instanceof RawExpression) {
                    return (string) $column;
                }
                if (strpos($column, '.') !== false) {
                    [$table, $col] = explode('.', $column, 2);
                    return $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
                }
                return $this->driver->wrapIdentifier($column);
            }, $columns);

            $this->query .= " GROUP BY " . implode(", ", $groupByColumns);
        }

        return $this;
    }

    /**
     * Add HAVING clause to the query
     *
     * @param array $conditions HAVING conditions
     * @return self Builder instance for chaining
     */
    public function having(array $conditions): self
    {
        $this->having[] = $conditions;

        // If query is already built, append the HAVING clause
        if (!empty($this->query)) {
            $havingClauses = [];
            foreach ($conditions as $col => $value) {
                $havingClauses[] = $this->driver->wrapIdentifier($col) . " = ?";
                $this->bindings[] = $value;
            }

            if (!empty($havingClauses)) {
                $this->query .= (strpos($this->query, 'HAVING') === false ? " HAVING " : " AND ") .
                    implode(" AND ", $havingClauses);
            }
        }

        return $this;
    }

    /**
     * Add raw HAVING clause to the query
     *
     * @param string $condition Raw SQL HAVING condition
     * @param array $bindings Parameter bindings for the condition
     * @return self Builder instance for chaining
     */
    public function havingRaw(string $condition, array $bindings = []): self
    {
        $rawExpression = new RawExpression($condition);
        $this->having[] = $rawExpression;

        // Add bindings to the main bindings array
        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }

        // If query is already built, append the raw HAVING condition
        if (!empty($this->query)) {
            $this->query .= (strpos($this->query, 'HAVING') === false ? " HAVING " : " AND ") . $condition;
        }

        return $this;
    }

    /**
     * Add WHERE IN condition to the query
     *
     * @param string $column Column name
     * @param array $values Array of values to match against
     * @return self Builder instance for chaining
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this->whereRaw('1 = 0'); // Always false if empty array
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn IN ($placeholders)", $values);
    }

    /**
     * Add WHERE NOT IN condition to the query
     *
     * @param string $column Column name
     * @param array $values Array of values to exclude
     * @return self Builder instance for chaining
     */
    public function whereNotIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this; // Always true if empty array, so no condition needed
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn NOT IN ($placeholders)", $values);
    }

    /**
     * Add WHERE BETWEEN condition to the query
     *
     * @param string $column Column name
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @return self Builder instance for chaining
     */
    public function whereBetween(string $column, $min, $max): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn BETWEEN ? AND ?", [$min, $max]);
    }

    /**
     * Add WHERE NULL condition to the query
     *
     * @param string $column Column name
     * @return self Builder instance for chaining
     */
    public function whereNull(string $column): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn IS NULL");
    }

    /**
     * Add WHERE NOT NULL condition to the query
     *
     * @param string $column Column name
     * @return self Builder instance for chaining
     */
    public function whereNotNull(string $column): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn IS NOT NULL");
    }

    /**
     * Add WHERE LIKE condition to the query
     *
     * @param string $column Column name
     * @param string $pattern LIKE pattern
     * @return self Builder instance for chaining
     */
    public function whereLike(string $column, string $pattern): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn LIKE ?", [$pattern]);
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

        $whereConditions = array_map(
            fn($col) => "{$this->driver->wrapIdentifier($col)} = ?",
            array_keys($conditions)
        );
        $sql .= implode(" AND ", $whereConditions);

        $stmt = $this->executeQuery($sql, array_values($conditions));

        return $stmt->rowCount() > 0;
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
        $tableName = $this->driver->wrapIdentifier($table);
        $whereConditions = array_map(
            fn($col) => "{$this->driver->wrapIdentifier($col)} = ?",
            array_keys($conditions)
        );
        $sql = "UPDATE $tableName SET deleted_at = NULL WHERE " . implode(" AND ", $whereConditions);
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
        $stmt = $this->prepareAndExecute($sql, $params); // Use here
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
        return $this->prepareAndExecute($sql, $params); // Replace entire method
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
        $timerId = $this->logger->startTiming('pagination');

        $this->logger->logEvent("Executing paginated query", [
            'page' => $page,
            'per_page' => $perPage,
            'query' => substr($this->query, 0, 100) . '...'
        ], 'debug');

        $offset = ($page - 1) * $perPage;

        // Remove existing LIMIT/OFFSET before adding a new one
        $paginatedQuery = preg_replace('/\sLIMIT\s\d+(\sOFFSET\s\d+)?/i', '', $this->query);
        $paginatedQuery .= " LIMIT ? OFFSET ?";

        // Execute paginated query
        $stmt = $this->prepareAndExecute($paginatedQuery, [...$this->bindings, $perPage, $offset]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // **Fix COUNT Query**
        $countQuery = preg_replace('/^SELECT\s.*?\sFROM/i', 'SELECT COUNT(*) as total FROM', $this->query);
        $countQuery = preg_replace('/\sORDER BY .*/i', '', $countQuery); // Remove ORDER BY

        // Use the optimized count query method here
        $countQuery = $this->getOptimizedCountQuery($this->query);
        $countStmt = $this->prepareAndExecute($countQuery, $this->bindings);
        $totalRecords = $countStmt->fetchColumn();

        $lastPage = (int) ceil($totalRecords / $perPage);
        $from = $totalRecords > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $totalRecords);

        $executionTime = $this->logger->endTiming($timerId);

        $this->logger->logEvent("Pagination complete", [
            'total_records' => $totalRecords,
            'total_pages' => $lastPage,
            'page' => $page,
            'record_count' => count($data)
        ], 'debug');

        return [
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalRecords,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
            'from' => $from,
            'to' => $to,
            // Optionally include execution time for debugging
            'execution_time_ms' => $executionTime
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
        )->get();

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
    public function join(string $table, string $on, string $type = 'INNER'): self
    {
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
    public function where(array $conditions): self
    {
        if (empty($conditions)) {
            return $this;
        }

        // Add bindings
        foreach ($conditions as $col => $value) {
            $this->bindings[] = $value;
        }

        // Build and append WHERE clause
        $whereClause = ltrim($this->buildClause('', $conditions), ' ');
        $this->query .= (strpos($this->query, 'WHERE') === false ? " WHERE " : " AND ") . $whereClause;

        return $this;
    }

    public function orderBy(array $orderBy): self
    {
        if (!empty($orderBy)) {
            $orderByClauses = [];
            foreach ($orderBy as $key => $value) {
                $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                $orderByClauses[] = "{$this->driver->wrapIdentifier($key)} $direction";
            }
            // Add ORDER BY clause (no need to check count, we know orderBy is not empty)
            $this->query .= " ORDER BY " . implode(", ", $orderByClauses);
        }
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query .= " LIMIT ?";
        $this->bindings[] = $limit;
        return $this;
    }

    /**
     * Add OFFSET clause to the query
     *
     * @param int $offset Number of rows to skip
     * @return self Builder instance for chaining
     */
    public function offset(int $offset): self
    {
        // Only add OFFSET if positive
        if ($offset > 0) {
            $this->query .= " OFFSET ?";
            $this->bindings[] = $offset;
        }
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
    public function get(): array
    {
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
    public function raw(string $expression): RawExpression
    {
        return new RawExpression($expression);
    }

    /**
     * Build a SQL clause with conditions
     *
     * @param string $keyword SQL keyword (WHERE, HAVING, etc.)
     * @param array $conditions Key-value pairs of conditions
     * @param string $separator Condition separator (AND, OR, etc.)
     * @return string Constructed clause or empty string if no conditions
     */
    private function buildClause(string $keyword, array $conditions, string $separator = ' AND '): string
    {
        if (empty($conditions)) {
            return '';
        }

        return $keyword . ' ' . implode($separator, array_map(function ($col) {
            if (strpos($col, '.') !== false) {
                [$table, $column] = explode('.', $col, 2);
                return $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($column) . " = ?";
            }
            return $this->driver->wrapIdentifier($col) . " = ?";
        }, array_keys($conditions)));
    }


    private function manageTransactionBegin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
    }

    private function manageTransactionEnd(bool $commit = true): void
    {
        if ($this->transactionLevel <= 0) {
            return; // No active transaction
        }

        if ($this->transactionLevel === 1) {
            $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        } elseif (!$commit) {
            // Only need to handle rollback for nested transactions
            $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    /**
     * Centralized method for preparing and executing queries
     */
    private function prepareAndExecute(string $sql, array $params = []): \PDOStatement
    {
        // Start timing the query with debug context if enabled
        $timerId = $this->logger->startTiming($this->debugMode ? 'query_with_debug' : 'query');
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
             // Log successful query
            $this->logger->logQuery($sql, $params, $timerId, null);
            return $stmt;
        } catch (PDOException $e) {
            // Log failed query
            $this->logger->logQuery($sql, $params, $timerId, $e);
            throw $e;
        }
    }

    // Optimization for count query in pagination
    private function getOptimizedCountQuery(string $query): string
    {
        // Add debug information if debug mode is enabled
        if ($this->debugMode) {
            $this->logger->logEvent('Optimizing count query', ['original_query' => $query], 'debug');
        }
        // Remove unnecessary parts that don't affect count
        $countQuery = preg_replace('/SELECT\s.*?\sFROM/is', 'SELECT COUNT(*) as total FROM', $query);
        $countQuery = preg_replace('/\sORDER BY\s.*$/is', '', $countQuery);
        $countQuery = preg_replace('/\sLIMIT\s.*$/is', '', $countQuery);

        // If there's a GROUP BY, we need to count differently
        if (strpos($countQuery, 'GROUP BY') !== false) {
            return "SELECT COUNT(*) as total FROM ($query) as count_table";
        }

        return $countQuery;
    }

    /**
     * Enable or disable debug mode
     *
     * @param bool $debug Whether to enable debug mode
     * @return self Builder instance for chaining
     */
    public function enableDebug(bool $debug = true): self
    {
        $this->debugMode = $debug;

        // Configure logger accordingly
        $this->logger->configure($debug, $debug);

        return $this;
    }

    /**
     * Get current debug mode status
     *
     * @return bool Current debug mode status
     */
    public function getDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * Set query logger instance
     *
     * @param QueryLogger $logger Logger instance
     * @return self Builder instance for chaining
     */
    public function setLogger(QueryLogger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the current query logger
     *
     * @return QueryLogger Current logger instance
     */
    public function getLogger(): QueryLogger
    {
        return $this->logger;
    }

    /**
     * Update records in database table
     *
     * Features:
     * - Automatic parameter binding for security
     * - Conditional updates with WHERE clauses
     * - Proper identifier escaping for tables and columns
     * - Execution monitoring and logging
     *
     * @param string $table Target table name
     * @param array $data Column data key-value pairs to update
     * @param array $conditions WHERE conditions to limit affected rows
     * @return int Number of affected rows
     * @throws PDOException On update failure
     *
     * @example
     * ```php
     * // Update a user's status
     * $affected = $query->update('users', ['status' => 'inactive'], ['id' => 5]);
     *
     * // Update multiple fields with complex condition
     * $affected = $query->update('orders',
     *     ['status' => 'shipped', 'shipped_at' => '2025-04-26'],
     *     ['status' => 'processing', 'id' => $orderId]
     * );
     * ```
     */
    public function update(string $table, array $data, array $conditions): int
    {
        if (empty($data)) {
            return 0; // Nothing to update
        }

        // Build SET clause with placeholders
        $setClauses = [];
        $values = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "{$this->driver->wrapIdentifier($column)} = ?";
            $values[] = $value;
        }

        // Build WHERE clause
        $whereClauses = [];
        foreach ($conditions as $column => $value) {
            $whereClauses[] = "{$this->driver->wrapIdentifier($column)} = ?";
            $values[] = $value;
        }

        $sql = "UPDATE {$this->driver->wrapIdentifier($table)} SET " .
               implode(', ', $setClauses);

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        // Execute the update query using the centralized method
        $stmt = $this->prepareAndExecute($sql, $values);

        return $stmt->rowCount();
    }

    /**
     * Get only the first result from the query
     *
     * Features:
     * - Optimizes query with LIMIT 1
     * - Returns a single record (not an array of records)
     * - Returns null if no records found
     *
     * @return array|null The first record from the query or null if none found
     * @throws PDOException On query execution failure
     *
     * @example
     * ```php
     * // Get the first matching user
     * $user = $query->select('users', ['*'])
     *     ->where(['status' => 'active'])
     *     ->orderBy(['created_at' => 'DESC'])
     *     ->first();
     * ```
     */
    public function first(): ?array
    {
        // Remove any existing LIMIT clause
        $this->query = preg_replace('/\sLIMIT\s\d+(\sOFFSET\s\d+)?/i', '', $this->query);

        // Add LIMIT 1 for optimization
        $this->query .= " LIMIT 1";

        $timerId = $this->logger->startTiming('first');
        try {
            $stmt = $this->pdo->prepare($this->query);
            $stmt->execute($this->bindings);

            // Log successful query
            $this->logger->logQuery($this->query, $this->bindings, $timerId);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            // Log failed query
            $this->logger->logQuery($this->query, $this->bindings, $timerId, $e);
            throw $e;
        }
    }
}
