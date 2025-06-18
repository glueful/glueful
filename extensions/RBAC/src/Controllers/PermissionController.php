<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\RBAC\Services\PermissionAssignmentService;
use Glueful\Extensions\RBAC\Repositories\PermissionRepository;
use Glueful\Exceptions\NotFoundException;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Constants\ErrorCodes;
use Symfony\Component\HttpFoundation\Request;

/**
 * Permission Controller
 *
 * Handles all permission-related operations including:
 * - Permission CRUD operations
 * - User permission assignments
 * - Permission validation and checking
 * - Batch permission operations
 */
class PermissionController
{
    use DatabaseConnectionTrait;

    private PermissionAssignmentService $permissionService;
    private PermissionRepository $permissionRepository;

    public function __construct(
        PermissionAssignmentService $permissionService,
        PermissionRepository $permissionRepository
    ) {
        $this->permissionService = $permissionService;
        $this->permissionRepository = $permissionRepository;
    }

    /**
     * Get all permissions
     *
     * @route GET /api/rbac/permissions
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        try {
            $page = (int) $request->query->get('page', 1);
            $perPage = (int) $request->query->get('per_page', 25);
            $search = $request->query->get('search', '');
            $category = $request->query->get('category', '');
            $resourceType = $request->query->get('resource_type', '');

            $filters = ['exclude_deleted' => true];
            if ($search) {
                $filters['search'] = $search;
            }
            if ($category) {
                $filters['category'] = $category;
            }
            if ($resourceType) {
                $filters['resource_type'] = $resourceType;
            }

            $permissions = $this->permissionRepository->findAllPaginated($filters, $page, $perPage);

            return Response::ok($permissions, 'Permissions retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get a single permission with details
     *
     * @route GET /api/rbac/permissions/{uuid}
     * @param array $params
     * @return Response
     */
    public function show(array $params): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';

            $permission = $this->permissionRepository->findRecordByUuid($uuid);
            if (!$permission) {
                throw new NotFoundException('Permission not found');
            }

            $permissionData = $permission;

            $userCount = count($this->permissionRepository->getUsersWithPermission($uuid));
            $permissionData['user_count'] = $userCount;

