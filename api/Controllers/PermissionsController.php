<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Repository\{PermissionRepository, RoleRepository};
use Glueful\Helpers\Request;
use Glueful\Database\{Connection, QueryBuilder};

/**
 * Permissions Controller
 *
 * Handles role and permission management operations:
 * - Listing and creating permissions
 * - Managing role permissions
 * - Assigning and removing user roles
 *
 * @package Glueful\Controllers
 */
class PermissionsController
{
    private RoleRepository $roleRepo;
    private PermissionRepository $permissionRepo;
    private QueryBuilder $queryBuilder;

    public function __construct()
    {
        $this->roleRepo = new RoleRepository();
        $this->permissionRepo = new PermissionRepository();

        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Get all permissions with pagination
     *
     * @return mixed HTTP response
     */
    public function getPermissions(): mixed
    {
        try {
            $data = Request::getPostData();

            // Set default values for pagination and filtering
            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['per_page'] ?? 25);

            // Build query for permissions
            $results = $this->queryBuilder
            ->join('roles', 'role_permissions.role_uuid = roles.uuid', 'INNER') // Ensure the JOIN is applied
            ->select('role_permissions', [
                'role_permissions.model',
                'role_permissions.permissions',
                'roles.name'
            ])
            ->paginate($page, $perPage);

            return Response::ok($results, 'Permissions retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get permissions error: " . $e->getMessage());
            return Response::error(
                'Failed to get permissions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get all roles
     *
     * @return mixed HTTP response
     */
    public function getRoles(): mixed
    {
        try {
            $roles = $this->roleRepo->getRoles();
            return Response::ok($roles, 'Roles retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get roles error: " . $e->getMessage());
            return Response::error(
                'Failed to get roles: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create a new permission
     *
     * @return mixed HTTP response
     */
    public function createPermission(): mixed
    {
        try {
            $data = Request::getPostData();

            if (!isset($data['model']) || !isset($data['permissions']) || !is_array($data['permissions'])) {
                $msg = 'Model name and permissions array are required';
                return Response::error($msg, Response::HTTP_BAD_REQUEST)->send();
            }

            $model = $data['model'];
            $permissions = $data['permissions'];
            $description = $data['description'] ?? null;

            $result = $this->permissionRepo->createPermission(
                $model,
                $permissions,
                $description
            );

            return Response::ok($result, 'Permission created successfully')->send();
        } catch (\Exception $e) {
            error_log("Create permission error: " . $e->getMessage());
            return Response::error(
                'Failed to create permission: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update an existing permission
     *
     * @return mixed HTTP response
     */
    public function updatePermission(): mixed
    {
        try {
            $data = Request::getPostData();

            if (!isset($data['model']) || !isset($data['permissions'])) {
                return Response::error('Model name and permissions are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->permissionRepo->updatePermission(
                $data['uuid'],
                $data
            );

            return Response::ok($result, 'Permission updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update permission error: " . $e->getMessage());
            return Response::error(
                'Failed to update permission: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Assign permissions to a role
     *
     * @return mixed HTTP response
     */
    public function assignPermissionsToRole(): mixed
    {
        try {
            $data = Request::getPostData();
            $roleUuid = $data['role_uuid'];
            $model = $data['model'];
            $permissions = $data['permissions'];
            $result = $this->permissionRepo->assignRolePermission($roleUuid, $model, $permissions);
            return Response::ok($result, 'Permissions assigned to role successfully')->send();
        } catch (\Exception $e) {
            error_log("Assign permissions to role error: " . $e->getMessage());
            return Response::error(
                'Assign permissions to role error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update role permission
     *
     * @return mixed HTTP response
     */
    public function updateRolePermission(): mixed
    {
        try {
            $data = Request::getPostData();
            $roleUuid = $data['role_uuid'];
            $model = $data['model'];
            $permissions = $data['permissions'];
            $result = $this->permissionRepo->updateRolePermission($roleUuid, $model, $permissions);
            return Response::ok($result, 'Role permissions updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update role permissions error: " . $e->getMessage());
            return Response::error(
                'Update role permissions error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Remove role permission
     *
     * @return mixed HTTP response
     */
    public function removeRolePermission(): mixed
    {
        try {
            $data = Request::getPostData();
            $roleUuid = $data['role_uuid'];
            $model = $data['model'];
            $result = $this->permissionRepo->removeRolePermission($roleUuid, $model);
            return Response::ok($result, 'Role permissions removed successfully')->send();
        } catch (\Exception $e) {
            error_log("Remove role permissions error: " . $e->getMessage());
            return Response::error(
                'Remove role permissions error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Assign roles to a user
     *
     * @return mixed HTTP response
     */
    public function assignRolesToUser(): mixed
    {
        try {
            $data = Request::getPostData();
            $result = $this->roleRepo->assignRole($data['user_uuid'], $data['role_uuid']);
            return Response::ok($result, 'Role assigned to user successfully')->send();
        } catch (\Exception $e) {
            error_log("Assign roles to user error: " . $e->getMessage());
            return Response::error(
                'Assign roles to user error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Remove user role
     *
     * @return mixed HTTP response
     */
    public function removeUserRole(): mixed
    {
        try {
            $data = Request::getPostData();
            $result = $this->roleRepo->unassignRole($data['user_uuid'], $data['role_uuid']);
            return Response::ok($result, 'Role removed from user successfully')->send();
        } catch (\Exception $e) {
            error_log("Remove user role error: " . $e->getMessage());
            return Response::error(
                'Remove user role error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update role's permissions
     *
     * @return mixed HTTP response
     */
    public function updateRolePermissions(): mixed
    {
        // This method appears to be incomplete in the AdminController
        // Implemented as a stub for now
        return Response::ok(null, 'Method not fully implemented yet')->send();
    }
}
