<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Repository\UserRepository;
use Glueful\Repository\Interfaces\RepositoryInterface;
use Glueful\Exceptions\NotFoundException;
use Glueful\Auth\TokenStorageService;
use Glueful\Database\RawExpression;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Logging\AuditEvent;

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
class UsersController extends BaseController
{
    private RepositoryInterface $userRepository;
    // Note: Role functionality migrated to RBAC extension
    private TokenStorageService $tokenStorage;

    public function __construct(
        ?\Glueful\Repository\RepositoryFactory $repositoryFactory = null,
        ?\Glueful\Auth\AuthenticationManager $authManager = null,
        ?\Glueful\Logging\AuditLogger $auditLogger = null,
        ?Request $request = null,
        ?UserRepository $userRepository = null,
        ?TokenStorageService $tokenStorage = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);

        // Initialize user-specific dependencies
        $this->userRepository = $userRepository ?? $this->repositoryFactory->getRepository('users');
        $this->tokenStorage = $tokenStorage ?? new TokenStorageService();
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
        // Permission check for viewing users
        $this->requirePermission('users.read', 'users');

        // Rate limiting for user listing
        $this->rateLimitResource('users', 'read');

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

        // Fetch roles from RBAC extension if available (optimized to avoid N+1 queries)
        $users = $paginatedResult['data'] ?? [];
        $container = app();

        // Extract user UUIDs for bulk role fetching
        $userUuids = array_column($users, 'uuid');
        $allUserRoles = [];

        try {
            // Check if RBAC role service is available in container
            if ($container->has('rbac.role_service') && !empty($userUuids)) {
                $roleService = $container->get('rbac.role_service');
                // Use bulk method to fetch roles for all users in one go
                $allUserRoles = $roleService->getBulkUserRoles($userUuids);
            }
        } catch (\Exception $e) {
            // Log error and gracefully fallback
            error_log("Failed to bulk fetch roles for users: " . $e->getMessage());
        }

        // Attach roles to each user
        foreach ($users as &$user) {
            $userUuid = $user['uuid'];
            $userRoles = $allUserRoles[$userUuid] ?? [];

            // Format roles for API response
            $user['roles'] = array_map(function ($roleData) {
                return [
                    'uuid' => $roleData['role']->getUuid(),
                    'name' => $roleData['role']->getName(),
                    'slug' => $roleData['role']->getSlug(),
                    'level' => $roleData['role']->getLevel(),
                    'is_system' => $roleData['role']->isSystem(),
                    'assigned_at' => $roleData['assignment']->getCreatedAt()
                ];
            }, $userRoles);
        }

        // Update the data in the pagination result and return it directly
        $paginatedResult['data'] = $users;

        $cacheKey = 'users_list_' . md5(serialize([
            $page, $perPage, $search, $status, $roleId, $sortBy, $sortOrder, $includeDeleted
        ]));

        $resData = $this->cacheResponse(
            $cacheKey,
            function () use ($paginatedResult) {
                return $paginatedResult;
            },
            300, // 5 minutes cache for user listing
            ['users', 'user_list']
        );
         return Response::ok($resData, 'Users retrieved successfully')->send();
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
        $uuid = $params['uuid'] ?? '';

        // Permission check with self-access logic
        if ($uuid !== $this->getCurrentUserUuid()) {
            $this->requirePermission('users.read', 'users', ['target_user_uuid' => $uuid]);
        } else {
            $this->requirePermission('users.self.read', 'users');
        }

        // Rate limiting for user details
        $this->rateLimitResource('users', 'read');

