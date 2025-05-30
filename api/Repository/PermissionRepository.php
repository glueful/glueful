<?php

declare(strict_types=1);

namespace Glueful\Repository;

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
 * This repository extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality.
 *
 * @package Glueful\Repository
 */
class PermissionRepository extends BaseRepository
{
    /**
     * Initialize repository
     *
     * Sets up database connection and query builder
     * for permission management operations.
     */
    public function __construct()
    {
        // Set the table and other configuration before calling parent constructor
        $this->table = 'role_permissions';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['uuid', 'role_uuid', 'model', 'permissions', 'created_at', 'updated_at'];

        // Call parent constructor to set up database connection
        parent::__construct();
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

            // Handle different permission formats
            if (is_string($perms)) {
                // Try JSON first
                $decoded = json_decode($perms, true);
                if ($decoded !== null) {
                    $perms = $decoded;
                } else {
                    // If not JSON, treat as string of permission characters
                    // Convert 'ABDC' to ['A', 'B', 'D', 'C']
                    $perms = str_split($perms);
                }
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
     * Also invalidates the user's permission cache.
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

        // Create data array for insert
        $data = [
            'uuid' => $uuid,
            'user_uuid' => $userUuid,
            'model' => $model,
            'permissions' => $permissionsData,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Insert permission record using direct db call since we're using a different table
        $success = $this->db->insert('user_permissions', $data);

        // Invalidate permission cache if successful
        if ($success) {
            $this->invalidatePermissionCache($userUuid);
        }

        return $success ? $uuid : false;
    }

    /**
     * Remove user-specific permissions
     *
     * Deletes user permission records for a specific resource.
     * This restores the default role-based permissions for the user.
     * Also invalidates the user's permission cache.
     *
     * @param string $userUuid Target user's UUID
     * @param string $model Permission model/resource to remove
     * @return bool True if permissions were removed, false otherwise
     */
    public function removeUserPermission(string $userUuid, string $model): bool
    {
        $result = $this->db->delete(
            'user_permissions',
            ['user_uuid' => $userUuid, 'model' => $model],
            false
        );

        // Invalidate permission cache if successful
        if ($result) {
            $this->invalidatePermissionCache($userUuid);
        }

        return $result;
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
        $permissions = $this->db->select($this->table, $this->defaultFields)
            ->where(['role_uuid' => $roleUuid])
            ->get();

        // Process permissions for easier use
        $formattedPermissions = [];
        foreach ($permissions as $permission) {
            $model = $permission['model'];
            $perms = $permission['permissions'];

            // Handle different permission formats
            if (is_string($perms)) {
                // Try JSON first
                $decoded = json_decode($perms, true);
                if ($decoded !== null) {
                    $perms = $decoded;
                } else {
                    // If not JSON, treat as string of permission characters
                    // Convert 'ABDC' to ['A', 'B', 'D', 'C']
                    $perms = str_split($perms);
                }
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

        // Create data array for insert
        $data = [
            'uuid' => $uuid,
            'role_uuid' => $roleUuid,
            'model' => $model,
            'permissions' => $permissions,
        ];

        // Use BaseRepository create method
        $success = $this->create($data);

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
        // Find the permission record to delete
        $permission = $this->db->select($this->table, ['uuid'])
            ->where(['role_uuid' => $roleUuid, 'model' => $model])
            ->get();

        if (!empty($permission)) {
            // Use BaseRepository delete method for better audit logging
            return $this->delete($permission[0]['uuid']);
        }

        return false;
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
        $existingPermission = $this->db->select($this->table, ['uuid'])
            ->where(['role_uuid' => $roleUuid, 'model' => $model])
            ->limit(1)
            ->get();

        if ($existingPermission) {
            // Update existing permission using BaseRepository update method
            $updateData = [
                'permissions' => $permissionsData,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            return $this->update($existingPermission[0]['uuid'], $updateData);
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

            if (
                isset($rolePerms[$model]) &&
                in_array($permission, $rolePerms[$model]['permissions'])
            ) {
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
     * @param string $roleUuid Role UUID to assign permission to
     * @return mixed The created permission record UUID or false if failed
     */
    public function createPermission(string $model, array $permissions, string $roleUuid): mixed
    {
        // Create data array for insert
        $data = [
            'uuid' => Utils::generateNanoID(),
            'role_uuid' => $roleUuid,
            'model' => $model,
            'permissions' => $permissions,
        ];

        // Use BaseRepository create method
        $success = $this->create($data);

        return $success ? $data['uuid'] : false;
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
        return $this->update($uuid, $data);
    }

    /**
     * Get all permissions in the system
     *
     * Retrieves all permissions across all roles.
     *
     * @return array All permissions in the system
     */
    public function getAllPermissions(): array
    {
        return $this->getAll();
    }

    /**
     * Check if user has permission with detailed debug information
     *
     * Enhanced version of hasPermission that returns detailed information about
     * why a permission check succeeded or failed. Useful for troubleshooting
     * permission issues and providing more informative error messages.
     *
     * @param string $userUuid User UUID to check
     * @param string $model Permission model/resource name
     * @param string $permission Permission to check for
     * @return array Detailed permission check results
     */
    public function hasPermissionDebug(string $userUuid, string $model, string $permission): array
    {
        $result = [
            'has_permission' => false,
            'reason' => '',
            'user' => ['uuid' => $userUuid],
            'model' => $model,
            'permission' => $permission,
            'user_permissions' => [],
            'role_permissions' => []
        ];

        // First check user-specific permissions (override)
        $userPerms = $this->getUserPermissions($userUuid);
        $result['user_permissions'] = $userPerms;

        if (isset($userPerms[$model])) {
            if (in_array($permission, $userPerms[$model])) {
                $result['has_permission'] = true;
                $result['reason'] = "User has direct permission for this action";
                return $result;
            } else {
                $result['reason'] = "User has permissions for this model but not the required action";
                // Continue checking roles anyway, for complete debug info
            }
        }

        // Check role permissions
        $roleRepo = new RoleRepository();
        $roles = $roleRepo->getUserRoles($userUuid);
        $result['user']['roles'] = $roles;

        if (empty($roles)) {
            $result['reason'] = "User has no roles assigned";
            return $result;
        }

        $rolePermissions = [];
        foreach ($roles as $role) {
            $roleUuid = $role['role_uuid'];
            $roleName = $role['role_name'] ?? $roleRepo->getRoleName($roleUuid);

            $rolePerms = $this->getRolePermissions($roleUuid);
            $rolePermissions[$roleName] = $rolePerms;

            if (
                isset($rolePerms[$model]) &&
                in_array($permission, $rolePerms[$model]['permissions'])
            ) {
                $result['has_permission'] = true;
                $result['reason'] = "Permission granted via '{$roleName}' role";
                $result['role_permissions'] = $rolePermissions;
                return $result;
            }
        }

        $result['role_permissions'] = $rolePermissions;
        $result['reason'] = "None of the user's roles grant the required permission";

        return $result;
    }

    /**
     * Get cached effective permissions for a user
     *
     * Retrieves the user's effective permissions from cache if available,
     * otherwise calculates them and caches the result.
     *
     * @param string $userUuid User UUID
     * @param int $ttl Cache time-to-live in seconds (default: 5 minutes)
     * @return array Complete set of user's effective permissions
     */
    public function getCachedEffectivePermissions(string $userUuid, int $ttl = 300): array
    {
        // Initialize cache if not already done
        if (!class_exists('\\Glueful\\Cache\\CacheEngine')) {
            require_once __DIR__ . '/../Cache/CacheEngine.php';
        }

        // Initialize the cache engine if needed
        \Glueful\Cache\CacheEngine::initialize();

        $cacheKey = "user_permissions:{$userUuid}";

        // Try to get from cache first
        $cachedPermissions = \Glueful\Cache\CacheEngine::get($cacheKey);
        if ($cachedPermissions) {
            return $cachedPermissions;
        }

        // Not in cache, calculate permissions
        $permissions = $this->getEffectivePermissions($userUuid);

        // Cache permissions
        \Glueful\Cache\CacheEngine::set($cacheKey, $permissions, $ttl);

        return $permissions;
    }

    /**
     * Invalidate cached permissions for a user
     *
     * Clears the cached permissions when user roles or permissions change.
     * Should be called whenever:
     * - User roles are changed
     * - Role permissions are updated
     * - Direct user permissions are modified
     *
     * @param string $userUuid User UUID
     * @return bool True if the cache was successfully invalidated
     */
    public function invalidatePermissionCache(string $userUuid): bool
    {
        // Initialize cache if not already done
        if (!class_exists('\\Glueful\\Cache\\CacheEngine')) {
            require_once __DIR__ . '/../Cache/CacheEngine.php';
        }

        // Initialize the cache engine if needed
        \Glueful\Cache\CacheEngine::initialize();

        $cacheKey = "user_permissions:{$userUuid}";

        return \Glueful\Cache\CacheEngine::delete($cacheKey);
    }
}
