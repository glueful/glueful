<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Initial Database Schema Migration
 *
 * Creates core system tables and relationships:
 * - Users and authentication
 * - Roles
 * - File storage and blobs
 * - User profiles
 *
 * Database Design:
 * - Follows ACID principles
 * - Implements proper indexing
 * - Uses foreign key constraints
 * - Supports soft deletes
 * - Handles timestamps
 *
 * Security Features:
 * - Password hashing
 * - Token management
 *
 * @package Glueful\Database\Migrations
 */
class CreateInitialSchema implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates all required database tables with:
     * - Primary and foreign keys
     * - Indexes for optimization
     * - Data integrity constraints
     * - Timestamp tracking
     *
     * Tables created:
     * - users: User accounts and authentication
     * - profiles: User profile information
     * - blobs: File storage metadata
     * - sessions: Authentication sessions
     *
     * @param SchemaBuilderInterface $schema Database schema builder
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Users Table
        $schema->createTable('users', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('username', 255);
            $table->string('email', 255);
            $table->string('password', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 40)->nullable();
            $table->string('x_forwarded_for_ip_address', 40)->nullable();
            $table->timestamp('last_login_date')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->unique('username');
            $table->unique('email');
        });

        // Create Blobs Table
        $schema->createTable('blobs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('mime_type', 127);
            $table->bigInteger('size');
            $table->string('url', 2048);
            $table->string('storage_type', 20)->default('local');
            $table->string('status', 20)->default('active');
            $table->string('created_by', 12);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('created_by');

            // Add foreign key
            $table->foreign('created_by')
                ->references('uuid')
                ->on('users');
        });

        // Create Profiles Table
        $schema->createTable('profiles', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('photo_uuid', 12)->nullable();
            $table->string('photo_url', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('user_uuid');
            $table->index('photo_uuid');

            // Add foreign keys
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('photo_uuid')
                ->references('uuid')
                ->on('blobs')
                ->nullOnDelete();
        });

        // Create Auth Sessions Table
        $schema->createTable('auth_sessions', function ($table) {
            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->text('token_fingerprint');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_token_refresh')->nullable();
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at');
            $table->string('status', 20)->default('active');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->text('provider')->default('jwt');
            $table->boolean('remember_me')->default(false);

            // Add indexes
            $table->unique('uuid');
            $table->index('user_uuid');
            $table->index('status');

            // Add foreign key
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users');
        });
    }

    /**
     * Reverse the migration
     *
     * Removes all created tables in correct order:
     * - Respects foreign key constraints
     * - Handles dependent tables
     * - Cleans up completely
     *
     * Drop order:
     * 1. Dependent tables first (sessions)
     * 2. Junction tables (role assignments)
     * 3. Feature tables (blobs, profiles)
     * 4. Core tables (roles, users)
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('auth_sessions');
        $schema->dropTableIfExists('profiles');
        $schema->dropTableIfExists('blobs');
        $schema->dropTableIfExists('users');
    }

    /**
     * Get migration description
     *
     * Provides human-readable description of:
     * - Migration purpose
     * - Major changes
     * - System impacts
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates initial database schema including users, profiles, and core system tables';
    }
}