            return Response::ok($permissionData, 'Permission details retrieved successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Create a new permission
     *
     * @route POST /api/rbac/permissions
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['name']) || empty($data['slug'])) {
                return Response::error(
                    'Permission name and slug are required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $permission = $this->permissionService->createPermission($data);
            if (!$permission) {
                return Response::error('Failed to create permission', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::created($permission->toArray(), 'Permission created successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Update an existing permission
     *
     * @route PUT /api/rbac/permissions/{uuid}
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function update(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            $updated = $this->permissionService->updatePermission($uuid, $data);
            if (!$updated) {
                return Response::error('Failed to update permission', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            $permission = $this->permissionRepository->findRecordByUuid($uuid);
            return Response::ok($permission, 'Permission updated successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Delete a permission
     *
     * @route DELETE /api/rbac/permissions/{uuid}
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function delete(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $force = filter_var($request->query->get('force', false), FILTER_VALIDATE_BOOLEAN);

            $deleted = $this->permissionService->deletePermission($uuid, $force);
            if (!$deleted) {
                return Response::error('Failed to delete permission', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Permission deleted successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Assign permission to user
     *
     * @route POST /api/rbac/permissions/{uuid}/assign
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function assignToUser(array $params, Request $request): Response
    {
        try {
            $permissionUuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['user_uuid'])) {
                return Response::error(
                    'User UUID is required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $permission = $this->permissionRepository->findRecordByUuid($permissionUuid);
            if (!$permission) {
                throw new NotFoundException('Permission not found');
            }

            $resource = $data['resource'] ?? '*';
            $options = [
                'granted_by' => $data['granted_by'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'constraints' => $data['constraints'] ?? null
            ];

            $assigned = $this->permissionService->assignPermissionToUser(
                $data['user_uuid'],
                $permission['slug'],
                $resource,
                $options
            );

            if (!$assigned) {
                return Response::error('Failed to assign permission', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Permission assigned successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Revoke permission from user
     *
     * @route DELETE /api/rbac/permissions/{uuid}/revoke
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function revokeFromUser(array $params, Request $request): Response
    {
        try {
            $permissionUuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['user_uuid'])) {
                return Response::error(
                    'User UUID is required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $permission = $this->permissionRepository->findRecordByUuid($permissionUuid);
            if (!$permission) {
                throw new NotFoundException('Permission not found');
            }

            $revoked = $this->permissionService->revokePermissionFromUser(
                $data['user_uuid'],
                $permission['slug']
            );

            return Response::ok(['revoked' => $revoked], 'Permission revocation processed')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Batch assign permissions to user
     *
     * @route POST /api/rbac/permissions/batch-assign
     * @param Request $request
     * @return Response
     */
    public function batchAssign(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['user_uuid']) || empty($data['permissions'])) {
                return Response::error(
                    'User UUID and permissions array are required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $globalOptions = $data['options'] ?? [];
            $results = $this->permissionService->batchAssignPermissions(
                $data['user_uuid'],
                $data['permissions'],
                $globalOptions
            );

            return Response::ok($results, 'Batch permission assignment completed')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Batch revoke permissions from user
     *
     * @route POST /api/rbac/permissions/batch-revoke
     * @param Request $request
     * @return Response
     */
    public function batchRevoke(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['user_uuid']) || empty($data['permission_slugs'])) {
                return Response::error(
                    'User UUID and permission_slugs array are required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $results = $this->permissionService->batchRevokePermissions(
                $data['user_uuid'],
                $data['permission_slugs']
            );

            return Response::ok($results, 'Batch permission revocation completed')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get user's direct permissions
     *
     * @route GET /api/rbac/users/{user_uuid}/permissions
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function getUserDirectPermissions(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $activeOnly = filter_var($request->query->get('active_only', true), FILTER_VALIDATE_BOOLEAN);

            $filters = [];
            if ($activeOnly) {
                $filters['active_only'] = true;
            }

            $permissions = $this->permissionService->getUserDirectPermissions($userUuid, $filters);

            return Response::ok($permissions, 'User direct permissions retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get user's effective permissions (direct + role-based)
     *
     * @route GET /api/rbac/users/{user_uuid}/effective-permissions
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function getUserEffectivePermissions(array $params, Request $request): Response
    {
        try {
            $userUuid = $params['user_uuid'] ?? '';
            $scope = $request->query->get('scope', []);
            if (is_string($scope)) {
                $scope = json_decode($scope, true) ?? [];
            }

            $permissions = $this->permissionService->getUserEffectivePermissions($userUuid, $scope);

            return Response::ok($permissions, 'User effective permissions retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Check if user has specific permission
     *
     * @route POST /api/rbac/check-permission
     * @param Request $request
     * @return Response
     */
    public function checkPermission(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['user_uuid']) || empty($data['permission'])) {
                return Response::error(
                    'User UUID and permission are required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $hasPermission = $this->permissionService->userHasPermission(
                $data['user_uuid'],
                $data['permission'],
                $data['resource'] ?? '*',
                $data['context'] ?? []
            );

            return Response::ok([
                'has_permission' => $hasPermission,
                'user_uuid' => $data['user_uuid'],
                'permission' => $data['permission'],
                'resource' => $data['resource'] ?? '*'
            ], 'Permission check completed')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get permission statistics
     *
     * @route GET /api/rbac/permissions/stats
     * @param Request $request
     * @return Response
     */
    public function stats(Request $request): Response
    {
        try {
            $stats = [];

            $stats['total_permissions'] = $this->getQueryBuilder()->count('permissions');
            $stats['system_permissions'] = $this->getQueryBuilder()->count('permissions', ['is_system' => true]);

            // Get permissions by category using QueryBuilder methods
            $permissionsByCategory = $this->getQueryBuilder()
                ->select('permissions', ['category'])
                ->groupBy(['category'])
                ->get();
            $stats['by_category'] = [];
            foreach ($permissionsByCategory as $stat) {
                $categoryName = $stat['category'] ?? 'uncategorized';
                $stats['by_category'][$categoryName] = $this->getQueryBuilder()
                    ->count('permissions', ['category' => $stat['category']]);
            }

            // Get permissions by resource type using QueryBuilder methods
            $permissionsByResource = $this->getQueryBuilder()
                ->select('permissions', ['resource_type'])
                ->groupBy(['resource_type'])
                ->get();
            $stats['by_resource_type'] = [];
            foreach ($permissionsByResource as $stat) {
                $resourceType = $stat['resource_type'] ?? 'general';
                $stats['by_resource_type'][$resourceType] = $this->getQueryBuilder()
                    ->count('permissions', ['resource_type' => $stat['resource_type']]);
            }

            $stats['direct_assignments'] = $this->getQueryBuilder()->count('user_permissions');

            return Response::ok($stats, 'Permission statistics retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Cleanup expired permissions
     *
     * @route POST /api/rbac/permissions/cleanup-expired
     * @param Request $request
     * @return Response
     */
    public function cleanupExpired(Request $request): Response
    {
        try {
            $results = $this->permissionService->cleanupExpiredPermissions();

            return Response::ok($results, "Cleaned up {$results['cleaned']} expired permissions")->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get permission categories
     *
     * @route GET /api/rbac/permissions/categories
     * @param Request $request
     * @return Response
     */
    public function getCategories(Request $request): Response
    {
        try {
            $categories = $this->getQueryBuilder()->select('permissions', ['DISTINCT category'])
                ->get();

            $categoryList = array_map(function ($row) {
                return $row['category'] ?? 'uncategorized';
            }, $categories);

            return Response::ok($categoryList, 'Permission categories retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get resource types
     *
     * @route GET /api/rbac/permissions/resource-types
     * @param Request $request
     * @return Response
     */
    public function getResourceTypes(Request $request): Response
    {
        try {
            $resourceTypes = $this->getQueryBuilder()->select('permissions', ['DISTINCT resource_type'])
                ->get();

            $typeList = array_map(function ($row) {
                return $row['resource_type'] ?? 'general';
            }, $resourceTypes);

            return Response::ok($typeList, 'Resource types retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }
}
