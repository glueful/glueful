<?php

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Initial Database Schema Migration
 * 
 * Creates core system tables and relationships:
 * - Users and authentication
 * - Roles and permissions
 * - File storage and blobs
 * - User profiles
 * - Audit logging
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
 * - Permission tracking
 * - Activity logging
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
     * - roles: Role definitions and hierarchy
     * - role_permissions, user_permissions: Access control rules
     * - profiles: User profile information
     * - blobs: File storage metadata
     * - sessions: Authentication sessions
     * - logs: System activity tracking
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Users Table
        $schema->createTable('users', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'username' => 'VARCHAR(255) NOT NULL',
            'email' => 'VARCHAR(255) NOT NULL',
            'password' => 'VARCHAR(100)',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'user_agent' => 'VARCHAR(512)',
            'ip_address' => 'VARCHAR(40)',
            'x_forwarded_for_ip_address' => 'VARCHAR(40)',
            'last_login_date' => 'TIMESTAMP',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'deleted_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'UNIQUE', 'column' => 'username'],
            ['type' => 'UNIQUE', 'column' => 'email']
        ]);

        // Create Roles Table
        $schema->createTable('roles', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT NOT NULL',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'deleted_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'UNIQUE', 'column' => 'name']
        ]);

        // Create Role Permissions Table
        $schema->createTable('role_permissions', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'role_uuid' => 'CHAR(12) NOT NULL',
            'model' => 'VARCHAR(255) NOT NULL',
            'permissions' => 'VARCHAR(10) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'role_uuid']
        ])->addForeignKey([
            [
                'column' => 'role_uuid',
                'references' => 'uuid',
                'on' => 'roles',
            ]
        ]);

        // Create User Permissions Table
        $schema->createTable('user_permissions', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'model' => 'VARCHAR(255) NOT NULL',
            'permissions' => 'VARCHAR(10) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid']
        ])->addForeignKey([
            [
                'column' => 'user_uuid',
                'references' => 'uuid',
                'on' => 'users',
            ]
        ]);

        // Create Blobs Table
        $schema->createTable('blobs', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT',
            'mime_type' => 'VARCHAR(127) NOT NULL',
            'size' => 'BIGINT NOT NULL',
            'url' => 'VARCHAR(2048) NOT NULL',
            'storage_type' => "VARCHAR(20) NOT NULL CHECK (storage_type IN ('local', 's3'))",
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'created_by' => 'CHAR(12) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP',
            'deleted_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'created_by']
        ])->addForeignKey([
            [
                'column' => 'created_by',
                'references' => 'uuid',
                'on' => 'users'
            ]
        ]);

        // Create Profiles Table
        $schema->createTable('profiles', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'first_name' => 'VARCHAR(100) DEFAULT NULL',
            'last_name' => 'VARCHAR(100) DEFAULT NULL',
            'photo_uuid' => 'CHAR(12) DEFAULT NULL',
            'photo_url' => 'VARCHAR(255) DEFAULT NULL',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'deleted_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'photo_uuid']
        ])->addForeignKey([
            [
                'column' => 'user_uuid',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'RESTRICT'
            ],
            [
                'column' => 'photo_uuid',
                'references' => 'uuid',
                'on' => 'blobs',
                'onDelete' => 'SET NULL'
            ]
        ]);

        // Create User Roles Lookup Table
        $schema->createTable('user_roles_lookup', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'role_uuid' => 'CHAR(12) NOT NULL'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'role_uuid']
        ])->addForeignKey([
            [
                'column' => 'user_uuid',
                'references' => 'uuid',
                'on' => 'users',
            ],
            [
                'column' => 'role_uuid',
                'references' => 'uuid',
                'on' => 'roles',
            ]
        ]);

        // Create Auth Sessions Table
        $schema->createTable('auth_sessions', [
            'id' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'access_token' => 'TEXT NOT NULL',
            'refresh_token' => 'TEXT NULL',
            'token_fingerprint' => 'TEXT NOT NULL',
            'ip_address' => 'VARCHAR(45) NULL',
            'user_agent' => 'TEXT NULL',
            'last_token_refresh' => 'TIMESTAMP NULL',
            'access_expires_at' => 'TIMESTAMP NOT NULL',
            'refresh_expires_at' => 'TIMESTAMP NOT NULL',
            'status' => "ENUM('active', 'revoked') DEFAULT 'active'",
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'status']
        ])->addForeignKey([
            [
                'column' => 'user_uuid',
                'references' => 'uuid',
                'on' => 'users'
            ]
        ]);

        // Create App Logs Table
        $schema->createTable('app_logs', [
            'id' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'level' => "ENUM('INFO', 'WARNING', 'ERROR') NOT NULL",
            'message' => 'TEXT NOT NULL',
            'context' => 'JSON NULL',
            'exec_time' => 'FLOAT NULL',
            'channel' => 'VARCHAR(255) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'level'],
            ['type' => 'INDEX', 'column' => 'channel'],
            ['type' => 'INDEX', 'column' => 'created_at']
        ]);
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
     * 1. Dependent tables first (logs, sessions)
     * 2. Junction tables (role assignments)
     * 3. Feature tables (blobs, profiles)
     * 4. Core tables (roles, users)
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('app_logs');
        $schema->dropTable('auth_sessions');
        $schema->dropTable('user_roles_lookup');
        $schema->dropTable('blobs');
        $schema->dropTable('profiles');
        $schema->dropTable('role_permissions');
        $schema->dropTable('user_permissions');
        $schema->dropTable('roles');
        $schema->dropTable('users');
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
        return 'Creates initial database schema including users, roles, and permissions tables';
    }
}