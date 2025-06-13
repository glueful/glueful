<?php

namespace Glueful\Extensions\RBAC\Services;

use Glueful\Extensions\RBAC\Repositories\PermissionRepository;
use Glueful\Extensions\RBAC\Repositories\UserPermissionRepository;
use Glueful\Extensions\RBAC\Repositories\RoleRepository;
use Glueful\Extensions\RBAC\Repositories\UserRoleRepository;
use Glueful\Extensions\RBAC\Repositories\RolePermissionRepository;
use Glueful\Extensions\RBAC\Models\Permission;
use Glueful\Helpers\Utils;

/**
 * Permission Assignment Service
 *
 * Business logic for permission assignment operations
 *
 * Features:
 * - Direct user permission assignments
 * - Role-based permission management
 * - Batch permission operations
 * - Permission validation and conflicts
 * - Temporal permission handling
 * - Resource-level permissions
 */
class PermissionAssignmentService
{
    private PermissionRepository $permissionRepository;
    private UserPermissionRepository $userPermissionRepository;
    private RoleRepository $roleRepository;
    private UserRoleRepository $userRoleRepository;
    private RolePermissionRepository $rolePermissionRepository;

    public function __construct(
        PermissionRepository $permissionRepository,
        UserPermissionRepository $userPermissionRepository,
        RoleRepository $roleRepository,
        UserRoleRepository $userRoleRepository,
        RolePermissionRepository $rolePermissionRepository
    ) {
        $this->permissionRepository = $permissionRepository;
        $this->userPermissionRepository = $userPermissionRepository;
        $this->roleRepository = $roleRepository;
        $this->userRoleRepository = $userRoleRepository;
        $this->rolePermissionRepository = $rolePermissionRepository;
    }