        $user = $this->userRepository->find($uuid);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Fetch roles from RBAC extension if available
        $container = app();
        try {
            if ($container->has('rbac.role_service')) {
                $roleService = $container->get('rbac.role_service');
                $userRoles = $roleService->getUserRoles($user['uuid']);

                // Format roles for API response
                $user['roles'] = array_map(function ($roleData) {
                    return [
                        'uuid' => $roleData['role']->getUuid(),
                        'name' => $roleData['role']->getName(),
                        'slug' => $roleData['role']->getSlug(),
                        'level' => $roleData['role']->getLevel(),
                        'is_system' => $roleData['role']->isSystem(),
                        'assigned_at' => $roleData['assignment']->getCreatedAt()
                    ];
                }, $userRoles);
            } else {
                $user['roles'] = [];
            }
        } catch (\Exception $e) {
            error_log("Failed to fetch roles for user {$user['uuid']}: " . $e->getMessage());
            $user['roles'] = [];
        }

        // Get user profile
        $user['profile'] = $this->getUserRepository()->getProfile($user['uuid']) ?? [];

        // Remove sensitive data
        unset($user['password']);

        $userRes = $this->cacheResponse(
            'user_details_' . $uuid,
            function () use ($user) {
                return $user;
            },
            600, // 10 minutes cache for user details
            ['users', 'user_' . $uuid]
        );
        return Response::ok($userRes, 'User details retrieved successfully')->send();
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
        // Permission check for creating users (admin only)
        $this->requirePermission('users.create', 'users');

        // Strict rate limiting for user creation
        $this->rateLimitResource('users', 'write', 10, 300);

        $data = $request->toArray();

