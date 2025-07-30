<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

/**
 * Alter Table Builder Interface
 *
 * Provides a fluent interface for building and executing table modifications.
 * Supports method chaining for multiple alterations in a single operation.
 *
 * Features:
 * - Column operations (add, modify, drop)
 * - Index management
 * - Constraint handling
 * - Batched alterations for performance
 *
 * Example usage:
 * ```php
 * $schema->alterTable('users')
 *     ->addColumn('email_verified', 'BOOLEAN', ['default' => false])
 *     ->modifyColumn('name', 'VARCHAR(100)')
 *     ->dropColumn('deprecated_field')
 *     ->addIndex(['email'], 'idx_email')
 *     ->execute();
 * ```
 */
interface AlterTableBuilderInterface
{
    /**
     * Add a new column to the table
     *
     * @param string $column Column name
     * @param string $type Column type
     * @param array $options Column options (nullable, default, etc.)
     * @return self For method chaining
     */
    public function addColumn(string $column, string $type, array $options = []): self;

    /**
     * Modify an existing column
     *
     * @param string $column Column to modify
     * @param string $type New column type
     * @param array $options New column options
     * @return self For method chaining
     */
    public function modifyColumn(string $column, string $type, array $options = []): self;

    /**
     * Drop a column from the table
     *
     * @param string $column Column to drop
     * @return self For method chaining
     */
    public function dropColumn(string $column): self;

    /**
     * Add an index to the table
     *
     * @param array $columns Columns to index
     * @param string $name Index name
     * @param bool $unique Whether the index should be unique
     * @return self For method chaining
     */
    public function addIndex(array $columns, string $name, bool $unique = false): self;

    /**
     * Drop an index from the table
     *
     * @param string $name Index name to drop
     * @return self For method chaining
     */
    public function dropIndex(string $name): self;

    /**
     * Rename a column
     *
     * @param string $from Current column name
     * @param string $to New column name
     * @return self For method chaining
     */
    public function renameColumn(string $from, string $to): self;

    /**
     * Add a foreign key constraint
     *
     * @param string $column Local column name
     * @param string $referencedTable Referenced table name
     * @param string $referencedColumn Referenced column name
     * @param string|null $constraintName Custom constraint name
     * @param string|null $onDelete ON DELETE action
     * @param string|null $onUpdate ON UPDATE action
     * @return self For method chaining
     */
    public function addForeignKey(
        string $column,
        string $referencedTable,
        string $referencedColumn = 'id',
        ?string $constraintName = null,
        ?string $onDelete = null,
        ?string $onUpdate = null
    ): self;

    /**
     * Drop a foreign key constraint
     *
     * @param string $constraintName Constraint name to drop
     * @return self For method chaining
     */
    public function dropForeignKey(string $constraintName): self;

    /**
     * Execute all pending alterations
     *
     * @return bool True if all alterations succeeded
     * @throws \RuntimeException If any alteration fails
     */
    public function execute(): bool;

    /**
     * Get the table name being altered
     *
     * @return string Table name
     */
    public function getTableName(): string;

    /**
     * Get all pending alterations
     *
     * @return array Array of pending alterations
     */
    public function getAlterations(): array;

    /**
     * Reset all pending alterations
     *
     * @return self For method chaining
     */
    public function reset(): self;
}
