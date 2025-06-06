<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Helpers\Utils;
use Glueful\Database\Connection;

/**
 * Role Repository
 *
 * Handles all database operations related to roles and role assignments:
 * - Role creation, retrieval, update and deletion
 * - User-role association management
 *
 * This repository extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality for role-related activities.
 *
 * @package Glueful\Repository
 */
class RoleRepository extends BaseRepository
{
    /**
     * Initialize repository
     *
     * Sets up database connection and query builder
     * for role management operations.
     */
    public function __construct(?Connection $connection = null)
    {
        // Configure repository settings before calling parent
        $this->containsSensitiveData = false;
        $this->sensitiveFields = [];
        $this->defaultFields = ['uuid', 'name', 'description', 'created_at', 'updated_at'];

        // Call parent constructor to set up database connection and audit logger
        parent::__construct($connection);
    }

    /**
     * Get the table name for this repository
     *
     * @return string The table name
     */
    public function getTableName(): string
    {
        return 'roles';
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
        return $this->findAll();
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
        return $this->find($uuid);
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
            ->select('user_roles_lookup', [
                'user_roles_lookup.role_uuid',
                'user_roles_lookup.user_uuid',
                'roles.name AS role_name',
                'roles.description'
            ])
            ->join('roles', '`user_roles_lookup`.`role_uuid` = `roles`.`uuid`', 'LEFT')
            ->where(['user_roles_lookup.user_uuid' => $uuid])
            ->get();
    }

