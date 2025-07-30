<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Builders;

use Glueful\Database\Schema\Interfaces\TableBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderContextInterface;
use Glueful\Database\Schema\Interfaces\ColumnBuilderInterface;
use Glueful\Database\Schema\Interfaces\ForeignKeyBuilderInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * Concrete Table Builder Implementation
 *
 * Provides fluent interface for defining table structure with columns,
 * indexes, and constraints. Handles both table creation and alteration.
 *
 * Features:
 * - Fluent method chaining for all operations
 * - Database-agnostic column types
 * - Automatic index name generation
 * - Foreign key relationship management
 * - Table option configuration
 * - Validation and error handling
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
class TableBuilder implements TableBuilderInterface, TableBuilderContextInterface
{
    /** @var SchemaBuilderInterface Parent schema builder */
    private SchemaBuilderInterface $schemaBuilder;

    /** @var SqlGeneratorInterface SQL generator */
    private SqlGeneratorInterface $sqlGenerator;

    /** @var string Table name */
    private string $tableName;

    /** @var bool Whether this is an alteration (true) or creation (false) */
    private bool $isAlteration;

    /** @var array<ColumnDefinition> Column definitions */
    private array $columns = [];

    /** @var array<IndexDefinition> Index definitions */
    private array $indexes = [];

    /** @var array<ForeignKeyDefinition> Foreign key definitions */
    private array $foreignKeys = [];

    /** @var array<string> Primary key column names */
    private array $primaryKey = [];

    /** @var array<string, mixed> Table options */
    private array $options = [];

    /** @var string|null Table comment */
    private ?string $comment = null;

    /**
     * Create a new table builder
     *
     * @param SchemaBuilderInterface $schemaBuilder Parent schema builder
     * @param SqlGeneratorInterface $sqlGenerator SQL generator
     * @param string $tableName Table name
     * @param bool $isAlteration Whether this is an alteration
     */
    public function __construct(
        SchemaBuilderInterface $schemaBuilder,
        SqlGeneratorInterface $sqlGenerator,
        string $tableName,
        bool $isAlteration = false
    ) {
        $this->schemaBuilder = $schemaBuilder;
        $this->sqlGenerator = $sqlGenerator;
        $this->tableName = $tableName;
        $this->isAlteration = $isAlteration;
    }

    // ===========================================
    // Column Type Methods
    // ===========================================

