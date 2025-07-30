<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Builders;

use Glueful\Database\Schema\Interfaces\ColumnBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderContextInterface;
use Glueful\Database\Schema\DTOs\ColumnDefinition;

/**
 * Concrete Column Builder Implementation
 *
 * Provides fluent interface for defining column constraints and properties.
 * Builds up column definition through method chaining and creates the final
 * ColumnDefinition when complete.
 *
 * Features:
 * - Fluent method chaining for all column properties
 * - Type-specific validation and constraints
 * - Database-agnostic column types
 * - Foreign key shortcut methods
 * - Position control (MySQL specific)
 * - Comprehensive validation
 *
 * Example usage:
 * ```php
 * $table->string('email')
 *     ->unique()
 *     ->index()
 *     ->comment('User email address')
 *     ->end();
 *
 * $table->foreignId('user_id')
 *     ->constrained('users')
 *     ->cascadeOnDelete()
 *     ->end();
 * ```
 */
class ColumnBuilder implements ColumnBuilderInterface
{
    /** @var TableBuilderContextInterface Parent table builder */
    private TableBuilderContextInterface $tableBuilder;

    /** @var string Column name */
    private string $name;

    /** @var string Column type */
    private string $type;

    /** @var int|null Column length */
    private ?int $length = null;

    /** @var int|null Column precision */
    private ?int $precision = null;

    /** @var int|null Column scale */
    private ?int $scale = null;

    /** @var bool Whether column allows NULL */
    private bool $nullable = true;

    /** @var mixed Default value */
    private mixed $default = null;

    /** @var string|null Raw default expression */
    private ?string $defaultRaw = null;

    /** @var bool Whether column is auto-incrementing */
    private bool $autoIncrement = false;

    /** @var bool Whether column is unsigned */
    private bool $unsigned = false;

    /** @var bool Whether column has unique constraint */
    private bool $unique = false;

    /** @var bool Whether column is primary key */
    private bool $primary = false;

    /** @var string|null Column to position after */
    private ?string $after = null;

    /** @var bool Whether to position as first column */
    private bool $first = false;

    /** @var string|null Column comment */
    private ?string $comment = null;

    /** @var string|null Character set */
    private ?string $charset = null;

    /** @var string|null Collation */
    private ?string $collation = null;

    /** @var bool Whether string column is binary */
    private bool $binary = false;

    /** @var bool Whether numeric column uses zerofill */
    private bool $zerofill = false;

    /** @var string|null Check constraint expression */
    private ?string $check = null;

    /** @var array Additional options */
    private array $options = [];

    /** @var bool Whether the column has been finalized */
    private bool $finalized = false;

    /** @var string|null Foreign key referenced table */
    private ?string $foreignTable = null;

    /** @var string Foreign key referenced column */
    private string $foreignColumn = 'id';

    /** @var string|null ON DELETE action */
    private ?string $onDelete = null;

    /** @var string|null ON UPDATE action */
    private ?string $onUpdate = null;

