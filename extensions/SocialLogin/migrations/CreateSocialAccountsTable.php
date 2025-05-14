<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Social Accounts Migration
 *
 * Creates the social_accounts table for storing linked social login accounts.
 *
 * Database Design:
 * - Implements proper indexing with unique constraints
 * - Uses foreign key constraints for user relationships
 * - Handles timestamps for creation and updates
 *
 * Security Features:
 * - Links to existing user accounts
 * - Stores provider-specific authentication data
 * - Supports multiple social accounts per user
 *
 * @package Glueful\Extensions\SocialLogin\Migrations
 */
class CreateSocialAccountsTable implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates the social_accounts table with:
     * - Primary and foreign keys
     * - Unique constraints
     * - Timestamp tracking
     *
     * Table created:
     * - social_accounts: Social login provider accounts linked to users
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Social Accounts Table
        $schema->createTable('social_accounts', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'provider' => 'VARCHAR(50) NOT NULL',
            'social_id' => 'VARCHAR(255) NOT NULL',
            'profile_data' => 'TEXT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'UNIQUE', 'column' => 'provider'],
            ['type' => 'UNIQUE', 'column' => 'social_id'],
        ])->addForeignKey([
            [
                'column' => 'user_uuid',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'CASCADE'
            ]
        ]);
    }

    /**
     * Reverse the migration
     *
     * Removes the social_accounts table:
     * - Respects foreign key constraints
     * - Completely cleans up all data
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('social_accounts');
    }

    /**
     * Get migration description
     *
     * Provides human-readable description of:
     * - Migration purpose
     * - System impacts
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return "Create social_accounts table for social login integration";
    }
}
