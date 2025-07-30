<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Query\Interfaces\QueryModifiersInterface;
use Glueful\Database\Driver\DatabaseDriver;

/**
 * Manages query modifiers (GROUP BY, HAVING, ORDER BY)
 *
 * This component handles the construction and management of query modifiers
 * that affect result grouping, filtering, and ordering.
 */
class QueryModifiers implements QueryModifiersInterface
{
    private array $groupBy = [];
    private array $having = [];
    private array $havingBindings = [];
    private array $orderBy = [];

    public function __construct(
        private DatabaseDriver $driver
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function groupBy(string|array $columns): void
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $column) {
            if (!in_array($column, $this->groupBy, true)) {
                $this->groupBy[] = $column;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getGroupBy(): array
    {
        return $this->groupBy;
    }

    /**
     * {@inheritdoc}
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): void
    {
        // Support multiple call patterns like WhereClause
        if ($value === null && $operator !== null) {
            // having('column', 'value') format
            $value = $operator;
            $operator = '=';
        } elseif ($operator === null) {
            throw new \InvalidArgumentException('HAVING clause requires at least two arguments');
        }

        $this->having[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => strtoupper($operator),
            'value' => $value,
            'boolean' => 'AND'
        ];

        if ($value !== null) {
            $this->havingBindings[] = $value;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function havingRaw(string $expression, array $bindings = []): void
    {
        $this->having[] = [
            'type' => 'raw',
            'expression' => $expression,
            'boolean' => 'AND'
        ];

        foreach ($bindings as $binding) {
            $this->havingBindings[] = $binding;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHaving(): array
    {
        return $this->having;
    }

    /**
     * {@inheritdoc}
     */
    public function orderBy(string|array $column, string $direction = 'ASC'): void
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid sort direction: {$direction}. Use ASC or DESC.");
        }

        if (is_array($column)) {
            // Handle array format: ['column1' => 'ASC', 'column2' => 'DESC']
            foreach ($column as $col => $dir) {
                if (is_int($col)) {
                    // Numeric key, use default direction
                    $this->orderBy[] = [
                        'type' => 'column',
                        'column' => $dir,
                        'direction' => $direction
                    ];
                } else {
                    // Associative array with column => direction
                    $this->orderBy[] = [
                        'type' => 'column',
                        'column' => $col,
                        'direction' => strtoupper($dir)
                    ];
                }
            }
        } else {
            $this->orderBy[] = [
                'type' => 'column',
                'column' => $column,
                'direction' => $direction
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function orderByRaw(string $expression): void
    {
        $this->orderBy[] = [
            'type' => 'raw',
            'expression' => $expression
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function orderByRandom(): void
    {
        // Different databases have different random functions
        $randomFunction = match ($this->driver->getDriverName()) {
            'mysql' => 'RAND()',
            'pgsql' => 'RANDOM()',
            'sqlite' => 'RANDOM()',
            default => 'RAND()'
        };

        $this->orderByRaw($randomFunction);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * {@inheritdoc}
     */
    public function clearGroupBy(): void
    {
        $this->groupBy = [];
    }

    /**
     * {@inheritdoc}
     */
    public function clearHaving(): void
    {
        $this->having = [];
        $this->havingBindings = [];
    }

    /**
     * {@inheritdoc}
     */
    public function clearOrderBy(): void
    {
        $this->orderBy = [];
    }

    /**
     * {@inheritdoc}
     */
    public function buildGroupByClause(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }

        $columns = array_map(
            fn($column) => $this->driver->wrapIdentifier($column),
            $this->groupBy
        );

        return ' GROUP BY ' . implode(', ', $columns);
    }

    /**
     * {@inheritdoc}
     */
    public function buildHavingClause(): string
    {
        if (empty($this->having)) {
            return '';
        }

        $conditions = [];

        foreach ($this->having as $index => $having) {
            $condition = '';

            if ($index > 0) {
                $condition .= ' ' . $having['boolean'] . ' ';
            }

            if ($having['type'] === 'raw') {
                $condition .= $having['expression'];
            } else {
                $column = $this->driver->wrapIdentifier($having['column']);
                $operator = $having['operator'];

                if ($having['value'] === null) {
                    $condition .= "{$column} {$operator} NULL";
                } else {
                    $condition .= "{$column} {$operator} ?";
                }
            }

            $conditions[] = $condition;
        }

        return ' HAVING ' . implode('', $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function buildOrderByClause(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }

        $clauses = [];

        foreach ($this->orderBy as $order) {
            if ($order['type'] === 'raw') {
                $clauses[] = $order['expression'];
            } else {
                $column = $this->driver->wrapIdentifier($order['column']);
                $clauses[] = "{$column} {$order['direction']}";
            }
        }

        return ' ORDER BY ' . implode(', ', $clauses);
    }

    /**
     * {@inheritdoc}
     */
    public function getHavingBindings(): array
    {
        return $this->havingBindings;
    }

    /**
     * Add OR HAVING condition
     *
     * @param string $column The column or expression
     * @param mixed $operator The operator or value
     * @param mixed $value The value (optional)
     */
    public function orHaving(string $column, mixed $operator = null, mixed $value = null): void
    {
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->having[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => strtoupper($operator),
            'value' => $value,
            'boolean' => 'OR'
        ];

        if ($value !== null) {
            $this->havingBindings[] = $value;
        }
    }

    /**
     * Add OR HAVING raw condition
     *
     * @param string $expression The raw SQL expression
     * @param array $bindings Parameter bindings
     */
    public function orHavingRaw(string $expression, array $bindings = []): void
    {
        $this->having[] = [
            'type' => 'raw',
            'expression' => $expression,
            'boolean' => 'OR'
        ];

        foreach ($bindings as $binding) {
            $this->havingBindings[] = $binding;
        }
    }

    /**
     * Clone the modifiers
     */
    public function clone(): self
    {
        $clone = new self($this->driver);
        $clone->groupBy = $this->groupBy;
        $clone->having = $this->having;
        $clone->havingBindings = $this->havingBindings;
        $clone->orderBy = $this->orderBy;
        return $clone;
    }
}
