<?php

/**
 * User Management Routes
 *
 * Administrative routes for user management functionality including:
 * - User CRUD operations
 * - Role assignment
 * - Bulk operations
 * - User statistics
 * - Import/Export
 * - Activity tracking
 * - Session management
 *
 * All routes are under /admin/users prefix and protected by authentication middleware
 * and require appropriate administrative permissions.
 */

use Glueful\Http\Router;
use Glueful\Controllers\UsersController;
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

Router::group('/admin/users', function () use ($container) {
    /**
     * @route GET /admin/users
     * @tag Users
     * @summary List all users with roles
     * @description Retrieves a paginated list of users with their associated roles
     * @requiresAuth true
     * @query page integer "Page number (default: 1)"
     * @query per_page integer "Items per page (default: 25)"
     * @query search string "Search term for username/email"
     * @query status string "Filter by status (active/inactive/suspended)"
     * @query role_id string "Filter by role UUID"
     * @query sort string "Sort field (default: created_at)"
     * @query order string "Sort order ASC/DESC (default: DESC)"
     * @query include_deleted boolean "Include soft-deleted users"
     * @response 200 application/json "List of users with roles" {
     *   success:boolean,
     *   data:array=[{
     *     uuid:string,
     *     username:string,
     *     email:string,
     *     status:string,
     *     created_at:string,
     *     last_login_date:string,
     *     roles:array=[{uuid:string,name:string}]
     *   }],
     *   pagination:object
     * }
     */
    Router::get('/', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->index($request);
    });

    /**
     * @route GET /admin/users/stats
     * @tag Users
     * @summary Get user statistics
     * @description Retrieves user statistics for dashboard
     * @requiresAuth true
     * @query period string "Time period (7days/30days/90days/year)"
     * @response 200 application/json "User statistics"
     */
    Router::get('/stats', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->stats($request);
    });

    /**
     * @route GET /admin/users/search
     * @tag Users
     * @summary Advanced user search
     * @description Search users with advanced filters
     * @requiresAuth true
     * @query q string "Search query"
     * @query status string "Filter by status"
     * @query role string "Filter by role name"
     * @query created_after string "Filter by creation date"
     * @query created_before string "Filter by creation date"
     * @query last_login_after string "Filter by last login"
     * @query last_login_before string "Filter by last login"
     * @query has_permission string "Filter by permission"
     * @response 200 application/json "Search results"
     */
    Router::get('/search', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->search($request);
    });

    /**
     * @route GET /admin/users/export
     * @tag Users
     * @summary Export users
     * @description Export users to CSV or JSON format
     * @requiresAuth true
     * @query format string "Export format (csv/json)"
     * @query status string "Filter by status"
     * @query role string "Filter by role"
     * @query include_deleted boolean "Include deleted users"
     * @response 200 "Export file"
     */
    Router::get('/export', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->export($request);
    });

    /**
     * @route POST /admin/users/import
     * @tag Users
     * @summary Import users
     * @description Import users from CSV or JSON file
     * @requiresAuth true
     * @requestBody file:file="Import file" format:string="File format (csv/json)"
     *              update_existing:boolean="Update existing users"
     *              send_welcome_email:boolean="Send welcome emails"
     *              default_password:string="Default password for new users"
     *              {required=file}
     * @response 200 application/json "Import results"
     */
    Router::post('/import', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->import($request);
    });

    /**
     * @route POST /admin/users/bulk
     * @tag Users
     * @summary Bulk operations
     * @description Perform bulk operations on multiple users
     * @requiresAuth true
     * @requestBody action:string="Action (delete/restore/activate/deactivate/suspend/assign_role/remove_role)"
     *              user_ids:array="Array of user UUIDs"
     *              role_id:string="Role UUID (for role operations)"
     *              {required=action,user_ids}
     * @response 200 application/json "Operation results"
     */
    Router::post('/bulk', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->bulk($request);
    });

    /**
     * @route GET /admin/users/{uuid}
     * @tag Users
     * @summary Get user details
     * @description Retrieves detailed information about a specific user
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @response 200 application/json "User details with roles and permissions"
     * @response 404 application/json "User not found"
     */
    Router::get('/{uuid}', function (array $params, Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->show($params);
    });

    /**
     * @route POST /admin/users
     * @tag Users
     * @summary Create new user
     * @description Creates a new user with optional role assignment
     * @requiresAuth true
     * @requestBody username:string="Username" email:string="Email address"
     *              password:string="Password" status:string="Status (active/inactive)"
     *              roles:array="Role UUIDs" profile:object="Profile data"
     *              {required=username,email,password}
     * @response 201 application/json "Created user"
     * @response 422 application/json "Validation errors"
     */
    Router::post('/', function (Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->create($request);
    });

    /**
     * @route PUT /admin/users/{uuid}
     * @tag Users
     * @summary Update user
     * @description Updates user information and roles
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @requestBody username:string="Username" email:string="Email address"
     *              status:string="Status" password:string="New password"
     *              roles:array="Role UUIDs" profile:object="Profile data"
     * @response 200 application/json "Updated user"
     * @response 404 application/json "User not found"
     * @response 422 application/json "Validation errors"
     */
    Router::put('/{uuid}', function (array $params, Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->update($params, $request);
    });

    /**
     * @route DELETE /admin/users/{uuid}
     * @tag Users
     * @summary Delete user
     * @description Soft deletes a user
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @response 200 application/json "User deleted"
     * @response 404 application/json "User not found"
     */
    Router::delete('/{uuid}', function (array $params) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->delete($params);
    });

    /**
     * @route POST /admin/users/{uuid}/restore
     * @tag Users
     * @summary Restore user
     * @description Restores a soft-deleted user
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @response 200 application/json "User restored"
     * @response 404 application/json "User not found"
     */
    Router::post('/{uuid}/restore', function (array $params) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->restore($params);
    });

    /**
     * @route GET /admin/users/{uuid}/activity
     * @tag Users
     * @summary Get user activity
     * @description Retrieves user activity log
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @query page integer "Page number"
     * @query per_page integer "Items per page"
     * @response 200 application/json "Activity log"
     * @response 404 application/json "User not found"
     */
    Router::get('/{uuid}/activity', function (array $params, Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->activity($params, $request);
    });

    /**
     * @route GET /admin/users/{uuid}/sessions
     * @tag Users
     * @summary Get user sessions
     * @description Retrieves all active sessions for a user
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @response 200 application/json "User sessions"
     * @response 404 application/json "User not found"
     */
    Router::get('/{uuid}/sessions', function (array $params) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->sessions($params);
    });

    /**
     * @route DELETE /admin/users/{uuid}/sessions
     * @tag Users
     * @summary Terminate user sessions
     * @description Terminates all or specific user sessions
     * @requiresAuth true
     * @param uuid path string true "User UUID"
     * @requestBody session_id:string="Specific session ID to terminate"
     * @response 200 application/json "Sessions terminated"
     * @response 404 application/json "User not found"
     */
    Router::delete('/{uuid}/sessions', function (array $params, Request $request) use ($container) {
        $usersController = $container->get(UsersController::class);
        return $usersController->terminateSessions($params, $request);
    });
}, requiresAuth: true);
