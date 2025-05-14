<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

/**
 * Role Repository
 *
 * Handles all database operations related to roles and role assignments:
 * - Role creation, retrieval, update and deletion
 * - User-role association management
 * - Role permission mapping
 *
 * This repository implements the repository pattern to abstract
 * database operations for role management and provide a clean API
 * for role data access and manipulation.
 *
 * @package Glueful\Repository
 */
class RoleRepository
{
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $db;

    /**
     * Initialize repository
     *
     * Sets up database connection and query builder
     * for role management operations.
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Get all roles
     *
     * Retrieves a complete list of available roles in the system.
     * Returns basic role information including identifiers and descriptions.
     *
     * @return array List of all roles in the system
     */
    public function getRoles(): array
    {
        return $this->db->select('roles', ['uuid', 'name', 'description'])->get();
    }

    /**
     * Get role by UUID
     *
     * Retrieves detailed information about a specific role.
     *
     * @param string $uuid Role UUID
     * @return array|null Role data or null if not found
     */
    public function getRoleByUUID(string $uuid): ?array
    {
        $role = $this->db->select('roles', ['uuid', 'name', 'description'])
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();

        return $role ? $role[0] : null;
    }

    /**
     * Get roles assigned to a user
     *
     * Retrieves all roles assigned to the specified user.
     * Includes role details through a join with the roles table.
     *
     * @param string $uuid User UUID to check
     * @return array List of roles assigned to the user
     */
    public function getUserRoles(string $uuid): array
    {
        return $this->db
        ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid', 'LEFT')
        ->select('user_roles_lookup', [
            'user_roles_lookup.role_uuid',
            'user_roles_lookup.user_uuid',
            'roles.name AS role_name',
            'roles.description'
        ])
        ->where(['user_roles_lookup.user_uuid' => $uuid])
        ->get();
    }

    /**
     * Create new role
     *
     * Creates a new role in the system with the specified attributes.
     * Automatically generates a UUID if not provided.
     *
     * @param array $data Role data (name, description, etc.)
     * @return string|bool Role UUID if successful, false on failure
     */
    public function addRole(array $data): string|bool
    {
        // Generate UUID if not provided
        if (!isset($data['uuid'])) {
            $data['uuid'] = Utils::generateNanoID();
        }

        // Insert role record
        $success = $this->db->insert('roles', $data);

        return $success ? $data['uuid'] : false;
    }

    /**
     * Update existing role
     *
     * Modifies an existing role's attributes.
     *
     * @param string $uuid Role UUID to update
     * @param array $data Updated role data
     * @return bool Success status
     */
    public function updateRole(string $uuid, array $data): bool
    {
        // Remove UUID from data to be updated
        $updateData = $data;
        unset($updateData['uuid']);

        // Add updated_at timestamp if not provided
        if (!isset($updateData['updated_at'])) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
        }

        // Update role using the update method with conditions
        $affected = $this->db->update(
            'roles',
            $updateData,
            ['uuid' => $uuid]
        );