    /**
     * Assign permission directly to user
     */
    public function assignPermissionToUser(
        string $userUuid,
        string $permissionSlug,
        string $resource = '*',
        array $options = []
    ): bool {
        $permission = $this->permissionRepository->findBySlug($permissionSlug);
        if (!$permission) {
            throw new \InvalidArgumentException('Permission not found: ' . $permissionSlug);
        }

        // Check if permission already exists
        $existing = $this->userPermissionRepository->findUserPermission($userUuid, $permission->getUuid());
        if ($existing) {
            return true; // Already assigned
        }

        $data = [
            'user_uuid' => $userUuid,
            'permission_uuid' => $permission->getUuid(),
            'granted_by' => $options['granted_by'] ?? null,
            'expires_at' => $options['expires_at'] ?? null
        ];

        // Set resource filter if not global
        if ($resource !== '*') {
            $data['resource_filter'] = json_encode(['resource' => $resource]);
        }

        // Set constraints if provided
        if (isset($options['constraints'])) {
            $data['constraints'] = json_encode($options['constraints']);
        }

        try {
            $result = $this->userPermissionRepository->create($data);
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Revoke permission from user
     */
    public function revokePermissionFromUser(string $userUuid, string $permissionSlug): bool
    {
        $permission = $this->permissionRepository->findBySlug($permissionSlug);
        if (!$permission) {
            return false; // Permission doesn't exist, consider it revoked
        }

        return $this->userPermissionRepository->revokeUserPermission($userUuid, $permission->getUuid());
    }

    /**
     * Batch assign permissions to user
     */
    public function batchAssignPermissions(string $userUuid, array $permissions, array $globalOptions = []): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($permissions as $permissionData) {
            $slug = $permissionData['permission'] ?? '';
            $resource = $permissionData['resource'] ?? '*';
            $options = array_merge($globalOptions, $permissionData['options'] ?? []);

            try {
                $success = $this->assignPermissionToUser($userUuid, $slug, $resource, $options);
                $results[] = [
                    'permission' => $slug,
                    'resource' => $resource,
                    'success' => $success,
                    'error' => null
                ];

                if ($success) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $results[] = [
                    'permission' => $slug,
                    'resource' => $resource,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $failed++;
            }
        }

        return [
            'total' => count($permissions),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results
        ];
    }

    /**
     * Batch revoke permissions from user
     */
    public function batchRevokePermissions(string $userUuid, array $permissionSlugs): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($permissionSlugs as $slug) {
            try {
                $success = $this->revokePermissionFromUser($userUuid, $slug);
                $results[] = [
                    'permission' => $slug,
                    'success' => $success,
                    'error' => null
                ];

                if ($success) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $results[] = [
                    'permission' => $slug,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $failed++;
            }
        }

        return [
            'total' => count($permissionSlugs),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results
        ];
    }

    /**
     * Get user's direct permissions
     */
    public function getUserDirectPermissions(string $userUuid, array $filters = []): array
    {
        $userPermissions = $this->userPermissionRepository->findByUser($userUuid, $filters);
        $permissions = [];

        foreach ($userPermissions as $userPermission) {
            $permission = $this->permissionRepository->findByUuid($userPermission->getPermissionUuid());
            if ($permission) {
                $permissions[] = [
                    'permission' => $permission,
                    'assignment' => $userPermission,
                    'resource_filter' => $userPermission->getResourceFilter(),
                    'constraints' => $userPermission->getConstraints(),
                    'expires_at' => $userPermission->getExpiresAt(),
                    'granted_by' => $userPermission->getGrantedBy()
                ];
            }
        }

        return $permissions;
    }

    /**
     * Get user's effective permissions (direct + role-based)
     */
    public function getUserEffectivePermissions(string $userUuid, array $scope = []): array
    {
        $effectivePermissions = [];

        // Get direct permissions
        $directPermissions = $this->getUserDirectPermissions($userUuid, ['active_only' => true]);
        foreach ($directPermissions as $permData) {
            $slug = $permData['permission']->getSlug();
            $resourceFilter = $permData['resource_filter'];
            $resource = $resourceFilter['resource'] ?? '*';

            if (!isset($effectivePermissions[$resource])) {
                $effectivePermissions[$resource] = [];
            }

            $effectivePermissions[$resource][] = [
                'permission' => $slug,
                'source' => 'direct',
                'expires_at' => $permData['expires_at'],
                'constraints' => $permData['constraints']
            ];
        }

        // Get role-based permissions
        $rolePermissions = $this->getUserRolePermissions($userUuid, $scope);
        foreach ($rolePermissions as $resource => $permissions) {
            if (!isset($effectivePermissions[$resource])) {
                $effectivePermissions[$resource] = [];
            }

            foreach ($permissions as $permission) {
                $effectivePermissions[$resource][] = [
                    'permission' => $permission['permission'],
                    'source' => 'role',
                    'role' => $permission['role'],
                    'expires_at' => $permission['expires_at']
                ];
            }
        }

        return $effectivePermissions;
    }

    /**
     * Check if user has specific permission
     */
    public function userHasPermission(
        string $userUuid,
        string $permissionSlug,
        string $resource = '*',
        array $context = []
    ): bool {
        $permission = $this->permissionRepository->findBySlug($permissionSlug);
        if (!$permission) {
            return false;
        }

        // Check direct permissions first
        if ($this->hasDirectPermission($userUuid, $permission->getUuid(), $resource, $context)) {
            return true;
        }

        // Check role-based permissions
        return $this->hasRoleBasedPermission($userUuid, $permission->getUuid(), $resource, $context);
    }

    /**
     * Create a new permission
     */
    public function createPermission(array $data): ?Permission
    {
        // Validate required fields
        if (empty($data['name']) || empty($data['slug'])) {
            throw new \InvalidArgumentException('Permission name and slug are required');
        }

        // Check for duplicates
        if ($this->permissionRepository->permissionExists($data['name'])) {
            throw new \InvalidArgumentException('Permission name already exists');
        }

        if ($this->permissionRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Permission slug already exists');
        }

        // Set defaults
        $data['uuid'] = $data['uuid'] ?? Utils::generateNanoID();
        $data['is_system'] = $data['is_system'] ?? false;
        $data['metadata'] = isset($data['metadata']) ? json_encode($data['metadata']) : null;

        return $this->permissionRepository->createPermission($data);
    }

    /**
     * Update an existing permission
     */
    public function updatePermission(string $uuid, array $data): bool
    {
        $permission = $this->permissionRepository->findByUuid($uuid);
        if (!$permission) {
            throw new \InvalidArgumentException('Permission not found');
        }

        // Protect system permissions
        if ($permission->isSystem()) {
            $protectedFields = ['is_system', 'slug'];
            foreach ($protectedFields as $field) {
                if (isset($data[$field])) {
                    throw new \InvalidArgumentException("Cannot modify {$field} for system permissions");
                }
            }
        }

        // Validate uniqueness if changed
        if (isset($data['name']) && $data['name'] !== $permission->getName()) {
            if ($this->permissionRepository->permissionExists($data['name'], $uuid)) {
                throw new \InvalidArgumentException('Permission name already exists');
            }
        }

        if (isset($data['slug']) && $data['slug'] !== $permission->getSlug()) {
            if ($this->permissionRepository->slugExists($data['slug'], $uuid)) {
                throw new \InvalidArgumentException('Permission slug already exists');
            }
        }

        // Encode metadata if provided
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        return $this->permissionRepository->update($uuid, $data);
    }

    /**
     * Delete a permission
     */
    public function deletePermission(string $uuid, bool $force = false): bool
    {
        $permission = $this->permissionRepository->findByUuid($uuid);
        if (!$permission) {
            throw new \InvalidArgumentException('Permission not found');
        }

        // Protect system permissions
        if ($permission->isSystem() && !$force) {
            throw new \InvalidArgumentException('Cannot delete system permissions');
        }

        // Check for assignments
        $assignments = $this->userPermissionRepository->findByPermission($uuid);
        if (!empty($assignments) && !$force) {
            throw new \InvalidArgumentException('Cannot delete permission: still assigned to users');
        }

        // If force delete, remove all assignments
        if ($force) {
            foreach ($assignments as $assignment) {
                $this->userPermissionRepository->delete($assignment->getUuid());
            }
        }

        return $this->permissionRepository->delete($uuid);
    }

    /**
     * Cleanup expired permissions
     */
    public function cleanupExpiredPermissions(): array
    {
        $expired = $this->userPermissionRepository->findExpiredPermissions();
        $count = $this->userPermissionRepository->cleanupExpiredPermissions();

        return [
            'cleaned' => $count,
            'expired_permissions' => $expired
        ];
    }

    // Private helper methods

    private function hasDirectPermission(
        string $userUuid,
        string $permissionUuid,
        string $resource,
        array $context
    ): bool {
        $userPermissions = $this->userPermissionRepository->findByUser($userUuid, [
            'permission_uuid' => $permissionUuid,
            'active_only' => true
        ]);

        foreach ($userPermissions as $userPermission) {
            $resourceContext = $resource !== '*' ? ['resource' => $resource] : [];

            if (
                $userPermission->matchesResource($resourceContext) &&
                $userPermission->satisfiesConstraints($context)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasRoleBasedPermission(
        string $userUuid,
        string $permissionUuid,
        string $resource,
        array $context
    ): bool {
        // Get user's active roles
        $userRoles = $this->userRoleRepository->getUserRoles($userUuid, $context['scope'] ?? []);

        if (empty($userRoles)) {
            return false;
        }

        // Check each role for the permission
        foreach ($userRoles as $userRole) {
            $role = $this->roleRepository->findByUuid($userRole->getRoleUuid());
            if (!$role) {
                continue;
            }

            // Check if this role has the permission
            if ($this->roleHasPermission($role->getUuid(), $permissionUuid, $resource)) {
                return true;
            }

            // Check role hierarchy (parent roles) if inheritance is enabled
            $parentRoles = $this->roleRepository->getRoleHierarchy($role->getUuid());
            foreach ($parentRoles as $parentRole) {
                if ($this->roleHasPermission($parentRole->getUuid(), $permissionUuid, $resource)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getUserRolePermissions(string $userUuid, array $scope): array
    {
        $rolePermissions = [];

        // Get user's active roles
        $userRoles = $this->userRoleRepository->getUserRoles($userUuid, $scope);

        foreach ($userRoles as $userRole) {
            $role = $this->roleRepository->findByUuid($userRole->getRoleUuid());
            if (!$role) {
                continue;
            }

            // Get permissions for this role
            $permissions = $this->getRolePermissions($role->getUuid());

            foreach ($permissions as $permission) {
                $resourceFilter = $permission['resource_filter'] ?? ['resource' => '*'];
                $resource = $resourceFilter['resource'] ?? '*';

                if (!isset($rolePermissions[$resource])) {
                    $rolePermissions[$resource] = [];
                }

                $rolePermissions[$resource][] = [
                    'permission' => $permission['permission_slug'],
                    'role' => $role->getName(),
                    'role_uuid' => $role->getUuid(),
                    'expires_at' => $userRole->getExpiresAt()
                ];
            }

            // Include inherited permissions from parent roles
            $parentRoles = $this->roleRepository->getRoleHierarchy($role->getUuid());
            foreach ($parentRoles as $parentRole) {
                $parentPermissions = $this->getRolePermissions($parentRole->getUuid());

                foreach ($parentPermissions as $permission) {
                    $resourceFilter = $permission['resource_filter'] ?? ['resource' => '*'];
                    $resource = $resourceFilter['resource'] ?? '*';

                    if (!isset($rolePermissions[$resource])) {
                        $rolePermissions[$resource] = [];
                    }

                    // Mark as inherited
                    $rolePermissions[$resource][] = [
                        'permission' => $permission['permission_slug'],
                        'role' => $parentRole->getName(),
                        'role_uuid' => $parentRole->getUuid(),
                        'inherited_from' => $role->getName(),
                        'expires_at' => $userRole->getExpiresAt()
                    ];
                }
            }
        }

        return $rolePermissions;
    }

    /**
     * Check if a role has a specific permission
     */
    private function roleHasPermission(string $roleUuid, string $permissionUuid, string $resource): bool
    {
        $context = $resource !== '*' ? ['resource' => $resource] : [];

        return $this->rolePermissionRepository->roleHasPermission(
            $roleUuid,
            $permissionUuid,
            $context
        );
    }

    /**
     * Get all permissions for a role
     */
    private function getRolePermissions(string $roleUuid): array
    {
        $rolePermissions = $this->rolePermissionRepository->getRolePermissions(
            $roleUuid,
            ['active_only' => true]
        );

        $permissions = [];
        foreach ($rolePermissions as $rolePermission) {
            $permission = $this->permissionRepository->findByUuid($rolePermission->getPermissionUuid());
            if ($permission) {
                $permissions[] = [
                    'permission_slug' => $permission->getSlug(),
                    'permission_name' => $permission->getName(),
                    'resource_filter' => $rolePermission->getResourceFilter()
                ];
            }
        }

        return $permissions;
    }
}
