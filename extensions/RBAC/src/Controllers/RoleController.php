<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\RBAC\Services\RoleService;
use Glueful\Extensions\RBAC\Repositories\RoleRepository;
use Glueful\Exceptions\NotFoundException;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Constants\ErrorCodes;
use Symfony\Component\HttpFoundation\Request;

/**
 * Role Controller
 *
 * Handles all role-related operations including:
 * - Role CRUD operations
 * - Role hierarchy management
 * - User role assignments
 * - Role statistics and analytics
 */
class RoleController
{
    use DatabaseConnectionTrait;

    private RoleService $roleService;
    private RoleRepository $roleRepository;

    public function __construct(RoleService $roleService, RoleRepository $roleRepository)
    {
        $this->roleService = $roleService;
        $this->roleRepository = $roleRepository;
    }

    /**
     * Get all roles with their hierarchy
     *
     * @route GET /api/rbac/roles
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
            $level = $request->query->get('level', '');
            $showTree = filter_var($request->query->get('tree', false), FILTER_VALIDATE_BOOLEAN);

            $includeDeleted = filter_var($request->query->get('include_deleted', false), FILTER_VALIDATE_BOOLEAN);
            $filters = ['exclude_deleted' => !$includeDeleted];
            if ($search) {
                $filters['search'] = $search;
            }
            if ($status) {
                $filters['status'] = $status;
            }
            if ($level !== '') {
                $filters['level'] = (int) $level;
            }

            if ($showTree) {
                $roleTree = $this->roleService->getRoleTree();
                return Response::ok($roleTree, 'Role hierarchy retrieved successfully')->send();
            }

            $roles = $this->roleRepository->findAllPaginated($filters, $page, $perPage);

            return Response::ok($roles, 'Roles retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get a single role with details
     *
     * @route GET /api/rbac/roles/{uuid}
     * @param array $params
     * @return Response
     */
    public function show(array $params): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';

            $role = $this->roleRepository->findRecordByUuid($uuid);
            if (!$role) {
                throw new NotFoundException('Role not found');
            }

            $roleData = $role;
            $roleData['hierarchy'] = $this->roleService->getRoleHierarchy($uuid);
            $roleData['children'] = $this->roleRepository->findChildren($uuid);

            $userCount = count($this->roleRepository->getUsersWithRole($uuid));
            $roleData['user_count'] = $userCount;

