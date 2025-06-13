<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\RBAC\Services\RoleService;
use Glueful\Extensions\RBAC\Services\PermissionAssignmentService;
use Glueful\Extensions\RBAC\Repositories\UserRoleRepository;
use Glueful\Exceptions\NotFoundException;
use Glueful\Helpers\DatabaseConnectionTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * User Role Controller
 *
 * Handles user-role relationship operations including:
 * - User role assignments and management
 * - User permission overview
 * - Role-based access control for users
 * - Bulk user-role operations
 */
class UserRoleController
{
    use DatabaseConnectionTrait;

    private RoleService $roleService;
    private PermissionAssignmentService $permissionService;
    private UserRoleRepository $userRoleRepository;

    public function __construct(
        RoleService $roleService,
        PermissionAssignmentService $permissionService,
        UserRoleRepository $userRoleRepository
    ) {
        $this->roleService = $roleService;
        $this->permissionService = $permissionService;
        $this->userRoleRepository = $userRoleRepository;
    }

    /**
     * Get all roles for a specific user
     *
     * @route GET /api/rbac/users/{user_uuid}/roles
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function getUserRoles(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $scope = $request->query->get('scope', []);
            if (is_string($scope)) {
                $scope = json_decode($scope, true) ?? [];
            }

            $roles = $this->roleService->getUserRoles($userUuid, $scope);

            return Response::ok($roles, 'User roles retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Assign multiple roles to a user
     *
     * @route POST /api/rbac/users/{user_uuid}/roles
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function assignRoles(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['role_uuids'])) {
                return Response::error(
                    'Role UUIDs array is required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            $options = [
                'scope' => $data['scope'] ?? [],
                'expires_at' => $data['expires_at'] ?? null,
                'assigned_by' => $data['assigned_by'] ?? null
            ];

            foreach ($data['role_uuids'] as $roleUuid) {
                try {
                    $assigned = $this->roleService->assignRoleToUser($userUuid, $roleUuid, $options);
                    if ($assigned) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to assign role {$roleUuid}";
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Error assigning role {$roleUuid}: " . $e->getMessage();
                }
            }

            return Response::ok(
                $results,
                "Role assignment completed: {$results['success']} succeeded, {$results['failed']} failed"
            )->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Revoke specific role from user
     *
     * @route DELETE /api/rbac/users/{user_uuid}/roles/{role_uuid}
     * @param array $params
     * @return Response
     */
    public function revokeRole(array $params): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $roleUuid = $params['role_uuid'] ?? '';

