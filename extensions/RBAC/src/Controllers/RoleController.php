<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Controllers;

use Glueful\Http\Response;
use Glueful\Extensions\RBAC\Services\RoleService;
use Glueful\Extensions\RBAC\Repositories\RoleRepository;
use Glueful\Exceptions\NotFoundException;
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
                return Response::success($roleTree, 'Role hierarchy retrieved successfully');
            }

            $roles = $this->roleRepository->findAllPaginated($filters, $page, $perPage);
            $rolesData = $roles['data'];
            $meta = $roles;
            unset($meta['data']);

            return Response::successWithMeta($rolesData, $meta, 'Roles retrieved successfully');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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

            return Response::success($roleData, 'Role details retrieved successfully');
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage());
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
                return Response::validation(
                    ['name' => ['Role name is required'], 'slug' => ['Role slug is required']],
                    'Validation failed'
                );
            }

            $role = $this->roleService->createRole($data);
            if (!$role) {
                return Response::serverError('Failed to create role');
            }

            return Response::created($role->toArray(), 'Role created successfully');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['error' => [$e->getMessage()]], 'Validation failed');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
                return Response::serverError('Failed to update role');
            }

            $role = $this->roleRepository->findRecordByUuid($uuid);
            return Response::success($role, 'Role updated successfully');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['error' => [$e->getMessage()]], 'Validation failed');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
                return Response::serverError('Failed to delete role');
            }

            return Response::success(null, 'Role deleted successfully');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['error' => [$e->getMessage()]], 'Validation failed');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
                return Response::validation(['user_uuid' => ['User UUID is required']], 'Validation failed');
            }

            $options = [
                'scope' => $data['scope'] ?? [],
                'expires_at' => $data['expires_at'] ?? null,
                'assigned_by' => $data['assigned_by'] ?? null
            ];

            $assigned = $this->roleService->assignRoleToUser($data['user_uuid'], $roleUuid, $options);
            if (!$assigned) {
                return Response::serverError('Failed to assign role');
            }

            return Response::success(null, 'Role assigned successfully');
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['error' => [$e->getMessage()]], 'Validation failed');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
                return Response::validation(['user_uuid' => ['User UUID is required']], 'Validation failed');
            }

            $revoked = $this->roleService->revokeRoleFromUser($data['user_uuid'], $roleUuid);
            if (!$revoked) {
                return Response::serverError('Failed to revoke role');
            }

            return Response::success(null, 'Role revoked successfully');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
            $usersData = $users['data'];
            $meta = $users;
            unset($meta['data']);

            return Response::successWithMeta($usersData, $meta, 'Role users retrieved successfully');
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage());
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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

            // Use repository methods for role counts
            $stats['total_roles'] = $this->roleRepository->countRoles(['exclude_deleted' => true]);
            $stats['active_roles'] = $this->roleRepository->countRoles([
                'status' => 'active',
                'exclude_deleted' => true
            ]);
            $stats['system_roles'] = $this->roleRepository->countRoles([
                'is_system' => 1,
                'exclude_deleted' => true
            ]);

            // Get all roles to calculate by_level statistics
            $allRoles = $this->roleRepository->findAllRoles(['exclude_deleted' => true]);
            $stats['by_level'] = [];
            foreach ($allRoles as $role) {
                $level = $role->getLevel();
                if (!isset($stats['by_level'][$level])) {
                    $stats['by_level'][$level] = 0;
                }
                $stats['by_level'][$level]++;
            }

            return Response::success($stats, 'Role statistics retrieved successfully');
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
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
                return Response::validation(
                    ['action' => ['Action is required'], 'role_ids' => ['Role IDs are required']],
                    'Validation failed'
                );
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

            return Response::success(
                $results,
                "Bulk operation completed: {$results['success']} succeeded, {$results['failed']} failed"
            );
        } catch (\Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }
}
