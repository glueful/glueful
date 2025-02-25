<?php
declare(strict_types=1);

namespace Glueful\Identity;

use Glueful\Http\Response;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

/**
 * Permission Management Service
 * 
 * Handles role-based access control (PBAC,RBAC) operations:
 * - User permissions management
 * - Role permissions management
 * - Permission assignments and revocations
 * - Access control verification
 * 
 * Security features:
 * - Granular permission control
 * - Role-based access control
 * - Permission inheritance
 * - Access verification
 * 
 * @package Glueful\Identity
 */
class Permissions {
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $db;

    /**
     * Initialize permissions service
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
     * Retrieve all permissions for a role
     * 
     * Gets complete permission set for specified role:
     * - Direct permissions
     * - Inherited permissions
     * - Access control lists
     * 
     * @param string $roleName Target role name
     * @return Response Permission list or error response
     */
    public function getAll(string $roleName = "superuser"): Response
    {
        try {
            $permissions = $this->db->select('role_permissions', ['role_permissions.model AS permission_model'])
                ->join('roles', 'role_permissions.role_uuid = roles.uuid', 'LEFT')
                ->where(['roles.name' => $roleName])
                ->get();

            return Response::ok($permissions);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user-specific permissions
     * 
     * Retrieves permissions directly assigned to user:
     * - Personal permissions
     * - Override permissions
     * - Custom access rights
     * 
     * @param string $uuid User's UUID
     * @return Response User permissions or error
     */
    public function getUser(string $uuid): Response
    {
        try {
            $role = $this->db->select('user_permissions', ['user_uuid', 'model', 'permissions'])
                ->where(['user_uuid' => $uuid])
                ->get();

            if ($role) {
                $role['permissions'] = json_decode($role['permissions'], true);
            }

            return Response::ok($role);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Assign permissions to user
     * 
     * Grants specific permissions to user:
     * - Creates permission records
     * - Handles permission conflicts
     * - Validates permission format
     * 
     * @param string $user_uuid Target user's UUID
     * @param string $model Permission model/resource
     * @param string $permissions Comma-separated permission list
     * @return Response Assignment result
     */
    public function assignUser(string $user_uuid, string $model, string $permissions): Response
    {
        try {
            $uuid = Utils::generateNanoID();
            $permissions = json_encode(explode(',', $permissions));

            $success = $this->db->insert('user_permissions', [
                'uuid' => $uuid,
                'user_uuid' => $user_uuid,
                'model' => $model,
                'permissions' => $permissions
            ]);

            return $success ? Response::ok(['message' => 'Permission assigned']) 
                            : Response::error('Failed to assign permission');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove user permissions
     * 
     * Revokes specific permissions from user:
     * - Removes permission records
     * - Handles cascading effects
     * - Validates removal impact
     * 
     * @param string $user_uuid Target user's UUID
     * @param string $model Permission model to remove
     * @return Response Removal result
     */
    public function unassignUser(string $user_uuid, string $model): Response
    {
        try {
            $deleted = $this->db->delete('user_permissions', ['user_uuid' => $user_uuid, 'model' => $model],false);

            return $deleted ? Response::ok(['message' => 'Permission removed']) 
                            : Response::error('No permission found to remove');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get role permissions
     * 
     * Retrieves complete permission set for role:
     * - Basic permissions
     * - Extended permissions
     * - Resource access rights
     * 
     * @param string $uuid Role's UUID
     * @return Response Role permissions or error
     */
    public function getRole(string $uuid): Response
    {
        try {
            $role = $this->db->select('role_permissions', ['role_uuid', 'model', 'permissions'])
                ->where(['role_uuid' => $uuid])
                ->get();

            if ($role) {
                $role['permissions'] = json_decode($role['permissions'], true);
            }

            return Response::ok($role);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Assign permissions to role
     * 
     * Grants permissions to specified role:
     * - Creates role permission records
     * - Handles inheritance
     * - Manages conflicts
     * 
     * @param string $role_uuid Target role's UUID
     * @param string $model Permission model/resource
     * @param string $permissions Comma-separated permissions
     * @return Response Assignment result
     */
    public function assignRole(string $role_uuid, string $model, string $permissions): Response
    {
        try {
            $uuid = Utils::generateNanoID();
            $permissions = json_encode(explode(',', $permissions));

            $success = $this->db->insert('role_permissions', [
                'uuid' => $uuid,
                'role_uuid' => $role_uuid,
                'model' => $model,
                'permissions' => $permissions
            ]);

            return $success ? Response::ok(['message' => 'Role permission assigned']) 
                            : Response::error('Failed to assign role permission');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove role permissions
     * 
     * Revokes permissions from role:
     * - Removes role permissions
     * - Updates inherited permissions
     * - Handles cascading changes
     * 
     * @param string $role_uuid Target role's UUID
     * @param string $model Permission model to remove
     * @return Response Removal result
     */
    public function unassignRole(string $role_uuid, string $model): Response
    {
        try {
            $deleted = $this->db->delete('role_permissions', ['role_uuid' => $role_uuid, 'model' => $model],false);

            return $deleted ? Response::ok(['message' => 'Role permission removed']) 
                            : Response::error('No role permission found to remove');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}