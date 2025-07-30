<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create Locks Table Migration
 *
 * Creates the locks table for Symfony Lock component integration.
 * This table stores distributed locks for preventing race conditions
 * in queue workers, scheduled tasks, and other concurrent processes.
 *
 * Table structure:
 * - key_id: The unique lock identifier
 * - token: The lock ownership token
 * - expiration: Unix timestamp when the lock expires
 *
 * @package Glueful\Database\Migrations
 */
class CreateLocksTable implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates the locks table with:
     * - Primary key on key_id for unique lock identification
     * - Index on expiration for cleanup operations
     * - Index on token for ownership verification
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Locks Table with auto-execute
        $schema->createTable('locks', function ($table) {
            $table->string('key_id', 255)->primary();
            $table->string('token', 255);
            $table->integer('expiration')->unsigned();

            // Add indexes
            $table->index('expiration');
            $table->index('token');
        });
    }

    /**
     * Reverse the migration
     *
     * Drops the locks table.
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('locks');
    }

    /**
     * Get migration description
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates locks table for Symfony Lock component integration';
    }
}
