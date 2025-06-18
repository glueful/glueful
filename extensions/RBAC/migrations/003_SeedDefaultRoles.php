<?php

namespace Glueful\Extensions\RBAC\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Helpers\Utils;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Interfaces\Permission\PermissionStandards;

/**
 * RBAC Default Roles Seeder
 *
 * Seeds default roles and permissions that comply with PermissionStandards:
 * - System roles (superuser, admin, user) with hierarchical levels
 * - Core permissions (ALL 5 required by PermissionStandards + extended permissions)
 * - Default role-permission assignments
 *
 * PermissionStandards Compliance:
 * - ✅ system.access (PERMISSION_SYSTEM_ACCESS)
 * - ✅ users.view (PERMISSION_USERS_VIEW)
 * - ✅ users.create (PERMISSION_USERS_CREATE)
 * - ✅ users.edit (PERMISSION_USERS_EDIT)
 * - ✅ users.delete (PERMISSION_USERS_DELETE)
 *
 * Extended RBAC Permissions:
 * - system.config, roles.*, content.*
 *
 * Features:
 * - Uses PermissionStandards constants for consistency
 * - Verifies all core permissions are created
 * - Hierarchical role structure with proper levels
 * - Clean database seeding with UUID support
 */
class SeedDefaultRoles implements MigrationInterface
{
    /** @var QueryBuilder Database interaction instance */
    private QueryBuilder $db;

    /**
     * Execute the migration
     */
    public function up(SchemaManager $schema): void
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Define role data
        $roleData = [
            'superuser' => [
                'name' => 'Superuser',
                'slug' => 'superuser',
                'description' => 'System administrator with full access',
                'level' => 100,
                'is_system' => 1,
                'status' => 'active'
            ],
            'administrator' => [
                'name' => 'Administrator',
                'slug' => 'administrator',
                'description' => 'Site administrator with management access',
                'level' => 80,
                'is_system' => 1,
                'status' => 'active'
            ],
            'manager' => [
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'User manager with limited admin access',
                'level' => 60,
                'is_system' => 1,
                'status' => 'active'
            ],
            'user' => [
                'name' => 'User',
                'slug' => 'user',
                'description' => 'Standard user with basic access',
                'level' => 10,
                'is_system' => 1,
                'status' => 'active'
            ]
        ];

        // Insert or get existing roles and store their UUIDs
        $roleUuids = [];
        foreach ($roleData as $slug => $role) {
            // Check if role already exists using WHERE clause
            $existingRole = $this->db->select('roles', ['uuid'])->where(['slug' => $role['slug']])->get();
            if (!empty($existingRole)) {
                $roleUuids[$slug] = $existingRole[0]['uuid'];
            } else {
                $role['uuid'] = Utils::generateNanoID();
                $roleUuids[$slug] = $role['uuid'];
                $roleId = $this->db->insert('roles', $role);
                if (!$roleId) {
                    throw new \RuntimeException('Failed to create role: ' . $role['name']);
                }
            }
        }

