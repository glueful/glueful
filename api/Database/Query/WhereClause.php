<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Query\Interfaces\WhereClauseInterface;

/**
 * WhereClause
 *
 * Handles all WHERE clause construction and management.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class WhereClause implements WhereClauseInterface
{
    protected array $conditions = [];
    protected array $bindings = [];
    protected DatabaseDriver $driver;

    public function __construct(DatabaseDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Add a WHERE condition
     *
     * Supports multiple call patterns:
     * - add(['column' => 'value'])         - Array format
     * - add('column', 'value')             - Simple format
     * - add('column', '>', 'value')        - Operator format
     * - add(callable $callback)            - Closure format
     */
    public function add($column, $operator = null, $value = null): void
    {
        $this->addCondition($column, $operator, $value, 'AND');
    }

    /**
     * Add an OR WHERE condition
     */
    public function addOr($column, $operator = null, $value = null): void
    {
        $this->addCondition($column, $operator, $value, 'OR');
    }

    /**
     * Add WHERE IN condition
     */
    public function whereIn(string $column, array $values): void
    {
        if (empty($values)) {
            $this->addRawCondition('1 = 0', [], 'AND'); // Always false
            return;
        }

        $placeholders = array_fill(0, count($values), '?');
        $condition = $this->wrapColumn($column) . ' IN (' . implode(', ', $placeholders) . ')';

        $this->addRawCondition($condition, $values, 'AND');
    }

    /**
     * Add WHERE NOT IN condition
     */
    public function whereNotIn(string $column, array $values): void
    {
        if (empty($values)) {
            return; // Always true, so no condition needed
        }

        $placeholders = array_fill(0, count($values), '?');
        $condition = $this->wrapColumn($column) . ' NOT IN (' . implode(', ', $placeholders) . ')';

        $this->addRawCondition($condition, $values, 'AND');
    }

    /**
     * Add WHERE condition (convenience method for nested queries)
     */
    public function where($column, $operator = null, $value = null): self
    {
        $this->add($column, $operator, $value);
        return $this;
    }

    /**
     * Add WHERE NULL condition
     */
    public function whereNull(string $column): void
    {
        $condition = $this->wrapColumn($column) . ' IS NULL';
        $this->addRawCondition($condition, [], 'AND');
    }

    /**
     * Add WHERE NOT NULL condition
     */
    public function whereNotNull(string $column): void
    {
        $condition = $this->wrapColumn($column) . ' IS NOT NULL';
        $this->addRawCondition($condition, [], 'AND');
    }

    /**
     * Add OR WHERE NULL condition
     */
    public function orWhereNull(string $column): self
    {
        $condition = $this->wrapColumn($column) . ' IS NULL';
        $this->addRawCondition($condition, [], 'OR');
        return $this;
    }

    /**
     * Add OR WHERE NOT NULL condition
     */
    public function orWhereNotNull(string $column): self
    {
        $condition = $this->wrapColumn($column) . ' IS NOT NULL';
        $this->addRawCondition($condition, [], 'OR');
        return $this;
    }

    /**
     * Add WHERE BETWEEN condition
     */
    public function whereBetween(string $column, $min, $max): void
    {
        $condition = $this->wrapColumn($column) . ' BETWEEN ? AND ?';
        $this->addRawCondition($condition, [$min, $max], 'AND');
    }

    /**
     * Add WHERE LIKE condition
     */
    public function whereLike(string $column, string $pattern): void
    {
        $condition = $this->wrapColumn($column) . ' LIKE ?';
        $this->addRawCondition($condition, [$pattern], 'AND');
    }

    /**
     * Add JSON contains WHERE condition (database-agnostic)
     *
     * Uses the appropriate JSON functions based on the database driver.
     *
     * @param string $column JSON column name (supports table.column format)
     * @param string $searchValue Value to search for within the JSON
     * @param string|null $path JSON path (optional, defaults to searching entire JSON)
     * @return void
     *
     * @example
     * ```php
     * // Search for a value anywhere in JSON column
     * $whereClause->whereJsonContains('details', 'login_failed');
     *
     * // Search within a specific JSON path (MySQL only)
     * $whereClause->whereJsonContains('metadata', 'active', '$.status');
     * ```
     */
    public function whereJsonContains(string $column, string $searchValue, ?string $path = null): void
    {
        // Wrap column identifier properly
        $wrappedColumn = $this->wrapColumn($column);

        // Build database-specific JSON search condition
        $driverClass = get_class($this->driver);

        if (strpos($driverClass, 'MySQL') !== false) {
            // MySQL: Use JSON_CONTAINS or JSON_SEARCH
            if ($path !== null) {
                // Search at specific path
                $condition = "JSON_CONTAINS($wrappedColumn, ?, '$path')";
                $this->addRawCondition($condition, [json_encode($searchValue)], 'AND');
            } else {
                // Search anywhere in JSON using JSON_SEARCH
                $condition = "JSON_SEARCH($wrappedColumn, 'one', ?) IS NOT NULL";
                $this->addRawCondition($condition, [$searchValue], 'AND');
            }
        } elseif (strpos($driverClass, 'PostgreSQL') !== false) {
            // PostgreSQL: Use jsonb operators or text casting
            if ($path !== null) {
                // Search at specific path using #>> operator
                $condition = "$wrappedColumn #>> ? = ?";
                $this->addRawCondition($condition, [$path, $searchValue], 'AND');
            } else {
                // Search anywhere using text casting and LIKE
                $condition = "$wrappedColumn::text LIKE ?";
                $this->addRawCondition($condition, ["%$searchValue%"], 'AND');
            }
        } else {
            // Generic fallback: Cast to text and use LIKE
            $condition = "CAST($wrappedColumn AS TEXT) LIKE ?";
            $this->addRawCondition($condition, ["%$searchValue%"], 'AND');
        }
    }

    /**
     * Add raw WHERE condition
     */
    public function whereRaw(string $condition, array $bindings = []): void
    {
        $this->addRawCondition($condition, $bindings, 'AND');
    }

    /**
     * Build database-agnostic JSON condition string for use in raw SQL
     *
     * @param string $column JSON column name
     * @param string $searchValue Value to search for
     * @param string|null $path JSON path (optional)
     * @return array Array with 'condition' and 'bindings' keys
     */
    public function buildJsonCondition(string $column, string $searchValue, ?string $path = null): array
    {
        $wrappedColumn = $this->wrapColumn($column);
        $driverClass = get_class($this->driver);

        if (strpos($driverClass, 'MySQL') !== false) {
            if ($path !== null) {
                return [
                    'condition' => "JSON_CONTAINS($wrappedColumn, ?, '$path')",
                    'bindings' => [json_encode($searchValue)]
                ];
            } else {
                return [
                    'condition' => "JSON_SEARCH($wrappedColumn, 'one', ?) IS NOT NULL",
                    'bindings' => [$searchValue]
                ];
            }
        } elseif (strpos($driverClass, 'PostgreSQL') !== false) {
            if ($path !== null) {
                return [
                    'condition' => "$wrappedColumn #>> ? = ?",
                    'bindings' => [$path, $searchValue]
                ];
            } else {
                return [
                    'condition' => "$wrappedColumn::text LIKE ?",
                    'bindings' => ["%$searchValue%"]
                ];
            }
        } else {
            // SQLite fallback
            return [
                'condition' => "CAST($wrappedColumn AS TEXT) LIKE ?",
                'bindings' => ["%$searchValue%"]
            ];
        }
    }

    /**
     * Build database-agnostic aggregation query with JSON conditions
     * Supports MySQL, PostgreSQL, and SQLite
     *
     * @param string $table Table name
     * @param string $selectColumns SELECT clause columns
     * @param string $groupByColumn Column to group by
     * @param string $orderByColumn Column to order by
     * @param string $orderDirection Order direction (ASC/DESC)
     * @param int $limit Number of results to limit
     * @param array $jsonConditions Array of JSON conditions ['column', 'value', 'path']
     * @return array Array with 'query' and 'bindings' keys
     */
    public function buildAggregationQuery(
        string $table,
        string $selectColumns,
        string $groupByColumn,
        string $orderByColumn,
        string $orderDirection = 'DESC',
        int $limit = 10,
        array $jsonConditions = []
    ): array {
        $wrappedTable = $this->driver->wrapIdentifier($table);
        $wrappedGroupBy = $this->driver->wrapIdentifier($groupByColumn);
        $wrappedOrderBy = $this->driver->wrapIdentifier($orderByColumn);

        $allBindings = [];
        $whereConditions = [];

        // Build JSON conditions
        foreach ($jsonConditions as $condition) {
            $jsonCondition = $this->buildJsonCondition($condition[0], $condition[1], $condition[2] ?? null);
            $whereConditions[] = $jsonCondition['condition'];
            $allBindings = array_merge($allBindings, $jsonCondition['bindings']);
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Build query with LIMIT (supported by MySQL, PostgreSQL, SQLite)
        $query = "SELECT $selectColumns FROM $wrappedTable $whereClause " .
                "GROUP BY $wrappedGroupBy ORDER BY $wrappedOrderBy $orderDirection LIMIT $limit";

        return [
            'query' => $query,
            'bindings' => $allBindings
        ];
    }

    /**
     * Build the WHERE clause SQL
     */
    public function toSql(): string
    {
        if (empty($this->conditions)) {
            return '';
        }

        $sql = '';
        foreach ($this->conditions as $index => $condition) {
            if ($index === 0) {
                $sql .= ' WHERE ';
            } else {
                $sql .= ' ' . $condition['boolean'] . ' ';
            }

            if ($condition['type'] === 'nested') {
                $sql .= '(' . $condition['query'] . ')';
            } else {
                $sql .= $condition['sql'];
            }
        }

        return $sql;
    }

    /**
     * Get all parameter bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Check if there are any conditions
     */
    public function hasConditions(): bool
    {
        return !empty($this->conditions);
    }

    /**
     * Reset all conditions
     */
    public function reset(): void
    {
        $this->conditions = [];
        $this->bindings = [];
    }

    /**
     * Add OR WHERE condition
     */
    public function orWhere($column, $operator = null, $value = null): self
    {
        $this->addCondition($column, $operator, $value, 'OR');
        return $this;
    }

    /**
     * Get conditions as array format for update/delete operations
     */
    public function getConditionsArray(): array
    {
        // For simple conditions, return an associative array
        // Only works for basic AND conditions with = operator
        $simpleConditions = [];
        $complexConditions = [];
        $bindingIndex = 0;

        foreach ($this->conditions as $condition) {
            if ($condition['type'] === 'basic' && $condition['boolean'] === 'AND') {
                // Extract column name from SQL like "`users`.`name` = ?"
                $sql = $condition['sql'];
                if (preg_match('/^(.+?)\s+=\s+\?$/', $sql, $matches)) {
                    $column = trim($matches[1], '`"[]');

                    // Remove table prefix if present (e.g., "users.name" becomes "name")
                    if (strpos($column, '.') !== false) {
                        $parts = explode('.', $column);
                        $column = end($parts);
                    }

                    // Get the corresponding binding value
                    if (isset($this->bindings[$bindingIndex])) {
                        $simpleConditions[$column] = $this->bindings[$bindingIndex];
                    }
                }
                $bindingIndex++;
            } else {
                // For complex conditions, we'll need a different approach
                $complexConditions[] = $condition;
            }
        }

        // If all conditions are simple AND with =, return associative array
        if (empty($complexConditions) && !empty($simpleConditions)) {
            return $simpleConditions;
        }

        // Otherwise, throw an exception as complex conditions aren't supported yet
        if (!empty($complexConditions)) {
            throw new \RuntimeException(
                'Complex WHERE conditions (OR, NOT, !=, etc.) are not yet supported for UPDATE/DELETE operations'
            );
        }

        return $simpleConditions;
    }

    /**
     * Add a condition (internal method)
     */
    protected function addCondition($column, $operator, $value, string $boolean): void
    {
        // Handle array format: ['column' => 'value']
        if (is_array($column)) {
            foreach ($column as $col => $val) {
                $this->addBasicCondition($col, '=', $val, $boolean);
            }
            return;
        }

        // Handle callable format: function($query) {...}
        // Only accept closures or array callables, not string function names
        if (is_callable($column) && !is_string($column)) {
            $this->addNestedCondition($column, $boolean);
            return;
        }

        // Handle three-parameter format: ('column', '>', 'value')
        if ($value !== null) {
            $this->addBasicCondition($column, $operator, $value, $boolean);
            return;
        }

        // Handle two-parameter format: ('column', 'value')
        if ($operator !== null) {
            $this->addBasicCondition($column, '=', $operator, $boolean);
            return;
        }
    }

    /**
     * Add basic condition
     */
    protected function addBasicCondition(string $column, string $operator, $value, string $boolean): void
    {
        $sql = $this->wrapColumn($column) . ' ' . $operator . ' ?';

        $this->conditions[] = [
            'type' => 'basic',
            'sql' => $sql,
            'boolean' => $boolean
        ];

        $this->bindings[] = $value;
    }

    /**
     * Add raw condition
     */
    protected function addRawCondition(string $sql, array $bindings, string $boolean): void
    {
        $this->conditions[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean
        ];

        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }
    }

    /**
     * Add nested condition
     */
    protected function addNestedCondition(callable $callback, string $boolean): void
    {
        $query = new self($this->driver);
        $callback($query);

        if ($query->hasConditions()) {
            $this->conditions[] = [
                'type' => 'nested',
                'query' => ltrim($query->toSql(), ' WHERE '),
                'boolean' => $boolean
            ];

            foreach ($query->getBindings() as $binding) {
                $this->bindings[] = $binding;
            }
        }
    }

    /**
     * Wrap column identifier
     */
    protected function wrapColumn(string $column): string
    {
        if (strpos($column, '.') !== false) {
            [$table, $col] = explode('.', $column, 2);
            return $this->driver->wrapIdentifier($table) . '.' . $this->driver->wrapIdentifier($col);
        }

        return $this->driver->wrapIdentifier($column);
    }
}
