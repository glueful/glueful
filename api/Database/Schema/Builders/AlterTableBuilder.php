<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Builders;

use Glueful\Database\Schema\Interfaces\AlterTableBuilderInterface;
use Glueful\Database\Schema\Interfaces\ColumnBuilderInterface;
use Glueful\Database\Schema\Interfaces\ForeignKeyBuilderInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderContextInterface;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\IndexDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * Concrete Alter Table Builder Implementation
 *
 * Provides fluent interface for table alterations including adding/dropping columns,
 * modifying existing columns, managing indexes, and foreign key constraints.
 * Tracks all changes and generates appropriate SQL statements.
 *
 * Features:
 * - Column addition, modification, and removal
 * - Index management (add/drop)
 * - Foreign key constraint management
 * - Table renaming and commenting
 * - Database-agnostic change tracking
 * - Transaction-safe execution
 *
 * Example usage:
 * ```php
 * $schema->alter('users', function($table) {
 *     $table->addColumn('middle_name')->string(100)->nullable()->after('first_name');
 *     $table->modifyColumn('email')->string(320)->unique();
 *     $table->dropColumn('old_field');
 *     $table->addIndex(['email', 'status'], 'idx_user_email_status');
 *     $table->dropForeignKey('fk_user_role');
 * });
 * ```
 */
class AlterTableBuilder implements AlterTableBuilderInterface, TableBuilderContextInterface
{
    /** @var SchemaBuilderInterface Parent schema builder */
    private SchemaBuilderInterface $schemaBuilder;

    /** @var SqlGeneratorInterface SQL generator for database-specific SQL */
    private SqlGeneratorInterface $sqlGenerator;

    /** @var string Table name being altered */
    private string $tableName;

    /** @var TableDefinition Current table definition */
    private TableDefinition $tableDefinition;

    /** @var array Changes to apply to the table */
    private array $changes = [
        'add_columns' => [],
        'modify_columns' => [],
        'drop_columns' => [],
        'add_indexes' => [],
        'drop_indexes' => [],
        'add_foreign_keys' => [],
        'drop_foreign_keys' => [],
        'rename_table' => null,
        'comment' => null,
    ];

    /**
     * Create a new alter table builder
     *
     * @param SchemaBuilderInterface $schemaBuilder Parent schema builder
     * @param SqlGeneratorInterface $sqlGenerator SQL generator
     * @param string $tableName Table name to alter
     * @param TableDefinition $tableDefinition Current table definition
     */
    public function __construct(
        SchemaBuilderInterface $schemaBuilder,
        SqlGeneratorInterface $sqlGenerator,
        string $tableName,
        TableDefinition $tableDefinition
    ) {
        $this->schemaBuilder = $schemaBuilder;
        $this->sqlGenerator = $sqlGenerator;
        $this->tableName = $tableName;
        $this->tableDefinition = $tableDefinition;
    }

    // ===========================================
    // Column Operations
    // ===========================================

    /**
     * Add a new column to the table (interface compliance)
     *
     * @param string $column Column name
     * @param string $type Column type
     * @param array $options Column options
     * @return self For method chaining
     */
    public function addColumn(string $column, string $type, array $options = []): self
    {
        $columnDefinition = new ColumnDefinition(
            name: $column,
            type: $type,
            nullable: $options['nullable'] ?? true,
            default: $options['default'] ?? null,
            length: $options['length'] ?? null,
            precision: $options['precision'] ?? null,
            scale: $options['scale'] ?? null,
            options: $options
        );

        $this->changes['add_columns'][] = $columnDefinition;
        return $this;
    }

