<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

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
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        $schema->createTable('locks', [
            'key_id' => 'VARCHAR(255) PRIMARY KEY',
            'token' => 'VARCHAR(255) NOT NULL',
            'expiration' => 'INT UNSIGNED NOT NULL'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'expiration'],
            ['type' => 'INDEX', 'column' => 'token']
        ]);
    }

    /**
     * Reverse the migration
     *
     * Drops the locks table.
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('locks');
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
