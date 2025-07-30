<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Builders;

use Glueful\Database\Schema\Interfaces\ForeignKeyBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderContextInterface;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * Concrete Foreign Key Builder Implementation
 *
 * Provides fluent interface for defining foreign key constraints with
 * referential actions and constraint naming. Builds up the foreign key
 * definition through method chaining.
 *
 * Features:
 * - Fluent method chaining for all foreign key properties
 * - Referential action specification (CASCADE, SET NULL, etc.)
 * - Custom constraint naming
 * - Database-specific options (deferrable, initially deferred)
 * - Validation and error checking
 *
 * Example usage:
 * ```php
 * $table->foreign('user_id')
 *     ->references('id')
 *     ->on('users')
 *     ->cascadeOnDelete()
 *     ->restrictOnUpdate()
 *     ->name('fk_posts_user_id')
 *     ->end();
 * ```
 */
class ForeignKeyBuilder implements ForeignKeyBuilderInterface
{
    /** @var TableBuilderContextInterface Parent table builder */
    private TableBuilderContextInterface $tableBuilder;

    /** @var string Local column name */
    private string $localColumn;

    /** @var string|null Referenced table name */
    private ?string $referencedTable = null;

    /** @var string|null Referenced column name */
    private ?string $referencedColumn = null;

    /** @var string|null ON DELETE action */
    private ?string $onDeleteAction = null;

    /** @var string|null ON UPDATE action */
    private ?string $onUpdateAction = null;

    /** @var string|null Custom constraint name */
    private ?string $constraintName = null;

    /** @var bool Whether constraint is deferrable (PostgreSQL) */
    private bool $deferrable = false;

    /** @var bool Whether constraint is initially deferred (PostgreSQL) */
    private bool $initiallyDeferred = false;

    /** @var array Additional options */
    private array $options = [];

    /** @var bool Whether foreign key has been finalized */
    private bool $finalized = false;

    /**
     * Create a new foreign key builder
     *
     * @param TableBuilderContextInterface $tableBuilder Parent table builder
     * @param string $localColumn Local column name
     */
    public function __construct(TableBuilderContextInterface $tableBuilder, string $localColumn)
    {
        $this->tableBuilder = $tableBuilder;
        $this->localColumn = $localColumn;
    }

    /**
     * Auto-finalize foreign key when object is destroyed
     *
     * This ensures foreign keys are properly registered even if end() is not called
     */
    public function __destruct()
    {
        if (!$this->finalized) {
            $this->finalizeForeignKey();
        }
    }

    // ===========================================
    // Reference Definition
    // ===========================================

    /**
     * Specify the referenced column
     *
     * @param string $column Referenced column name
     * @return self For method chaining
     */
    public function references(string $column): self
    {
        $this->referencedColumn = $column;
        return $this;
    }

    /**
     * Specify the referenced table
     *
     * @param string $table Referenced table name
     * @return self For method chaining
     */
    public function on(string $table): self
    {
        $this->referencedTable = $table;
        return $this;
    }

    // ===========================================
    // ON DELETE Actions
    // ===========================================

    /**
     * Set ON DELETE CASCADE
     * Automatically delete related records when parent is deleted
     *
     * @return self For method chaining
     */
    public function cascadeOnDelete(): self
    {
        $this->onDeleteAction = 'CASCADE';
        return $this;
    }

    /**
     * Set ON DELETE SET NULL
     * Set foreign key to NULL when parent is deleted
     *
     * @return self For method chaining
     */
    public function nullOnDelete(): self
    {
        $this->onDeleteAction = 'SET NULL';
        return $this;
    }

    /**
     * Set ON DELETE RESTRICT
     * Prevent parent deletion if child records exist
     *
     * @return self For method chaining
     */
    public function restrictOnDelete(): self
    {
        $this->onDeleteAction = 'RESTRICT';
        return $this;
    }

    /**
     * Set ON DELETE NO ACTION
     * Same as RESTRICT but check is deferred
     *
     * @return self For method chaining
     */
    public function noActionOnDelete(): self
    {
        $this->onDeleteAction = 'NO ACTION';
        return $this;
    }

    // ===========================================
    // ON UPDATE Actions
    // ===========================================

    /**
     * Set ON UPDATE CASCADE
     * Automatically update foreign key when parent key changes
     *
     * @return self For method chaining
     */
    public function cascadeOnUpdate(): self
    {
        $this->onUpdateAction = 'CASCADE';
        return $this;
    }

    /**
     * Set ON UPDATE SET NULL
     * Set foreign key to NULL when parent key changes
     *
     * @return self For method chaining
     */
    public function nullOnUpdate(): self
    {
        $this->onUpdateAction = 'SET NULL';
        return $this;
    }

