<?php
declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

/**
 * Permission Repository
 * 
 * Handles all database operations related to permissions and access control:
 * - User permission management
 * - Role permission management
 * - Permission assignment and revocation
 * - Access control queries
 * 
 * This repository implements the repository pattern to abstract
 * database operations for permission management and provide a clean API
 * for permission data access and manipulation.
 * 
 * @package Glueful\Repository
 */
class PermissionRepository {
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $db;

    /**
     * Initialize repository
     * 
     * Sets up database connection and query builder
     * for permission management operations.
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Get permissions for a role by name
     * 
     * Retrieves all permissions associated with a specific role name.
     * Joins with the roles table to lookup by name rather than UUID.
     * 
     * @param string $roleName Role name to get permissions for
     * @return array List of permissions for the role
     */
    public function getPermissionsByRoleName(string $roleName = "superuser"): array
    {
        return $this->db
            ->join('roles', 'role_permissions.role_uuid = roles.uuid', 'LEFT')
            ->select('role_permissions', [
                'role_permissions.model',
                'role_permissions.permissions',
                'roles.name AS role_name'
            ])
            ->where(['roles.name' => $roleName])
            ->get();
    }

    /**
     * Get user-specific permissions
     * 
     * Retrieves permissions directly assigned to a specific user,
     * independent of their role-based permissions.
     * 
     * @param string $userUuid User UUID to get permissions for
     * @return array User-specific permissions
     */
    public function getUserPermissions(string $userUuid): array
    {
        $permissions = $this->db->select('user_permissions', [
                'user_uuid', 
                'model', 
                'permissions'
            ])
            ->where(['user_uuid' => $userUuid])
            ->get();

        // Process permissions for easier use
        $formattedPermissions = [];
        foreach ($permissions as $permission) {
            $model = $permission['model'];
            $perms = $permission['permissions'];
            
            // Handle JSON formatted permissions
            if (is_string($perms)) {
                $perms = json_decode($perms, true);
            }
            
            $formattedPermissions[$model] = $perms;
        }

        return $formattedPermissions;
    }

