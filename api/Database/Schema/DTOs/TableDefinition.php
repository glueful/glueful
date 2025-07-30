<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\DTOs;

/**
 * Table Definition Data Transfer Object
 *
 * Represents a complete table definition including columns, indexes,
 * foreign keys, and table options. Used to pass table structure
 * between schema builders and SQL generators.
 *
 * Features:
 * - Immutable data structure
 * - Type-safe column definitions
 * - Index and constraint management
 * - Table metadata and options
 * - Validation helpers
 *
 * Example usage:
 * ```php
 * $table = new TableDefinition(
 *     name: 'users',
 *     columns: [
 *         new ColumnDefinition('id', 'bigInteger', autoIncrement: true),
 *         new ColumnDefinition('email', 'string', length: 255, unique: true)
 *     ],
 *     indexes: [
 *         new IndexDefinition(['email'], 'idx_users_email', unique: true)
 *     ]
 * );
 * ```
 */
readonly class TableDefinition
{
    /**
     * Create a new table definition
     *
     * @param string $name Table name
     * @param array<ColumnDefinition> $columns Array of column definitions
     * @param array<IndexDefinition> $indexes Array of index definitions
     * @param array<ForeignKeyDefinition> $foreignKeys Array of foreign key definitions
     * @param array<string> $primaryKey Primary key column names
     * @param array<string, mixed> $options Table options (engine, charset, etc.)
     * @param bool $temporary Whether this is a temporary table
     * @param string|null $comment Table comment
     */
    public function __construct(
        public string $name,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
        public array $primaryKey = [],
        public array $options = [],
        public bool $temporary = false,
        public ?string $comment = null
    ) {
        $this->validateColumns();
        $this->validateIndexes();
        $this->validateForeignKeys();
    }

    /**
     * Get column by name
     *
     * @param string $name Column name
     * @return ColumnDefinition|null Column definition or null if not found
     */
    public function getColumn(string $name): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Check if column exists
     *
     * @param string $name Column name
     * @return bool True if column exists
     */
    public function hasColumn(string $name): bool
    {
        return $this->getColumn($name) !== null;
    }

    /**
     * Get index by name
     *
     * @param string $name Index name
     * @return IndexDefinition|null Index definition or null if not found
     */
    public function getIndex(string $name): ?IndexDefinition
    {
        foreach ($this->indexes as $index) {
            if ($index->name === $name) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Check if index exists
     *
     * @param string $name Index name
     * @return bool True if index exists
     */
    public function hasIndex(string $name): bool
    {
        return $this->getIndex($name) !== null;
    }

    /**
     * Get foreign key by name
     *
     * @param string $name Foreign key name
     * @return ForeignKeyDefinition|null Foreign key definition or null if not found
     */
    public function getForeignKey(string $name): ?ForeignKeyDefinition
    {
        foreach ($this->foreignKeys as $foreignKey) {
            if ($foreignKey->name === $name) {
                return $foreignKey;
            }
        }
        return null;
    }

    /**
     * Check if foreign key exists
     *
     * @param string $name Foreign key name
     * @return bool True if foreign key exists
     */
    public function hasForeignKey(string $name): bool
    {
        return $this->getForeignKey($name) !== null;
    }

    /**
     * Get all column names
     *
     * @return array<string> Array of column names
     */
    public function getColumnNames(): array
    {
        return array_map(fn($column) => $column->name, $this->columns);
    }

    /**
     * Create a copy of this table definition with modifications
     *
     * @param array $changes Changes to apply
     * @return self New table definition instance
     */
    public function with(array $changes): self
    {
        return new self(
            name: $changes['name'] ?? $this->name,
            columns: $changes['columns'] ?? $this->columns,
            indexes: $changes['indexes'] ?? $this->indexes,
            foreignKeys: $changes['foreignKeys'] ?? $this->foreignKeys,
            primaryKey: $changes['primaryKey'] ?? $this->primaryKey,
            options: $changes['options'] ?? $this->options,
            temporary: $changes['temporary'] ?? $this->temporary,
            comment: $changes['comment'] ?? $this->comment
        );
    }

    /**
     * Add a column to this table definition
     *
     * @param ColumnDefinition $column Column to add
     * @return self New table definition instance
     */
    public function addColumn(ColumnDefinition $column): self
    {
        return $this->with(['columns' => [...$this->columns, $column]]);
    }

    /**
     * Remove a column from this table definition
     *
     * @param string $name Column name to remove
     * @return self New table definition instance
     */
    public function removeColumn(string $name): self
    {
        $columns = array_filter(
            $this->columns,
            fn($column) => $column->name !== $name
        );
        return $this->with(['columns' => array_values($columns)]);
    }

    /**
     * Validate column definitions
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateColumns(): void
    {
        $columnNames = [];
        foreach ($this->columns as $column) {
            if (!$column instanceof ColumnDefinition) {
                throw new \InvalidArgumentException('All columns must be ColumnDefinition instances');
            }

            if (in_array($column->name, $columnNames)) {
                throw new \InvalidArgumentException("Duplicate column name: {$column->name}");
            }

            $columnNames[] = $column->name;
        }
    }

    /**
     * Validate index definitions
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateIndexes(): void
    {
        $indexNames = [];
        foreach ($this->indexes as $index) {
            if (!$index instanceof IndexDefinition) {
                throw new \InvalidArgumentException('All indexes must be IndexDefinition instances');
            }

            if (in_array($index->name, $indexNames)) {
                throw new \InvalidArgumentException("Duplicate index name: {$index->name}");
            }

            $indexNames[] = $index->name;

            // Validate that all indexed columns exist
            foreach ($index->columns as $columnName) {
                if (!$this->hasColumn($columnName)) {
                    throw new \InvalidArgumentException(
                        "Index {$index->name} references non-existent column: {$columnName}"
                    );
                }
            }
        }
    }

    /**
     * Validate foreign key definitions
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateForeignKeys(): void
    {
        $fkNames = [];
        foreach ($this->foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof ForeignKeyDefinition) {
                throw new \InvalidArgumentException('All foreign keys must be ForeignKeyDefinition instances');
            }

            if (in_array($foreignKey->name, $fkNames)) {
                throw new \InvalidArgumentException("Duplicate foreign key name: {$foreignKey->name}");
            }

            $fkNames[] = $foreignKey->name;

            // Validate that local column exists
            if (!$this->hasColumn($foreignKey->localColumn)) {
                throw new \InvalidArgumentException(
                    "Foreign key {$foreignKey->name} references non-existent local column: {$foreignKey->localColumn}"
                );
            }
        }
    }
}
