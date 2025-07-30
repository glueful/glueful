<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

/**
 * Foreign Key Builder Interface
 *
 * Provides fluent interface for defining foreign key constraints with
 * referential actions and constraint naming.
 *
 * Features:
 * - Reference table and column specification
 * - ON DELETE actions (CASCADE, SET NULL, RESTRICT, NO ACTION)
 * - ON UPDATE actions (CASCADE, SET NULL, RESTRICT, NO ACTION)
 * - Custom constraint naming
 * - Validation of foreign key relationships
 *
 * Example usage:
 * ```php
 * $table->foreign('user_id')
 *     ->references('id')
 *     ->on('users')
 *     ->cascadeOnDelete()
 *     ->restrictOnUpdate()
 *     ->name('fk_posts_user_id');
 * ```
 */
interface ForeignKeyBuilderInterface
{
    // ===========================================
    // Reference Definition
    // ===========================================

    /**
     * Specify the referenced column
     *
     * @param string $column Referenced column name
     * @return self For method chaining
     */
    public function references(string $column): self;

    /**
     * Specify the referenced table
     *
     * @param string $table Referenced table name
     * @return self For method chaining
     */
    public function on(string $table): self;

    // ===========================================
    // ON DELETE Actions
    // ===========================================

    /**
     * Set ON DELETE CASCADE
     * Automatically delete related records when parent is deleted
     *
     * @return self For method chaining
     */
    public function cascadeOnDelete(): self;

    /**
     * Set ON DELETE SET NULL
     * Set foreign key to NULL when parent is deleted
     *
     * @return self For method chaining
     */
    public function nullOnDelete(): self;

    /**
     * Set ON DELETE RESTRICT
     * Prevent parent deletion if child records exist
     *
     * @return self For method chaining
     */
    public function restrictOnDelete(): self;

    /**
     * Set ON DELETE NO ACTION
     * Same as RESTRICT but check is deferred
     *
     * @return self For method chaining
     */
    public function noActionOnDelete(): self;

    // ===========================================
    // ON UPDATE Actions
    // ===========================================

    /**
     * Set ON UPDATE CASCADE
     * Automatically update foreign key when parent key changes
     *
     * @return self For method chaining
     */
    public function cascadeOnUpdate(): self;

    /**
     * Set ON UPDATE SET NULL
     * Set foreign key to NULL when parent key changes
     *
     * @return self For method chaining
     */
    public function nullOnUpdate(): self;

    /**
     * Set ON UPDATE RESTRICT
     * Prevent parent key change if child records exist
     *
     * @return self For method chaining
     */
    public function restrictOnUpdate(): self;

    /**
     * Set ON UPDATE NO ACTION
     * Same as RESTRICT but check is deferred
     *
     * @return self For method chaining
     */
    public function noActionOnUpdate(): self;

    // ===========================================
    // Constraint Options
    // ===========================================

    /**
     * Set custom constraint name
     *
     * @param string $name Constraint name
     * @return self For method chaining
     */
    public function name(string $name): self;

    /**
     * Make constraint deferrable (PostgreSQL)
     *
     * @param bool $deferrable Whether constraint is deferrable
     * @return self For method chaining
     */
    public function deferrable(bool $deferrable = true): self;

    /**
     * Set constraint as initially deferred (PostgreSQL)
     *
     * @param bool $deferred Whether constraint is initially deferred
     * @return self For method chaining
     */
    public function initiallyDeferred(bool $deferred = true): self;

    // ===========================================
    // Completion
    // ===========================================

    /**
     * Complete foreign key definition and return to table builder
     *
     * @return TableBuilderContextInterface For continued table building
     */
    public function end(): TableBuilderContextInterface;

    /**
     * Execute foreign key operations (typically calls parent table execute)
     *
     * @return mixed Results of executed operations (SchemaBuilderInterface for chaining or array for results)
     * @throws \RuntimeException If execution fails
     */
    public function execute(): mixed;

    // ===========================================
    // Information Methods
    // ===========================================

    /**
     * Get local column name
     *
     * @return string Local column name
     */
    public function getLocalColumn(): string;

    /**
     * Get referenced table name
     *
     * @return string|null Referenced table name
     */
    public function getReferencedTable(): ?string;

    /**
     * Get referenced column name
     *
     * @return string|null Referenced column name
     */
    public function getReferencedColumn(): ?string;

    /**
     * Get ON DELETE action
     *
     * @return string|null ON DELETE action
     */
    public function getOnDeleteAction(): ?string;

    /**
     * Get ON UPDATE action
     *
     * @return string|null ON UPDATE action
     */
    public function getOnUpdateAction(): ?string;

    /**
     * Get constraint name
     *
     * @return string|null Constraint name
     */
    public function getConstraintName(): ?string;
}