            $revoked = $this->roleService->revokeRoleFromUser($userUuid, $roleUuid);
            if (!$revoked) {
                return Response::error('Failed to revoke role', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Role revoked successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Replace all user roles
     *
     * @route PUT /api/rbac/users/{user_uuid}/roles
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function replaceUserRoles(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $data = $request->toArray();

            if (!isset($data['role_uuids']) || !is_array($data['role_uuids'])) {
                return Response::error(
                    'Role UUIDs array is required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $scope = $data['scope'] ?? [];
            $currentRoles = $this->roleService->getUserRoles($userUuid, $scope);
            $currentRoleUuids = array_column(array_column($currentRoles, 'role'), 'uuid');

            $results = [
                'added' => 0,
                'removed' => 0,
                'errors' => []
            ];

            $options = [
                'scope' => $scope,
                'expires_at' => $data['expires_at'] ?? null,
                'assigned_by' => $data['assigned_by'] ?? null
            ];

            // Remove roles that are no longer assigned
            foreach ($currentRoleUuids as $currentRoleUuid) {
                if (!in_array($currentRoleUuid, $data['role_uuids'])) {
                    try {
                        $this->roleService->revokeRoleFromUser($userUuid, $currentRoleUuid);
                        $results['removed']++;
                    } catch (\Exception $e) {
                        $results['errors'][] = "Failed to remove role {$currentRoleUuid}: " . $e->getMessage();
                    }
                }
            }

            // Add new roles
            foreach ($data['role_uuids'] as $roleUuid) {
                if (!in_array($roleUuid, $currentRoleUuids)) {
                    try {
                        $this->roleService->assignRoleToUser($userUuid, $roleUuid, $options);
                        $results['added']++;
                    } catch (\Exception $e) {
                        $results['errors'][] = "Failed to add role {$roleUuid}: " . $e->getMessage();
                    }
                }
            }

            return Response::ok(
                $results,
                "Roles updated: {$results['added']} added, {$results['removed']} removed"
            )->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Check if user has specific role
     *
     * @route POST /api/rbac/users/{user_uuid}/check-role
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function checkUserRole(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['role_slug'])) {
                return Response::error(
                    'Role slug is required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $scope = $data['scope'] ?? [];
            $hasRole = $this->roleService->userHasRole($userUuid, $data['role_slug'], $scope);

            return Response::ok([
                'has_role' => $hasRole,
                'user_uuid' => $userUuid,
                'role_slug' => $data['role_slug'],
                'scope' => $scope
            ], 'Role check completed')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get user's complete access overview (roles + permissions)
     *
     * @route GET /api/rbac/users/{user_uuid}/access-overview
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function getUserAccessOverview(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $scope = $request->query->get('scope', []);
            if (is_string($scope)) {
                $scope = json_decode($scope, true) ?? [];
            }

            $overview = [
                'user_uuid' => $userUuid,
                'roles' => $this->roleService->getUserRoles($userUuid, $scope),
                'direct_permissions' => $this->permissionService->getUserDirectPermissions($userUuid, ['active_only' => true]),
                'effective_permissions' => $this->permissionService->getUserEffectivePermissions($userUuid, $scope)
            ];

            return Response::ok($overview, 'User access overview retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get role assignment history for user
     *
     * @route GET /api/rbac/users/{user_uuid}/role-history
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function getUserRoleHistory(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $page = (int) $request->query->get('page', 1);
            $perPage = (int) $request->query->get('per_page', 25);
            $includeDeleted = filter_var($request->query->get('include_deleted', true), FILTER_VALIDATE_BOOLEAN);

            $filters = ['user_uuid' => $userUuid];
            if (!$includeDeleted) {
                $filters['exclude_deleted'] = true;
            }

            $history = $this->userRoleRepository->getUserRoleHistoryPaginated($userUuid, $filters, $page, $perPage);

            return Response::ok($history, 'User role history retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Bulk assign role to multiple users
     *
     * @route POST /api/rbac/roles/{role_uuid}/assign-users
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function bulkAssignRoleToUsers(array $params, Request $request): Response
    {
        try {
            $roleUuid = $params['role_uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['user_uuids'])) {
                return Response::error(
                    'User UUIDs array is required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            $options = [
                'scope' => $data['scope'] ?? [],
                'expires_at' => $data['expires_at'] ?? null,
                'assigned_by' => $data['assigned_by'] ?? null
            ];

            foreach ($data['user_uuids'] as $userUuid) {
                try {
                    $assigned = $this->roleService->assignRoleToUser($userUuid, $roleUuid, $options);
                    if ($assigned) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to assign role to user {$userUuid}";
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Error for user {$userUuid}: " . $e->getMessage();
                }
            }

            return Response::ok(
                $results,
                "Bulk role assignment completed: {$results['success']} succeeded, {$results['failed']} failed"
            )->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Bulk revoke role from multiple users
     *
     * @route DELETE /api/rbac/roles/{role_uuid}/revoke-users
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function bulkRevokeRoleFromUsers(array $params, Request $request): Response
    {
        try {
            $roleUuid = $params['role_uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['user_uuids'])) {
                return Response::error(
                    'User UUIDs array is required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            foreach ($data['user_uuids'] as $userUuid) {
                try {
                    $revoked = $this->roleService->revokeRoleFromUser($userUuid, $roleUuid);
                    if ($revoked) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to revoke role from user {$userUuid}";
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Error for user {$userUuid}: " . $e->getMessage();
                }
            }

            return Response::ok(
                $results,
                "Bulk role revocation completed: {$results['success']} succeeded, {$results['failed']} failed"
            )->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get user role statistics
     *
     * @route GET /api/rbac/user-roles/stats
     * @param Request $request
     * @return Response
     */
    public function stats(Request $request): Response
    {
        try {
            $stats = [];

            $totalAssignments = $this->getQueryBuilder()->select('user_roles', ['COUNT(*) as total'])
                ->where(['deleted_at' => null])
                ->get();
            $stats['total_assignments'] = $totalAssignments[0]['total'] ?? 0;

            $activeAssignments = $this->getQueryBuilder()->select('user_roles', ['COUNT(*) as total'])
                ->where(['deleted_at' => null])
                ->whereRaw('(expires_at IS NULL OR expires_at > NOW())')
                ->get();
            $stats['active_assignments'] = $activeAssignments[0]['total'] ?? 0;

            $expiredAssignments = $this->getQueryBuilder()->select('user_roles', ['COUNT(*) as total'])
                ->where(['deleted_at' => null])
                ->whereRaw('expires_at IS NOT NULL AND expires_at <= NOW()')
                ->get();
            $stats['expired_assignments'] = $expiredAssignments[0]['total'] ?? 0;

            $usersWithRoles = $this->getQueryBuilder()->select('user_roles', ['COUNT(DISTINCT user_uuid) as total'])
                ->where(['deleted_at' => null])
                ->get();
            $stats['users_with_roles'] = $usersWithRoles[0]['total'] ?? 0;

            return Response::ok($stats, 'User role statistics retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Cleanup expired role assignments
     *
     * @route POST /api/rbac/user-roles/cleanup-expired
     * @param Request $request
     * @return Response
     */
    public function cleanupExpiredRoles(Request $request): Response
    {
        try {
            $expired = $this->userRoleRepository->findExpiredRoles();
            $count = $this->userRoleRepository->cleanupExpiredRoles();

            $results = [
                'cleaned' => $count,
                'expired_roles' => $expired
            ];

            return Response::ok($results, "Cleaned up {$count} expired role assignments")->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
        }
    }
}