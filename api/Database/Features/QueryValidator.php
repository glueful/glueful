<?php

declare(strict_types=1);

namespace Glueful\Database\Features;

use Glueful\Database\Features\Interfaces\QueryValidatorInterface;
use Glueful\Database\Query\Interfaces\QueryStateInterface;

/**
 * Validates query components to ensure data integrity and prevent errors
 *
 * This component provides comprehensive validation for all query types,
 * including table names, column names, data values, and query structure.
 */
class QueryValidator implements QueryValidatorInterface
{
    private bool $strictMode = true;
    private array $customRules = [];

    /**
     * Reserved SQL keywords that shouldn't be used as identifiers
     */
    private const RESERVED_KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'INSERT', 'UPDATE', 'DELETE', 'JOIN',
        'INNER', 'LEFT', 'RIGHT', 'OUTER', 'ON', 'AS', 'ORDER', 'BY',
        'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'UNION', 'ALL', 'DISTINCT',
        'VALUES', 'SET', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN', 'EXISTS',
        'BETWEEN', 'LIKE', 'DESC', 'ASC', 'COUNT', 'SUM', 'AVG', 'MAX', 'MIN'
    ];

    /**
     * {@inheritdoc}
     */
    public function validate(QueryStateInterface $state): void
    {
        $table = $state->getTable();
        if ($table === null) {
            throw new \InvalidArgumentException('No table specified for query');
        }

        $this->validateTableName($table);

        // Validate based on query type
        if (!empty($state->getSelectColumns())) {
            $this->validateSelect($state);
        }

        // Validate pagination
        $this->validatePagination($state->getLimit(), $state->getOffset());

        // Run custom validation rules
        foreach ($this->customRules as $name => $validator) {
            $validator($state);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateSelect(QueryStateInterface $state): void
    {
        $columns = $state->getSelectColumns();

        if (empty($columns)) {
            throw new \InvalidArgumentException('No columns specified for SELECT query');
        }

        // Validate column names if not using wildcard
        if (!in_array('*', $columns, true)) {
            $this->validateColumnNames($columns);
        }

        // Validate joins
        $joins = $state->getJoins();
        foreach ($joins as $join) {
            if (!isset($join['table'], $join['first'], $join['operator'], $join['second'])) {
                throw new \InvalidArgumentException('Invalid join configuration');
            }
            $this->validateTableName($join['table']);
        }

        // Validate GROUP BY columns
        $groupBy = $state->getGroupBy();
        if (!empty($groupBy)) {
            $this->validateColumnNames($groupBy);
        }

        // Validate ORDER BY
        $orderBy = $state->getOrderBy();
        foreach ($orderBy as $column => $direction) {
            $this->validateColumnName($column);
            if (!in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException("Invalid sort direction: $direction");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateInsert(string $table, array $data): void
    {
        $this->validateTableName($table);

        if (empty($data)) {
            throw new \InvalidArgumentException('No data provided for INSERT');
        }

        $columns = array_keys($data);
        $this->validateColumnNames($columns);

        // Validate data types in strict mode
        if ($this->strictMode) {
            foreach ($data as $column => $value) {
                $this->validateValue($column, $value);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateUpdate(string $table, array $data, array $conditions): void
    {
        $this->validateTableName($table);

        if (empty($data)) {
            throw new \InvalidArgumentException('No data provided for UPDATE');
        }

        if (empty($conditions) && $this->strictMode) {
            throw new \InvalidArgumentException(
                'UPDATE without WHERE clause is dangerous. Disable strict mode to allow this.'
            );
        }

        $columns = array_keys($data);
        $this->validateColumnNames($columns);

        // Validate data types in strict mode
        if ($this->strictMode) {
            foreach ($data as $column => $value) {
                $this->validateValue($column, $value);
            }
        }

        // Validate condition columns
        if (!empty($conditions)) {
            $conditionColumns = array_keys($conditions);
            $this->validateColumnNames($conditionColumns);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateDelete(string $table, array $conditions): void
    {
        $this->validateTableName($table);

        if (empty($conditions) && $this->strictMode) {
            throw new \InvalidArgumentException(
                'DELETE without WHERE clause is dangerous. Disable strict mode to allow this.'
            );
        }

        // Validate condition columns
        if (!empty($conditions)) {
            $conditionColumns = array_keys($conditions);
            $this->validateColumnNames($conditionColumns);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateTableName(string $table): void
    {
        if (empty($table)) {
            throw new \InvalidArgumentException('Table name cannot be empty');
        }

        // Check for SQL injection attempts
        if (preg_match('/[;\'"`]/', $table)) {
            throw new \InvalidArgumentException('Invalid characters in table name');
        }

        // Check length
        if (strlen($table) > 64) {
            throw new \InvalidArgumentException('Table name too long (max 64 characters)');
        }

        // Check if it's a reserved keyword
        if ($this->strictMode && in_array(strtoupper($table), self::RESERVED_KEYWORDS, true)) {
            throw new \InvalidArgumentException("Table name '$table' is a reserved SQL keyword");
        }

        // Validate identifier format
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(
                'Invalid table name format. Must start with letter or underscore, ' .
                'followed by letters, numbers, or underscores.'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateColumnNames(array $columns): void
    {
        foreach ($columns as $key => $column) {
            if (is_array($column)) {
                // Handle nested arrays (aliased columns like ['column' => 'alias'])
                foreach ($column as $colKey => $colValue) {
                    if (is_string($colKey)) {
                        $this->validateColumnName($colKey);
                    }
                    if (is_string($colValue)) {
                        $this->validateColumnName($colValue);
                    }
                }
            } elseif (is_string($key) && is_string($column)) {
                // Handle associative array like ['column' => 'alias']
                $this->validateColumnName($key);
                $this->validateColumnName($column);
            } elseif (is_string($column)) {
                // Handle simple string column
                $this->validateColumnName($column);
            }
            // Skip validation for non-string values (like RawExpression objects)
        }
    }

    /**
     * Validate a single column name
     */
    private function validateColumnName(string $column): void
    {
        if (empty($column)) {
            throw new \InvalidArgumentException('Column name cannot be empty');
        }

        // Allow table.column format
        $parts = explode('.', $column);
        foreach ($parts as $part) {
            // Check for SQL injection attempts
            if (preg_match('/[;\'"`]/', $part)) {
                throw new \InvalidArgumentException("Invalid characters in column name: $column");
            }

            // Skip validation for aggregate functions or expressions
            if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN|DISTINCT)\s*\(/i', $part)) {
                continue;
            }

            // Skip validation for wildcard
            if ($part === '*') {
                continue;
            }

            // Check if it's a reserved keyword
            if ($this->strictMode && in_array(strtoupper($part), self::RESERVED_KEYWORDS, true)) {
                throw new \InvalidArgumentException("Column name '$part' is a reserved SQL keyword");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validatePagination(?int $limit, ?int $offset): void
    {
        if ($limit !== null && $limit <= 0) {
            throw new \InvalidArgumentException('LIMIT must be greater than 0');
        }

        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException('OFFSET cannot be negative');
        }

        if ($offset !== null && $limit === null && $this->strictMode) {
            throw new \InvalidArgumentException('OFFSET requires LIMIT to be set');
        }

        // Warn about large limits in strict mode
        if ($this->strictMode && $limit !== null && $limit > 1000) {
            trigger_error('Large LIMIT value detected. Consider using pagination.', E_USER_WARNING);
        }
    }

    /**
     * Validate a value for SQL safety
     */
    private function validateValue(string $column, mixed $value): void
    {
        // Allow null values
        if ($value === null) {
            return;
        }

        // Check for potentially dangerous values
        if (is_string($value)) {
            // Check for SQL injection patterns
            if (preg_match('/;\s*(DROP|DELETE|UPDATE|INSERT|CREATE|ALTER)\s/i', $value)) {
                throw new \InvalidArgumentException(
                    "Potentially dangerous SQL detected in value for column '$column'"
                );
            }

            // Warn about extremely long strings
            if (strlen($value) > 65535) {
                trigger_error("Very long string value for column '$column'", E_USER_WARNING);
            }
        }

        // Validate array values (for IN clauses, etc.)
        if (is_array($value)) {
            if (empty($value)) {
                throw new \InvalidArgumentException("Empty array value for column '$column'");
            }
            foreach ($value as $item) {
                $this->validateValue($column, $item);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setStrictMode(bool $strict): void
    {
        $this->strictMode = $strict;
    }

    /**
     * {@inheritdoc}
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * {@inheritdoc}
     */
    public function addRule(string $name, callable $validator): void
    {
        $this->customRules[$name] = $validator;
    }

    /**
     * {@inheritdoc}
     */
    public function removeRule(string $name): void
    {
        unset($this->customRules[$name]);
    }
}