            return Response::ok($roleData, 'Role details retrieved successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Create a new role
     *
     * @route POST /api/rbac/roles
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['name']) || empty($data['slug'])) {
                return Response::error(
                    'Role name and slug are required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $role = $this->roleService->createRole($data);
            if (!$role) {
                return Response::error('Failed to create role', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::created($role->toArray(), 'Role created successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Update an existing role
     *
     * @route PUT /api/rbac/roles/{uuid}
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function update(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            $updated = $this->roleService->updateRole($uuid, $data);
            if (!$updated) {
                return Response::error('Failed to update role', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            $role = $this->roleRepository->findRecordByUuid($uuid);
            return Response::ok($role, 'Role updated successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Delete a role
     *
     * @route DELETE /api/rbac/roles/{uuid}
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function delete(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $force = filter_var($request->query->get('force', false), FILTER_VALIDATE_BOOLEAN);

            $deleted = $this->roleService->deleteRole($uuid, $force);
            if (!$deleted) {
                return Response::error('Failed to delete role', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Role deleted successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Assign role to user
     *
     * @route POST /api/rbac/roles/{uuid}/assign
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function assignToUser(array $params, Request $request): Response
    {
        try {
            $roleUuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['user_uuid'])) {
                return Response::error(
                    'User UUID is required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $options = [
                'scope' => $data['scope'] ?? [],
                'expires_at' => $data['expires_at'] ?? null,
                'assigned_by' => $data['assigned_by'] ?? null
            ];

            $assigned = $this->roleService->assignRoleToUser($data['user_uuid'], $roleUuid, $options);
            if (!$assigned) {
                return Response::error('Failed to assign role', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Role assigned successfully')->send();
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), ErrorCodes::BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Revoke role from user
     *
     * @route DELETE /api/rbac/roles/{uuid}/revoke
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function revokeFromUser(array $params, Request $request): Response
    {
        try {
            $roleUuid = $params['uuid'] ?? '';
            $data = $request->toArray();

            if (empty($data['user_uuid'])) {
                return Response::error(
                    'User UUID is required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $revoked = $this->roleService->revokeRoleFromUser($data['user_uuid'], $roleUuid);
            if (!$revoked) {
                return Response::error('Failed to revoke role', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Role revoked successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get users assigned to a role
     *
     * @route GET /api/rbac/roles/{uuid}/users
     * @param array $params
     * @param Request $request
     * @return Response
     */
    public function getUsers(array $params, Request $request): Response
    {
        try {
            $uuid = $params['uuid'] ?? '';
            $page = (int) $request->query->get('page', 1);
            $perPage = (int) $request->query->get('per_page', 25);

            $role = $this->roleRepository->findRecordByUuid($uuid);
            if (!$role) {
                throw new NotFoundException('Role not found');
            }

            $users = $this->roleRepository->getUsersWithRolePaginated($uuid, $page, $perPage);

            return Response::ok($users, 'Role users retrieved successfully')->send();
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage())->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Get role statistics
     *
     * @route GET /api/rbac/roles/stats
     * @param Request $request
     * @return Response
     */
    public function stats(Request $request): Response
    {
        try {
            $stats = [];

            $stats['total_roles'] = $this->getQueryBuilder()->count('roles', ['deleted_at' => null]);
            $stats['active_roles'] = $this->getQueryBuilder()->count('roles', [
                'status' => 'active',
                'deleted_at' => null
            ]);
            $stats['system_roles'] = $this->getQueryBuilder()->count('roles', [
                'is_system' => true,
                'deleted_at' => null
            ]);

            // Get roles by level using database-agnostic methods
            $rolesByLevel = $this->getQueryBuilder()
                ->select('roles', ['level'])
                ->where(['deleted_at' => null])
                ->groupBy(['level'])
                ->get();
            $stats['by_level'] = [];
            foreach ($rolesByLevel as $stat) {
                $level = $stat['level'];
                $stats['by_level'][$level] = $this->getQueryBuilder()
                    ->count('roles', ['level' => $level, 'deleted_at' => null]);
            }

            return Response::ok($stats, 'Role statistics retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }

    /**
     * Bulk role operations
     *
     * @route POST /api/rbac/roles/bulk
     * @param Request $request
     * @return Response
     */
    public function bulk(Request $request): Response
    {
        try {
            $data = $request->toArray();

            if (empty($data['action']) || empty($data['role_ids'])) {
                return Response::error(
                    'Action and role_ids are required',
                    ErrorCodes::BAD_REQUEST
                )->send();
            }

            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            foreach ($data['role_ids'] as $roleUuid) {
                try {
                    $role = $this->roleRepository->findRecordByUuid($roleUuid);
                    if (!$role) {
                        $results['failed']++;
                        $results['errors'][] = "Role {$roleUuid} not found";
                        continue;
                    }

                    switch ($data['action']) {
                        case 'delete':
                            $force = $data['force'] ?? false;
                            $this->roleService->deleteRole($roleUuid, $force);
                            break;
                        case 'activate':
                            $this->roleService->updateRole($roleUuid, ['status' => 'active']);
                            break;
                        case 'deactivate':
                            $this->roleService->updateRole($roleUuid, ['status' => 'inactive']);
                            break;
                        default:
                            throw new \InvalidArgumentException('Invalid action');
                    }

                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Failed for role {$roleUuid}: " . $e->getMessage();
                }
            }

            return Response::ok(
                $results,
                "Bulk operation completed: {$results['success']} succeeded, {$results['failed']} failed"
            )->send();
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }
}
