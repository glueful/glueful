<?php

namespace Glueful\Extensions\RBAC\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * RBAC Permissions Tables Migration
 *
 * Creates RBAC permission management tables:
 * - rbac_permissions: Permission definitions
 * - rbac_role_permissions: Role-permission assignments
 * - rbac_user_permissions: Direct user permissions
 * - rbac_permission_audit: Permission audit trail
 *
 * Features:
 * - Granular permission definitions
 * - Resource-level filtering
 * - Temporal constraints
 * - Complete audit trail
 * - Permission inheritance
 */
class CreatePermissionsTables implements MigrationInterface
{
    /**
     * Execute the migration
     */
    public function up(SchemaManager $schema): void
    {
        // Create Permissions Table
        $schema->createTable('permissions', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(100) NOT NULL',
            'slug' => 'VARCHAR(100) NOT NULL',
            'description' => 'TEXT',
            'category' => 'VARCHAR(50)',
            'resource_type' => 'VARCHAR(100)',
            'is_system' => 'BOOLEAN DEFAULT FALSE',
            'metadata' => 'JSON',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'UNIQUE', 'column' => 'name'],
            ['type' => 'UNIQUE', 'column' => 'slug'],
            ['type' => 'INDEX', 'column' => 'category'],
            ['type' => 'INDEX', 'column' => 'resource_type']
        ]);

        // Create Role Permissions Table
        $schema->createTable('role_permissions', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'role_uuid' => 'CHAR(12) NOT NULL',
            'permission_uuid' => 'CHAR(12) NOT NULL',
            'resource_filter' => 'JSON',
            'constraints' => 'JSON',
            'granted_by' => 'CHAR(12)',
            'expires_at' => 'TIMESTAMP NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'role_uuid'],
            ['type' => 'INDEX', 'column' => 'permission_uuid'],
            ['type' => 'INDEX', 'column' => 'expires_at'],
            ['type' => 'INDEX', 'column' => 'granted_by']
        ])->addForeignKey([
            [
                'column' => 'role_uuid',
                'references' => 'uuid',
                'on' => 'roles',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'permission_uuid',
                'references' => 'uuid',
                'on' => 'permissions',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'granted_by',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'SET NULL'
            ]
        ]);

        // Create User Permissions Table
        $schema->createTable('user_permissions', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'permission_uuid' => 'CHAR(12) NOT NULL',
            'resource_filter' => 'JSON',
            'constraints' => 'JSON',
            'granted_by' => 'CHAR(12)',
            'expires_at' => 'TIMESTAMP NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'permission_uuid'],
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
                'column' => 'permission_uuid',
                'references' => 'uuid',
                'on' => 'permissions',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'granted_by',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'SET NULL'
            ]
        ]);

        // Create Permission Audit Table
        $schema->createTable('permission_audit', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'action' => "ENUM('GRANTED', 'REVOKED', 'MODIFIED', 'EXPIRED') NOT NULL",
            'subject_type' => "ENUM('user', 'role') NOT NULL",
            'subject_uuid' => 'CHAR(12) NOT NULL',
            'permission_uuid' => 'CHAR(12) NOT NULL',
            'target_uuid' => 'CHAR(12)',
            'old_data' => 'JSON',
            'new_data' => 'JSON',
            'reason' => 'TEXT',
            'performed_by' => 'CHAR(12)',
            'ip_address' => 'VARCHAR(45)',
            'user_agent' => 'TEXT',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'subject_type'],
            ['type' => 'INDEX', 'column' => 'subject_uuid'],
            ['type' => 'INDEX', 'column' => 'permission_uuid'],
            ['type' => 'INDEX', 'column' => 'target_uuid'],
            ['type' => 'INDEX', 'column' => 'performed_by'],
            ['type' => 'INDEX', 'column' => 'created_at']
        ]);
    }

    /**
     * Reverse the migration
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('permission_audit');
        $schema->dropTable('user_permissions');
        $schema->dropTable('role_permissions');
        $schema->dropTable('permissions');
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return 'Create RBAC permissions, role-permissions, user-permissions, and audit tables';
    }
}
