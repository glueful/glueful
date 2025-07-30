<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

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
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Social Accounts Table
        $schema->createTable('social_accounts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('provider', 50);
            $table->string('social_id', 255);
            $table->text('profile_data')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('user_uuid');
            $table->unique(['provider', 'social_id']); // Composite unique constraint

            // Add foreign key
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migration
     *
     * Removes the social_accounts table:
     * - Respects foreign key constraints
     * - Completely cleans up all data
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('social_accounts');
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
