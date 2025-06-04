<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Repository\UserRepository;
use Glueful\Repository\RoleRepository;
use Glueful\Repository\PermissionRepository;
use Glueful\Exceptions\NotFoundException;
use Glueful\Auth\TokenStorageService;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Database\RawExpression;
use Glueful\Database\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * UsersController
 *
 * Handles all user-related operations including:
 * - User CRUD operations with role management
 * - Bulk operations
 * - User search and filtering
 * - Password management
 * - Session management
 * - User statistics and analytics
 * - Import/Export functionality
 */
class UsersController
{
    use DatabaseConnectionTrait;

    private UserRepository $userRepository;
    private RoleRepository $roleRepository;
    private PermissionRepository $permissionRepository;
    private TokenStorageService $tokenStorage;

    public function __construct(
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository,
        TokenStorageService $tokenStorage
    ) {
        $this->userRepository = $userRepository;
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Get all users with their roles
     *
     * @route GET /api/users
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        try {
            $page = (int) $request->query->get('page', 1);
            $perPage = (int) $request->query->get('per_page', 25);
            $search = $request->query->get('search', '');
            $status = $request->query->get('status', '');
            $roleId = $request->query->get('role_id', '');
            $sortBy = $request->query->get('sort', 'created_at');
            $sortOrder = strtoupper($request->query->get('order', 'DESC'));
            $includeDeleted = filter_var($request->query->get('include_deleted', false), FILTER_VALIDATE_BOOLEAN);

            // Build conditions for filtering
            $conditions = [];
            if ($status) {
                $conditions['status'] = $status;
            }

            // Handle search across username and email
            if ($search) {
                // For complex search, we might need to use findWhere or implement search in repository
                // For now, we'll keep it simple and let the repository handle it if it supports it
                $conditions['search'] = $search;
            }

            // Handle role filtering
            if ($roleId) {
                $conditions['role_id'] = $roleId;
            }

            $orderBy = [$sortBy => $sortOrder];

            // Use the query builder directly to handle soft deletes properly
            $query = $this->getQueryBuilder()->select('users', [
                'uuid', 'username', 'email', 'status', 'created_at', 'last_login_date'
            ]);

            // Add standard WHERE conditions
            if (!empty($conditions)) {
                $query->where($conditions);
            }

            // Handle soft deletes with proper NULL checking
            if (!$includeDeleted) {
                $query->whereNull('deleted_at');
            }

            // Add ordering
            if (count($orderBy) > 0) {
                $query->orderBy($orderBy);
            }

            $paginatedResult = $query->paginate($page, $perPage);

            // Attach roles to the users from the pagination result
            $users = $paginatedResult['data'] ?? [];
            foreach ($users as &$user) {
                $user['roles'] = $this->roleRepository->getUserRoles($user['uuid']);
            }

            // Update the data in the pagination result and return it directly
            $paginatedResult['data'] = $users;

            return Response::ok($paginatedResult, 'Users retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get a single user with roles and detailed information
     *
     * @route GET /api/users/{uuid}
     * @param array $params
     * @return Response
     */
    public function show(array $params): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';

            $user = $this->userRepository->findByUUID($uuid);
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            // Get user roles using UUID
            $user['roles'] = $this->roleRepository->getUserRoles($user['uuid']);

            // Get user permissions (aggregated from all roles)
            $user['permissions'] = $this->permissionRepository->getEffectivePermissions($user['uuid']);

            // Get user profile
            $user['profile'] = $this->userRepository->getProfile($user['uuid']) ?? [];

            // Remove sensitive data
            unset($user['password']);

            return Response::ok($user, 'User details retrieved successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Create a new user with roles
     *
     * @route POST /api/users
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        try {
            $data = $request->toArray();

            // Validate required fields
            if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
                return Response::error(
                    'Username, email, and password are required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::error(
                    'Invalid email format',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            // Validate password length
            if (strlen($data['password']) < 8) {
                return Response::error(
                    'Password must be at least 8 characters',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            // Hash password using PHP's password_hash
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

            // Extract roles and profile data
            $roleUuids = $data['roles'] ?? [];
            $profileData = $data['profile'] ?? [];
            unset($data['roles'], $data['profile']);

            // Create user
            $userUuid = $this->userRepository->create($data);
            if (!$userUuid) {
                return Response::error('Failed to create user', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            // Assign roles if provided
            if (!empty($roleUuids)) {
                foreach ($roleUuids as $roleUuid) {
                    $this->roleRepository->assignRole($userUuid, $roleUuid);
                }
            }

            // Create profile if data provided
            if (!empty($profileData)) {
                $this->userRepository->updateProfile($userUuid, $profileData);
            }

            // Fetch created user with roles
            $user = $this->userRepository->findByUUID($userUuid);
            $user['roles'] = $this->roleRepository->getUserRoles($userUuid);
            unset($user['password']);

            return Response::created($user, 'User created successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Update user information
     *
     * @route PUT /api/users/{uuid}
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function update(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            $user = $this->userRepository->findByUUID($uuid);
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            // Validate email if provided
            if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::error(
                    'Invalid email format',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            // Handle password update
            if (isset($data['password'])) {
                if (strlen($data['password']) < 8) {
                    return Response::error(
                        'Password must be at least 8 characters',
                        Response::HTTP_BAD_REQUEST
                    )->send();
                }
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

                // Invalidate user sessions on password change
                $this->tokenStorage->revokeAllUserSessions($user['uuid']);
            }

            // Extract roles and profile data
            $roleUuids = $data['roles'] ?? null;
            $profileData = $data['profile'] ?? null;
            unset($data['roles'], $data['profile']);

            // Update user
            if (!empty($data)) {
                $this->userRepository->update($user['uuid'], $data);
            }

            // Update roles if provided
            if ($roleUuids !== null) {
                // Get current roles
                $currentRoles = $this->roleRepository->getUserRoles($user['uuid']);
                $currentRoleUuids = array_column($currentRoles, 'role_uuid');

                // Remove roles that are no longer assigned
                foreach ($currentRoleUuids as $currentRoleUuid) {
                    if (!in_array($currentRoleUuid, $roleUuids)) {
                        $this->roleRepository->unassignRole($user['uuid'], $currentRoleUuid);
                    }
                }

                // Add new roles
                foreach ($roleUuids as $roleUuid) {
                    if (!in_array($roleUuid, $currentRoleUuids)) {
                        $this->roleRepository->assignRole($user['uuid'], $roleUuid);
                    }
                }
            }

            // Update profile if provided
            if ($profileData !== null) {
                $this->userRepository->updateProfile($user['uuid'], $profileData);
            }

            // Fetch updated user
            $updatedUser = $this->userRepository->findByUUID($user['uuid']);
            $updatedUser['roles'] = $this->roleRepository->getUserRoles($user['uuid']);
            unset($updatedUser['password']);

            return Response::ok($updatedUser, 'User updated successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Delete user (soft delete)
     *
     * @route DELETE /api/users/{uuid}
     * @param array $params
     * @return Response
     */
    public function delete(array $params): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';

            $user = $this->userRepository->findByUUID($uuid);
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            // Prevent deletion of superuser
            $roles = $this->roleRepository->getUserRoles($user['uuid']);
            if (in_array('superuser', array_column($roles, 'role_name'))) {
                return Response::error(
                    'Cannot delete superuser',
                    Response::HTTP_FORBIDDEN
                )->send();
            }

            // Soft delete user (use BaseRepository delete method)
            $this->userRepository->delete($user['uuid']);

            // Invalidate user sessions
            $this->tokenStorage->revokeAllUserSessions($user['uuid']);

            return Response::ok(null, 'User deleted successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Restore soft-deleted user
     *
     * @route POST /api/users/{uuid}/restore
     * @param array $params
     * @return Response
     */
    public function restore(array $params): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';

            $user = $this->userRepository->findByUUID($uuid); // For restore, check if user exists
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            // Use BaseRepository restore method - implement in UserRepository if not exists
            // For now, update the deleted_at field to null
            $this->userRepository->update($user['uuid'], ['deleted_at' => null]);

            return Response::ok(null, 'User restored successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Bulk operations on users
     *
     * @route POST /api/users/bulk
     * @param Request $request
     * @return Response
     */
    public function bulk(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['action']) || empty($data['user_ids'])) {
                return Response::error(
                    'Action and user_ids are required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            foreach ($data['user_ids'] as $userUuid) {
                try {
                    $user = $this->userRepository->findByUuid($userUuid);
                    if (!$user) {
                        $results['failed']++;
                        $results['errors'][] = "User {$userUuid} not found";
                        continue;
                    }

                    switch ($data['action']) {
                        case 'delete':
                            $this->userRepository->delete($user['uuid']);
                            break;
                        case 'restore':
                            $this->userRepository->update($user['uuid'], ['deleted_at' => null]);
                            break;
                        case 'activate':
                            $this->userRepository->update($user['uuid'], ['status' => 'active']);
                            break;
                        case 'deactivate':
                            $this->userRepository->update($user['uuid'], ['status' => 'inactive']);
                            break;
                        case 'suspend':
                            $this->userRepository->update($user['uuid'], ['status' => 'suspended']);
                            $this->tokenStorage->revokeAllUserSessions($user['uuid']);
                            break;
                        case 'assign_role':
                            if (!empty($data['role_id'])) {
                                $this->roleRepository->assignRole($user['uuid'], $data['role_id']);
                            }
                            break;
                        case 'remove_role':
                            if (!empty($data['role_id'])) {
                                $this->roleRepository->unassignRole($user['uuid'], $data['role_id']);
                            }
                            break;
                    }

                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Failed for user {$userUuid}: " . $e->getMessage();
                }
            }

            return Response::ok(
                $results,
                "Bulk operation completed: {$results['success']} succeeded, {$results['failed']} failed"
            )->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get user statistics
     *
     * @route GET /api/users/stats
     * @param Request $request
     * @return Response
     */
    public function stats(Request $request): Response
    {
        try {
            $period = $request->query->get('period', '30days');

            // Get basic user statistics using QueryBuilder
            $stats = [];

            // Total users
            $totalUsers = $this->getQueryBuilder()->select('users', [new RawExpression('COUNT(*) as total')])
                ->where(['deleted_at' => null])
                ->get();
            $stats['total_users'] = $totalUsers[0]['total'] ?? 0;

            // Active users
            $activeUsers = $this->getQueryBuilder()->select('users', [new RawExpression('COUNT(*) as total')])
                ->where(['status' => 'active', 'deleted_at' => null])
                ->get();
            $stats['active_users'] = $activeUsers[0]['total'] ?? 0;

            // Users by status
            $statusStats = $this->getQueryBuilder()->select('users', ['status', new RawExpression('COUNT(*) as count')])
                ->where(['deleted_at' => null])
                ->groupBy(['status'])
                ->get();
            $stats['by_status'] = [];
            foreach ($statusStats as $stat) {
                $stats['by_status'][$stat['status']] = $stat['count'];
            }

            // New users in period - calculate date in PHP for cross-database compatibility
            $daysBack = match ($period) {
                '7days' => 7,
                '30days' => 30,
                '90days' => 90,
                'year' => 365,
                default => 30
            };
            $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$daysBack} days"));

            $newUsers = $this->getQueryBuilder()->select('users', [new RawExpression('COUNT(*) as total')])
                ->where(['deleted_at' => null])
                ->whereRaw('created_at >= ?', [$dateThreshold])
                ->get();
            $stats['new_users_' . $period] = $newUsers[0]['total'] ?? 0;

            return Response::ok($stats, 'Statistics retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Search users with advanced filters
     *
     * @route GET /api/users/search
     * @param Request $request
     * @return Response
     */
    public function search(Request $request): Response
    {
        try {
            $query = $request->query->get('q', '');
            $filters = [
                'status' => $request->query->get('status'),
                'role' => $request->query->get('role'),
                'created_after' => $request->query->get('created_after'),
                'created_before' => $request->query->get('created_before'),
                'last_login_after' => $request->query->get('last_login_after'),
                'last_login_before' => $request->query->get('last_login_before'),
                'has_permission' => $request->query->get('has_permission')
            ];

            $page = (int) $request->query->get('page', 1);
            $perPage = (int) $request->query->get('per_page', 25);

            // Build search query using QueryBuilder
            $searchQuery = $this->getQueryBuilder()->select('users', [
                'users.uuid',
                'users.username',
                'users.email',
                'users.status',
                'users.created_at',
                'users.last_login_date'
            ]);

            // Apply text search
            if ($query) {
                $searchQuery->whereRaw("(users.username LIKE ? OR users.email LIKE ?)", ["%{$query}%", "%{$query}%"]);
            }

            // Apply filters
            $whereConditions = ['deleted_at' => null];
            if ($filters['status']) {
                $whereConditions['status'] = $filters['status'];
            }
            if ($filters['created_after']) {
                $searchQuery->whereRaw("users.created_at >= ?", [$filters['created_after']]);
            }
            if ($filters['created_before']) {
                $searchQuery->whereRaw("users.created_at <= ?", [$filters['created_before']]);
            }
            if ($filters['last_login_after']) {
                $searchQuery->whereRaw("users.last_login_date >= ?", [$filters['last_login_after']]);
            }
            if ($filters['last_login_before']) {
                $searchQuery->whereRaw("users.last_login_date <= ?", [$filters['last_login_before']]);
            }

            $searchQuery->where($whereConditions);

            // Add role filter if specified
            if ($filters['role']) {
                $searchQuery->join('user_roles_lookup', 'users.uuid = user_roles_lookup.user_uuid')
                           ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid')
                           ->where(['roles.name' => $filters['role']]);
            }

            // Apply pagination
            $searchQuery->limit($perPage)->offset(($page - 1) * $perPage);

            $users = $searchQuery->get();

            // Get roles for each user
            foreach ($users as &$user) {
                $user['roles'] = $this->roleRepository->getUserRoles($user['uuid']);
            }

            $results = [
                'data' => $users,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => count($users) // Simplified for now
                ]
            ];

            return Response::ok($results, 'Search completed successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Export users to CSV/JSON
     *
     * @route GET /api/users/export
     * @param Request $request
     * @return Response
     */
    public function export(Request $request): Response
    {
        try {
            $format = $request->query->get('format', 'csv');
            $filters = [
                'status' => $request->query->get('status'),
                'role' => $request->query->get('role'),
                'include_deleted' => filter_var($request->query->get('include_deleted', false), FILTER_VALIDATE_BOOLEAN)
            ];

            // Get users for export using QueryBuilder
            $exportQuery = $this->getQueryBuilder()->select('users', [
                'users.uuid',
                'users.username',
                'users.email',
                'users.status',
                'users.created_at',
                'users.last_login_date'
            ]);

            $whereConditions = [];
            if (!$filters['include_deleted']) {
                $whereConditions['deleted_at'] = null;
            }
            if ($filters['status']) {
                $whereConditions['status'] = $filters['status'];
            }

            if (!empty($whereConditions)) {
                $exportQuery->where($whereConditions);
            }

            // Add role filter if specified
            if ($filters['role']) {
                $exportQuery->join('user_roles_lookup', 'users.uuid = user_roles_lookup.user_uuid')
                           ->join('roles', 'user_roles_lookup.role_uuid = roles.uuid')
                           ->where(['roles.name' => $filters['role']]);
            }

            $users = $exportQuery->get();

            // Get roles and profiles for each user
            foreach ($users as &$user) {
                $user['roles'] = $this->roleRepository->getUserRoles($user['uuid']);
                $user['profile'] = $this->userRepository->getProfile($user['uuid']) ?? [];
            }

            if ($format === 'json') {
                return Response::ok($users, 'Export completed successfully')->send();
            }

            // CSV export
            $csv = $this->generateCSV($users);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
            echo $csv;
            exit;
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Import users from CSV/JSON
     *
     * @route POST /api/users/import
     * @param Request $request
     * @return Response
     */
    public function import(Request $request): Response
    {
        try {
            $file = $request->files->get('file');

            if (!$file) {
                return Response::error(
                    'File is required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            // Basic import functionality - for full implementation, consider using a dedicated import service
            $results = [
                'created' => 0,
                'updated' => 0,
                'failed' => 0,
                'errors' => []
            ];

            // For now, return a placeholder response
            $results['errors'][] = 'Import functionality needs to be implemented based on specific requirements';

            $message = "Import completed: {$results['created']} created, " .
                      "{$results['updated']} updated, {$results['failed']} failed";
            return Response::ok($results, $message)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get user activity log
     *
     * @route GET /api/users/{uuid}/activity
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function activity(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $page = (int) $request->query->get('page', 1);
            $perPage = (int) $request->query->get('per_page', 50);

            $user = $this->userRepository->findByUUID($uuid);
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            // Get user activity from audit logs using QueryBuilder
            $activities = $this->getQueryBuilder()->select('audit_logs', [
                'action',
                'entity_type',
                'entity_id',
                'old_values',
                'new_values',
                'created_at',
                'user_id'
            ])
            ->where(['user_id' => $user['uuid']])
            ->orderBy(['created_at' => 'DESC'])
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

            return Response::ok($activities, 'Activity log retrieved successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }


    /**
     * Get user sessions
     *
     * @route GET /api/users/{uuid}/sessions
     * @param array $params
     * @return Response
     */
    public function sessions(array $params): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';

            $user = $this->userRepository->findByUUID($uuid);
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            // Get user sessions directly from auth_sessions table
            $sessions = $this->getQueryBuilder()->select('auth_sessions', [
                'session_id',
                'access_token',
                'status',
                'provider',
                'ip_address',
                'user_agent',
                'created_at',
                'last_activity',
                'last_token_refresh',
                'access_expires_at',
                'refresh_expires_at'
            ])
            ->where(['user_uuid' => $user['uuid'], 'status' => 'active'])
            ->orderBy(['last_activity' => 'DESC'])
            ->get();

            // Mask sensitive token data
            foreach ($sessions as &$session) {
                if (isset($session['access_token'])) {
                    $session['access_token'] = substr($session['access_token'], 0, 8) . '...';
                }
            }

            return Response::ok($sessions, 'Sessions retrieved successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Terminate user sessions
     *
     * @route DELETE /api/users/{uuid}/sessions
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function terminateSessions(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $sessionId = $request->get('session_id'); // Optional: terminate specific session

            $user = $this->userRepository->findByUUID($uuid);
            if (!$user) {
                throw new NotFoundException('User not found');
            }

            if ($sessionId) {
                // Get the session token to revoke
                $session = $this->getQueryBuilder()->select('auth_sessions', ['access_token'])
                    ->where(['session_id' => $sessionId, 'user_uuid' => $user['uuid']])
                    ->limit(1)
                    ->get();

                if (!empty($session)) {
                    $this->tokenStorage->revokeSession($session[0]['access_token']);
                }
            } else {
                // Revoke all user sessions
                $this->tokenStorage->revokeAllUserSessions($user['uuid']);
            }

            return Response::ok(null, 'Session(s) terminated successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Generate CSV from users data
     *
     * @param array $users
     * @return string
     */
    private function generateCSV(array $users): string
    {
        $output = fopen('php://temp', 'r+');

        // Headers
        fputcsv($output, [
            'UUID',
            'Username',
            'Email',
            'Status',
            'Roles',
            'Created At',
            'Last Login',
            'First Name',
            'Last Name'
        ]);

        // Data
        foreach ($users as $user) {
            fputcsv($output, [
                $user['uuid'],
                $user['username'],
                $user['email'],
                $user['status'],
                implode(', ', array_column($user['roles'] ?? [], 'name')),
                $user['created_at'],
                $user['last_login_date'] ?? 'Never',
                $user['profile']['first_name'] ?? '',
                $user['profile']['last_name'] ?? ''
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
