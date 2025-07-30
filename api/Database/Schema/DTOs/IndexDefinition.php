<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\DTOs;

/**
 * Index Definition Data Transfer Object
 *
 * Represents a database index definition including columns, type,
 * and options. Used to pass index structure between schema
 * builders and SQL generators.
 *
 * Features:
 * - Immutable data structure
 * - Type-safe properties
 * - Index type validation
 * - Column order support
 * - Database-specific options
 *
 * Example usage:
 * ```php
 * $index = new IndexDefinition(
 *     columns: ['email', 'status'],
 *     name: 'idx_users_email_status',
 *     type: 'index',
 *     unique: false
 * );
 * ```
 */
readonly class IndexDefinition
{
    /**
     * Create a new index definition
     *
     * @param array<string> $columns Array of column names
     * @param string $name Index name
     * @param string $type Index type (index, unique, primary, fulltext, spatial)
     * @param bool $unique Whether index enforces uniqueness
     * @param array<string, string> $lengths Column lengths for partial indexes
     * @param array<string, string> $orders Column sort orders (ASC, DESC)
     * @param string|null $algorithm Index algorithm (BTREE, HASH, etc.)
     * @param string|null $comment Index comment
     * @param array<string, mixed> $options Additional index options
     */
    public function __construct(
        public array $columns,
        public string $name,
        public string $type = 'index',
        public bool $unique = false,
        public array $lengths = [],
        public array $orders = [],
        public ?string $algorithm = null,
        public ?string $comment = null,
        public array $options = []
    ) {
        $this->validateColumns();
        $this->validateType();
        $this->validateOptions();
    }

    /**
     * Check if index is unique
     *
     * @return bool True if index is unique
     */
    public function isUnique(): bool
    {
        return $this->unique || $this->type === 'unique' || $this->type === 'primary';
    }

    /**
     * Check if index is primary key
     *
     * @return bool True if index is primary key
     */
    public function isPrimary(): bool
    {
        return $this->type === 'primary';
    }

    /**
     * Check if index is fulltext
     *
     * @return bool True if index is fulltext
     */
    public function isFulltext(): bool
    {
        return $this->type === 'fulltext';
    }

    /**
     * Check if index is spatial
     *
     * @return bool True if index is spatial
     */
    public function isSpatial(): bool
    {
        return $this->type === 'spatial';
    }

    /**
     * Get column count
     *
     * @return int Number of columns in index
     */
    public function getColumnCount(): int
    {
        return count($this->columns);
    }

    /**
     * Check if index is composite (multi-column)
     *
     * @return bool True if index has multiple columns
     */
    public function isComposite(): bool
    {
        return $this->getColumnCount() > 1;
    }

    /**
     * Get length for a specific column
     *
     * @param string $column Column name
     * @return string|null Length specification or null
     */
    public function getColumnLength(string $column): ?string
    {
        return $this->lengths[$column] ?? null;
    }

    /**
     * Get sort order for a specific column
     *
     * @param string $column Column name
     * @return string Sort order (ASC or DESC), defaults to ASC
     */
    public function getColumnOrder(string $column): string
    {
        return $this->orders[$column] ?? 'ASC';
    }

    /**
     * Check if column has custom length
     *
     * @param string $column Column name
     * @return bool True if column has custom length
     */
    public function hasColumnLength(string $column): bool
    {
        return isset($this->lengths[$column]);
    }

    /**
     * Check if any column has custom length
     *
     * @return bool True if any column has custom length
     */
    public function hasCustomLengths(): bool
    {
        return !empty($this->lengths);
    }

    /**
     * Check if any column has custom sort order
     *
     * @return bool True if any column has custom sort order
     */
    public function hasCustomOrders(): bool
    {
        return !empty($this->orders);
    }

    /**
     * Create a copy of this index definition with modifications
     *
     * @param array $changes Changes to apply
     * @return self New index definition instance
     */
    public function with(array $changes): self
    {
        return new self(
            columns: $changes['columns'] ?? $this->columns,
            name: $changes['name'] ?? $this->name,
            type: $changes['type'] ?? $this->type,
            unique: $changes['unique'] ?? $this->unique,
            lengths: $changes['lengths'] ?? $this->lengths,
            orders: $changes['orders'] ?? $this->orders,
            algorithm: $changes['algorithm'] ?? $this->algorithm,
            comment: $changes['comment'] ?? $this->comment,
            options: $changes['options'] ?? $this->options
        );
    }

    /**
     * Add a column to this index
     *
     * @param string $column Column name
     * @param string|null $length Column length for partial index
     * @param string $order Sort order (ASC or DESC)
     * @return self New index definition instance
     */
    public function addColumn(string $column, ?string $length = null, string $order = 'ASC'): self
    {
        $columns = [...$this->columns, $column];
        $lengths = $this->lengths;
        $orders = $this->orders;

        if ($length !== null) {
            $lengths[$column] = $length;
        }

        if ($order !== 'ASC') {
            $orders[$column] = $order;
        }

        return $this->with([
            'columns' => $columns,
            'lengths' => $lengths,
            'orders' => $orders
        ]);
    }

    /**
     * Remove a column from this index
     *
     * @param string $column Column name
     * @return self New index definition instance
     */
    public function removeColumn(string $column): self
    {
        $columns = array_values(array_filter(
            $this->columns,
            fn($col) => $col !== $column
        ));

        $lengths = $this->lengths;
        $orders = $this->orders;
        unset($lengths[$column], $orders[$column]);

        return $this->with([
            'columns' => $columns,
            'lengths' => $lengths,
            'orders' => $orders
        ]);
    }

    /**
     * Validate column specifications
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateColumns(): void
    {
        if (empty($this->columns)) {
            throw new \InvalidArgumentException('Index must have at least one column');
        }

        // Check for duplicate columns
        $uniqueColumns = array_unique($this->columns);
        if (count($uniqueColumns) !== count($this->columns)) {
            throw new \InvalidArgumentException('Index cannot have duplicate columns');
        }

        // Validate column names
        foreach ($this->columns as $column) {
            if (!is_string($column) || empty(trim($column))) {
                throw new \InvalidArgumentException('All column names must be non-empty strings');
            }
        }

        // Validate lengths reference valid columns
        foreach (array_keys($this->lengths) as $column) {
            if (!in_array($column, $this->columns)) {
                throw new \InvalidArgumentException(
                    "Length specified for column '{$column}' not in index columns"
                );
            }
        }

        // Validate orders reference valid columns
        foreach (array_keys($this->orders) as $column) {
            if (!in_array($column, $this->columns)) {
                throw new \InvalidArgumentException(
                    "Order specified for column '{$column}' not in index columns"
                );
            }
        }
    }

    /**
     * Validate index type
     *
     * @throws \InvalidArgumentException If type is invalid
     */
    private function validateType(): void
    {
        $validTypes = ['index', 'unique', 'primary', 'fulltext', 'spatial'];

        if (!in_array($this->type, $validTypes)) {
            throw new \InvalidArgumentException(
                "Invalid index type: {$this->type}. Valid types: " . implode(', ', $validTypes)
            );
        }

        // Primary key should only have one index per table (validated at table level)
        // Fulltext indexes have special requirements (text columns only)
        // Spatial indexes have special requirements (geometry columns only)
    }

    /**
     * Validate index options
     *
     * @throws \InvalidArgumentException If options are invalid
     */
    private function validateOptions(): void
    {
        // Name validation
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Index name cannot be empty');
        }

        // Validate sort orders
        foreach ($this->orders as $column => $order) {
            $order = strtoupper($order);
            if (!in_array($order, ['ASC', 'DESC'])) {
                throw new \InvalidArgumentException(
                    "Invalid sort order '{$order}' for column '{$column}'. Must be ASC or DESC"
                );
            }
        }

        // Validate algorithm if specified
        if ($this->algorithm !== null) {
            $validAlgorithms = ['BTREE', 'HASH', 'RTREE'];
            $algorithm = strtoupper($this->algorithm);
            if (!in_array($algorithm, $validAlgorithms)) {
                throw new \InvalidArgumentException(
                    "Invalid index algorithm: {$this->algorithm}. Valid algorithms: " .
                    implode(', ', $validAlgorithms)
                );
            }
        }

        // Type-specific validations
        if ($this->type === 'unique' && !$this->unique) {
            throw new \InvalidArgumentException('Unique index type must have unique flag set to true');
        }

        if ($this->type === 'primary' && (!$this->unique || $this->getColumnCount() === 0)) {
            throw new \InvalidArgumentException('Primary key index must be unique and have columns');
        }
    }
}