    /**
     * Create a new column builder
     *
     * @param TableBuilderContextInterface $tableBuilder Parent table builder
     * @param string $name Column name
     * @param string $type Column type
     * @param array $options Initial options
     */
    public function __construct(
        TableBuilderContextInterface $tableBuilder,
        string $name,
        string $type,
        array $options = []
    ) {
        $this->tableBuilder = $tableBuilder;
        $this->name = $name;
        $this->type = $type;

        // Apply initial options
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            } else {
                $this->options[$key] = $value;
            }
        }
    }

    /**
     * Destructor - auto-finalize column if not already done
     */
    public function __destruct()
    {
        if (!$this->finalized) {
            $this->finalizeColumn();
        }
    }

    // ===========================================
    // Nullability
    // ===========================================

    /**
     * Allow NULL values for this column
     *
     * @param bool $nullable Whether column should be nullable (default: true)
     * @return self For method chaining
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * Require NOT NULL for this column
     *
     * @return self For method chaining
     */
    public function notNullable(): self
    {
        $this->nullable = false;
        return $this;
    }

    /**
     * Require NOT NULL for this column (alias for notNullable)
     *
     * @return self For method chaining
     */
    public function notNull(): self
    {
        return $this->notNullable();
    }

    // ===========================================
    // Default Values
    // ===========================================

    /**
     * Set default value for column
     *
     * @param mixed $value Default value
     * @return self For method chaining
     */
    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->defaultRaw = null; // Clear raw default
        return $this;
    }

    /**
     * Use database expression as default (e.g., CURRENT_TIMESTAMP)
     *
     * @param string $expression SQL expression
     * @return self For method chaining
     */
    public function defaultRaw(string $expression): self
    {
        $this->defaultRaw = $expression;
        $this->default = null; // Clear regular default
        return $this;
    }

    /**
     * Use current timestamp as default
     *
     * @return self For method chaining
     */
    public function useCurrent(): self
    {
        return $this->defaultRaw('CURRENT_TIMESTAMP');
    }

    /**
     * Update timestamp on row update
     *
     * @return self For method chaining
     */
    public function useCurrentOnUpdate(): self
    {
        $this->options['onUpdate'] = 'CURRENT_TIMESTAMP';
        return $this;
    }

    // ===========================================
    // Constraints
    // ===========================================

    /**
     * Add unique constraint to this column
     *
     * @param string|null $indexName Custom index name (auto-generated if null)
     * @return self For method chaining
     */
    public function unique(?string $indexName = null): self
    {
        $this->unique = true;
        if ($indexName) {
            $this->options['uniqueIndexName'] = $indexName;
        }
        return $this;
    }

    /**
     * Add index to this column
     *
     * @param string|null $indexName Custom index name (auto-generated if null)
     * @return self For method chaining
     */
    public function index(?string $indexName = null): self
    {
        $this->options['index'] = true;
        if ($indexName) {
            $this->options['indexName'] = $indexName;
        }
        return $this;
    }

    /**
     * Set this column as primary key
     *
     * @return self For method chaining
     */
    public function primary(): self
    {
        $this->primary = true;
        $this->nullable = false; // Primary keys cannot be null
        return $this;
    }

    /**
     * Enable auto increment for this column
     *
     * @return self For method chaining
     */
    public function autoIncrement(): self
    {
        $this->autoIncrement = true;
        $this->nullable = false; // Auto increment columns cannot be null
        return $this;
    }

    /**
     * Add check constraint
     *
     * @param string $constraint Check constraint expression
     * @return self For method chaining
     */
    public function check(string $constraint): self
    {
        $this->check = $constraint;
        return $this;
    }

    // ===========================================
    // Column Positioning (MySQL)
    // ===========================================

    /**
     * Position column as first in table
     *
     * @return self For method chaining
     */
    public function first(): self
    {
        $this->first = true;
        $this->after = null; // Clear AFTER positioning
        return $this;
    }

    /**
     * Position column after another column
     *
     * @param string $column Column to position after
     * @return self For method chaining
     */
    public function after(string $column): self
    {
        $this->after = $column;
        $this->first = false; // Clear FIRST positioning
        return $this;
    }

    // ===========================================
    // Column Metadata
    // ===========================================

    /**
     * Add comment to column
     *
     * @param string $comment Column comment
     * @return self For method chaining
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Set column charset (MySQL)
     *
     * @param string $charset Character set
     * @return self For method chaining
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set column collation (MySQL)
     *
     * @param string $collation Collation name
     * @return self For method chaining
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    // ===========================================
    // Type-Specific Options
    // ===========================================

    /**
     * Set column as unsigned (numeric types)
     *
     * @return self For method chaining
     */
    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    /**
     * Enable zero fill for numeric columns (MySQL)
     *
     * @return self For method chaining
     */
    public function zerofill(): self
    {
        $this->zerofill = true;
        return $this;
    }

    /**
     * Set binary flag for string columns (MySQL)
     *
     * @return self For method chaining
     */
    public function binary(): self
    {
        $this->binary = true;
        return $this;
    }

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
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        if ($table === null) {
            // Guess table name from column name (e.g., user_id -> users)
            $table = $this->guessTableFromColumnName($this->name);
        }

        $this->foreignTable = $table;
        $this->foreignColumn = $column;
        return $this;
    }

    /**
     * Set foreign key ON DELETE CASCADE
     *
     * @return self For method chaining
     */
    public function cascadeOnDelete(): self
    {
        $this->onDelete = 'CASCADE';
        return $this;
    }

    /**
     * Set foreign key ON UPDATE CASCADE
     *
     * @return self For method chaining
     */
    public function cascadeOnUpdate(): self
    {
        $this->onUpdate = 'CASCADE';
        return $this;
    }

    /**
     * Set foreign key ON DELETE SET NULL
     *
     * @return self For method chaining
     */
    public function nullOnDelete(): self
    {
        $this->onDelete = 'SET NULL';
        return $this;
    }

    /**
     * Set foreign key ON DELETE RESTRICT
     *
     * @return self For method chaining
     */
    public function restrictOnDelete(): self
    {
        $this->onDelete = 'RESTRICT';
        return $this;
    }

    /**
     * Set foreign key ON UPDATE RESTRICT
     *
     * @return self For method chaining
     */
    public function restrictOnUpdate(): self
    {
        $this->onUpdate = 'RESTRICT';
        return $this;
    }

    /**
     * Set foreign key ON DELETE NO ACTION
     *
     * @return self For method chaining
     */
    public function noActionOnDelete(): self
    {
        $this->onDelete = 'NO ACTION';
        return $this;
    }

    /**
     * Set foreign key ON UPDATE NO ACTION
     *
     * @return self For method chaining
     */
    public function noActionOnUpdate(): self
    {
        $this->onUpdate = 'NO ACTION';
        return $this;
    }

    // ===========================================
    // Type Modifications
    // ===========================================

    /**
     * Change column type to string/varchar
     *
     * @param int $length Maximum length
     * @return self For method chaining
     */
    public function string(int $length = 255): self
    {
        $this->type = 'string';
        $this->length = $length;
        return $this;
    }

    /**
     * Change column type to text
     *
     * @return self For method chaining
     */
    public function text(): self
    {
        $this->type = 'text';
        return $this;
    }

    /**
     * Change column type to integer
     *
     * @return self For method chaining
     */
    public function integer(): self
    {
        $this->type = 'integer';
        return $this;
    }

    /**
     * Change column type to big integer
     *
     * @return self For method chaining
     */
    public function bigInteger(): self
    {
        $this->type = 'bigInteger';
        return $this;
    }

    /**
     * Change column type to boolean
     *
     * @return self For method chaining
     */
    public function boolean(): self
    {
        $this->type = 'boolean';
        return $this;
    }

    /**
     * Change column type to decimal
     *
     * @param int $precision Total digits
     * @param int $scale Decimal places
     * @return self For method chaining
     */
    public function decimal(int $precision = 8, int $scale = 2): self
    {
        $this->type = 'decimal';
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    /**
     * Change column type to timestamp
     *
     * @return self For method chaining
     */
    public function timestamp(): self
    {
        $this->type = 'timestamp';
        return $this;
    }

    /**
     * Change column type to JSON
     *
     * @return self For method chaining
     */
    public function json(): self
    {
        $this->type = 'json';
        return $this;
    }

    // ===========================================
    // Completion
    // ===========================================

    /**
     * Complete column definition and return to table builder
     *
     * @return TableBuilderContextInterface For continued table building
     */
    public function end(): TableBuilderContextInterface
    {
        $this->finalizeColumn();
        return $this->tableBuilder;
    }

    /**
     * Finalize the column definition and add it to the table
     *
     * @return void
     */
    private function finalizeColumn(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;

        // Create the column definition
        $columnDefinition = new ColumnDefinition(
            name: $this->name,
            type: $this->type,
            length: $this->length,
            precision: $this->precision,
            scale: $this->scale,
            nullable: $this->nullable,
            default: $this->default,
            defaultRaw: $this->defaultRaw,
            autoIncrement: $this->autoIncrement,
            unsigned: $this->unsigned,
            unique: $this->unique,
            primary: $this->primary,
            after: $this->after,
            first: $this->first,
            comment: $this->comment,
            charset: $this->charset,
            collation: $this->collation,
            binary: $this->binary,
            zerofill: $this->zerofill,
            check: $this->check,
            options: $this->options
        );

        // Add to table builder
        $this->tableBuilder->addColumnDefinition($columnDefinition);

        // Add foreign key if specified
        if ($this->foreignTable) {
            $constraintName = $this->generateForeignKeyName();
            $foreignKey = new \Glueful\Database\Schema\DTOs\ForeignKeyDefinition(
                localColumn: $this->name,
                referencedTable: $this->foreignTable,
                referencedColumn: $this->foreignColumn,
                name: $constraintName,
                onDelete: $this->onDelete,
                onUpdate: $this->onUpdate
            );

            $this->tableBuilder->addForeignKeyDefinition($foreignKey);
        }

        // Add unique index if specified
        if ($this->unique && !$this->primary) {
            $indexName = $this->options['uniqueIndexName'] ?? null;
            $this->tableBuilder->unique($this->name, $indexName);
        }

        // Add regular index if specified
        if (isset($this->options['index']) && $this->options['index'] && !$this->unique && !$this->primary) {
            $indexName = $this->options['indexName'] ?? null;
            $this->tableBuilder->index($this->name, $indexName);
        }
    }

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get column name
     *
     * @return string Column name
     */
    public function getColumnName(): string
    {
        return $this->name;
    }

    /**
     * Get column type
     *
     * @return string Column type
     */
    public function getColumnType(): string
    {
        return $this->type;
    }

    /**
     * Check if column is nullable
     *
     * @return bool True if nullable
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * Get default value
     *
     * @return mixed Default value
     */
    public function getDefault(): mixed
    {
        return $this->default;
    }

    // ===========================================
    // Private Helper Methods
    // ===========================================

    /**
     * Guess table name from column name
     *
     * @param string $columnName Column name (e.g., 'user_id')
     * @return string Guessed table name (e.g., 'users')
     */
    private function guessTableFromColumnName(string $columnName): string
    {
        // Remove '_id' suffix and pluralize
        $tableName = str_replace('_id', '', $columnName);

        // Simple pluralization (could be enhanced)
        if (str_ends_with($tableName, 'y')) {
            return substr($tableName, 0, -1) . 'ies';
        }
        if (str_ends_with($tableName, 's') || str_ends_with($tableName, 'sh') || str_ends_with($tableName, 'ch')) {
            return $tableName . 'es';
        }

        return $tableName . 's';
    }

    /**
     * Generate foreign key constraint name
     *
     * @return string Generated constraint name
     */
    private function generateForeignKeyName(): string
    {
        return 'fk_' . $this->tableBuilder->getTableName() . '_' . $this->name;
    }

    /**
     * Execute column operations (delegates to parent table builder)
     *
     * @return mixed Results of executed operations
     * @throws \RuntimeException If execution fails
     */
    public function execute(): mixed
    {
        return $this->tableBuilder->execute();
    }
}