    /**
     * Assign permissions to user
     * 
     * Creates or updates user-specific permissions for a resource.
     * These permissions override role-based permissions.
     * 
     * @param string $userUuid Target user's UUID
     * @param string $model Permission model/resource name
     * @param string|array $permissions Comma-separated permission list or array
     * @return string|bool New permission UUID if successful, false otherwise
     */
    public function assignUserPermission(string $userUuid, string $model, $permissions): string|bool
    {
        // Generate UUID for new permission record
        $uuid = Utils::generateNanoID();
        
        // Format permissions based on input type
        if (is_array($permissions)) {
            $permissionsData = json_encode($permissions);
        } else {
            $permissionsData = json_encode(explode(',', $permissions));
        }
        
        // Insert permission record
        $success = $this->db->insert('user_permissions', [
            'uuid' => $uuid,
            'user_uuid' => $userUuid,
            'model' => $model,
            'permissions' => $permissionsData,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $success ? $uuid : false;
    }

    /**
     * Remove user-specific permissions
     * 
     * Deletes user permission records for a specific resource.
     * This restores the default role-based permissions for the user.
     * 
     * @param string $userUuid Target user's UUID
     * @param string $model Permission model/resource to remove
     * @return bool True if permissions were removed, false otherwise
     */
    public function removeUserPermission(string $userUuid, string $model): bool
    {
        return $this->db->delete(
            'user_permissions', 
            ['user_uuid' => $userUuid, 'model' => $model], 
            false
        );
    }

    /**
     * Get role permissions by UUID
     * 
     * Retrieves all permissions associated with a specific role UUID.
     * 
     * @param string $roleUuid Role UUID to get permissions for
     * @return array Role permissions, organized by model
     */
    public function getRolePermissions(string $roleUuid): array
    {
        $permissions = $this->db->select('role_permissions', [
                'role_uuid', 
                'model', 
                'permissions',
                'created_at',
                'updated_at'
            ])
            ->where(['role_uuid' => $roleUuid])
            ->get();
        
        // Process permissions for easier use
        $formattedPermissions = [];
        foreach ($permissions as $permission) {
            $model = $permission['model'];
            $perms = $permission['permissions'];
            
            // Handle JSON formatted permissions
            if (is_string($perms)) {
                $perms = json_decode($perms, true);
            }
            
            $formattedPermissions[$model] = [
                'permissions' => $perms,
                'created_at' => $permission['created_at'] ?? null,
                'updated_at' => $permission['updated_at'] ?? null
            ];
        }
        
        return $formattedPermissions;
    }

    /**
     * Assign permissions to role
     * 
     * Creates or updates permissions for a role on a specific resource.
     * 
     * @param string $roleUuid Target role's UUID
     * @param string $model Permission model/resource name
     * @param string|array $permissions Comma-separated permissions or array
     * @return string|bool New permission UUID if successful, false otherwise
     */
    public function assignRolePermission(string $roleUuid, string $model, $permissions): string|bool
    {
        // Generate UUID for new permission record
        $uuid = Utils::generateNanoID();
        
        // Format permissions based on input type
        // if (is_array($permissions)) {
        //     $permissionsData = json_encode($permissions);
        // } else {
        //     $permissionsData = json_encode(explode(',', $permissions));
        // }
        
        // Insert permission record
        $success = $this->db->insert('role_permissions', [
            'uuid' => $uuid,
            'role_uuid' => $roleUuid,
            'model' => $model,
            'permissions' => $permissions,
        ]);

        return $success ? $uuid : false;
    }

    /**
     * Remove role permissions
     * 
     * Deletes permissions for a role on a specific resource.
     * This affects all users with this role.
     * 
     * @param string $roleUuid Target role's UUID
     * @param string $model Permission model/resource to remove
     * @return bool True if permissions were removed, false otherwise
     */
    public function removeRolePermission(string $roleUuid, string $model): bool
    {
        return $this->db->delete(
            'role_permissions', 
            ['role_uuid' => $roleUuid, 'model' => $model],
            false
        );
    }
    
    /**
     * Update role permissions
     * 
     * Modifies existing permissions for a role on a specific resource.
     * Creates the permission if it doesn't exist.
     * 
     * @param string $roleUuid Target role's UUID
     * @param string $model Permission model/resource name
     * @param array|string $permissions Updated permissions
     * @return bool True if permissions were updated successfully
     */
    public function updateRolePermission(string $roleUuid, string $model, $permissions): bool
    {
        // Format permissions based on input type
        if (is_array($permissions)) {
            $permissionsData = json_encode($permissions);
        } else {
            $permissionsData = json_encode(explode(',', $permissions));
        }
        
        // Check if permission record exists
        $existingPermission = $this->db->select('role_permissions', ['uuid'])
            ->where(['role_uuid' => $roleUuid, 'model' => $model])
            ->limit(1)
            ->get();
            
        if ($existingPermission) {
            // Update existing permission
            $data = [
                [
                    'uuid' => $existingPermission[0]['uuid'],
                    'permissions' => $permissionsData,
                ]
            ];
            
            return $this->db->upsert(
                'role_permissions',
                $data,
                ['permissions', 'updated_at']
            ) > 0;
        } else {
            // Create new permission
            return (bool) $this->assignRolePermission($roleUuid, $model, $permissions);
        }
    }
    
    /**
     * Check if user has permission
     * 
     * Determines if a user has a specific permission on a resource,
     * either directly or through their assigned roles.
     * 
     * @param string $userUuid User UUID to check
     * @param string $model Permission model/resource name 
     * @param string $permission Permission to check for
     * @return bool True if user has the permission
     */
    public function hasPermission(string $userUuid, string $model, string $permission): bool
    {
        // First check user-specific permissions (override)
        $userPerms = $this->getUserPermissions($userUuid);
        if (isset($userPerms[$model])) {
            return in_array($permission, $userPerms[$model]);
        }
        
        // If no user-specific permission, check role permissions
        $roles = (new RoleRepository())->getUserRoles($userUuid);
        
        foreach ($roles as $role) {
            $roleUuid = $role['role_uuid'];
            $rolePerms = $this->getRolePermissions($roleUuid);
            
            if (isset($rolePerms[$model]) && 
                in_array($permission, $rolePerms[$model]['permissions'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get effective user permissions
     * 
     * Computes the effective permissions for a user by combining:
     * - Direct user permissions (highest priority)
     * - Role-based permissions from all assigned roles
     * 
     * @param string $userUuid User UUID
     * @return array Complete set of user's effective permissions
     */
    public function getEffectivePermissions(string $userUuid): array
    {
        // Get user-specific permissions (overrides)
        $userPerms = $this->getUserPermissions($userUuid);
        
        // Get all user roles
        $roleRepo = new RoleRepository();
        $roles = $roleRepo->getUserRoles($userUuid);
        
        // Collect role permissions
        $rolePerms = [];
        foreach ($roles as $role) {
            $roleUuid = $role['role_uuid'];
            $permissions = $this->getRolePermissions($roleUuid);
            
            foreach ($permissions as $model => $data) {
                if (!isset($rolePerms[$model])) {
                    $rolePerms[$model] = [];
                }
                
                // Merge permissions for this model from this role
                $rolePerms[$model] = array_unique(
                    array_merge($rolePerms[$model], $data['permissions'])
                );
            }
        }
        
        // Merge with user permissions (user permissions take precedence)
        $effectivePerms = $rolePerms;
        foreach ($userPerms as $model => $perms) {
            $effectivePerms[$model] = $perms;
        }
        
        return $effectivePerms;
    }

    /**
     * Create a new permission for a model
     * 
     * @param string $model The model/resource name (e.g., 'users', 'posts')
     * @param array $permissions Array of permission actions (e.g., ['read', 'write', 'delete'])
     * @param string|null $description Optional description of the permission set
     * @return array The created permission record
     * @throws \Exception If permission creation fails
     */
    public function createPermission(string $model, array $permissions, $roleUuid): mixed 
    {
        // Generate UUID for new permission record
        $uuid = Utils::generateNanoID();
        $success = $this->db->insert('role_permissions', [
            'uuid' => $uuid,
            'role_uuid' => $roleUuid,
            'model' => $model,
            'permissions' => $permissions,
        ]);

        return $success ? $uuid : false;
    }

    /**
     * Update a permission record
     * 
     * @param string $uuid The UUID of the permission record
     * @param array $data The updated permission data
     * @return bool True if the permission was updated successfully
     */
    public function updatePermission(string $uuid, array $data): bool
    {
        return $this->db->upsert('role_permissions', $data, ['uuid' => $uuid]) > 0;
    }

}