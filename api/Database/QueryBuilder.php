<?php

namespace Glueful\Database;

use PDO;
use PDOException;
use Exception;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\RawExpression;
use Glueful\Database\PooledConnection;

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
 * - Query purpose annotation
 * - Advanced query logging with table name extraction
 * - Query complexity analysis
 * - N+1 query detection
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
 * - Parameter sanitization in logs
 *
 * Example with query purpose annotation:
 * ```php
 * $users = $queryBuilder
 *     ->withPurpose('User authentication check')
 *     ->select('users', ['id', 'email'])
 *     ->where(['status' => 'active', 'email' => $email])
 *     ->get();
 * ```
 */
class QueryBuilder
{
    /** @var PDO Active database connection */
    protected PDO $pdo;

    /** @var PooledConnection|null Pooled connection wrapper if using connection pooling */
    protected ?PooledConnection $pooledConnection = null;

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

    /** @var bool Whether the final query has been built */
    private bool $finalQueryBuilt = false;

    /** @var string|null Purpose of the current query for business context */
    private ?string $queryPurpose = null;

    /** @var QueryLogger Logger for queries and database operations */
    protected QueryLogger $logger;

    /** @var bool Whether to enable query optimization */
    protected bool $optimizationEnabled = false;

    /** @var float Minimum percentage improvement threshold for applying optimizations */
    protected float $optimizationThreshold = 10.0;

    /** @var QueryOptimizer|null Query optimizer instance */
    protected ?QueryOptimizer $optimizer = null;

    /** @var bool Whether to enable query result caching */
    protected bool $cachingEnabled = false;

    /** @var int|null Cache TTL in seconds, null means use default from config */
    protected ?int $cacheTtl = null;

    /** @var QueryCacheService|null Query cache service instance */
    protected ?QueryCacheService $cacheService = null;

    /**
     * Initialize query builder
     *
     * Supports both traditional PDO connections and pooled connections.
     * When a PooledConnection is provided, it automatically handles connection
     * lifecycle management including release back to the pool.
     *
     * @param PDO|PooledConnection $connection Database connection
     * @param DatabaseDriver $driver Database-specific driver
     * @param QueryLogger|null $logger Query logger
     *
     */
    public function __construct($connection, DatabaseDriver $driver, ?QueryLogger $logger = null)
    {
        if ($connection instanceof PooledConnection) {
            $this->pooledConnection = $connection;
            $this->pdo = $connection->getPDO(); // Get underlying PDO instance
        } elseif ($connection instanceof PDO) {
            $this->pdo = $connection;
        } else {
            throw new \InvalidArgumentException('Connection must be PDO or PooledConnection instance');
        }

        $this->driver = $driver;
        $this->logger = $logger ?? new QueryLogger();
    }

