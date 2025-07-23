<?php

namespace Glueful\Extensions\RBAC\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * RBAC Roles Tables Migration
 *
 * Creates RBAC role management tables:
 * - rbac_roles: Role definitions with hierarchy
 * - rbac_user_roles: User-role assignments with scope
 *
 * Features:
 * - Hierarchical role structure
 * - Scoped role assignments
 * - Temporal permissions (expiry)
 * - Audit trail support
 * - Soft deletes
 */
class CreateRolesTables implements MigrationInterface
{
    /**
     * Execute the migration
     */
    public function up(SchemaManager $schema): void
    {
        // Create Roles Table
        $schema->createTable('roles', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(100) NOT NULL',
            'slug' => 'VARCHAR(100) NOT NULL',
            'description' => 'TEXT',
            'parent_uuid' => 'CHAR(12) NULL',
            'level' => 'INT DEFAULT 0',
            'is_system' => 'BOOLEAN DEFAULT FALSE',
            'metadata' => 'JSON',
            'status' => "ENUM('active', 'inactive') DEFAULT 'active'",
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'deleted_at' => 'TIMESTAMP NULL'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'UNIQUE', 'column' => 'name'],
            ['type' => 'UNIQUE', 'column' => 'slug'],
            ['type' => 'INDEX', 'column' => 'parent_uuid'],
            ['type' => 'INDEX', 'column' => 'status'],
            ['type' => 'INDEX', 'column' => 'level']
        ])->addForeignKey([
            [
                'column' => 'parent_uuid',
                'references' => 'uuid',
                'on' => 'roles',
                'onDelete' => 'SET NULL'
            ]
        ]);

        // Create User Roles Table
        $schema->createTable('user_roles', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'role_uuid' => 'CHAR(12) NOT NULL',
            'scope' => 'JSON',
            'granted_by' => 'CHAR(12)',
            'expires_at' => 'TIMESTAMP NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'role_uuid'],
            ['type' => 'INDEX', 'column' => 'expires_at'],
            ['type' => 'INDEX', 'column' => 'granted_by']
        ])->addForeignKey([
            [
                'column' => 'user_uuid',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'role_uuid',
                'references' => 'uuid',
                'on' => 'roles',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'granted_by',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'SET NULL'
            ]
        ]);
    }

    /**
     * Reverse the migration
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('user_roles');
        $schema->dropTable('roles');
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return 'Create RBAC roles and user roles tables with hierarchical support';
    }
}
