<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

/**
 * Column Builder Interface
 *
 * Provides fluent interface for defining column constraints and properties.
 * Supports method chaining for building complex column definitions.
 *
 * Features:
 * - Nullable/not null constraints
 * - Default values
 * - Unique constraints
 * - Index creation
 * - Auto increment
 * - Column positioning (MySQL)
 * - Foreign key shortcuts
 * - Comments
 *
 * Example usage:
 * ```php
 * $table->string('email')
 *     ->unique()
 *     ->index()
 *     ->comment('User email address');
 *
 * $table->foreignId('user_id')
 *     ->constrained('users')
 *     ->cascadeOnDelete();
 * ```
 */
interface ColumnBuilderInterface
{
    // ===========================================
    // Nullability
    // ===========================================

    /**
     * Allow NULL values for this column
     *
     * @param bool $nullable Whether column should be nullable (default: true)
     * @return self For method chaining
     */
    public function nullable(bool $nullable = true): self;

    /**
     * Require NOT NULL for this column
     *
     * @return self For method chaining
     */
    public function notNullable(): self;

    /**
     * Require NOT NULL for this column (alias for notNullable)
     *
     * @return self For method chaining
     */
    public function notNull(): self;

    // ===========================================
    // Default Values
    // ===========================================

    /**
     * Set default value for column
     *
     * @param mixed $value Default value
     * @return self For method chaining
     */
    public function default(mixed $value): self;

    /**
     * Use database expression as default (e.g., CURRENT_TIMESTAMP)
     *
     * @param string $expression SQL expression
     * @return self For method chaining
     */
    public function defaultRaw(string $expression): self;

    /**
     * Use current timestamp as default
     *
     * @return self For method chaining
     */
    public function useCurrent(): self;

    /**
     * Update timestamp on row update
     *
     * @return self For method chaining
     */
    public function useCurrentOnUpdate(): self;

    // ===========================================
    // Constraints
    // ===========================================

    /**
     * Add unique constraint to this column
     *
     * @param string|null $indexName Custom index name (auto-generated if null)
     * @return self For method chaining
     */
    public function unique(?string $indexName = null): self;

    /**
     * Add index to this column
     *
     * @param string|null $indexName Custom index name (auto-generated if null)
     * @return self For method chaining
     */
    public function index(?string $indexName = null): self;

    /**
     * Set this column as primary key
     *
     * @return self For method chaining
     */
    public function primary(): self;

    /**
     * Enable auto increment for this column
     *
     * @return self For method chaining
     */
    public function autoIncrement(): self;

    /**
     * Add check constraint
     *
     * @param string $constraint Check constraint expression
     * @return self For method chaining
     */
    public function check(string $constraint): self;

    // ===========================================
    // Column Positioning (MySQL)
    // ===========================================

    /**
     * Position column as first in table
     *
     * @return self For method chaining
     */
    public function first(): self;

    /**
     * Position column after another column
     *
     * @param string $column Column to position after
     * @return self For method chaining
     */
    public function after(string $column): self;

    // ===========================================
    // Column Metadata
    // ===========================================

    /**
     * Add comment to column
     *
     * @param string $comment Column comment
     * @return self For method chaining
     */
    public function comment(string $comment): self;

    /**
     * Set column charset (MySQL)
     *
     * @param string $charset Character set
     * @return self For method chaining
     */
    public function charset(string $charset): self;

    /**
     * Set column collation (MySQL)
     *
     * @param string $collation Collation name
     * @return self For method chaining
     */
    public function collation(string $collation): self;

    // ===========================================
    // Type-Specific Options
    // ===========================================

    /**
     * Set column as unsigned (numeric types)
     *
     * @return self For method chaining
     */
    public function unsigned(): self;

    /**
     * Enable zero fill for numeric columns (MySQL)
     *
     * @return self For method chaining
     */
    public function zerofill(): self;

    /**
     * Set binary flag for string columns (MySQL)
     *
     * @return self For method chaining
     */
    public function binary(): self;

    // ===========================================
    // Foreign Key Shortcuts
    // ===========================================

    /**
     * Create foreign key constraint to another table
     *
     * @param string|null $table Referenced table (guessed from column name if null)
     * @param string $column Referenced column (default: 'id')
     * @return self For method chaining
     */
    public function constrained(?string $table = null, string $column = 'id'): self;

    /**
     * Set foreign key ON DELETE CASCADE
     *
     * @return self For method chaining
     */
    public function cascadeOnDelete(): self;

    /**
     * Set foreign key ON UPDATE CASCADE
     *
     * @return self For method chaining
     */
    public function cascadeOnUpdate(): self;

    /**
     * Set foreign key ON DELETE SET NULL
     *
     * @return self For method chaining
     */
    public function nullOnDelete(): self;

    /**
     * Set foreign key ON DELETE RESTRICT
     *
     * @return self For method chaining
     */
    public function restrictOnDelete(): self;

    /**
     * Set foreign key ON UPDATE RESTRICT
     *
     * @return self For method chaining
     */
    public function restrictOnUpdate(): self;

    /**
     * Set foreign key ON DELETE NO ACTION
     *
     * @return self For method chaining
     */
    public function noActionOnDelete(): self;

    /**
     * Set foreign key ON UPDATE NO ACTION
     *
     * @return self For method chaining
     */
    public function noActionOnUpdate(): self;

    // ===========================================
    // Type Modifications
    // ===========================================

    /**
     * Change column type to string/varchar
     *
     * @param int $length Maximum length
     * @return self For method chaining
     */
    public function string(int $length = 255): self;

    /**
     * Change column type to text
     *
     * @return self For method chaining
     */
    public function text(): self;

    /**
     * Change column type to integer
     *
     * @return self For method chaining
     */
    public function integer(): self;

    /**
     * Change column type to big integer
     *
     * @return self For method chaining
     */
    public function bigInteger(): self;

    /**
     * Change column type to boolean
     *
     * @return self For method chaining
     */
    public function boolean(): self;

    /**
     * Change column type to decimal
     *
     * @param int $precision Total digits
     * @param int $scale Decimal places
     * @return self For method chaining
     */
    public function decimal(int $precision = 8, int $scale = 2): self;

    /**
     * Change column type to timestamp
     *
     * @return self For method chaining
     */
    public function timestamp(): self;

    /**
     * Change column type to JSON
     *
     * @return self For method chaining
     */
    public function json(): self;

    // ===========================================
    // Completion
    // ===========================================

    /**
     * Complete column definition and return to table builder
     *
     * @return TableBuilderContextInterface For continued table building
     */
    public function end(): TableBuilderContextInterface;

    /**
     * Execute column operations (typically calls parent table execute)
     *
     * @return mixed Results of executed operations (SchemaBuilderInterface for chaining or array for results)
     * @throws \RuntimeException If execution fails
     */
    public function execute(): mixed;

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get column name
     *
     * @return string Column name
     */
    public function getColumnName(): string;

    /**
     * Get column type
     *
     * @return string Column type
     */
    public function getColumnType(): string;

    /**
     * Check if column is nullable
     *
     * @return bool True if nullable
     */
    public function isNullable(): bool;

    /**
     * Get default value
     *
     * @return mixed Default value
     */
    public function getDefault(): mixed;
}
