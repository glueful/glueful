<?php

/**
 * RBAC Extension Routes
 *
 * This file defines routes for RBAC (Role-Based Access Control) functionality including:
 * - Role management (CRUD operations, hierarchy)
 * - Permission management (CRUD operations, assignments)
 * - User-role relationships
 * - User permission assignments
 * - Permission checking and validation
 * - Statistics and maintenance operations
 *
 * All routes in this extension use proper authentication middleware
 * and require appropriate RBAC permissions.
 */

use Glueful\Http\Router;
use Glueful\Extensions\RBAC\Controllers\{
    RoleController,
    PermissionController,
    UserRoleController
};
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

// Controllers will be resolved from the DI container when routes are called
// This ensures proper dependency injection and lazy loading

Router::group('/rbac', function () use ($container) {

    // Role Management Routes
    Router::group('/roles', function () use ($container) {
        /**
         * @route GET /rbac/roles
         * @tag RBAC Roles
         * @summary List all roles
         * @description Retrieves a list of all roles with optional filtering and pagination
         * @requiresAuth true
         * @param page query integer false "Page number for pagination (default: 1)"
         * @param per_page query integer false "Number of items per page (default: 25)"
         * @param search query string false "Search term for role name or slug"
         * @param status query string false "Filter by role status (active, inactive)"
         * @param level query integer false "Filter by role hierarchy level"
         * @param tree query boolean false "Return roles as hierarchical tree structure"
         * @response 200 application/json "Roles retrieved successfully" {
         *   data:array=[{
         *     uuid:string="Role unique identifier",
         *     name:string="Role name",
         *     slug:string="Role slug",
         *     level:integer="Hierarchy level",
         *     status:string="Role status",
         *     is_system:boolean="Whether role is system-protected",
         *     parent_uuid:string="Parent role UUID",
         *     created_at:string="Creation timestamp"
         *   }],
         *   pagination:object="Pagination metadata"
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->index($request);
        });

        /**
         * @route GET /rbac/roles/stats
         * @tag RBAC Roles
         * @summary Get role statistics
         * @description Retrieves comprehensive statistics about roles
         * @requiresAuth true
         * @response 200 application/json "Role statistics retrieved successfully" {
         *   total_roles:integer="Total number of roles",
         *   active_roles:integer="Number of active roles",
         *   system_roles:integer="Number of system roles",
         *   by_level:object="Roles grouped by hierarchy level"
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/stats', function (Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->stats($request);
        });

        /**
         * @route POST /rbac/roles/bulk
         * @tag RBAC Roles
         * @summary Bulk role operations
         * @description Performs bulk operations on multiple roles
         * @requiresAuth true
         * @requestBody action:string="Action to perform (delete, activate, deactivate)"
         *              role_ids:array=[string="Role UUIDs"]
         *              force:boolean="Force operation even with dependencies"
         *              {required=action,role_ids}
         * @response 200 application/json "Bulk operation completed" {
         *   success:integer="Number of successful operations",
         *   failed:integer="Number of failed operations",
         *   errors:array="Error messages for failed operations"
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/bulk', function (Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->bulk($request);
        });

        /**
         * @route GET /rbac/roles/{uuid}
         * @tag RBAC Roles
         * @summary Get role details
         * @description Retrieves detailed information about a specific role
         * @requiresAuth true
         * @param uuid path string true "Role UUID"
         * @response 200 application/json "Role details retrieved successfully" {
         *   uuid:string="Role unique identifier",
         *   name:string="Role name",
         *   slug:string="Role slug",
         *   description:string="Role description",
         *   level:integer="Hierarchy level",
         *   status:string="Role status",
         *   parent_uuid:string="Parent role UUID",
         *   hierarchy:array="Role hierarchy chain",
         *   children:array="Child roles",
         *   user_count:integer="Number of users with this role",
         *   metadata:object="Role metadata",
         *   created_at:string="Creation timestamp",
         *   updated_at:string="Last update timestamp"
         * }
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::get('/{uuid}', function (array $params) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->show($params);
        });

        /**
         * @route POST /rbac/roles
         * @tag RBAC Roles
         * @summary Create new role
         * @description Creates a new role with specified properties
         * @requiresAuth true
         * @requestBody name:string="Role name"
         *              slug:string="Role slug"
         *              description:string="Role description"
         *              parent_uuid:string="Parent role UUID for hierarchy"
         *              status:string="Role status (active, inactive)"
         *              metadata:object="Additional role metadata"
         *              {required=name,slug}
         * @response 201 application/json "Role created successfully" {
         *   uuid:string="Role unique identifier",
         *   name:string="Role name",
         *   slug:string="Role slug"
         * }
         * @response 400 application/json "Invalid request format or validation errors"
         * @response 403 application/json "Permission denied"
         * @response 409 application/json "Role name or slug already exists"
         */
        Router::post('/', function (Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->create($request);
        });

        /**
         * @route PUT /rbac/roles/{uuid}
         * @tag RBAC Roles
         * @summary Update role
         * @description Updates an existing role's properties
         * @requiresAuth true
         * @param uuid path string true "Role UUID"
         * @requestBody name:string="Role name"
         *              description:string="Role description"
         *              parent_uuid:string="Parent role UUID"
         *              status:string="Role status"
         *              metadata:object="Role metadata"
         * @response 200 application/json "Role updated successfully"
         * @response 400 application/json "Invalid request format or validation errors"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::put('/{uuid}', function (array $params, Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->update($params, $request);
        });

        /**
         * @route DELETE /rbac/roles/{uuid}
         * @tag RBAC Roles
         * @summary Delete role
         * @description Deletes a role with optional force deletion
         * @requiresAuth true
         * @param uuid path string true "Role UUID"
         * @param force query boolean false "Force delete even if assigned to users or has children"
         * @response 200 application/json "Role deleted successfully"
         * @response 400 application/json "Cannot delete role (has dependencies)"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::delete('/{uuid}', function (array $params, Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->delete($params, $request);
        });

        /**
         * @route POST /rbac/roles/{uuid}/assign
         * @tag RBAC Roles
         * @summary Assign role to user
         * @description Assigns a role to a specific user
         * @requiresAuth true
         * @param uuid path string true "Role UUID"
         * @requestBody user_uuid:string="User UUID"
         *              scope:array="Assignment scope"
         *              expires_at:string="Expiration timestamp"
         *              assigned_by:string="UUID of assigning user"
         *              {required=user_uuid}
         * @response 200 application/json "Role assigned successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role or user not found"
         */
        Router::post('/{uuid}/assign', function (array $params, Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->assignToUser($params, $request);
        });

        /**
         * @route DELETE /rbac/roles/{uuid}/revoke
         * @tag RBAC Roles
         * @summary Revoke role from user
         * @description Revokes a role from a specific user
         * @requiresAuth true
         * @param uuid path string true "Role UUID"
         * @requestBody user_uuid:string="User UUID" {required=user_uuid}
         * @response 200 application/json "Role revoked successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role or user not found"
         */
        Router::delete('/{uuid}/revoke', function (array $params, Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->revokeFromUser($params, $request);
        });

        /**
         * @route GET /rbac/roles/{uuid}/users
         * @tag RBAC Roles
         * @summary Get users with role
         * @description Retrieves all users assigned to a specific role
         * @requiresAuth true
         * @param uuid path string true "Role UUID"
         * @param page query integer false "Page number for pagination"
         * @param per_page query integer false "Number of items per page"
         * @response 200 application/json "Role users retrieved successfully" {
         *   data:array=[{
         *     user_uuid:string="User UUID",
         *     username:string="Username",
         *     email:string="User email",
         *     assigned_at:string="Assignment timestamp",
         *     expires_at:string="Expiration timestamp",
         *     scope:array="Assignment scope"
         *   }],
         *   pagination:object="Pagination metadata"
         * }
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::get('/{uuid}/users', function (array $params, Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->getUsers($params, $request);
        });

        /**
         * @route POST /rbac/roles/bulk
         * @tag RBAC Roles
         * @summary Bulk role operations
         * @description Performs bulk operations on multiple roles
         * @requiresAuth true
         * @requestBody action:string="Action to perform (delete, activate, deactivate)"
         *              role_ids:array=[string="Role UUIDs"]
         *              force:boolean="Force operation even with dependencies"
         *              {required=action,role_ids}
         * @response 200 application/json "Bulk operation completed" {
         *   success:integer="Number of successful operations",
         *   failed:integer="Number of failed operations",
         *   errors:array="Error messages for failed operations"
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/bulk', function (Request $request) use ($container) {
            $roleController = $container->get(RoleController::class);
            return $roleController->bulk($request);
        });

        /**
         * @route POST /rbac/roles/{role_uuid}/assign-users
         * @tag RBAC Roles
         * @summary Bulk assign role to users
         * @description Assigns a role to multiple users
         * @requiresAuth true
         * @param role_uuid path string true "Role UUID"
         * @requestBody user_uuids:array=[string="User UUIDs"]
         *              scope:array="Assignment scope"
         *              expires_at:string="Expiration timestamp"
         *              assigned_by:string="UUID of assigning user"
         *              {required=user_uuids}
         * @response 200 application/json "Bulk role assignment completed"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::post('/{role_uuid}/assign-users', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->bulkAssignRoleToUsers($params, $request);
        });

        /**
         * @route DELETE /rbac/roles/{role_uuid}/revoke-users
         * @tag RBAC Roles
         * @summary Bulk revoke role from users
         * @description Revokes a role from multiple users
         * @requiresAuth true
         * @param role_uuid path string true "Role UUID"
         * @requestBody user_uuids:array=[string="User UUIDs"] {required=user_uuids}
         * @response 200 application/json "Bulk role revocation completed"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::delete('/{role_uuid}/revoke-users', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->bulkRevokeRoleFromUsers($params, $request);
        });
    }, requiresAdminAuth: false);

    // Permission Management Routes
    Router::group('/permissions', function () use ($container) {
        /**
         * @route GET /rbac/permissions
         * @tag RBAC Permissions
         * @summary List all permissions
         * @description Retrieves a list of all permissions with optional filtering
         * @requiresAuth true
         * @param page query integer false "Page number for pagination"
         * @param per_page query integer false "Number of items per page"
         * @param search query string false "Search term for permission name or slug"
         * @param category query string false "Filter by permission category"
         * @param resource_type query string false "Filter by resource type"
         * @response 200 application/json "Permissions retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->index($request);
        });

        /**
         * @route GET /rbac/permissions/stats
         * @tag RBAC Permissions
         * @summary Get permission statistics
         * @description Retrieves comprehensive statistics about permissions
         * @requiresAuth true
         * @response 200 application/json "Permission statistics retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/stats', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->stats($request);
        });

        /**
         * @route POST /rbac/permissions/cleanup-expired
         * @tag RBAC Permissions
         * @summary Cleanup expired permissions
         * @description Removes all expired permission assignments
         * @requiresAuth true
         * @response 200 application/json "Expired permissions cleaned up"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/cleanup-expired', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->cleanupExpired($request);
        });

        /**
         * @route GET /rbac/permissions/categories
         * @tag RBAC Permissions
         * @summary Get permission categories
         * @description Retrieves all available permission categories
         * @requiresAuth true
         * @response 200 application/json "Permission categories retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/categories', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->getCategories($request);
        });

        /**
         * @route GET /rbac/permissions/resource-types
         * @tag RBAC Permissions
         * @summary Get resource types
         * @description Retrieves all available resource types
         * @requiresAuth true
         * @response 200 application/json "Resource types retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/resource-types', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->getResourceTypes($request);
        });

        /**
         * @route GET /rbac/permissions/{uuid}
         * @tag RBAC Permissions
         * @summary Get permission details
         * @description Retrieves detailed information about a specific permission
         * @requiresAuth true
         * @param uuid path string true "Permission UUID"
         * @response 200 application/json "Permission details retrieved successfully"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Permission not found"
         */
        Router::get('/{uuid}', function (array $params) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->show($params);
        });

        /**
         * @route POST /rbac/permissions
         * @tag RBAC Permissions
         * @summary Create new permission
         * @description Creates a new permission with specified properties
         * @requiresAuth true
         * @requestBody name:string="Permission name"
         *              slug:string="Permission slug"
         *              description:string="Permission description"
         *              category:string="Permission category"
         *              resource_type:string="Resource type"
         *              metadata:object="Additional permission metadata"
         *              {required=name,slug}
         * @response 201 application/json "Permission created successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 409 application/json "Permission name or slug already exists"
         */
        Router::post('/', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->create($request);
        });

        /**
         * @route PUT /rbac/permissions/{uuid}
         * @tag RBAC Permissions
         * @summary Update permission
         * @description Updates an existing permission's properties
         * @requiresAuth true
         * @param uuid path string true "Permission UUID"
         * @requestBody name:string="Permission name"
         *              description:string="Permission description"
         *              category:string="Permission category"
         *              metadata:object="Permission metadata"
         * @response 200 application/json "Permission updated successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Permission not found"
         */
        Router::put('/{uuid}', function (array $params, Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->update($params, $request);
        });

        /**
         * @route DELETE /rbac/permissions/{uuid}
         * @tag RBAC Permissions
         * @summary Delete permission
         * @description Deletes a permission with optional force deletion
         * @requiresAuth true
         * @param uuid path string true "Permission UUID"
         * @param force query boolean false "Force delete even if assigned to users"
         * @response 200 application/json "Permission deleted successfully"
         * @response 400 application/json "Cannot delete permission (still assigned)"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Permission not found"
         */
        Router::delete('/{uuid}', function (array $params, Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->delete($params, $request);
        });

        /**
         * @route POST /rbac/permissions/{uuid}/assign
         * @tag RBAC Permissions
         * @summary Assign permission to user
         * @description Assigns a permission directly to a user
         * @requiresAuth true
         * @param uuid path string true "Permission UUID"
         * @requestBody user_uuid:string="User UUID"
         *              resource:string="Resource filter"
         *              expires_at:string="Expiration timestamp"
         *              constraints:object="Permission constraints"
         *              granted_by:string="UUID of granting user"
         *              {required=user_uuid}
         * @response 200 application/json "Permission assigned successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Permission or user not found"
         */
        Router::post('/{uuid}/assign', function (array $params, Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->assignToUser($params, $request);
        });

        /**
         * @route DELETE /rbac/permissions/{uuid}/revoke
         * @tag RBAC Permissions
         * @summary Revoke permission from user
         * @description Revokes a permission from a specific user
         * @requiresAuth true
         * @param uuid path string true "Permission UUID"
         * @requestBody user_uuid:string="User UUID" {required=user_uuid}
         * @response 200 application/json "Permission revoked successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Permission or user not found"
         */
        Router::delete('/{uuid}/revoke', function (array $params, Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->revokeFromUser($params, $request);
        });

        /**
         * @route POST /rbac/permissions/batch-assign
         * @tag RBAC Permissions
         * @summary Batch assign permissions
         * @description Assigns multiple permissions to a user
         * @requiresAuth true
         * @requestBody user_uuid:string="User UUID"
         *              permissions:array=[{permission:string, resource:string, options:object}]
         *              options:object="Global options for all assignments"
         *              {required=user_uuid,permissions}
         * @response 200 application/json "Batch permission assignment completed"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/batch-assign', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->batchAssign($request);
        });

        /**
         * @route POST /rbac/permissions/batch-revoke
         * @tag RBAC Permissions
         * @summary Batch revoke permissions
         * @description Revokes multiple permissions from a user
         * @requiresAuth true
         * @requestBody user_uuid:string="User UUID"
         *              permission_slugs:array=[string="Permission slugs to revoke"]
         *              {required=user_uuid,permission_slugs}
         * @response 200 application/json "Batch permission revocation completed"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/batch-revoke', function (Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->batchRevoke($request);
        });
    }, requiresAdminAuth: false);

    // User-specific RBAC Routes
    Router::group('/users', function () use ($container) {
        /**
         * @route GET /rbac/users/{user_uuid}/roles
         * @tag RBAC Users
         * @summary Get user roles
         * @description Retrieves all roles assigned to a specific user
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @param scope query string false "JSON-encoded scope filter"
         * @response 200 application/json "User roles retrieved successfully"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "User not found"
         */
        Router::get('/{user_uuid}/roles', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->getUserRoles($params, $request);
        });

        /**
         * @route POST /rbac/users/{user_uuid}/roles
         * @tag RBAC Users
         * @summary Assign roles to user
         * @description Assigns multiple roles to a user
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @requestBody role_uuids:array=[string="Role UUIDs"]
         *              scope:array="Assignment scope"
         *              expires_at:string="Expiration timestamp"
         *              assigned_by:string="UUID of assigning user"
         *              {required=role_uuids}
         * @response 200 application/json "Roles assigned successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/{user_uuid}/roles', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->assignRoles($params, $request);
        });

        /**
         * @route PUT /rbac/users/{user_uuid}/roles
         * @tag RBAC Users
         * @summary Replace user roles
         * @description Replaces all user roles with the specified set
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @requestBody role_uuids:array=[string="Role UUIDs"]
         *              scope:array="Assignment scope"
         *              expires_at:string="Expiration timestamp"
         *              assigned_by:string="UUID of assigning user"
         *              {required=role_uuids}
         * @response 200 application/json "User roles updated successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::put('/{user_uuid}/roles', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->replaceUserRoles($params, $request);
        });

        /**
         * @route DELETE /rbac/users/{user_uuid}/roles/{role_uuid}
         * @tag RBAC Users
         * @summary Revoke specific role from user
         * @description Revokes a specific role from a user
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @param role_uuid path string true "Role UUID"
         * @response 200 application/json "Role revoked successfully"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "User or role not found"
         */
        Router::delete('/{user_uuid}/roles/{role_uuid}', function (array $params) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->revokeRole($params);
        });

        /**
         * @route GET /rbac/users/{user_uuid}/permissions
         * @tag RBAC Users
         * @summary Get user direct permissions
         * @description Retrieves all permissions directly assigned to a user
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @param active_only query boolean false "Return only active permissions"
         * @response 200 application/json "User permissions retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/{user_uuid}/permissions', function (array $params, Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->getUserDirectPermissions($params, $request);
        });

        /**
         * @route GET /rbac/users/{user_uuid}/effective-permissions
         * @tag RBAC Users
         * @summary Get user effective permissions
         * @description Retrieves all effective permissions for a user (direct + role-based)
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @param scope query string false "JSON-encoded scope filter"
         * @response 200 application/json "User effective permissions retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/{user_uuid}/effective-permissions', function (array $params, Request $request) use ($container) {
            $permissionController = $container->get(PermissionController::class);
            return $permissionController->getUserEffectivePermissions($params, $request);
        });

        /**
         * @route GET /rbac/users/{user_uuid}/access-overview
         * @tag RBAC Users
         * @summary Get user access overview
         * @description Retrieves complete access overview for a user (roles + permissions)
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @param scope query string false "JSON-encoded scope filter"
         * @response 200 application/json "User access overview retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/{user_uuid}/access-overview', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->getUserAccessOverview($params, $request);
        });

        /**
         * @route GET /rbac/users/{user_uuid}/role-history
         * @tag RBAC Users
         * @summary Get user role history
         * @description Retrieves role assignment history for a user
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @param page query integer false "Page number for pagination"
         * @param per_page query integer false "Number of items per page"
         * @param include_deleted query boolean false "Include deleted role assignments"
         * @response 200 application/json "User role history retrieved successfully"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/{user_uuid}/role-history', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->getUserRoleHistory($params, $request);
        });

        /**
         * @route POST /rbac/users/{user_uuid}/check-role
         * @tag RBAC Users
         * @summary Check if user has role
         * @description Checks if a user has a specific role
         * @requiresAuth true
         * @param user_uuid path string true "User UUID"
         * @requestBody role_slug:string="Role slug to check"
         *              scope:array="Scope filter"
         *              {required=role_slug}
         * @response 200 application/json "Role check completed" {
         *   has_role:boolean="Whether user has the role",
         *   user_uuid:string="User UUID",
         *   role_slug:string="Role slug checked"
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/{user_uuid}/check-role', function (array $params, Request $request) use ($container) {
            $userRoleController = $container->get(UserRoleController::class);
            return $userRoleController->checkUserRole($params, $request);
        });
    }, requiresAdminAuth: false);

    // Permission/Role Checking Routes
    /**
     * @route POST /rbac/check-permission
     * @tag RBAC Validation
     * @summary Check user permission
     * @description Checks if a user has a specific permission
     * @requiresAuth true
     * @requestBody user_uuid:string="User UUID"
     *              permission:string="Permission slug to check"
     *              resource:string="Resource filter"
     *              context:object="Permission context"
     *              {required=user_uuid,permission}
     * @response 200 application/json "Permission check completed" {
     *   has_permission:boolean="Whether user has the permission",
     *   user_uuid:string="User UUID",
     *   permission:string="Permission checked"
     * }
     * @response 400 application/json "Invalid request format"
     * @response 403 application/json "Permission denied"
     */
    Router::post('/check-permission', function (Request $request) use ($container) {
        $permissionController = $container->get(PermissionController::class);
        return $permissionController->checkPermission($request);
    });

    // Statistics and Maintenance Routes
    /**
     * @route GET /rbac/user-roles/stats
     * @tag RBAC Statistics
     * @summary Get user-role statistics
     * @description Retrieves statistics about user-role assignments
     * @requiresAuth true
     * @response 200 application/json "User-role statistics retrieved successfully"
     * @response 403 application/json "Permission denied"
     */
    Router::get('/user-roles/stats', function (Request $request) use ($container) {
        $userRoleController = $container->get(UserRoleController::class);
        return $userRoleController->stats($request);
    });

    /**
     * @route POST /rbac/user-roles/cleanup-expired
     * @tag RBAC Maintenance
     * @summary Cleanup expired role assignments
     * @description Removes all expired role assignments
     * @requiresAuth true
     * @response 200 application/json "Expired role assignments cleaned up"
     * @response 403 application/json "Permission denied"
     */
    Router::post('/user-roles/cleanup-expired', function (Request $request) use ($container) {
        $userRoleController = $container->get(UserRoleController::class);
        return $userRoleController->cleanupExpiredRoles($request);
    });
}, requiresAdminAuth: false);