    /**
     * Destructor - ensures proper cleanup of pooled connections
     *
     * Automatically releases pooled connections back to the pool when
     * the QueryBuilder instance is destroyed, preventing connection leaks.
     */
    public function __destruct()
    {
        // Release pooled connection back to pool
        // The PooledConnection destructor will handle the actual release
        $this->pooledConnection = null;
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
     * Check if a transaction is currently active
     *
     * @return bool True if a transaction is active, false otherwise
     */
    public function isTransactionActive(): bool
    {
        return $this->transactionLevel > 0;
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

        // Get affected rows
        $rowCount = $stmt->rowCount();

        return $rowCount;
    }

    /**
     * Insert multiple rows in a single query for better performance
     *
     * @param string $table Table name
     * @param array $rows Array of associative arrays representing rows to insert
     * @return int Number of affected rows
     * @throws \InvalidArgumentException If rows array is empty or invalid
     */
    public function insertBatch(string $table, array $rows): int
    {
        if (empty($rows)) {
            throw new \InvalidArgumentException('Cannot perform batch insert with empty rows array');
        }

        // Get columns from the first row
        $firstRow = reset($rows);
        if (!is_array($firstRow)) {
            throw new \InvalidArgumentException('Each row must be an associative array');
        }

        $columns = array_keys($firstRow);
        $columnCount = count($columns);

        // Validate all rows have the same columns
        foreach ($rows as $index => $row) {
            if (!is_array($row) || count($row) !== $columnCount || array_keys($row) !== $columns) {
                throw new \InvalidArgumentException("Row at index {$index} has inconsistent columns");
            }
        }

        // Build column list
        $columnList = implode(', ', array_map([$this->driver, 'wrapIdentifier'], $columns));

        // Build placeholders for all rows
        $rowPlaceholder = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        // Build the SQL query
        $sql = "INSERT INTO {$this->driver->wrapIdentifier($table)} ($columnList) VALUES $allPlaceholders";

        // Flatten all values into a single array
        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column];
            }
        }

        // Execute the query
        $stmt = $this->prepareAndExecute($sql, $values);

        return $stmt->rowCount();
    }

    /**
     * Determine if a table is considered sensitive for audit logging
     *
     * Sensitive tables contain data that should be audited for security,
     * compliance, or privacy reasons. Operations on these tables are
     * logged to the audit system.
     *
     * @param string $table Table name
     * @return bool True if table is sensitive
     */
    protected function isSensitiveTable(string $table): bool
    {
        // Strip table prefix if any
        $prefix = config('database.connections.mysql.prefix', '');
        if (!empty($prefix) && strpos($table, $prefix) === 0) {
            $table = substr($table, strlen($prefix));
        }

        // List of sensitive tables that require audit logging
        $sensitiveTables = [
            'users',
            'permissions',
            'roles',
            'user_roles_lookup',
            'profiles',
            'api_keys',
            'tokens',
            'auth_sessions',
            'audit_logs',
            'oauth_access_tokens',
            'oauth_auth_codes',
            'oauth_clients',
            'oauth_personal_access_clients',
            'oauth_refresh_tokens',
            'password_resets',
        ];

        return in_array($table, $sensitiveTables);
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
        $this->joins = []; // Reset joins
        $this->finalQueryBuilt = false; // Reset final query build flag

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
                // Handle table.* specially - don't wrap the asterisk
                if ($col === '*') {
                    return $this->driver->wrapIdentifier($table) . ".*";
                }
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
     * Add OR WHERE NULL condition to the query
     *
     * @param string $column Column name
     * @return self Builder instance for chaining
     */
    public function orWhereNull(string $column): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        // Build OR WHERE NULL clause
        $condition = "$wrappedColumn IS NULL";

        // If no WHERE exists yet, start with WHERE, otherwise use OR
        if (strpos($this->query, 'WHERE') === false) {
            $this->query .= " WHERE " . $condition;
        } else {
            $this->query .= " OR " . $condition;
        }

        return $this;
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
     * Add WHERE column < value condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @param mixed $value Value to compare against
     * @return self Builder instance for chaining
     */
    public function whereLessThan(string $column, $value): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn < ?", [$value]);
    }

    /**
     * Add WHERE column > value condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @param mixed $value Value to compare against
     * @return self Builder instance for chaining
     */
    public function whereGreaterThan(string $column, $value): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn > ?", [$value]);
    }

    /**
     * Add WHERE column <= value condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @param mixed $value Value to compare against
     * @return self Builder instance for chaining
     */
    public function whereLessThanOrEqual(string $column, $value): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn <= ?", [$value]);
    }

    /**
     * Add WHERE column >= value condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @param mixed $value Value to compare against
     * @return self Builder instance for chaining
     */
    public function whereGreaterThanOrEqual(string $column, $value): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn >= ?", [$value]);
    }

    /**
     * Add WHERE NOT EQUAL condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @param mixed $value Value to compare against
     * @return self Builder instance for chaining
     */
    public function whereNotEqual(string $column, $value): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        return $this->whereRaw("$wrappedColumn != ?", [$value]);
    }

    /**
     * Add OR WHERE NOT NULL condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @return self Builder instance for chaining
     */
    public function orWhereNotNull(string $column): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        // Build OR WHERE NOT NULL clause
        $condition = "$wrappedColumn IS NOT NULL";

        // If no WHERE exists yet, start with WHERE, otherwise use OR
        if (strpos($this->query, 'WHERE') === false) {
            $this->query .= " WHERE " . $condition;
        } else {
            $this->query .= " OR " . $condition;
        }

        return $this;
    }

    /**
     * Add OR WHERE GREATER THAN condition to the query
     *
     * @param string $column Column name (supports table.column format)
     * @param mixed $value Value to compare against
     * @return self Builder instance for chaining
     */
    public function orWhereGreaterThan(string $column, $value): self
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        $this->bindings[] = $value;
        $condition = "$wrappedColumn > ?";

        // If no WHERE exists yet, start with WHERE, otherwise use OR
        if (strpos($this->query, 'WHERE') === false) {
            $this->query .= " WHERE " . $condition;
        } else {
            $this->query .= " OR " . $condition;
        }

        return $this;
    }

    /**
     * Add complex OR condition to the query
     *
     * Allows building complex OR conditions like "(field IS NULL OR field > value)"
     *
     * @param callable $callback Callback function that receives a new QueryBuilder instance
     * @return self Builder instance for chaining
     *
     * @example
     * ```php
     * $query->whereOr(function($q) {
     *     $q->whereNull('expires_at')->orWhereGreaterThan('expires_at', $currentTime);
     * });
     * ```
     */
    public function whereOr(callable $callback): self
    {
        // Create a new QueryBuilder instance for the OR group
        $orQuery = new self($this->pdo, $this->driver, $this->logger);

        // Execute the callback to build the OR conditions
        $callback($orQuery);

        // Extract the WHERE part from the built query
        $orQueryString = $orQuery->getQueryString();
        $pattern = '/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+GROUP\s+BY|\s+HAVING|\s+LIMIT|$)/i';
        if (preg_match($pattern, $orQueryString, $matches)) {
            $orCondition = trim($matches[1]);

            // Add the OR condition as a group
            if (strpos($this->query, 'WHERE') === false) {
                $this->query .= " WHERE (" . $orCondition . ")";
            } else {
                $this->query .= " AND (" . $orCondition . ")";
            }

            // Merge bindings
            $this->bindings = array_merge($this->bindings, $orQuery->getBindings());
        }

        return $this;
    }

    /**
     * Get current query string (for internal use)
     *
     * @return string Current query string
     */
    protected function getQueryString(): string
    {
        return $this->query;
    }

    /**
     * Get current bindings (for internal use)
     *
     * @return array Current parameter bindings
     */
    protected function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Add WHERE JSON contains condition to the query
     *
     * Provides database-agnostic JSON searching with proper parameter binding.
     * Uses the appropriate JSON functions based on the database driver.
     *
     * @param string $column JSON column name (supports table.column format)
     * @param string $searchValue Value to search for within the JSON
     * @param string $path JSON path (optional, defaults to searching entire JSON)
     * @return self Builder instance for chaining
     *
     * @example
     * ```php
     * // Search for a value anywhere in JSON column
     * $query->whereJsonContains('details', 'login_failed');
     *
     * // Search within a specific JSON path (MySQL only)
     * $query->whereJsonContains('metadata', 'active', '$.status');
     * ```
     */
    public function whereJsonContains(string $column, string $searchValue, ?string $path = null): self
    {
        // Wrap column identifier properly
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            $wrappedColumn = $this->driver->wrapIdentifier($table) . "." . $this->driver->wrapIdentifier($col);
        } else {
            $wrappedColumn = $this->driver->wrapIdentifier($column);
        }

        // Build database-specific JSON search condition
        $driverClass = get_class($this->driver);

        if (strpos($driverClass, 'MySQL') !== false) {
            // MySQL: Use JSON_CONTAINS or JSON_SEARCH
            if ($path !== null) {
                // Search at specific path
                $condition = "JSON_CONTAINS($wrappedColumn, ?, '$path')";
                return $this->whereRaw($condition, [json_encode($searchValue)]);
            } else {
                // Search anywhere in JSON using JSON_SEARCH
                $condition = "JSON_SEARCH($wrappedColumn, 'one', ?) IS NOT NULL";
                return $this->whereRaw($condition, [$searchValue]);
            }
        } elseif (strpos($driverClass, 'PostgreSQL') !== false) {
            // PostgreSQL: Use jsonb operators or text casting
            if ($path !== null) {
                // Search at specific path using #>> operator
                $condition = "$wrappedColumn #>> ? = ?";
                return $this->whereRaw($condition, [$path, $searchValue]);
            } else {
                // Search anywhere using text casting and LIKE
                $condition = "$wrappedColumn::text LIKE ?";
                return $this->whereRaw($condition, ["%$searchValue%"]);
            }
        } else {
            // Generic fallback: Cast to text and use LIKE
            $condition = "CAST($wrappedColumn AS TEXT) LIKE ?";
            return $this->whereRaw($condition, ["%$searchValue%"]);
        }
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

        $whereConditions = [];
        foreach (array_keys($conditions) as $condition) {
            $whereConditions[] = $this->parseCondition($condition);
        }
        $sql .= implode(" AND ", $whereConditions);

        $stmt = $this->executeQuery($sql, array_values($conditions));

        $result = $stmt->rowCount() > 0;

        return $result;
    }

    /**
     * Parse condition string to extract column name and operator
     *
     * Supports operators: =, <, >, <=, >=, !=, <>, LIKE, NOT LIKE
     * Examples:
     * - 'column' -> 'column = ?'
     * - 'column <' -> 'column < ?'
     * - 'column LIKE' -> 'column LIKE ?'
     *
     * @param string $condition Condition string
     * @return string Parsed SQL condition
     */
    private function parseCondition(string $condition): string
    {
        // Trim the condition
        $condition = trim($condition);

        // Define supported operators (order matters - longer operators first)
        $operators = ['<=', '>=', '<>', '!=', 'NOT LIKE', 'LIKE', '<', '>', '='];

        foreach ($operators as $operator) {
            if (str_ends_with($condition, ' ' . $operator)) {
                $column = trim(substr($condition, 0, -strlen($operator) - 1));
                return $this->driver->wrapIdentifier($column) . ' ' . $operator . ' ?';
            }
        }

        // Default to equals if no operator found
        return $this->driver->wrapIdentifier($condition) . ' = ?';
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
        $stmt->execute($this->flattenBindings($this->bindings));
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
        $timerId = $this->logger->startTiming();

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

    /**
     * Add OR WHERE conditions to the query
     *
     * @param array $conditions Key-value pairs of column => value conditions
     * @return self
     */
    public function orWhere(array $conditions): self
    {
        if (empty($conditions)) {
            return $this;
        }

        // Add bindings
        foreach ($conditions as $col => $value) {
            $this->bindings[] = $value;
        }

        // Build and append OR WHERE clause
        $whereClause = ltrim($this->buildClause('', $conditions), ' ');

        // If no WHERE exists yet, start with WHERE, otherwise use OR
        if (strpos($this->query, 'WHERE') === false) {
            $this->query .= " WHERE " . $whereClause;
        } else {
            $this->query .= " OR " . $whereClause;
        }

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
        // Build the final query with all components (JOINs, etc.)
        $this->buildFinalQuery();

        // Use caching if enabled
        if ($this->cachingEnabled) {
            $cacheService = $this->getCacheService();

            return $cacheService->getOrExecute(
                $this->query,
                $this->bindings,
                function () {
                    // Original get() logic
                    $stmt = $this->pdo->prepare($this->query);
                    $stmt->execute($this->flattenBindings($this->bindings));
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                },
                $this->cacheTtl
            );
        }

        // Original logic if caching is not enabled
        $stmt = $this->pdo->prepare($this->query);
        $stmt->execute($this->flattenBindings($this->bindings));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Optimize the current query
     *
     * Analyzes the current query and applies optimizations if they meet
     * the threshold criteria. Returns the QueryBuilder instance for chaining.
     *
     * @return self QueryBuilder instance for chaining
     */
    public function optimize(): self
    {
        // Run the optimization
        $optimization = $this->getOptimizer()->optimizeQuery($this->query, $this->bindings);

        // Check if the optimization exceeds the threshold
        if ($optimization['estimated_improvement']['execution_time'] >= $this->optimizationThreshold) {
            // Use the optimized query
            $this->query = $optimization['optimized_query'];

            // Log the optimization if in debug mode
            if ($this->debugMode) {
                $this->logger->logEvent('Applied query optimization', [
                    'original_query' => $optimization['original_query'],
                    'optimized_query' => $optimization['optimized_query'],
                    'estimated_improvement' => $optimization['estimated_improvement'],
                ], 'info');
            }
        }

        // Enable optimization flag
        $this->optimizationEnabled = true;

        return $this;
    }

    /**
     * Enable query result caching for this query
     *
     * When enabled, the query results will be stored in cache and
     * subsequent identical queries will return the cached result
     * until the TTL expires or the cache is invalidated.
     *
     * @param int|null $ttl Cache TTL in seconds (null = use default TTL)
     * @return self QueryBuilder instance for chaining
     */
    public function cache(?int $ttl = null): self
    {
        $this->cachingEnabled = true;
        $this->cacheTtl = $ttl;

        // Log cache activation if in debug mode
        if ($this->debugMode) {
            $this->logger->logEvent('Query caching enabled', [
                'query' => $this->query,
                'ttl' => $ttl ?? 'default',
            ], 'debug');
        }

        return $this;
    }

    /**
     * Disable query result caching for this query
     *
     * @return self QueryBuilder instance for chaining
     */
    public function disableCache(): self
    {
        $this->cachingEnabled = false;

        if ($this->debugMode) {
            $this->logger->logEvent('Query caching disabled', [
                'query' => $this->query,
            ], 'debug');
        }

        return $this;
    }

    /**
     * Get the query cache service instance
     *
     * Lazy-loads the cache service if it hasn't been created yet.
     *
     * @return QueryCacheService The query cache service instance
     */
    public function getCacheService(): QueryCacheService
    {
        if ($this->cacheService === null) {
            $this->cacheService = new QueryCacheService();
        }
        return $this->cacheService;
    }

    /**
     * Get the current SQL query
     *
     * Returns the SQL query string in its current state.
     *
     * @return string The current SQL query
     */
    public function toSql(): string
    {
        return $this->query;
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
     * Flatten bindings array to ensure no nested arrays exist
     *
     * @param array $bindings The bindings array to flatten
     * @return array Flattened bindings array
     */
    private function flattenBindings(array $bindings): array
    {
        $flattened = [];
        foreach ($bindings as $binding) {
            if (is_array($binding)) {
                // Convert array to JSON string to prevent array to string conversion
                $flattened[] = json_encode($binding);
            } else {
                $flattened[] = $binding;
            }
        }
        return $flattened;
    }

    /**
     * Centralized method for preparing and executing queries
     */
    private function prepareAndExecute(string $sql, array $params = []): \PDOStatement
    {
        // Start timing the query with debug context if enabled
        $timerId = $this->logger->startTiming($this->debugMode ? 'query_with_debug' : 'query');

        // Capture current purpose and reset it to allow for new purposes on subsequent queries
        $purpose = $this->queryPurpose;
        $this->queryPurpose = null;

        // Flatten bindings to prevent array to string conversion warnings
        $flattenedParams = $this->flattenBindings($params);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flattenedParams);
             // Log successful query with purpose
            $this->logger->logQuery($sql, $flattenedParams, $timerId, null, $purpose);
            return $stmt;
        } catch (PDOException $e) {
            // Log failed query with purpose
            $this->logger->logQuery($sql, $flattenedParams, $timerId, $e, $purpose);
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
     * Enable or disable query optimization
     *
     * When enabled, queries will be analyzed and potentially optimized
     * before execution if the optimization meets the threshold requirements.
     *
     * @param bool $enable Whether to enable optimization
     * @return self Builder instance for chaining
     */
    public function enableOptimization(bool $enable = true): self
    {
        $this->optimizationEnabled = $enable;
        return $this;
    }

    /**
     * Set the optimization threshold
     *
     * The threshold represents the minimum percentage improvement required
     * to apply an optimization. Higher values mean optimizations will only
     * be applied when they provide significant improvements.
     *
     * @param float $threshold Percentage threshold (1.0 = 1%)
     * @return self Builder instance for chaining
     */
    public function setOptimizationThreshold(float $threshold): self
    {
        $this->optimizationThreshold = max(0.0, $threshold);
        return $this;
    }

    /**
     * Get the query optimizer instance
     *
     * Lazy-loads the optimizer if it hasn't been created yet.
     *
     * @return QueryOptimizer The query optimizer instance
     */
    public function getOptimizer(): QueryOptimizer
    {
        if ($this->optimizer === null) {
            // Create connection wrapper for the optimizer using current PDO connection
            $connection = new Connection();
            $this->optimizer = new QueryOptimizer();
            $this->optimizer->setConnection($connection);
        }
        return $this->optimizer;
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

        // Get affected rows
        $rowCount = $stmt->rowCount();

        return $rowCount;
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
            $stmt->execute($this->flattenBindings($this->bindings));

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

    /**
     * Set a business purpose for the query
     *
     * This provides context for logging and helps with understanding
     * the business purpose of database operations
     *
     * @param string $purpose Business purpose for the query
     * @return self Builder instance for chaining
     */
    public function withPurpose(string $purpose): self
    {
        $this->queryPurpose = $purpose;
        return $this;
    }

    /**
     * Build the final SQL query with all components
     *
     * This method rebuilds the query to ensure all JOINs, WHERE clauses,
     * and other components are included in the final SQL.
     *
     * @return void
     */
    private function buildFinalQuery(): void
    {
        if (empty($this->query) || $this->finalQueryBuilt) {
            return; // Nothing to rebuild or already built
        }

        // Extract the base query parts
        if (preg_match('/^SELECT (.+) FROM (.+?)(?:\s+(WHERE.+))?$/i', $this->query, $matches)) {
            $selectClause = $matches[1];
            $fromClause = $matches[2];
            $whereClause = $matches[3] ?? '';

            // Rebuild with JOINs
            $sql = "SELECT $selectClause FROM $fromClause";

            // Add JOIN clauses if any
            if (!empty($this->joins)) {
                $sql .= " " . implode(" ", $this->joins);
            }

            // Add back the WHERE clause if it exists
            if (!empty($whereClause)) {
                $sql .= " " . $whereClause;
            }

            $this->query = $sql;
            $this->finalQueryBuilt = true;
        }
    }

     /**
   * Enhanced search with multi-column text search
   */
    public function search(array $searchFields, string $query, string $operator = 'OR'): self
    {
        if (empty($query) || empty($searchFields)) {
            return $this;
        }

        $conditions = [];
        $bindings = [];

        foreach ($searchFields as $field) {
            // Wrap field identifier properly
            if (strpos($field, '.') !== false) {
                [$table, $col] = explode('.', $field, 2);
                $wrappedField = $this->driver->wrapIdentifier($table) . '.' . $this->driver->wrapIdentifier($col);
            } else {
                $wrappedField = $this->driver->wrapIdentifier($field);
            }

            $conditions[] = "{$wrappedField} LIKE ?";
            $bindings[] = "%{$query}%";
        }

        $searchCondition = '(' . implode(" {$operator} ", $conditions) . ')';

        // Always use whereRaw since we're building a raw SQL condition
        $this->whereRaw($searchCondition, $bindings);

        return $this;
    }

  /**
   * Advanced filtering with multiple operators
   */
    public function advancedWhere(array $filters): self
    {
        foreach ($filters as $field => $condition) {
            if (is_array($condition)) {
                foreach ($condition as $operator => $value) {
                    switch (strtolower($operator)) {
                        case 'like':
                        case 'ilike':
                            $this->whereLike($field, $value);
                            break;
                        case 'in':
                            $this->whereIn($field, (array)$value);
                            break;
                        case 'between':
                            $this->whereBetween($field, $value[0], $value[1]);
                            break;
                        case 'gt':
                        case '>':
                            $this->whereRaw($this->driver->wrapIdentifier($field) . ' > ?', [$value]);
                            break;
                        case 'gte':
                        case '>=':
                            $this->whereRaw($this->driver->wrapIdentifier($field) . ' >= ?', [$value]);
                            break;
                        case 'lt':
                        case '<':
                            $this->whereRaw($this->driver->wrapIdentifier($field) . ' < ?', [$value]);
                            break;
                        case 'lte':
                        case '<=':
                            $this->whereRaw($this->driver->wrapIdentifier($field) . ' <= ?', [$value]);
                            break;
                        case 'ne':
                        case '!=':
                            $this->whereRaw($this->driver->wrapIdentifier($field) . ' != ?', [$value]);
                            break;
                        default:
                            $this->where([$field => $value]);
                    }
                }
            } else {
                $this->where([$field => $condition]);
            }
        }

        return $this;
    }

    /**
     * Get the database driver instance
     *
     * Provides access to database-specific operations like identifier quoting
     * and datetime formatting for repository implementations.
     *
     * @return DatabaseDriver The database driver instance
     */
    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    /**
     * Check if using a pooled connection
     *
     * @return bool True if using connection pooling
     */
    public function isUsingPooledConnection(): bool
    {
        return $this->pooledConnection !== null;
    }

    /**
     * Get pooled connection instance
     *
     * @return PooledConnection|null Pooled connection or null if not using pooling
     */
    public function getPooledConnection(): ?PooledConnection
    {
        return $this->pooledConnection;
    }

    /**
     * Get connection statistics (if using pooled connection)
     *
     * @return array Connection statistics or empty array
     */
    public function getConnectionStats(): array
    {
        return $this->pooledConnection ? $this->pooledConnection->getStats() : [];
    }

    /**
     * Get raw PDO connection
     *
     * Provides direct access to the underlying PDO instance for cases
     * where direct PDO methods are needed.
     *
     * @return PDO The PDO connection instance
     */
    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * Force release of pooled connection
     *
     * Manually releases the pooled connection back to the pool.
     * Useful for long-running processes that want to release connections early.
     *
     * @return void
     */
    public function releaseConnection(): void
    {
        if ($this->pooledConnection) {
            // Check if in transaction - don't release if so
            if ($this->transactionLevel > 0) {
                $this->logger->logEvent("Cannot release pooled connection - transaction active", [
                    'transaction_level' => $this->transactionLevel
                ]);
                return;
            }

            $this->logger->logEvent("Manually releasing pooled connection", [
                'connection_id' => $this->pooledConnection->getId()
            ]);

            $this->pooledConnection = null;
        }
    }
}