        // Define permission data
        $permissionData = [
            'sys_access' => [
                'name' => 'System Access',
                'slug' => PermissionStandards::PERMISSION_SYSTEM_ACCESS,
                'category' => PermissionStandards::CATEGORY_SYSTEM,
                'description' => 'Access to system functionality'
            ],
            'sys_config' => [
                'name' => 'System Configuration',
                'slug' => PermissionStandards::PERMISSION_SYSTEM_CONFIG,
                'category' => PermissionStandards::CATEGORY_SYSTEM,
                'description' => 'Modify system configuration'
            ],
            'usr_view' => [
                'name' => 'View Users',
                'slug' => PermissionStandards::PERMISSION_USERS_VIEW,
                'category' => PermissionStandards::CATEGORY_USERS,
                'description' => 'View user accounts'
            ],
            'usr_create' => [
                'name' => 'Create Users',
                'slug' => PermissionStandards::PERMISSION_USERS_CREATE,
                'category' => PermissionStandards::CATEGORY_USERS,
                'description' => 'Create new user accounts'
            ],
            'usr_edit' => [
                'name' => 'Edit Users',
                'slug' => PermissionStandards::PERMISSION_USERS_EDIT,
                'category' => PermissionStandards::CATEGORY_USERS,
                'description' => 'Edit user accounts'
            ],
            'usr_delete' => [
                'name' => 'Delete Users',
                'slug' => PermissionStandards::PERMISSION_USERS_DELETE,
                'category' => PermissionStandards::CATEGORY_USERS,
                'description' => 'Delete user accounts'
            ],
            'rol_view' => [
                'name' => 'View Roles',
                'slug' => PermissionStandards::CATEGORY_ROLES . '.' . PermissionStandards::ACTION_VIEW,
                'category' => PermissionStandards::CATEGORY_ROLES,
                'description' => 'View role definitions'
            ],
            'rol_create' => [
                'name' => 'Create Roles',
                'slug' => PermissionStandards::CATEGORY_ROLES . '.' . PermissionStandards::ACTION_CREATE,
                'category' => PermissionStandards::CATEGORY_ROLES,
                'description' => 'Create new roles'
            ],
            'rol_edit' => [
                'name' => 'Edit Roles',
                'slug' => PermissionStandards::CATEGORY_ROLES . '.' . PermissionStandards::ACTION_EDIT,
                'category' => PermissionStandards::CATEGORY_ROLES,
                'description' => 'Edit role definitions'
            ],
            'rol_delete' => [
                'name' => 'Delete Roles',
                'slug' => PermissionStandards::CATEGORY_ROLES . '.' . PermissionStandards::ACTION_DELETE,
                'category' => PermissionStandards::CATEGORY_ROLES,
                'description' => 'Delete roles'
            ],
            'rol_assign' => [
                'name' => 'Assign Roles',
                'slug' => PermissionStandards::CATEGORY_ROLES . '.' . PermissionStandards::ACTION_ASSIGN,
                'category' => PermissionStandards::CATEGORY_ROLES,
                'description' => 'Assign roles to users'
            ],
            'cnt_view' => [
                'name' => 'View Content',
                'slug' => PermissionStandards::CATEGORY_CONTENT . '.' . PermissionStandards::ACTION_VIEW,
                'category' => PermissionStandards::CATEGORY_CONTENT,
                'description' => 'View content'
            ],
            'cnt_create' => [
                'name' => 'Create Content',
                'slug' => PermissionStandards::CATEGORY_CONTENT . '.' . PermissionStandards::ACTION_CREATE,
                'category' => PermissionStandards::CATEGORY_CONTENT,
                'description' => 'Create new content'
            ],
            'cnt_edit' => [
                'name' => 'Edit Content',
                'slug' => PermissionStandards::CATEGORY_CONTENT . '.' . PermissionStandards::ACTION_EDIT,
                'category' => PermissionStandards::CATEGORY_CONTENT,
                'description' => 'Edit content'
            ],
            'cnt_delete' => [
                'name' => 'Delete Content',
                'slug' => PermissionStandards::CATEGORY_CONTENT . '.' . PermissionStandards::ACTION_DELETE,
                'category' => PermissionStandards::CATEGORY_CONTENT,
                'description' => 'Delete content'
            ]
        ];

        // Insert or get existing permissions and store their UUIDs
        $permissionUuids = [];
        foreach ($permissionData as $key => $permission) {
            // Check if permission already exists using WHERE clause
            $existingPermission = $this->db->select('permissions', ['uuid'])
                ->where(['slug' => $permission['slug']])
                ->get();
            if (!empty($existingPermission)) {
                $permissionUuids[$key] = $existingPermission[0]['uuid'];
            } else {
                $permission['uuid'] = Utils::generateNanoID();
                $permission['is_system'] = 1;
                $permissionUuids[$key] = $permission['uuid'];
                $permissionId = $this->db->insert('permissions', $permission);
                if (!$permissionId) {
                    throw new \RuntimeException('Failed to create permission: ' . $permission['name']);
                }
            }
        }