    /**
     * Add a new column and return column builder (for fluent column definition)
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function newColumn(string $name): ColumnBuilderInterface
    {
        return new ColumnBuilder($this, $name, 'string');
    }


    /**
     * Modify an existing column (fluent interface)
     *
     * @param string $name Column name to modify
     * @return ColumnBuilderInterface For column redefinition
     */
    public function modifyColumnFluent(string $name): ColumnBuilderInterface
    {
        // Find existing column definition to use as base
        $existingColumn = $this->findColumnDefinition($name);

        if ($existingColumn) {
            return new ColumnBuilder($this, $name, $existingColumn->type, [
                'length' => $existingColumn->length,
                'precision' => $existingColumn->precision,
                'scale' => $existingColumn->scale,
                'nullable' => $existingColumn->nullable,
                'default' => $existingColumn->getDefaultValue(),
                'autoIncrement' => $existingColumn->autoIncrement,
                'unsigned' => $existingColumn->unsigned,
                'unique' => $existingColumn->unique,
                'primary' => $existingColumn->primary,
                'comment' => $existingColumn->comment,
                'charset' => $existingColumn->charset,
                'collation' => $existingColumn->collation,
                'binary' => $existingColumn->binary,
                'zerofill' => $existingColumn->zerofill,
                'check' => $existingColumn->check,
            ]);
        }

        // If column doesn't exist, create new one
        return new ColumnBuilder($this, $name, 'string');
    }

    /**
     * Modify an existing column (interface compliance)
     *
     * @param string $column Column to modify
     * @param string $type New column type
     * @param array $options New column options
     * @return self For method chaining
     */
    public function modifyColumn(string $column, string $type, array $options = []): self
    {
        $columnDefinition = new ColumnDefinition(
            name: $column,
            type: $type,
            nullable: $options['nullable'] ?? true,
            default: $options['default'] ?? null,
            length: $options['length'] ?? null,
            precision: $options['precision'] ?? null,
            scale: $options['scale'] ?? null,
            options: $options
        );

        $this->changes['modify_columns'][] = $columnDefinition;
        return $this;
    }

    /**
     * Drop a column from the table (interface compliance)
     *
     * @param string $column Column to drop
     * @return self For method chaining
     */
    public function dropColumn(string $column): self
    {
        $this->changes['drop_columns'][] = $column;
        return $this;
    }