        return $affected > 0;
    }

    /**
     * Delete role
     *
     * Removes a role from the system.
     * This operation may affect users assigned to this role.
     *
     * @param string $uuid Role UUID to delete
     * @return bool Success status
     */
    public function deleteRole(string $uuid): bool
    {
        return $this->db->delete('roles', ['uuid' => $uuid]);
    }

    /**
     * Assign role to user
     *
     * Creates an association between a user and a role.
     * Checks for existing assignments to prevent duplicates.
     * Also invalidates the user's permission cache.
     *
     * @param string $userUuid User UUID to assign role to
     * @param string $roleUuid Role UUID to assign
     * @return bool True if successful, false if already assigned
     */
    public function assignRole(string $userUuid, string $roleUuid): bool
    {
        // Check if assignment already exists
        $exists = $this->db->select('user_roles_lookup', ['role_uuid'])
            ->where(['user_uuid' => $userUuid, 'role_uuid' => $roleUuid])
            ->get();

        if ($exists) {
            return false;
        }

        // Create assignment
        $data = [
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid,
        ];

        $result = $this->db->insert('user_roles_lookup', $data);

        // Invalidate the user's permission cache
        if ($result && class_exists('\\Glueful\\Permissions\\PermissionManager')) {
            \Glueful\Permissions\PermissionManager::invalidateCache($userUuid);
        }

        return $result ? true : false;
    }

    /**
     * Remove role from user
     *
     * Revokes a role assignment from a user.
     * Also invalidates the user's permission cache.
     *
     * @param string $userUuid User UUID to remove role from
     * @param string $roleUuid Role UUID to remove
     * @return bool Success status
     */
    public function unassignRole(string $userUuid, string $roleUuid): bool
    {
        $result = $this->db->delete('user_roles_lookup', [
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid
        ]);

        // Invalidate the user's permission cache
        if ($result && class_exists('\\Glueful\\Permissions\\PermissionManager')) {
            \Glueful\Permissions\PermissionManager::invalidateCache($userUuid);
        }

        return $result;
    }

    /**
     * Get users assigned to role
     *
     * Retrieves all users that have been assigned a specific role.
     *
     * @param string $roleUuid Role UUID to check
     * @return array List of users with this role
     */
    public function getUsersWithRole(string $roleUuid): array
    {
        return $this->db
            ->join('users', 'user_roles_lookup.user_uuid = users.uuid', 'LEFT')
            ->select('user_roles_lookup', [
                'user_roles_lookup.user_uuid',
                'users.username',
                'users.email',
                'users.status'
            ])
            ->where(['user_roles_lookup.role_uuid' => $roleUuid])
            ->get();
    }

    /**
     * Check if user has role
     *
     * Verifies if a specific user has been assigned a particular role.
     *
     * @param string $userUuid User UUID to check
     * @param string $roleName Role name to check for
     * @return bool True if user has the role
     */
    public function hasRole(string $userUuid, string $roleName): bool
    {
        $result = $this->db
            ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid', 'LEFT')
            ->select('user_roles_lookup', ['user_roles_lookup.role_uuid'])
            ->where([
                'user_roles_lookup.user_uuid' => $userUuid,
                'roles.name' => $roleName
            ])
            ->limit(1)
            ->get();

        return !empty($result);
    }

    /**
     * Get role permissions
     *
     * Retrieves all permissions associated with a specific role.
     *
     * @param string $roleUuid Role UUID to get permissions for
     * @return array List of permissions for the role
     */
    public function getRolePermissions(string $roleUuid): array
    {
        $permissions = $this->db->select('role_permissions', ['model', 'permissions'])
            ->where(['role_uuid' => $roleUuid])
            ->get();

        // Format permissions
        $formattedPermissions = [];
        foreach ($permissions as $permission) {
            $model = $permission['model'];
            $perms = is_string($permission['permissions']) ?
                json_decode($permission['permissions'], true) :
                $permission['permissions'];

            $formattedPermissions[$model] = $perms;
        }

        return $formattedPermissions;
    }

    /**
     * Check if user has specific role
     *
     * @param string $userId User ID to check
     * @param string $roleName Role name to verify
     * @return bool True if user has role
     */
    public function userHasRole(string $userId, string $roleName): bool
    {
        $result = $this->db->select('user_roles_lookup', [''])
            ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid', 'LEFT')
            ->where([
                'user_roles_lookup.user_uuid' => $userId,
                'roles.name' => $roleName
            ])
            ->count('user_roles_lookup');

        return $result > 0;
    //    return (bool)($result[0]['has_role'] ?? 0);
    }

    /**
     * Get role name by UUID
     *
     * Retrieves the name of a role given its UUID.
     *
     * @param string $roleUuid Role UUID to look up
     * @return string|null Role name if found, null otherwise
     */
    public function getRoleName(string $roleUuid): ?string
    {
        $role = $this->db->select('roles', ['name'])
            ->where(['uuid' => $roleUuid])
            ->limit(1)
            ->get();

        return $role ? $role[0]['name'] : null;
    }

    /**
     * Get role UUID by name (static method for cross-repository use)
     *
     * @param string $name Role name
     * @return string|null Role UUID or null if not found
     */
    public function getRoleUuidByName(string $name): ?string
    {

        $query = $this->db->select('roles', ['uuid'])
            ->where(['name' => $name])
            ->limit(1)
            ->get();

        if ($query && !empty($query[0])) {
            return $query[0]['uuid'];
        }

        return null;
    }
}