    /**
     * Add auto-incrementing primary key column
     *
     * @param string $name Column name (default: 'id')
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function id(string $name = 'id'): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'id', [
            'autoIncrement' => true,
            'primary' => true,
            'nullable' => false
        ]);
    }

    /**
     * Add string/varchar column
     *
     * @param string $name Column name
     * @param int $length Maximum length (default: 255)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function string(string $name, int $length = 255): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'string', ['length' => $length]);
    }

    /**
     * Add text column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function text(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'text');
    }

    /**
     * Add integer column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function integer(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'integer');
    }

    /**
     * Add big integer column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function bigInteger(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'bigInteger');
    }

    /**
     * Add boolean column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function boolean(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'boolean');
    }

    /**
     * Add decimal column
     *
     * @param string $name Column name
     * @param int $precision Total digits (default: 8)
     * @param int $scale Decimal places (default: 2)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'decimal', [
            'precision' => $precision,
            'scale' => $scale
        ]);
    }

    /**
     * Add timestamp column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function timestamp(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'timestamp');
    }

    /**
     * Add datetime column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function dateTime(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'datetime');
    }

    /**
     * Add date column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function date(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'date');
    }

    /**
     * Add time column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function time(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'time');
    }

    /**
     * Add JSON column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function json(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'json');
    }

    /**
     * Add UUID column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function uuid(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'uuid');
    }

    /**
     * Add float column
     *
     * @param string $name Column name
     * @param int $precision Total digits (default: 8)
     * @param int $scale Decimal places (default: 2)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function float(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'float', [
            'precision' => $precision,
            'scale' => $scale
        ]);
    }

    /**
     * Add double column
     *
     * @param string $name Column name
     * @param int $precision Total digits (default: 15)
     * @param int $scale Decimal places (default: 8)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function double(string $name, int $precision = 15, int $scale = 8): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'double', [
            'precision' => $precision,
            'scale' => $scale
        ]);
    }

    /**
     * Add enum column
     *
     * @param string $name Column name
     * @param array $values Allowed enum values
     * @param string|null $default Default value (must be one of the allowed values)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function enum(string $name, array $values, ?string $default = null): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'enum', [
            'values' => $values,
            'default' => $default
        ]);
    }

    /**
     * Add binary column
     *
     * @param string $name Column name
     * @param int|null $length Fixed length for binary data (null for variable length)
     * @return ColumnBuilderInterface For fluent chaining
     */
    public function binary(string $name, ?int $length = null): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'binary', [
            'length' => $length
        ]);
    }

    /**
     * Add foreign ID column (bigInteger with foreign key)
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining with constrained() method
     */
    public function foreignId(string $name): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, 'foreignId');
    }

    // ===========================================
    // Convenience Methods
    // ===========================================

    /**
     * Add created_at and updated_at timestamp columns
     *
     * @return self For method chaining
     */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->useCurrent()->end();
        $this->timestamp('updated_at')->nullable()->end();
        return $this;
    }

    /**
     * Add deleted_at timestamp column for soft deletes
     *
     * @return self For method chaining
     */
    public function softDeletes(): self
    {
        $this->timestamp('deleted_at')->nullable()->end();
        return $this;
    }

    /**
     * Add remember_token string column for authentication
     *
     * @return self For method chaining
     */
    public function rememberToken(): self
    {
        $this->string('remember_token', 100)->nullable()->end();
        return $this;
    }

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
    public function addColumn(string $name, string $type, array $options = []): ColumnBuilderInterface
    {
        return $this->addColumnBuilder($name, $type, $options);
    }

    /**
     * Modify an existing column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For fluent chaining with new definition
     */
    public function modifyColumn(string $name): ColumnBuilderInterface
    {
        // For modification, we create a column builder that will replace the existing definition
        return new ColumnBuilder($this, $name, 'string', ['_modify' => true]);
    }

    /**
     * Rename a column
     *
     * @param string $from Current column name
     * @param string $to New column name
     * @return self For method chaining
     */
    public function renameColumn(string $from, string $to): self
    {
        // Store rename operation for later execution
        $this->options['_renames'] = $this->options['_renames'] ?? [];
        $this->options['_renames'][] = ['from' => $from, 'to' => $to];
        return $this;
    }

    /**
     * Drop a column
     *
     * @param string $name Column name
     * @return self For method chaining
     */
    public function dropColumn(string $name): self
    {
        // Store drop operation for later execution
        $this->options['_drops'] = $this->options['_drops'] ?? [];
        $this->options['_drops'][] = $name;
        return $this;
    }

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
    public function index(array|string $columns, ?string $name = null): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $name = $name ?: $this->generateIndexName($columns, 'index');

        $this->indexes[] = new IndexDefinition($columns, $name, 'index');
        return $this;
    }

    /**
     * Add a unique index
     *
     * @param array|string $columns Column(s) for unique constraint
     * @param string|null $name Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function unique(array|string $columns, ?string $name = null): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $name = $name ?: $this->generateIndexName($columns, 'unique');

        $this->indexes[] = new IndexDefinition($columns, $name, 'unique', true);
        return $this;
    }

    /**
     * Set primary key
     *
     * @param array|string $columns Column(s) for primary key
     * @return self For method chaining
     */
    public function primary(array|string $columns): self
    {
        $this->primaryKey = is_string($columns) ? [$columns] : $columns;
        return $this;
    }

    /**
     * Add a fulltext index (where supported)
     *
     * @param array|string $columns Column(s) for fulltext index
     * @param string|null $name Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function fulltext(array|string $columns, ?string $name = null): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        $name = $name ?: $this->generateIndexName($columns, 'fulltext');

        $this->indexes[] = new IndexDefinition($columns, $name, 'fulltext');
        return $this;
    }

    /**
     * Drop an index
     *
     * @param string $name Index name
     * @return self For method chaining
     */
    public function dropIndex(string $name): self
    {
        $this->options['_drop_indexes'] = $this->options['_drop_indexes'] ?? [];
        $this->options['_drop_indexes'][] = $name;
        return $this;
    }

    /**
     * Drop a unique constraint
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function dropUnique(string $name): self
    {
        return $this->dropIndex($name);
    }

    /**
     * Drop primary key
     *
     * @return self For method chaining
     */
    public function dropPrimary(): self
    {
        $this->options['_drop_primary'] = true;
        return $this;
    }

    // ===========================================
    // Foreign Key Operations
    // ===========================================

    /**
     * Create a foreign key constraint
     *
     * @param string $column Local column name
     * @return ForeignKeyBuilderInterface For fluent foreign key definition
     */
    public function foreign(string $column): ForeignKeyBuilderInterface
    {
        return new ForeignKeyBuilder($this, $column);
    }

    /**
     * Drop a foreign key constraint
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function dropForeign(string $name): self
    {
        $this->options['_drop_foreign_keys'] = $this->options['_drop_foreign_keys'] ?? [];
        $this->options['_drop_foreign_keys'][] = $name;
        return $this;
    }

    // ===========================================
    // Table Options
    // ===========================================

    /**
     * Set table engine (MySQL)
     *
     * @param string $engine Engine name (InnoDB, MyISAM, etc.)
     * @return self For method chaining
     */
    public function engine(string $engine): self
    {
        $this->options['engine'] = $engine;
        return $this;
    }

    /**
     * Set table charset (MySQL)
     *
     * @param string $charset Character set
     * @return self For method chaining
     */
    public function charset(string $charset): self
    {
        $this->options['charset'] = $charset;
        return $this;
    }

    /**
     * Set table collation (MySQL)
     *
     * @param string $collation Collation name
     * @return self For method chaining
     */
    public function collation(string $collation): self
    {
        $this->options['collation'] = $collation;
        return $this;
    }

    /**
     * Add table comment
     *
     * @param string $comment Table comment
     * @return self For method chaining
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    // ===========================================
    // Execution Methods
    // ===========================================

    /**
     * Create the table and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function create(): SchemaBuilderInterface
    {
        $tableDefinition = new TableDefinition(
            name: $this->tableName,
            columns: $this->columns,
            indexes: $this->indexes,
            foreignKeys: $this->foreignKeys,
            primaryKey: $this->primaryKey,
            options: $this->options,
            comment: $this->comment
        );

        $sql = $this->sqlGenerator->createTable($tableDefinition);
        $this->schemaBuilder->addPendingOperation($sql);

        return $this->schemaBuilder;
    }

    /**
     * Execute alterations and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function execute(): SchemaBuilderInterface
    {
        if ($this->isAlteration) {
            // Handle table alterations
            $this->executeAlterations();
        } else {
            // Create new table
            $this->create();
        }

        return $this->schemaBuilder;
    }

    /**
     * Drop the table and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function drop(): SchemaBuilderInterface
    {
        return $this->schemaBuilder->dropTable($this->tableName);
    }

    /**
     * Drop the table if it exists and return to schema builder
     *
     * @return SchemaBuilderInterface For continued chaining
     */
    public function dropIfExists(): SchemaBuilderInterface
    {
        return $this->schemaBuilder->dropTableIfExists($this->tableName);
    }

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get the table name
     *
     * @return string Table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Check if this is a table creation (vs alteration)
     *
     * @return bool True if creating new table
     */
    public function isCreating(): bool
    {
        return !$this->isAlteration;
    }

    // ===========================================
    // Internal Helper Methods
    // ===========================================

    /**
     * Create a column builder and add it to the table
     *
     * @param string $name Column name
     * @param string $type Column type
     * @param array $options Column options
     * @return ColumnBuilderInterface Column builder
     */
    private function addColumnBuilder(string $name, string $type, array $options = []): ColumnBuilderInterface
    {
        return new ColumnBuilder($this, $name, $type, $options);
    }

    /**
     * Add a column definition to the table
     *
     * @param ColumnDefinition $column Column definition
     * @return void
     */
    public function addColumnDefinition(ColumnDefinition $column): void
    {
        $this->columns[] = $column;

        // Auto-add to primary key if marked as primary
        if ($column->primary) {
            $this->primaryKey[] = $column->name;
        }
    }

    /**
     * Add a foreign key definition to the table
     *
     * @param ForeignKeyDefinition $foreignKey Foreign key definition
     * @return void
     */
    public function addForeignKeyDefinition(ForeignKeyDefinition $foreignKey): void
    {
        $this->foreignKeys[] = $foreignKey;
    }

    /**
     * Generate automatic index name
     *
     * @param array<string> $columns Column names
     * @param string $type Index type
     * @return string Generated index name
     */
    private function generateIndexName(array $columns, string $type): string
    {
        $suffix = match ($type) {
            'unique' => 'unique',
            'fulltext' => 'fulltext',
            default => 'index'
        };

        return $this->tableName . '_' . implode('_', $columns) . '_' . $suffix;
    }

    /**
     * Execute table alterations
     *
     * @return void
     */
    private function executeAlterations(): void
    {
        // For now, generate basic alteration SQL
        // In a full implementation, this would handle all types of alterations
        $tableDefinition = new TableDefinition(
            name: $this->tableName,
            columns: $this->columns,
            indexes: $this->indexes,
            foreignKeys: $this->foreignKeys,
            primaryKey: $this->primaryKey,
            options: $this->options,
            comment: $this->comment
        );

        $changes = [
            'add_columns' => $this->columns,
            'add_indexes' => $this->indexes,
            'add_foreign_keys' => $this->foreignKeys
        ];

        $sqlStatements = $this->sqlGenerator->alterTable($tableDefinition, $changes);

        foreach ($sqlStatements as $sql) {
            $this->schemaBuilder->addPendingOperation($sql);
        }
    }
}
