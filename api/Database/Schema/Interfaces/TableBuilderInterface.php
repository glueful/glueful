<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

/**
 * Table Builder Interface
 *
 * Provides fluent interface for defining table structure with columns,
 * indexes, and constraints. Supports both table creation and alteration.
 *
 * Column Types:
 * - id(): Auto-incrementing primary key
 * - string(): VARCHAR column
 * - text(): TEXT column
 * - integer(): INTEGER column
 * - bigInteger(): BIGINT column
 * - boolean(): BOOLEAN column
 * - decimal(): DECIMAL column
 * - timestamp(): TIMESTAMP column
 * - json(): JSON column
 *
 * Example usage:
 * ```php
 * $table->id()
 *     ->string('name', 100)->index()
 *     ->string('email')->unique()
 *     ->boolean('is_active')->default(true)
 *     ->timestamps()
 *     ->create();
 * ```
 */
interface TableBuilderInterface
{
    // ===========================================
    // Column Type Methods
    // ===========================================

    /**
     * Add auto-incrementing primary key column
     *
     * @param string $name Column name (default: 'id')
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function id(string $name = 'id'): ColumnBuilderInterface;

    /**
     * Add string/varchar column
     *
     * @param string $name Column name
     * @param int $length Maximum length (default: 255)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function string(string $name, int $length = 255): ColumnBuilderInterface;

    /**
     * Add text column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function text(string $name): ColumnBuilderInterface;

    /**
     * Add integer column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function integer(string $name): ColumnBuilderInterface;

    /**
     * Add big integer column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function bigInteger(string $name): ColumnBuilderInterface;

    /**
     * Add boolean column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function boolean(string $name): ColumnBuilderInterface;

    /**
     * Add decimal column
     *
     * @param string $name Column name
     * @param int $precision Total digits (default: 8)
     * @param int $scale Decimal places (default: 2)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface;

    /**
     * Add timestamp column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function timestamp(string $name): ColumnBuilderInterface;

    /**
     * Add datetime column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function dateTime(string $name): ColumnBuilderInterface;

    /**
     * Add date column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function date(string $name): ColumnBuilderInterface;

    /**
     * Add time column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function time(string $name): ColumnBuilderInterface;

    /**
     * Add JSON column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function json(string $name): ColumnBuilderInterface;

    /**
     * Add UUID column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function uuid(string $name): ColumnBuilderInterface;

    /**
     * Add float column
     *
     * @param string $name Column name
     * @param int $precision Total digits (default: 8)
     * @param int $scale Decimal places (default: 2)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function float(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface;

    /**
     * Add double column
     *
     * @param string $name Column name
     * @param int $precision Total digits (default: 15)
     * @param int $scale Decimal places (default: 8)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function double(string $name, int $precision = 15, int $scale = 8): ColumnBuilderInterface;

    /**
     * Add enum column
     *
     * @param string $name Column name
     * @param array $values Allowed enum values
     * @param string|null $default Default value (must be one of the allowed values)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function enum(string $name, array $values, ?string $default = null): ColumnBuilderInterface;

    /**
     * Add binary column
     *
     * @param string $name Column name
     * @param int|null $length Fixed length for binary data (null for variable length)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function binary(string $name, ?int $length = null): ColumnBuilderInterface;

    /**
     * Add foreign ID column (bigInteger with foreign key)
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining with constrained() method
     */
    public function foreignId(string $name): ColumnBuilderInterface;

    // ===========================================
    // Convenience Methods
    // ===========================================

    /**
     * Add created_at and updated_at timestamp columns
     *
     * @return self For method chaining
     */
    public function timestamps(): self;

    /**
     * Add deleted_at timestamp column for soft deletes
     *
     * @return self For method chaining
     */
    public function softDeletes(): self;

    /**
     * Add remember_token string column for authentication
     *
     * @return self For method chaining
     */
    public function rememberToken(): self;

    // ===========================================
    // Column Operations (for alterations)
    // ===========================================

    /**
     * Add a new column (generic method)
     *
     * @param string $name Column name
     * @param string $type Column type
     * @param array $options Column options
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function addColumn(string $name, string $type, array $options = []): ColumnBuilderInterface;

    /**
     * Modify an existing column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining with new definition
     */
    public function modifyColumn(string $name): ColumnBuilderInterface;

    /**
     * Rename a column
     *
     * @param string $from Current column name
     * @param string $to New column name
     * @return self For method chaining
     */
    public function renameColumn(string $from, string $to): self;

    /**
     * Drop a column
     *
     * @param string $name Column name
     * @return self For method chaining
     */
    public function dropColumn(string $name): self;

    // ===========================================
    // Index Operations
    // ===========================================

    /**
     * Add an index
     *
     * @param array|string $columns Column(s) to index
     * @param string|null $name Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function index(array|string $columns, ?string $name = null): self;

    /**
     * Add a unique index
     *
     * @param array|string $columns Column(s) for unique constraint
     * @param string|null $name Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function unique(array|string $columns, ?string $name = null): self;

    /**
     * Set primary key
     *
     * @param array|string $columns Column(s) for primary key
     * @return self For method chaining
     */
    public function primary(array|string $columns): self;

    /**
     * Add a fulltext index (where supported)
     *
     * @param array|string $columns Column(s) for fulltext index
     * @param string|null $name Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function fulltext(array|string $columns, ?string $name = null): self;

    /**
     * Drop an index
     *
     * @param string $name Index name
     * @return self For method chaining
     */
    public function dropIndex(string $name): self;

    /**
     * Drop a unique constraint
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function dropUnique(string $name): self;

    /**
     * Drop primary key
     *
     * @return self For method chaining
     */
    public function dropPrimary(): self;

    // ===========================================
    // Foreign Key Operations
    // ===========================================

    /**
     * Create a foreign key constraint
     *
     * @param string $column Local column name
     * @return ForeignKeyBuilderInterface For fluent foreign key definition
     */
    public function foreign(string $column): ForeignKeyBuilderInterface;

    /**
     * Drop a foreign key constraint
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function dropForeign(string $name): self;

    // ===========================================
    // Table Options
    // ===========================================

    /**
     * Set table engine (MySQL)
     *
     * @param string $engine Engine name (InnoDB, MyISAM, etc.)
     * @return self For method chaining
     */
    public function engine(string $engine): self;

    /**
     * Set table charset (MySQL)
     *
     * @param string $charset Character set
     * @return self For method chaining
     */
    public function charset(string $charset): self;

    /**
     * Set table collation (MySQL)
     *
     * @param string $collation Collation name
     * @return self For method chaining
     */
    public function collation(string $collation): self;

    /**
     * Add table comment
     *
     * @param string $comment Table comment
     * @return self For method chaining
     */
    public function comment(string $comment): self;

    // ===========================================
    // Execution Methods
    // ===========================================

    /**
     * Create the table and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function create(): SchemaBuilderInterface;

    /**
     * Execute alterations and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function execute(): SchemaBuilderInterface;

    /**
     * Drop the table and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function drop(): SchemaBuilderInterface;

    /**
     * Drop the table if it exists and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function dropIfExists(): SchemaBuilderInterface;

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get the table name
     *
     * @return string Table name
     */
    public function getTableName(): string;

    /**
     * Check if this is a table creation (vs alteration)
     *
     * @return bool True if creating new table
     */
    public function isCreating(): bool;
}
