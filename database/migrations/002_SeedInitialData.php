<?php

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Initial System Data Seeder
 *
 * Note: Role and permission seeding is now handled by the RBAC extension.
 * This migration is kept for compatibility but performs no operations.
 *
 * @package Glueful\Database\Migrations
 */
class SeedInitialData implements MigrationInterface
{
    /**
     * Execute initial data seeding
     *
     * Note: Role and permission management is now handled by the RBAC extension.
     * This migration is a no-op to maintain migration history compatibility.
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Role and permission seeding is now handled by the RBAC extension
        // This migration is kept for compatibility but performs no operations
    }

    /**
     * Revert seeded data
     *
     * Note: Role and permission management is now handled by the RBAC extension.
     * This migration is a no-op to maintain migration history compatibility.
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        // Role and permission management is now handled by the RBAC extension
        // This migration is kept for compatibility but performs no operations
    }

    /**
     * Get seeder description
     *
     * @return string Human-readable description
     */
    public function getDescription(): string
    {
        return 'Legacy data seeder (now handled by RBAC extension)';
    }
}