    /**
     * Drop multiple columns from the table
     *
     * @param string ...$columns Column names to drop
     * @return self For method chaining
     */
    public function dropColumns(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->changes['drop_columns'][] = $column;
        }
        return $this;
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
        // For simplicity, we'll track this as a separate operation
        $this->changes['rename_columns'][$from] = $to;
        return $this;
    }

    /**
     * Change column type and properties
     *
     * @param string $name Column name
     * @param string $type New column type
     * @param array $options Column options
     * @return ColumnBuilderInterface For column definition
     */
    public function changeColumn(string $name, string $type, array $options = []): ColumnBuilderInterface
    {
        return new ColumnBuilder($this, $name, $type, $options);
    }

    // ===========================================
    // Convenience Column Type Methods
    // ===========================================

    /**
     * Add an ID column (auto-increment primary key)
     *
     * @param string $name Column name (default: 'id')
     * @return ColumnBuilderInterface For column definition
     */
    public function id(string $name = 'id'): ColumnBuilderInterface
    {
        return $this->newColumn($name)->bigInteger()->unsigned()->autoIncrement()->primary();
    }

    /**
     * Add a string/varchar column
     *
     * @param string $name Column name
     * @param int $length Maximum length
     * @return ColumnBuilderInterface For column definition
     */
    public function string(string $name, int $length = 255): ColumnBuilderInterface
    {
        return $this->newColumn($name)->string($length);
    }

    /**
     * Add a text column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function text(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->text();
    }

    /**
     * Add an integer column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function integer(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->integer();
    }

    /**
     * Add a big integer column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function bigInteger(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->bigInteger();
    }

    /**
     * Add a foreign ID column (unsigned big integer)
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function foreignId(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->bigInteger()->unsigned();
    }

    /**
     * Add a boolean column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function boolean(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->boolean();
    }

    /**
     * Add a decimal column
     *
     * @param string $name Column name
     * @param int $precision Total digits
     * @param int $scale Decimal places
     * @return ColumnBuilderInterface For column definition
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnBuilderInterface
    {
        return $this->newColumn($name)->decimal($precision, $scale);
    }

    /**
     * Add a timestamp column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function timestamp(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->timestamp();
    }

    /**
     * Add a JSON column
     *
     * @param string $name Column name
     * @return ColumnBuilderInterface For column definition
     */
    public function json(string $name): ColumnBuilderInterface
    {
        return $this->newColumn($name)->json();
    }

    /**
     * Add standard timestamps (created_at, updated_at)
     *
     * @return self For method chaining
     */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        return $this;
    }

    // ===========================================
    // Index Operations
    // ===========================================

    /**
     * Add an index to the table (Interface-compliant method)
     *
     * @param array $columns Column names for the index
     * @param string $name Index name
     * @param bool $unique Whether the index should be unique
     * @return self For method chaining
     */
    public function addIndex(array $columns, string $name, bool $unique = false): self
    {
        $type = $unique ? 'unique' : 'index';
        return $this->addIndexWithType($columns, $name, $type);
    }

    /**
     * Add an index to the table with specific type
     *
     * @param array|string $columns Column name(s) for the index
     * @param string|null $name Index name (auto-generated if null)
     * @param string $type Index type ('index', 'unique', 'fulltext')
     * @return self For method chaining
     */
    private function addIndexWithType($columns, ?string $name = null, string $type = 'index'): self
    {
        $columns = is_array($columns) ? $columns : [$columns];

        if (!$name) {
            $name = $this->generateIndexName($columns, $type);
        }

        $indexDefinition = new IndexDefinition(
            name: $name,
            columns: $columns,
            type: $type
        );

        $this->changes['add_indexes'][] = $indexDefinition;
        return $this;
    }

    /**
     * Add a unique index
     *
     * @param array|string $columns Column name(s)
     * @param string|null $name Index name
     * @return self For method chaining
     */
    public function unique($columns, ?string $name = null): self
    {
        return $this->addIndexWithType($columns, $name, 'unique');
    }

    /**
     * Add a regular index
     *
     * @param array|string $columns Column name(s)
     * @param string|null $name Index name
     * @return self For method chaining
     */
    public function index($columns, ?string $name = null): self
    {
        return $this->addIndexWithType($columns, $name, 'index');
    }

    /**
     * Drop an index from the table
     *
     * @param string $name Index name to drop
     * @return self For method chaining
     */
    public function dropIndex(string $name): self
    {
        $this->changes['drop_indexes'][] = $name;
        return $this;
    }

    /**
     * Drop multiple indexes from the table
     *
     * @param string ...$indexNames Index names to drop
     * @return self For method chaining
     */
    public function dropIndexes(string ...$indexNames): self
    {
        foreach ($indexNames as $indexName) {
            $this->changes['drop_indexes'][] = $indexName;
        }
        return $this;
    }


    // ===========================================
    // Foreign Key Operations
    // ===========================================

    /**
     * Add a foreign key constraint
     *
     * @param string $column Local column name
     * @return ForeignKeyBuilderInterface For foreign key definition
     */
    public function foreign(string $column): ForeignKeyBuilderInterface
    {
        return new ForeignKeyBuilder($this, $column);
    }

    /**
     * Drop a foreign key constraint
     *
     * @param string $constraintName Constraint name to drop
     * @return self For method chaining
     */
    public function dropForeignKey(string $constraintName): self
    {
        $this->changes['drop_foreign_keys'][] = $constraintName;
        return $this;
    }

    /**
     * Drop multiple foreign key constraints
     *
     * @param string ...$constraintNames Constraint names to drop
     * @return self For method chaining
     */
    public function dropForeignKeys(string ...$constraintNames): self
    {
        foreach ($constraintNames as $constraintName) {
            $this->changes['drop_foreign_keys'][] = $constraintName;
        }
        return $this;
    }

    /**
     * Add a foreign key constraint (Interface-compliant method)
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
    ): self {
        $foreignKey = new ForeignKeyDefinition(
            localColumn: $column,
            referencedTable: $referencedTable,
            referencedColumn: $referencedColumn,
            name: $constraintName ?? "fk_{$this->tableName}_{$column}",
            onDelete: $onDelete,
            onUpdate: $onUpdate
        );

        $this->changes['add_foreign_keys'][] = $foreignKey;
        return $this;
    }

    /**
     * Drop foreign key constraint by column name
     *
     * @param string $column Column name
     * @return self For method chaining
     */
    public function dropConstrainedForeignId(string $column): self
    {
        $constraintName = 'fk_' . $this->tableName . '_' . $column;
        return $this->dropForeignKey($constraintName);
    }

    // ===========================================
    // Table Operations
    // ===========================================

    /**
     * Rename the table
     *
     * @param string $name New table name
     * @return self For method chaining
     */
    public function rename(string $name): self
    {
        $this->changes['rename_table'] = $name;
        return $this;
    }

    /**
     * Add or update table comment
     *
     * @param string $comment Table comment
     * @return self For method chaining
     */
    public function comment(string $comment): self
    {
        $this->changes['comment'] = $comment;
        return $this;
    }

    // ===========================================
    // Execution and Completion
    // ===========================================

    /**
     * Execute all pending alterations
     *
     * @return bool True if all alterations succeeded
     * @throws \RuntimeException If any alteration fails
     */
    public function execute(): bool
    {
        if (empty(array_filter($this->changes))) {
            return true; // No changes to execute
        }

        // Generate SQL statements for the changes
        $statements = $this->sqlGenerator->alterTable($this->tableDefinition, $this->changes);

        // Execute each statement by adding to pending operations
        foreach ($statements as $statement) {
            $this->schemaBuilder->addPendingOperation($statement);
        }

        // Execute all pending operations
        $results = $this->schemaBuilder->execute();

        return !empty($results);
    }

    /**
     * Get all pending alterations
     *
     * @return array Array of pending alterations
     */
    public function getAlterations(): array
    {
        return $this->changes;
    }

    /**
     * Reset all pending alterations
     *
     * @return self For method chaining
     */
    public function reset(): self
    {
        $this->changes = [
            'add_columns' => [],
            'modify_columns' => [],
            'drop_columns' => [],
            'rename_columns' => [],
            'add_indexes' => [],
            'drop_indexes' => [],
            'add_foreign_keys' => [],
            'drop_foreign_keys' => []
        ];
        return $this;
    }

    /**
     * Execute the alterations and return the schema builder (legacy method)
     *
     * @return SchemaBuilderInterface For continued schema operations
     */
    public function executeAndReturn(): SchemaBuilderInterface
    {
        $this->execute();
        return $this->schemaBuilder;
    }

    // ===========================================
    // Internal Methods for Builders
    // ===========================================

    /**
     * Add a column definition to the changes (for ColumnBuilder)
     *
     * @param ColumnDefinition $column Column definition
     * @return void
     */
    public function addColumnDefinition(ColumnDefinition $column): void
    {
        $this->changes['add_columns'][] = $column;
    }

    /**
     * Add a modified column definition to the changes (for ColumnBuilder)
     *
     * @param ColumnDefinition $column Column definition
     * @return void
     */
    public function addModifiedColumnDefinition(ColumnDefinition $column): void
    {
        $this->changes['modify_columns'][] = $column;
    }

    /**
     * Add a foreign key definition to the changes (for ForeignKeyBuilder)
     *
     * @param ForeignKeyDefinition $foreignKey Foreign key definition
     * @return void
     */
    public function addForeignKeyDefinition(ForeignKeyDefinition $foreignKey): void
    {
        $this->changes['add_foreign_keys'][] = $foreignKey;
    }

    /**
     * Get the table name being altered (for ColumnBuilder and ForeignKeyBuilder)
     *
     * @return string Table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    // ===========================================
    // TableBuilderInterface Implementation
    // ===========================================

    /**
     * Set primary key (for TableBuilderInterface compatibility)
     *
     * @param array|string $columns Column(s) for primary key
     * @return self For method chaining
     */
    public function primary(array|string $columns): self
    {
        $columnArray = is_array($columns) ? $columns : [$columns];
        $this->changes['set_primary_key'] = $columnArray;
        return $this;
    }

    /**
     * Add a fulltext index (for TableBuilderInterface compatibility)
     *
     * @param array|string $columns Column(s) for fulltext index
     * @param string|null $name Index name (auto-generated if null)
     * @return self For method chaining
     */
    public function fulltext(array|string $columns, ?string $name = null): self
    {
        return $this->addIndexWithType($columns, $name, 'fulltext');
    }

    /**
     * Drop a unique constraint (compatible with both interfaces)
     *
     * @param array|string $columns Column name(s) or constraint name
     * @return self For method chaining
     */
    public function dropUnique($columns): self
    {
        if (is_string($columns)) {
            $indexName = $columns;
        } else {
            $indexName = $this->generateIndexName((array)$columns, 'unique');
        }

        return $this->dropIndex($indexName);
    }

    /**
     * Drop primary key (for TableBuilderInterface compatibility)
     *
     * @return self For method chaining
     */
    public function dropPrimary(): self
    {
        $this->changes['drop_primary_key'] = true;
        return $this;
    }

    /**
     * Drop a foreign key constraint (for TableBuilderInterface compatibility)
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function dropForeign(string $name): self
    {
        return $this->dropForeignKey($name);
    }

    /**
     * Set table engine (for TableBuilderInterface compatibility)
     *
     * @param string $engine Engine name
     * @return self For method chaining
     */
    public function engine(string $engine): self
    {
        $this->changes['engine'] = $engine;
        return $this;
    }

    /**
     * Set table charset (for TableBuilderInterface compatibility)
     *
     * @param string $charset Character set
     * @return self For method chaining
     */
    public function charset(string $charset): self
    {
        $this->changes['charset'] = $charset;
        return $this;
    }

    /**
     * Set table collation (for TableBuilderInterface compatibility)
     *
     * @param string $collation Collation name
     * @return self For method chaining
     */
    public function collation(string $collation): self
    {
        $this->changes['collation'] = $collation;
        return $this;
    }

    /**
     * Create the table (not applicable for alter operations)
     *
     * @return SchemaBuilderInterface
     * @throws \LogicException Always throws since this is for alterations
     */
    public function create(): SchemaBuilderInterface
    {
        throw new \LogicException('Cannot create table from alter operation. Use execute() instead.');
    }

    /**
     * Drop the table (not applicable for alter operations)
     *
     * @return SchemaBuilderInterface
     * @throws \LogicException Always throws since this is for alterations
     */
    public function drop(): SchemaBuilderInterface
    {
        throw new \LogicException('Cannot drop table from alter operation. Use schema->dropTable() instead.');
    }

    /**
     * Drop the table if exists (not applicable for alter operations)
     *
     * @return SchemaBuilderInterface
     * @throws \LogicException Always throws since this is for alterations
     */
    public function dropIfExists(): SchemaBuilderInterface
    {
        throw new \LogicException('Cannot drop table from alter operation. Use schema->dropTable() instead.');
    }

    /**
     * Check if this is a table creation (always false for alter operations)
     *
     * @return bool Always false
     */
    public function isCreating(): bool
    {
        return false;
    }

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get all pending changes
     *
     * @return array Array of changes to be applied
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Check if there are any pending changes
     *
     * @return bool True if there are changes to apply
     */
    public function hasChanges(): bool
    {
        return !empty(array_filter($this->changes));
    }

    /**
     * Get the current table definition
     *
     * @return TableDefinition Current table definition
     */
    public function getTableDefinition(): TableDefinition
    {
        return $this->tableDefinition;
    }

    // ===========================================
    // Private Helper Methods
    // ===========================================

    /**
     * Find an existing column definition
     *
     * @param string $name Column name
     * @return ColumnDefinition|null Column definition if found
     */
    private function findColumnDefinition(string $name): ?ColumnDefinition
    {
        foreach ($this->tableDefinition->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Generate index name from columns and type
     *
     * @param array $columns Column names
     * @param string $type Index type
     * @return string Generated index name
     */
    private function generateIndexName(array $columns, string $type): string
    {
        $prefix = match ($type) {
            'unique' => 'unique',
            'fulltext' => 'fulltext',
            default => 'idx'
        };

        $columnPart = implode('_', $columns);
        return "{$prefix}_{$this->tableName}_{$columnPart}";
    }
}
