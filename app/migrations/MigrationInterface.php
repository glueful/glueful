<?php

declare(strict_types=1);

namespace Glueful\App\Database\Migrations;

use Glueful\App\Database\Schemas\SchemaManager;

/**
 * Migration Interface
 * 
 * Defines the contract for database migrations.
 * All migration classes must implement:
 * - up() for applying changes
 * - down() for reverting changes
 * - getDescription() for migration details
 */
interface MigrationInterface
{
    /**
     * Apply migration changes
     * 
     * Performs forward migration:
     * - Creating/modifying tables
     * - Adding/updating data
     * - Adding constraints/indexes
     * 
     * @param SchemaManager $schema Database schema manager
     * @throws \PDOException If migration fails
     */
    public function up(SchemaManager $schema): void;

    /**
     * Revert migration changes
     * 
     * Rolls back changes made by up():
     * - Dropping created tables
     * - Removing added data
     * - Removing constraints/indexes
     * 
     * @param SchemaManager $schema Database schema manager
     * @throws \PDOException If rollback fails
     */
    public function down(SchemaManager $schema): void;

    /**
     * Get migration description
     * 
     * Returns human-readable description of changes.
     * Used for logging and documentation.
     * 
     * @return string Migration description
     */
    public function getDescription(): string;
}