        // Verify all core permissions are created
        $this->verifyCorePermissions();

        // Assign permissions to roles
        $rolePermissions = [
            // Superuser gets all permissions
            'superuser' => [
                'sys_access', 'sys_config', 'usr_view', 'usr_create',
                'usr_edit', 'usr_delete', 'rol_view', 'rol_create',
                'rol_edit', 'rol_delete', 'rol_assign', 'cnt_view',
                'cnt_create', 'cnt_edit', 'cnt_delete'
            ],
            // Administrator gets most permissions except system config
            'administrator' => [
                'sys_access', 'usr_view', 'usr_create', 'usr_edit',
                'usr_delete', 'rol_view', 'rol_assign', 'cnt_view',
                'cnt_create', 'cnt_edit', 'cnt_delete'
            ],
            // Manager gets user and content management
            'manager' => [
                'sys_access', 'usr_view', 'usr_edit', 'rol_view',
                'rol_assign', 'cnt_view', 'cnt_create', 'cnt_edit',
                'cnt_delete'
            ],
            // User gets basic content access
            'user' => [
                'cnt_view', 'cnt_create', 'cnt_edit'
            ]
        ];

        // Collect all role-permission assignments for bulk processing
        $assignments = [];
        foreach ($rolePermissions as $roleSlug => $permissionKeys) {
            foreach ($permissionKeys as $permissionKey) {
                $assignments[] = [
                    'role_uuid' => $roleUuids[$roleSlug],
                    'permission_uuid' => $permissionUuids[$permissionKey]
                ];
            }
        }

        // Check existing assignments in bulk
        $existingPairs = [];
        if (!empty($assignments)) {
            $existing = $this->db->select('role_permissions', ['role_uuid', 'permission_uuid'])->get();
            foreach ($existing as $row) {
                $existingPairs[$row['role_uuid'] . '|' . $row['permission_uuid']] = true;
            }
        }

        // Prepare new assignments for batch insert
        $newAssignments = [];
        foreach ($assignments as $assignment) {
            $key = $assignment['role_uuid'] . '|' . $assignment['permission_uuid'];
            if (!isset($existingPairs[$key])) {
                $newAssignments[] = [
                    'uuid' => Utils::generateNanoID(),
                    'role_uuid' => $assignment['role_uuid'],
                    'permission_uuid' => $assignment['permission_uuid']
                ];
            }
        }

        // Bulk insert new assignments
        if (!empty($newAssignments)) {
            $this->db->insertBatch('role_permissions', $newAssignments);
        }
    }

    /**
     * Reverse the migration
     */
    public function down(SchemaManager $schema): void
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Delete all system roles and permissions (will cascade to role_permissions)
        $this->db->delete('permissions', ['is_system' => 1]);
        $this->db->delete('roles', ['is_system' => 1]);
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return 'Seed default RBAC roles, permissions, and role-permission assignments';
    }

    /**
     * Verify that all core permissions from PermissionStandards are created
     *
     * @return void
     * @throws \RuntimeException If any core permission is missing
     */
    private function verifyCorePermissions(): void
    {
        foreach (PermissionStandards::CORE_PERMISSIONS as $corePermission) {
            $results = $this->db
                ->select('permissions', ['uuid'])
                ->where(['slug' => $corePermission, 'is_system' => 1])
                ->get();

            if (empty($results)) {
                throw new \RuntimeException(
                    "Core permission '{$corePermission}' was not created. " .
                    "RBAC extension must implement all core permissions defined in PermissionStandards."
                );
            }
        }

        // Log successful verification
        error_log("RBAC Migration: All " . count(PermissionStandards::CORE_PERMISSIONS) .
                  " core permissions verified successfully.");
    }
}
