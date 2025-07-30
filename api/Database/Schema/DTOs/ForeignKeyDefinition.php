<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\DTOs;

/**
 * Foreign Key Definition Data Transfer Object
 *
 * Represents a foreign key constraint definition including local column,
 * referenced table/column, and referential actions. Used to pass foreign
 * key structure between schema builders and SQL generators.
 *
 * Features:
 * - Immutable data structure
 * - Type-safe properties
 * - Referential action validation
 * - Constraint naming
 * - Database-specific options
 *
 * Example usage:
 * ```php
 * $foreignKey = new ForeignKeyDefinition(
 *     localColumn: 'user_id',
 *     referencedTable: 'users',
 *     referencedColumn: 'id',
 *     name: 'fk_posts_user_id',
 *     onDelete: 'CASCADE',
 *     onUpdate: 'RESTRICT'
 * );
 * ```
 */
readonly class ForeignKeyDefinition
{
    /**
     * Create a new foreign key definition
     *
     * @param string $localColumn Local column name
     * @param string $referencedTable Referenced table name
     * @param string $referencedColumn Referenced column name
     * @param string $name Constraint name
     * @param string|null $onDelete ON DELETE action (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * @param string|null $onUpdate ON UPDATE action (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * @param bool $deferrable Whether constraint is deferrable (PostgreSQL)
     * @param bool $initiallyDeferred Whether constraint is initially deferred (PostgreSQL)
     * @param array<string, mixed> $options Additional constraint options
     */
    public function __construct(
        public string $localColumn,
        public string $referencedTable,
        public string $referencedColumn,
        public string $name,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        public bool $deferrable = false,
        public bool $initiallyDeferred = false,
        public array $options = []
    ) {
        $this->validateColumns();
        $this->validateActions();
        $this->validateOptions();
    }

    /**
     * Check if foreign key has ON DELETE action
     *
     * @return bool True if ON DELETE action is specified
     */
    public function hasOnDeleteAction(): bool
    {
        return $this->onDelete !== null;
    }

    /**
     * Check if foreign key has ON UPDATE action
     *
     * @return bool True if ON UPDATE action is specified
     */
    public function hasOnUpdateAction(): bool
    {
        return $this->onUpdate !== null;
    }

    /**
     * Get ON DELETE action or default
     *
     * @param string $default Default action if none specified
     * @return string ON DELETE action
     */
    public function getOnDeleteAction(string $default = 'RESTRICT'): string
    {
        return $this->onDelete ?? $default;
    }

    /**
     * Get ON UPDATE action or default
     *
     * @param string $default Default action if none specified
     * @return string ON UPDATE action
     */
    public function getOnUpdateAction(string $default = 'RESTRICT'): string
    {
        return $this->onUpdate ?? $default;
    }

    /**
     * Check if constraint will cascade deletes
     *
     * @return bool True if ON DELETE CASCADE
     */
    public function cascadesOnDelete(): bool
    {
        return strtoupper($this->onDelete ?? '') === 'CASCADE';
    }

    /**
     * Check if constraint will cascade updates
     *
     * @return bool True if ON UPDATE CASCADE
     */
    public function cascadesOnUpdate(): bool
    {
        return strtoupper($this->onUpdate ?? '') === 'CASCADE';
    }

    /**
     * Check if constraint will set null on delete
     *
     * @return bool True if ON DELETE SET NULL
     */
    public function setsNullOnDelete(): bool
    {
        return strtoupper($this->onDelete ?? '') === 'SET NULL';
    }

    /**
     * Check if constraint will set null on update
     *
     * @return bool True if ON UPDATE SET NULL
     */
    public function setsNullOnUpdate(): bool
    {
        return strtoupper($this->onUpdate ?? '') === 'SET NULL';
    }

    /**
     * Check if constraint restricts deletes
     *
     * @return bool True if ON DELETE RESTRICT
     */
    public function restrictsOnDelete(): bool
    {
        return strtoupper($this->onDelete ?? 'RESTRICT') === 'RESTRICT';
    }

    /**
     * Check if constraint restricts updates
     *
     * @return bool True if ON UPDATE RESTRICT
     */
    public function restrictsOnUpdate(): bool
    {
        return strtoupper($this->onUpdate ?? 'RESTRICT') === 'RESTRICT';
    }

    /**
     * Get full reference specification
     *
     * @return string Reference specification (table.column)
     */
    public function getReference(): string
    {
        return "{$this->referencedTable}.{$this->referencedColumn}";
    }

    /**
     * Check if constraint is deferrable (PostgreSQL)
     *
     * @return bool True if constraint is deferrable
     */
    public function isDeferrable(): bool
    {
        return $this->deferrable;
    }

    /**
     * Check if constraint is initially deferred (PostgreSQL)
     *
     * @return bool True if constraint is initially deferred
     */
    public function isInitiallyDeferred(): bool
    {
        return $this->initiallyDeferred;
    }

    /**
     * Create a copy of this foreign key definition with modifications
     *
     * @param array $changes Changes to apply
     * @return self New foreign key definition instance
     */
    public function with(array $changes): self
    {
        return new self(
            localColumn: $changes['localColumn'] ?? $this->localColumn,
            referencedTable: $changes['referencedTable'] ?? $this->referencedTable,
            referencedColumn: $changes['referencedColumn'] ?? $this->referencedColumn,
            name: $changes['name'] ?? $this->name,
            onDelete: $changes['onDelete'] ?? $this->onDelete,
            onUpdate: $changes['onUpdate'] ?? $this->onUpdate,
            deferrable: $changes['deferrable'] ?? $this->deferrable,
            initiallyDeferred: $changes['initiallyDeferred'] ?? $this->initiallyDeferred,
            options: $changes['options'] ?? $this->options
        );
    }

    /**
     * Validate column specifications
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateColumns(): void
    {
        if (empty(trim($this->localColumn))) {
            throw new \InvalidArgumentException('Local column name cannot be empty');
        }

        if (empty(trim($this->referencedTable))) {
            throw new \InvalidArgumentException('Referenced table name cannot be empty');
        }

        if (empty(trim($this->referencedColumn))) {
            throw new \InvalidArgumentException('Referenced column name cannot be empty');
        }

        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Foreign key constraint name cannot be empty');
        }
    }

    /**
     * Validate referential actions
     *
     * @throws \InvalidArgumentException If actions are invalid
     */
    private function validateActions(): void
    {
        $validActions = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'];

        if ($this->onDelete !== null) {
            $onDelete = strtoupper($this->onDelete);
            if (!in_array($onDelete, $validActions)) {
                throw new \InvalidArgumentException(
                    "Invalid ON DELETE action: {$this->onDelete}. Valid actions: " .
                    implode(', ', $validActions)
                );
            }
        }

        if ($this->onUpdate !== null) {
            $onUpdate = strtoupper($this->onUpdate);
            if (!in_array($onUpdate, $validActions)) {
                throw new \InvalidArgumentException(
                    "Invalid ON UPDATE action: {$this->onUpdate}. Valid actions: " .
                    implode(', ', $validActions)
                );
            }
        }
    }

    /**
     * Validate constraint options
     *
     * @throws \InvalidArgumentException If options are invalid
     */
    private function validateOptions(): void
    {
        // Initially deferred requires deferrable
        if ($this->initiallyDeferred && !$this->deferrable) {
            throw new \InvalidArgumentException(
                'Initially deferred constraint must also be deferrable'
            );
        }

        // Validate constraint name format (basic check)
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $this->name)) {
            throw new \InvalidArgumentException(
                'Foreign key constraint name must start with letter and contain only letters, numbers, and underscores'
            );
        }
    }
}