        // Enhanced input validation and sanitization
        $validationErrors = $this->validateUserInput($data, 'create');
        if (!empty($validationErrors)) {
            return Response::error(
                'Validation failed: ' . implode(', ', $validationErrors),
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        // Sanitize input data
        $data = $this->sanitizeUserInput($data);

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

        // Note: Role assignment disabled - use RBAC extension API
        if (!empty($roleUuids)) {
            // Role assignment is now handled by the RBAC extension
            // Use the RBAC API endpoints for role management
        }

        // Create profile if data provided
        if (!empty($profileData)) {
            $this->getUserRepository()->updateProfile($userUuid, $profileData);
        }

        // Fetch created user
        $user = $this->getUserRepository()->findByUUID($userUuid);
        // Fetch newly assigned roles from RBAC extension if available
        $container = app();
        try {
            if ($container->has('rbac.role_service')) {
                $roleService = $container->get('rbac.role_service');
                $userRoles = $roleService->getUserRoles($user['uuid']);

                $user['roles'] = array_map(function ($roleData) {
                    return [
                        'uuid' => $roleData['role']->getUuid(),
                        'name' => $roleData['role']->getName(),
                        'slug' => $roleData['role']->getSlug(),
                        'level' => $roleData['role']->getLevel(),
                        'is_system' => $roleData['role']->isSystem(),
                        'assigned_at' => $roleData['assignment']->getCreatedAt()
                    ];
                }, $userRoles);
            } else {
                $user['roles'] = [];
            }
        } catch (\Exception $e) {
            error_log("Failed to fetch roles for newly created user {$user['uuid']}: " . $e->getMessage());
            $user['roles'] = [];
        }

        unset($user['password']);

        // Audit log user creation
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'user_created',
            AuditEvent::SEVERITY_INFO,
            [
                'created_user_uuid' => $userUuid,
                'created_user_email' => $data['email'] ?? null,
                'created_user_username' => $data['username'] ?? null,
                'creator_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        // Invalidate user-related caches
        $this->invalidateCache(['users', 'user_list', 'statistics']);

        return Response::created($user, 'User created successfully')->send();
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
        $uuid = $params['uuid'] ?? '';
        $data = $request->toArray();

        // Permission check with self-access logic
        if ($uuid !== $this->getCurrentUserUuid()) {
            $this->requirePermission('users.update', 'users', ['target_user_uuid' => $uuid]);
        } else {
            $this->requirePermission('users.self.update', 'users');
        }

        // Moderate rate limiting for user updates
        $this->rateLimitResource('users', 'write', 20, 300);

        $user = $this->getUserRepository()->findByUUID($uuid);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Enhanced input validation and sanitization
        $validationErrors = $this->validateUserInput($data, 'update');
        if (!empty($validationErrors)) {
            return Response::error(
                'Validation failed: ' . implode(', ', $validationErrors),
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        // Sanitize input data
        $data = $this->sanitizeUserInput($data);

        // Handle password update
        if (isset($data['password'])) {
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

        // Note: Role updates disabled - use RBAC extension API
        if ($roleUuids !== null) {
            // Role management is now handled by the RBAC extension
            // Use the RBAC API endpoints for role assignment/removal
        }

        // Update profile if provided
        if ($profileData !== null) {
            $this->getUserRepository()->updateProfile($user['uuid'], $profileData);
        }

        // Fetch updated user
        $updatedUser = $this->getUserRepository()->findByUUID($user['uuid']);
        // Fetch updated roles from RBAC extension if available
        $container = app();
        try {
            if ($container->has('rbac.role_service')) {
                $roleService = $container->get('rbac.role_service');
                $userRoles = $roleService->getUserRoles($updatedUser['uuid']);

                $updatedUser['roles'] = array_map(function ($roleData) {
                    return [
                        'uuid' => $roleData['role']->getUuid(),
                        'name' => $roleData['role']->getName(),
                        'slug' => $roleData['role']->getSlug(),
                        'level' => $roleData['role']->getLevel(),
                        'is_system' => $roleData['role']->isSystem(),
                        'assigned_at' => $roleData['assignment']->getCreatedAt()
                    ];
                }, $userRoles);
            } else {
                $updatedUser['roles'] = [];
            }
        } catch (\Exception $e) {
            error_log("Failed to fetch roles for updated user {$updatedUser['uuid']}: " . $e->getMessage());
            $updatedUser['roles'] = [];
        }

        unset($updatedUser['password']);

        // Audit log user update
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'user_updated',
            AuditEvent::SEVERITY_INFO,
            [
                'updated_user_uuid' => $user['uuid'],
                'updated_user_email' => $user['email'] ?? null,
                'updated_fields' => array_keys($data),
                'is_self_update' => $uuid === $this->getCurrentUserUuid(),
                'updater_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        // Invalidate user-specific and list caches
        $this->invalidateCache(['users', 'user_' . $user['uuid'], 'user_list', 'statistics']);

        return Response::ok($updatedUser, 'User updated successfully')->send();
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
        $uuid = $params['uuid'] ?? '';

        // Permission check for deleting users (admin only)
        $this->requirePermission('users.delete', 'users', ['target_user_uuid' => $uuid]);

        // Strict rate limiting for user deletion
        $this->rateLimitResource('users', 'delete', 5, 600);

        // Require low risk behavior for destructive operations
        $this->requireLowRiskBehavior(0.4, 'user_deletion');

        $user = $this->getUserRepository()->findByUUID($uuid);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Note: Superuser protection disabled - implement in RBAC extension
        // Superuser protection should be implemented using RBAC permissions

        // Soft delete user (use BaseRepository delete method)
        $this->getUserRepository()->delete($user['uuid']);

        // Invalidate user sessions
        $this->tokenStorage->revokeAllUserSessions($user['uuid']);

        // Audit log user deletion
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'user_deleted',
            AuditEvent::SEVERITY_WARNING,
            [
                'deleted_user_uuid' => $user['uuid'],
                'deleted_user_email' => $user['email'] ?? null,
                'deleted_user_username' => $user['username'] ?? null,
                'deleter_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        // Invalidate all user-related caches
        $this->invalidateCache(['users', 'user_' . $user['uuid'], 'user_list', 'statistics']);

        return Response::ok(null, 'User deleted successfully')->send();
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
        $uuid = $params['uuid'] ?? '';

        // Permission check for restoring users (admin only)
        $this->requirePermission('users.restore', 'users', ['target_user_uuid' => $uuid]);

        // Strict rate limiting for user restoration
        $this->rateLimitResource('users', 'restore', 5, 600);

        $user = $this->getUserRepository()->findByUUID($uuid); // For restore, check if user exists
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        // Use BaseRepository restore method - implement in UserRepository if not exists
        // For now, update the deleted_at field to null
        $this->userRepository->update($user['uuid'], ['deleted_at' => null]);

        // Audit log user restoration
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'user_restored',
            AuditEvent::SEVERITY_INFO,
            [
                'restored_user_uuid' => $user['uuid'],
                'restored_user_email' => $user['email'] ?? null,
                'restored_user_username' => $user['username'] ?? null,
                'restorer_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        return Response::ok(null, 'User restored successfully')->send();
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
        // Permission check for bulk operations (admin only)
        $this->requirePermission('users.bulk.operations', 'users');

        // Very strict rate limiting for bulk operations
        $this->rateLimitResource('users', 'bulk', 2, 1200);

        // Require very low risk behavior for bulk operations
        $this->requireLowRiskBehavior(0.2, 'user_bulk_operations');

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

        // Batch find users to avoid N+1 queries
        $users = $this->userRepository->findMultiple($data['user_ids']);
        $usersByUuid = array_column($users, null, 'uuid');

        // Prepare batch updates and session revocations
        $batchUpdates = [];
        $sessionsToRevoke = [];

        foreach ($data['user_ids'] as $userUuid) {
            try {
                if (!isset($usersByUuid[$userUuid])) {
                    $results['failed']++;
                    $results['errors'][] = "User {$userUuid} not found";
                    continue;
                }

                $user = $usersByUuid[$userUuid];

                switch ($data['action']) {
                    case 'delete':
                        $this->userRepository->delete($user['uuid']);
                        break;
                    case 'restore':
                        $batchUpdates[$userUuid] = ['deleted_at' => null];
                        break;
                    case 'activate':
                        $batchUpdates[$userUuid] = ['status' => 'active'];
                        break;
                    case 'deactivate':
                        $batchUpdates[$userUuid] = ['status' => 'inactive'];
                        break;
                    case 'suspend':
                        $batchUpdates[$userUuid] = ['status' => 'suspended'];
                        $sessionsToRevoke[] = $userUuid;
                        break;
                    case 'assign_role':
                    case 'remove_role':
                        // Note: Role operations disabled - use RBAC extension API
                        $results['failed']++;
                        $results['errors'][] = "Role operations moved to RBAC extension for user {$userUuid}";
                        continue 2;
                }

                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Failed for user {$userUuid}: " . $e->getMessage();
            }
        }

        // Execute batch updates by grouping similar operations
        if (!empty($batchUpdates)) {
            // Group updates by the data being changed to enable true bulk operations
            $updateGroups = [];
            foreach ($batchUpdates as $userUuid => $updateData) {
                $dataKey = serialize($updateData);
                if (!isset($updateGroups[$dataKey])) {
                    $updateGroups[$dataKey] = [
                        'uuids' => [],
                        'data' => $updateData
                    ];
                }
                $updateGroups[$dataKey]['uuids'][] = $userUuid;
            }

            // Execute bulk updates for each group
            foreach ($updateGroups as $group) {
                $this->userRepository->bulkUpdate($group['uuids'], $group['data']);
            }
        }

        // Execute batch session revocations
        if (!empty($sessionsToRevoke)) {
            foreach ($sessionsToRevoke as $userUuid) {
                $this->tokenStorage->revokeAllUserSessions($userUuid);
            }
        }

        // Single audit log for the entire bulk operation
        $this->asyncAudit(
            AuditEvent::CATEGORY_USER,
            'bulk_user_action',
            AuditEvent::SEVERITY_INFO,
            [
                'action' => $data['action'],
                'total_users' => count($data['user_ids']),
                'successful' => $results['success'],
                'failed' => $results['failed'],
                'user_ids' => $data['user_ids']
            ]
        );

        // Audit log bulk operation
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'user_bulk_operation',
            AuditEvent::SEVERITY_WARNING,
            [
                'action' => $data['action'],
                'user_ids' => $data['user_ids'],
                'success_count' => $results['success'],
                'failed_count' => $results['failed'],
                'errors' => $results['errors'],
                'operator_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        return Response::ok(
            $results,
            "Bulk operation completed: {$results['success']} succeeded, {$results['failed']} failed"
        )->send();
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
        // Permission check for viewing user statistics
        $this->requirePermission('users.statistics', 'users');

        // Rate limiting for statistics
        $this->rateLimitResource('users', 'stats', 30, 300);

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
            ->whereGreaterThanOrEqual('created_at', $dateThreshold)
            ->get();
        $stats['new_users_' . $period] = $newUsers[0]['total'] ?? 0;

        $userStats = $this->cacheResponse(
            'user_stats_' . $period,
            function () use ($stats) {
                return $stats;
            },
            900, // 15 minutes cache
            ['users', 'statistics']
        );

        return Response::ok($userStats, 'Statistics retrieved successfully')->send();
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
        // Permission check for searching users
        $this->requirePermission('users.search', 'users');

        // Rate limiting for search operations
        $this->rateLimitResource('users', 'search', 50, 300);

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
            $searchQuery->search(['users.username', 'users.email'], $query, 'OR');
        }

        // Apply filters
        $whereConditions = ['deleted_at' => null];
        if ($filters['status']) {
            $whereConditions['status'] = $filters['status'];
        }
        if ($filters['created_after']) {
            $searchQuery->whereGreaterThanOrEqual('users.created_at', $filters['created_after']);
        }
        if ($filters['created_before']) {
            $searchQuery->whereLessThanOrEqual('users.created_at', $filters['created_before']);
        }
        if ($filters['last_login_after']) {
            $searchQuery->whereGreaterThanOrEqual('users.last_login_date', $filters['last_login_after']);
        }
        if ($filters['last_login_before']) {
            $searchQuery->whereLessThanOrEqual('users.last_login_date', $filters['last_login_before']);
        }

        $searchQuery->where($whereConditions);

        // Note: Role filtering disabled - implement with RBAC extension
        if ($filters['role']) {
            // Role filtering is now handled by the RBAC extension
            return Response::error(
                'Role filtering requires RBAC extension',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        // Apply pagination
        $searchQuery->limit($perPage)->offset(($page - 1) * $perPage);

        $users = $searchQuery->get();

        // Note: Roles managed by RBAC extension
        foreach ($users as &$user) {
            $user['roles'] = [];
        }

        $results = [
            'data' => $users,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => count($users) // Simplified for now
            ]
        ];

        $res = $this->cacheResponse(
            'users_search_' . md5(serialize([$query, $filters, $page, $perPage])),
            function () use ($results) {
                return $results;
            },
            300, // 5 minutes cache for search results
            ['users', 'search']
        );

        return Response::ok($res, 'Search completed successfully')->send();
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
        // Permission check for exporting users (admin only)
        $this->requirePermission('users.export', 'users');

        // Very strict rate limiting for export operations
        $this->rateLimitResource('users', 'export', 3, 900);

        // Require low risk behavior for data export
        $this->requireLowRiskBehavior(0.3, 'user_export');

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

        // Note: Role filtering disabled - implement with RBAC extension
        if ($filters['role']) {
            return Response::error(
                'Role filtering requires RBAC extension',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        $users = $exportQuery->get();

        // Get profiles for each user
        foreach ($users as &$user) {
            $user['roles'] = []; // Roles managed by RBAC extension
            $user['profile'] = $this->getUserRepository()->getProfile($user['uuid']) ?? [];
        }

        // Audit log user export
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'users_exported',
            AuditEvent::SEVERITY_INFO,
            [
                'export_format' => $format,
                'filters' => $filters,
                'user_count' => count($users),
                'exporter_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        if ($format === 'json') {
            return Response::ok($users, 'Export completed successfully')->send();
        }

        // CSV export
        $csv = $this->generateCSV($users);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
        echo $csv;
        exit;
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
        // Permission check for importing users (admin only)
        $this->requirePermission('users.import', 'users');

        // Very strict rate limiting for import operations
        $this->rateLimitResource('users', 'import', 1, 1800);

        // Require very low risk behavior for bulk data import
        $this->requireLowRiskBehavior(0.2, 'user_import');

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

        // Audit log user import attempt
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'users_import_attempted',
            AuditEvent::SEVERITY_INFO,
            [
                'file_name' => $file->isValid() ? $file->getClientOriginalName() : null,
                'file_size' => $file->isValid() ? $file->getSize() : null,
                'results' => $results,
                'importer_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        $message = "Import completed: {$results['created']} created, " .
                  "{$results['updated']} updated, {$results['failed']} failed";
        return Response::ok($results, $message)->send();
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
        $uuid = $params['uuid'] ?? '';

        // Permission check with self-access logic
        if ($uuid !== $this->getCurrentUserUuid()) {
            $this->requirePermission('users.view_activity', 'users', ['target_user_uuid' => $uuid]);
        } else {
            $this->requirePermission('users.self.view_activity', 'users');
        }

        // Rate limiting for activity logs
        $this->rateLimitResource('users', 'activity', 100, 300);
        $page = (int) $request->query->get('page', 1);
        $perPage = (int) $request->query->get('per_page', 50);

        $user = $this->getUserRepository()->findByUUID($uuid);
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

        $activityRes = $this->cacheResponse(
            'user_activity_' . $uuid . '_' . $page . '_' . $perPage,
            function () use ($activities) {
                return Response::ok($activities, 'Activity log retrieved successfully');
            },
            600, // 10 minutes cache for activity logs
            ['users', 'user_' . $uuid, 'activity']
        );
        return Response::ok($activityRes, 'Activity log retrieved successfully')->send();
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
        $uuid = $params['uuid'] ?? '';

        // Permission check with self-access logic
        if ($uuid !== $this->getCurrentUserUuid()) {
            $this->requirePermission('users.view_sessions', 'users', ['target_user_uuid' => $uuid]);
        } else {
            $this->requirePermission('users.self.view_sessions', 'users');
        }

        // Rate limiting for session viewing
        $this->rateLimitResource('users', 'sessions', 50, 300);

        $user = $this->getUserRepository()->findByUUID($uuid);
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

        $sessionRes = $this->cacheResponse(
            'user_sessions_' . $uuid,
            function () use ($sessions) {
                return $sessions;
            },
            60, // 1 minute cache for sessions (frequently changing)
            ['users', 'user_' . $uuid, 'sessions']
        );
        return Response::ok($sessionRes, 'Sessions retrieved successfully')->send();
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
        $uuid = $params['uuid'] ?? '';

        // Permission check with self-access logic
        if ($uuid !== $this->getCurrentUserUuid()) {
            $this->requirePermission('users.manage_sessions', 'users', ['target_user_uuid' => $uuid]);
        } else {
            $this->requirePermission('users.self.manage_sessions', 'users');
        }

        // Strict rate limiting for session termination
        $this->rateLimitResource('users', 'terminate_sessions', 10, 300);
        $sessionId = $request->get('session_id'); // Optional: terminate specific session

        $user = $this->getUserRepository()->findByUUID($uuid);
        if (!$user) {
            throw new NotFoundException('User not found');
        }

        $terminatedSessions = 0;
        if ($sessionId) {
            // Get the session token to revoke
            $session = $this->getQueryBuilder()->select('auth_sessions', ['access_token'])
                ->where(['session_id' => $sessionId, 'user_uuid' => $user['uuid']])
                ->limit(1)
                ->get();

            if (!empty($session)) {
                $this->tokenStorage->revokeSession($session[0]['access_token']);
                $terminatedSessions = 1;
            }
        } else {
            // Revoke all user sessions
            $activeSessions = $this->getQueryBuilder()->select('auth_sessions', ['session_id'])
                ->where(['user_uuid' => $user['uuid'], 'status' => 'active'])
                ->get();
            $terminatedSessions = count($activeSessions);
            $this->tokenStorage->revokeAllUserSessions($user['uuid']);
        }

        // Audit log session termination
        $this->auditLogger->audit(
            'security',
            'user_sessions_terminated',
            AuditEvent::SEVERITY_INFO,
            [
                'target_user_uuid' => $user['uuid'],
                'target_user_email' => $user['email'] ?? null,
                'session_id' => $sessionId,
                'terminated_sessions_count' => $terminatedSessions,
                'is_self_termination' => $uuid === $this->getCurrentUserUuid(),
                'terminator_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        return Response::ok(null, 'Session(s) terminated successfully')->send();
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

    /**
     * Validate user input data
     *
     * @param array $data Input data
     * @param string $operation Operation type (create, update)
     * @return array Validation errors
     */
    private function validateUserInput(array $data, string $operation): array
    {
        $errors = [];

        // Required fields for creation
        if ($operation === 'create') {
            if (empty($data['username'])) {
                $errors[] = 'Username is required';
            }
            if (empty($data['email'])) {
                $errors[] = 'Email is required';
            }
            if (empty($data['password'])) {
                $errors[] = 'Password is required';
            }
        }

        // Username validation
        if (isset($data['username'])) {
            if (strlen($data['username']) < 3) {
                $errors[] = 'Username must be at least 3 characters';
            }
            if (strlen($data['username']) > 50) {
                $errors[] = 'Username cannot exceed 50 characters';
            }
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $data['username'])) {
                $errors[] = 'Username can only contain letters, numbers, dots, hyphens, and underscores';
            }
        }

        // Email validation
        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            }
            if (strlen($data['email']) > 255) {
                $errors[] = 'Email cannot exceed 255 characters';
            }
        }

        // Password validation
        if (isset($data['password'])) {
            if (strlen($data['password']) < 8) {
                $errors[] = 'Password must be at least 8 characters';
            }
            if (strlen($data['password']) > 255) {
                $errors[] = 'Password cannot exceed 255 characters';
            }
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $data['password'])) {
                $errors[] = 'Password must contain at least one lowercase letter, one uppercase letter, and one number';
            }
        }

        // Status validation
        if (isset($data['status'])) {
            $validStatuses = ['active', 'inactive', 'suspended', 'pending'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors[] = 'Invalid status. Must be one of: ' . implode(', ', $validStatuses);
            }
        }

        // Profile validation
        if (isset($data['profile']) && is_array($data['profile'])) {
            if (isset($data['profile']['first_name']) && strlen($data['profile']['first_name']) > 100) {
                $errors[] = 'First name cannot exceed 100 characters';
            }
            if (isset($data['profile']['last_name']) && strlen($data['profile']['last_name']) > 100) {
                $errors[] = 'Last name cannot exceed 100 characters';
            }
        }

        return $errors;
    }

    /**
     * Sanitize user input data
     *
     * @param array $data Input data
     * @return array Sanitized data
     */
    private function sanitizeUserInput(array $data): array
    {
        $sanitized = [];

        // Sanitize string fields
        $stringFields = ['username', 'email', 'status'];
        foreach ($stringFields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = trim(strip_tags($data[$field]));
            }
        }

        // Password doesn't need sanitization (will be hashed)
        if (isset($data['password'])) {
            $sanitized['password'] = $data['password'];
        }

        // Sanitize profile data
        if (isset($data['profile']) && is_array($data['profile'])) {
            $sanitized['profile'] = [];
            foreach ($data['profile'] as $key => $value) {
                if (is_string($value)) {
                    $sanitized['profile'][$key] = trim(strip_tags($value));
                } else {
                    $sanitized['profile'][$key] = $value;
                }
            }
        }

        // Preserve other fields
        $preserveFields = ['roles'];
        foreach ($preserveFields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = $data[$field];
            }
        }

        return $sanitized;
    }

    /**
     * Get UserRepository instance for user-specific methods
     *
     * @return UserRepository
     */
    private function getUserRepository(): UserRepository
    {
        if ($this->userRepository instanceof UserRepository) {
            return $this->userRepository;
        }

        // This should not happen in practice, but provides type safety
        throw new \RuntimeException('UserRepository not available');
    }
}