    /**
     * Create new role
     *
     * Creates a new role in the system with the specified attributes.
     * Automatically generates a UUID if not provided.
     * This method leverages the parent create method for audit logging.
     *
     * @param array $data Role data (name, description, etc.)
     * @param string|null $userId ID of user creating the role (for audit)
     * @return string|bool Role UUID if successful, false on failure
     */
    public function addRole(array $data, ?string $userId = null): string|bool
    {
        // Generate UUID if not provided
        if (!isset($data['uuid'])) {
            $data['uuid'] = Utils::generateNanoID();
        }

        // Set timestamps if not provided
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        // Insert role record using parent create method for audit logging
        try {
            $uuid = parent::create($data);
            return $uuid;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Update existing role
     *
     * Modifies an existing role's attributes.
     * This method leverages the parent update method for audit logging.
     *
     * @param string $uuid Role UUID to update
     * @param array $data Updated role data
     * @param string|null $userId ID of user updating the role (for audit)
     * @return bool Success status
     */
    public function updateRole(string $uuid, array $data, ?string $userId = null): bool
    {
        // Remove UUID from data to be updated
        $updateData = $data;
        unset($updateData['uuid']);

        // Add updated_at timestamp if not provided
        if (!isset($updateData['updated_at'])) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
        }

        // Update role using parent update method for audit logging
        return parent::update($uuid, $updateData);
    }

    /**
     * Delete role
     *
     * Removes a role from the system.
     * This operation may affect users assigned to this role.
     * This method leverages the parent delete method for audit logging.
     *
     * @param string $uuid Role UUID to delete
     * @param string|null $userId ID of user deleting the role (for audit)
     * @return bool Success status
     */
    public function deleteRole(string $uuid, ?string $userId = null): bool
    {
        // Use parent delete method for audit logging
        return parent::delete($uuid);
    }

    /**
     * Assign role to user
     *
     * Creates an association between a user and a role.
     * Checks for existing assignments to prevent duplicates.
     *
     * @param string $userUuid User UUID to assign role to
     * @param string $roleUuid Role UUID to assign
     * @param string|null $assignedByUserId ID of user performing the assignment (for audit)
     * @return bool True if successful, false if already assigned
     */
    public function assignRole(string $userUuid, string $roleUuid, ?string $assignedByUserId = null): bool
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
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Store the original table and switch to user_roles_lookup temporarily
        $originalTable = $this->table;
        $this->table = 'user_roles_lookup';

        // Use create method to get audit logging
        try {
            $result = parent::create($data);
        } catch (\Exception $e) {
            // Restore the original table
            $this->table = $originalTable;
            return false;
        }

        // Restore the original table
        $this->table = $originalTable;


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
     * @param string|null $removedByUserId ID of user performing the removal (for audit)
     * @return bool Success status
     */
    public function unassignRole(string $userUuid, string $roleUuid, ?string $removedByUserId = null): bool
    {
        // Store original values
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Switch to user_roles_lookup temporarily and use a composite key
            $this->table = 'user_roles_lookup';

            // Get the original record for audit logging
            $originalData = $this->db->select($this->table, ['*'])
                ->where(['user_uuid' => $userUuid, 'role_uuid' => $roleUuid])
                ->limit(1)
                ->get();

            $originalData = $originalData ? $originalData[0] : null;

            // Delete the record directly as BaseRepository's delete doesn't support composite keys
            $result = $this->db->delete('user_roles_lookup', [
                'user_uuid' => $userUuid,
                'role_uuid' => $roleUuid
            ]);

            // Manual audit logging since we're not using parent::delete
            if ($result && $this->auditLogger && $originalData) {
                $this->auditDataAction(
                    'delete',
                    $userUuid . '_' . $roleUuid, // Composite identifier
                    [],
                    $removedByUserId,
                    $originalData
                );
            }


            return $result;
        } finally {
            // Restore original values
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
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
            ->select('user_roles_lookup', [
                'user_roles_lookup.user_uuid',
                'users.username',
                'users.email',
                'users.status'
            ])
            ->join('users', 'user_roles_lookup.user_uuid = users.uuid', 'LEFT')
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
            ->select('user_roles_lookup', ['user_roles_lookup.role_uuid'])
            ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid', 'LEFT')
            ->where([
                'user_roles_lookup.user_uuid' => $userUuid,
                'roles.name' => $roleName
            ])
            ->limit(1)
            ->get();

        return !empty($result);
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
        $result = $this->db->select('user_roles_lookup', ['user_roles_lookup.role_uuid'])
            ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid', 'LEFT')
            ->where([
                'user_roles_lookup.user_uuid' => $userId,
                'roles.name' => $roleName
            ])
            ->count('user_roles_lookup');

        return $result > 0;
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
        $role = $this->findBy('uuid', $roleUuid, ['name']);
        return $role ? $role['name'] : null;
    }

    /**
     * Get role UUID by name (static method for cross-repository use)
     *
     * @param string $name Role name
     * @return string|null Role UUID or null if not found
     */
    public function getRoleUuidByName(string $name): ?string
    {
        $role = $this->findBy('name', $name, ['uuid']);
        return $role ? $role['uuid'] : null;
    }

    /**
     * Find role by name
     *
     * @param string $name Role name
     * @return array|null Role data or null if not found
     */
    public function findByName(string $name): ?array
    {
        return $this->findBy('name', $name);
    }

    /**
     * Check if role name exists
     *
     * @param string $name The role name to check
     * @param string|null $excludeUuid UUID to exclude from check (for updates)
     * @return bool True if role name exists
     */
    public function nameExists(string $name, ?string $excludeUuid = null): bool
    {
        $conditions = ['name' => $name];

        if ($excludeUuid) {
            $conditions['uuid'] = ['!=', $excludeUuid];
        }

        return $this->count($conditions) > 0;
    }

    /**
     * Get all active roles
     *
     * @return array Array of active roles
     */
    public function findActive(): array
    {
        return $this->findWhere(['status' => 'active']);
    }

    /**
     * Bulk assign roles to users
     *
     * @param array $userUuids Array of user UUIDs
     * @param string $roleUuid Role UUID to assign
     * @return int Number of assignments created
     */
    public function bulkAssignRole(array $userUuids, string $roleUuid): int
    {
        $assignments = [];
        foreach ($userUuids as $userUuid) {
            $assignments[] = [
                'user_uuid' => $userUuid,
                'role_uuid' => $roleUuid,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Temporarily switch table for bulk operation
        $originalTable = $this->table;
        $this->table = 'user_roles_lookup';

        try {
            $uuids = $this->bulkCreate($assignments);
            $count = count($uuids);
        } catch (\Exception $e) {
            $count = 0;
        } finally {
            $this->table = $originalTable;
        }

        return $count;
    }
}
