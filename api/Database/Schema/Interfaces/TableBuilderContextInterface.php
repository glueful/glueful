<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Interfaces;

use Glueful\Database\Schema\DTOs\ColumnDefinition;
use Glueful\Database\Schema\DTOs\ForeignKeyDefinition;

/**
 * Table Builder Context Interface
 *
 * Shared interface for classes that can be used as context for ColumnBuilder
 * and ForeignKeyBuilder. This includes both TableBuilder (for table creation)
 * and AlterTableBuilder (for table alterations).
 *
 * This interface provides the minimal methods needed by column and foreign key
 * builders to function properly, without requiring full TableBuilder interface
 * compliance.
 */
interface TableBuilderContextInterface
{
    /**
     * Add a column definition to the table/changes
     *
     * @param ColumnDefinition $column Column definition
     * @return void
     */
    public function addColumnDefinition(ColumnDefinition $column): void;

    /**
     * Add a foreign key definition to the table/changes
     *
     * @param ForeignKeyDefinition $foreignKey Foreign key definition
     * @return void
     */
    public function addForeignKeyDefinition(ForeignKeyDefinition $foreignKey): void;

    /**
     * Get the table name
     *
     * @return string Table name
     */
    public function getTableName(): string;

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
     * Execute the table operations
     *
     * @return mixed Results of executed operations (SchemaBuilderInterface for chaining or array for results)
     * @throws \RuntimeException If execution fails
     */
    public function execute(): mixed;
}