    /**
     * Set ON UPDATE RESTRICT
     * Prevent parent key change if child records exist
     *
     * @return self For method chaining
     */
    public function restrictOnUpdate(): self
    {
        $this->onUpdateAction = 'RESTRICT';
        return $this;
    }

    /**
     * Set ON UPDATE NO ACTION
     * Same as RESTRICT but check is deferred
     *
     * @return self For method chaining
     */
    public function noActionOnUpdate(): self
    {
        $this->onUpdateAction = 'NO ACTION';
        return $this;
    }

    // ===========================================
    // Constraint Options
    // ===========================================

    /**
     * Set custom constraint name
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function name(string $name): self
    {
        $this->constraintName = $name;
        return $this;
    }

    /**
     * Make constraint deferrable (PostgreSQL)
     *
     * @param bool $deferrable Whether constraint is deferrable
     * @return self For method chaining
     */
    public function deferrable(bool $deferrable = true): self
    {
        $this->deferrable = $deferrable;
        return $this;
    }

    /**
     * Set constraint as initially deferred (PostgreSQL)
     *
     * @param bool $deferred Whether constraint is initially deferred
     * @return self For method chaining
     */
    public function initiallyDeferred(bool $deferred = true): self
    {
        $this->initiallyDeferred = $deferred;
        return $this;
    }

    // ===========================================
    // Completion
    // ===========================================

    /**
     * Complete foreign key definition and return to table builder
     *
     * @return TableBuilderContextInterface For continued table building
     */
    public function end(): TableBuilderContextInterface
    {
        $this->finalizeForeignKey();
        return $this->tableBuilder;
    }

    /**
     * Finalize the foreign key definition by adding it to the table builder
     *
     * @return void
     */
    private function finalizeForeignKey(): void
    {
        if ($this->finalized) {
            return;
        }

        // Set defaults if not specified
        $referencedTable = $this->referencedTable ?? $this->guessReferencedTable();
        $referencedColumn = $this->referencedColumn ?? 'id';
        $constraintName = $this->constraintName ?? $this->generateConstraintName($referencedTable);

        // Create foreign key definition
        $foreignKeyDefinition = new ForeignKeyDefinition(
            localColumn: $this->localColumn,
            referencedTable: $referencedTable,
            referencedColumn: $referencedColumn,
            name: $constraintName,
            onDelete: $this->onDeleteAction,
            onUpdate: $this->onUpdateAction,
            deferrable: $this->deferrable,
            initiallyDeferred: $this->initiallyDeferred,
            options: $this->options
        );

        // Add to table builder
        $this->tableBuilder->addForeignKeyDefinition($foreignKeyDefinition);
        $this->finalized = true;
    }

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get local column name
     *
     * @return string Local column name
     */
    public function getLocalColumn(): string
    {
        return $this->localColumn;
    }

    /**
     * Get referenced table name
     *
     * @return string|null Referenced table name
     */
    public function getReferencedTable(): ?string
    {
        return $this->referencedTable;
    }

    /**
     * Get referenced column name
     *
     * @return string|null Referenced column name
     */
    public function getReferencedColumn(): ?string
    {
        return $this->referencedColumn;
    }

    /**
     * Get ON DELETE action
     *
     * @return string|null ON DELETE action
     */
    public function getOnDeleteAction(): ?string
    {
        return $this->onDeleteAction;
    }

    /**
     * Get ON UPDATE action
     *
     * @return string|null ON UPDATE action
     */
    public function getOnUpdateAction(): ?string
    {
        return $this->onUpdateAction;
    }

    /**
     * Get constraint name
     *
     * @return string|null Constraint name
     */
    public function getConstraintName(): ?string
    {
        return $this->constraintName;
    }

    // ===========================================
    // Private Helper Methods
    // ===========================================

    /**
     * Guess referenced table from local column name
     *
     * @return string Guessed table name
     */
    private function guessReferencedTable(): string
    {
        // Remove '_id' suffix and pluralize
        $tableName = str_replace('_id', '', $this->localColumn);

        // Simple pluralization (same logic as ColumnBuilder)
        if (str_ends_with($tableName, 'y')) {
            return substr($tableName, 0, -1) . 'ies';
        }
        if (str_ends_with($tableName, 's') || str_ends_with($tableName, 'sh') || str_ends_with($tableName, 'ch')) {
            return $tableName . 'es';
        }

        return $tableName . 's';
    }

    /**
     * Generate constraint name
     *
     * @param string $referencedTable Referenced table name
     * @return string Generated constraint name
     */
    private function generateConstraintName(string $referencedTable): string
    {
        return 'fk_' . $this->tableBuilder->getTableName() . '_' . $this->localColumn . '_' . $referencedTable;
    }

    /**
     * Execute foreign key operations (delegates to parent table builder)
     *
     * @return mixed Results of executed operations
     * @throws \RuntimeException If execution fails
     */
    public function execute(): mixed
    {
        return $this->tableBuilder->execute();
    }
}
