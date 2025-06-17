<?php

namespace Glueful\Extensions\RBAC;

use Glueful\Interfaces\Permission\PermissionProviderInterface;
use Glueful\Extensions\RBAC\Repositories\RoleRepository;
use Glueful\Extensions\RBAC\Repositories\PermissionRepository;
use Glueful\Extensions\RBAC\Repositories\UserRoleRepository;
use Glueful\Extensions\RBAC\Repositories\UserPermissionRepository;
use Glueful\Extensions\RBAC\Repositories\RolePermissionRepository;
use Glueful\Extensions\RBAC\Models\Role;
use Glueful\Cache\CacheEngine;

/**
 * RBAC Permission Provider
 *
 * Implements role-based access control with hierarchical roles,
 * direct user permissions, and comprehensive permission management.
 *
 * Features:
 * - Hierarchical role system
 * - Direct user permissions (overrides)
 * - Resource-level permission filtering
 * - Temporal permissions (expiry)
 * - Permission inheritance
 * - Scoped permissions
 */
class RBACPermissionProvider implements PermissionProviderInterface
{
    private ?RoleRepository $roleRepository = null;
    private ?PermissionRepository $permissionRepository = null;
    private ?UserRoleRepository $userRoleRepository = null;
    private ?UserPermissionRepository $userPermissionRepository = null;
    private ?RolePermissionRepository $rolePermissionRepository = null;
    private array $config = [
        'cache_ttl' => 3600,
        'cache_enabled' => true,
        'cache_prefix' => 'rbac:',
        'enable_hierarchy' => true,
        'enable_inheritance' => true,
        'max_hierarchy_depth' => 10
    ];
    private array $permissionCache = [];
    private string $cachePrefix = 'rbac:';
    private bool $cacheEnabled = true;

    public function initialize(array $config = []): void
    {
        $this->config = array_merge([
            'cache_ttl' => 3600,
            'cache_enabled' => true,
            'cache_prefix' => 'rbac:',
            'enable_hierarchy' => true,
            'enable_inheritance' => true,
            'max_hierarchy_depth' => 10
        ], $config);

        $this->cacheEnabled = $this->config['cache_enabled'];
        $this->cachePrefix = $this->config['cache_prefix'];

        // Initialize cache engine if enabled
        if ($this->cacheEnabled) {
            try {
                CacheEngine::initialize($this->cachePrefix);
            } catch (\Exception $e) {
                // Graceful degradation - disable cache if initialization fails
                $this->cacheEnabled = false;
                error_log("RBAC Cache initialization failed: " . $e->getMessage());
            }
        }

        // Don't instantiate repositories here - use lazy loading
    }

    /**
     * Get role repository (lazy loading)
     */
    private function getRoleRepository(): RoleRepository
    {
        if ($this->roleRepository === null) {
            $this->roleRepository = new RoleRepository();
        }
        return $this->roleRepository;
    }

    /**
     * Get permission repository (lazy loading)
     */
    private function getPermissionRepository(): PermissionRepository
    {
        if ($this->permissionRepository === null) {
            $this->permissionRepository = new PermissionRepository();
        }
        return $this->permissionRepository;
    }

    /**
     * Get user role repository (lazy loading)
     */
    private function getUserRoleRepository(): UserRoleRepository
    {
        if ($this->userRoleRepository === null) {
            $this->userRoleRepository = new UserRoleRepository();
        }
        return $this->userRoleRepository;
    }

    /**
     * Get user permission repository (lazy loading)
     */
    private function getUserPermissionRepository(): UserPermissionRepository
    {
        if ($this->userPermissionRepository === null) {
            $this->userPermissionRepository = new UserPermissionRepository();
        }
        return $this->userPermissionRepository;
    }

    /**
     * Get role permission repository (lazy loading)
     */
    private function getRolePermissionRepository(): RolePermissionRepository
    {
        if ($this->rolePermissionRepository === null) {
            $this->rolePermissionRepository = new RolePermissionRepository();
        }
        return $this->rolePermissionRepository;
    }

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        // Generate cache key for this permission check
        $cacheKey = $this->generateCacheKey('check', $userUuid, [
            'permission' => $permission,
            'resource' => $resource,
            'context' => $context
        ]);

        // Check cache if enabled and context is cacheable (no dynamic constraints)
        if ($this->cacheEnabled && $this->isContextCacheable($context)) {
            try {
                $cached = CacheEngine::get($cacheKey);
                if ($cached !== null) {
                    return (bool)$cached;
                }
            } catch (\Exception $e) {
                // Log but continue without cache
                error_log("Cache read failed for permission check: " . $e->getMessage());
            }
        }

        // Perform the actual permission check
        $result = false;

        // First check direct user permissions (these override role permissions)
        if ($this->hasDirectPermission($userUuid, $permission, $resource, $context)) {
            $result = true;
        } else {
            // Then check role-based permissions
            $result = $this->hasRoleBasedPermission($userUuid, $permission, $resource, $context);
        }

        // Cache the result if context is cacheable
        if ($this->cacheEnabled && $this->isContextCacheable($context)) {
            try {
                // Cache permission checks for a shorter time (15 minutes)
                CacheEngine::set($cacheKey, $result, 900);
            } catch (\Exception $e) {
                error_log("Cache write failed for permission check: " . $e->getMessage());
            }
        }

        return $result;
    }

    public function getUserPermissions(string $userUuid): array
    {
        // Check memory cache first
        if (isset($this->permissionCache[$userUuid])) {
            return $this->permissionCache[$userUuid];
        }

        // Check distributed cache
        $cacheKey = $this->generateCacheKey('user_permissions', $userUuid);
        if ($this->cacheEnabled) {
            try {
                $cached = CacheEngine::get($cacheKey);
                if ($cached !== null) {
                    $this->permissionCache[$userUuid] = $cached;
                    return $cached;
                }
            } catch (\Exception $e) {
                // Log but continue without cache
                error_log("Cache read failed for user permissions {$userUuid}: " . $e->getMessage());
            }
        }

        // Build permissions from scratch
        $permissions = [];

        // Get direct user permissions
        $directPermissions = $this->getDirectUserPermissions($userUuid);
        $permissions = array_merge_recursive($permissions, $directPermissions);

        // Get role-based permissions
        $rolePermissions = $this->getRoleBasedPermissions($userUuid);
        $permissions = array_merge_recursive($permissions, $rolePermissions);

        // Cache the result
        $this->permissionCache[$userUuid] = $permissions;
        if ($this->cacheEnabled) {
            try {
                CacheEngine::set($cacheKey, $permissions, $this->config['cache_ttl']);
            } catch (\Exception $e) {
                error_log("Cache write failed for user permissions {$userUuid}: " . $e->getMessage());
            }
        }

        return $permissions;
    }

    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool
    {
        $permissionModel = $this->getPermissionRepository()->findPermissionBySlug($permission);
        if (!$permissionModel) {
            return false;
        }

        $data = [
            'user_uuid' => $userUuid,
            'permission_uuid' => $permissionModel->getUuid(),
            'granted_by' => $options['granted_by'] ?? null,
            'expires_at' => $options['expires_at'] ?? null
        ];

        // Set resource filter if specified
        if ($resource !== '*') {
            $data['resource_filter'] = json_encode(['resource' => $resource]);
        }

        // Set constraints if specified
        if (isset($options['constraints'])) {
            $data['constraints'] = json_encode($options['constraints']);
        }

        try {
            $result = $this->getUserPermissionRepository()->create($data);
            if (!empty($result)) {
                $this->invalidateUserCache($userUuid);
                return true;
            }
        } catch (\Exception $e) {
            // Log error but don't throw
            error_log("Failed to assign permission: " . $e->getMessage());
        }

        return false;
    }

    public function revokePermission(string $userUuid, string $permission, string $resource): bool
    {
        $permissionModel = $this->getPermissionRepository()->findPermissionBySlug($permission);
        if (!$permissionModel) {
            return false;
        }

        $result = $this->getUserPermissionRepository()->revokeUserPermission($userUuid, $permissionModel->getUuid());

        if ($result) {
            $this->invalidateUserCache($userUuid);
            return true;
        }

        return false;
    }

    public function getAvailablePermissions(): array
    {
        $permissions = $this->getPermissionRepository()->findAllPermissions();
        $result = [];

        foreach ($permissions as $permission) {
            $result[$permission->getSlug()] = $permission->getDescription() ?: $permission->getName();
        }

        return $result;
    }

    public function getAvailableResources(): array
    {
        // Get unique resource types from permissions
        $resourceTypes = $this->getPermissionRepository()->getResourceTypes();
        $result = [];

        foreach ($resourceTypes as $type) {
            $result[$type] = ucfirst(str_replace('_', ' ', $type));
        }

        // Add some common resources
        $commonResources = [
            'users' => 'User Management',
            'roles' => 'Role Management',
            'permissions' => 'Permission Management',
            'system' => 'System Administration'
        ];

        return array_merge($commonResources, $result);
    }

    public function batchAssignPermissions(string $userUuid, array $permissions, array $options = []): bool
    {
        $success = true;

        foreach ($permissions as $permissionData) {
            $permission = $permissionData['permission'] ?? '';
            $resource = $permissionData['resource'] ?? '*';
            $permissionOptions = array_merge($options, $permissionData['options'] ?? []);

            if (!$this->assignPermission($userUuid, $permission, $resource, $permissionOptions)) {
                $success = false;
            }
        }

        return $success;
    }

    public function batchRevokePermissions(string $userUuid, array $permissions): bool
    {
        $success = true;

        foreach ($permissions as $permissionData) {
            $permission = $permissionData['permission'] ?? '';
            $resource = $permissionData['resource'] ?? '*';

            if (!$this->revokePermission($userUuid, $permission, $resource)) {
                $success = false;
            }
        }

        return $success;
    }

    public function invalidateUserCache(string $userUuid): void
    {
        // Clear memory cache
        unset($this->permissionCache[$userUuid]);

        // Clear distributed cache if enabled
        if ($this->cacheEnabled) {
            try {
                $userPermissionsKey = $this->generateCacheKey('user_permissions', $userUuid);
                $userRolesKey = $this->generateCacheKey('user_roles', $userUuid);

                CacheEngine::delete($userPermissionsKey);
                CacheEngine::delete($userRolesKey);

                // Also clear any permission check cache for this user
                $this->clearUserPermissionChecks($userUuid);
            } catch (\Exception $e) {
                error_log("Failed to invalidate cache for user {$userUuid}: " . $e->getMessage());
            }
        }
    }

    public function invalidateAllCache(): void
    {
        // Clear memory cache
        $this->permissionCache = [];

        // Clear distributed cache if enabled
        if ($this->cacheEnabled) {
            try {
                // Clear all RBAC-related cache keys
                CacheEngine::deletePattern($this->cachePrefix . '*');
            } catch (\Exception $e) {
                error_log("Failed to invalidate all RBAC cache: " . $e->getMessage());
            }
        }
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'RBAC Permission Provider',
            'version' => '1.0.0',
            'description' => 'Hierarchical role-based access control with direct user permissions',
            'capabilities' => [
                'hierarchical_roles',
                'direct_permissions',
                'temporal_permissions',
                'resource_filtering',
                'permission_inheritance',
                'scoped_permissions'
            ],
            'author' => 'Glueful Team'
        ];
    }

    public function healthCheck(): array
    {
        $checks = [];

        try {
            // Test role repository
            $roleCount = $this->getRoleRepository()->countRoles();
            $checks['roles'] = [
                'status' => 'healthy',
                'message' => "Found {$roleCount} roles"
            ];
        } catch (\Exception $e) {
            $checks['roles'] = [
                'status' => 'error',
                'message' => 'Role repository error: ' . $e->getMessage()
            ];
        }

        try {
            // Test permission repository
            $permissionCount = $this->getPermissionRepository()->countPermissions();
            $checks['permissions'] = [
                'status' => 'healthy',
                'message' => "Found {$permissionCount} permissions"
            ];
        } catch (\Exception $e) {
            $checks['permissions'] = [
                'status' => 'error',
                'message' => 'Permission repository error: ' . $e->getMessage()
            ];
        }

        $healthy = array_reduce($checks, fn($carry, $check) => $carry && $check['status'] === 'healthy', true);

        return [
            'status' => $healthy ? 'healthy' : 'error',
            'checks' => $checks,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    // RBAC-specific methods

    public function assignRole(string $userUuid, string $roleSlug, array $options = []): bool
    {
        $role = $this->getRoleRepository()->findRoleBySlug($roleSlug);
        if (!$role) {
            return false;
        }

        $result = $this->getUserRoleRepository()->assignRole($userUuid, $role->getUuid(), $options);

        if ($result) {
            $this->invalidateUserCache($userUuid);
            return true;
        }

        return false;
    }

    public function revokeRole(string $userUuid, string $roleSlug): bool
    {
        $role = $this->getRoleRepository()->findRoleBySlug($roleSlug);
        if (!$role) {
            return false;
        }

        $result = $this->getUserRoleRepository()->revokeRole($userUuid, $role->getUuid());

        if ($result) {
            $this->invalidateUserCache($userUuid);
            return true;
        }

        return false;
    }

    public function getUserRoles(string $userUuid, array $scope = []): array
    {
        $userRoles = $this->getUserRoleRepository()->getUserRoles($userUuid, $scope);
        $roles = [];

        foreach ($userRoles as $userRole) {
            $role = $this->getRoleRepository()->findRoleByUuid($userRole->getRoleUuid());
            if ($role) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function hasRole(string $userUuid, string $roleSlug, array $scope = []): bool
    {
        $role = $this->getRoleRepository()->findRoleBySlug($roleSlug);
        if (!$role) {
            return false;
        }

        return $this->getUserRoleRepository()->hasUserRole($userUuid, $role->getUuid(), $scope);
    }

    // Private helper methods

    private function hasDirectPermission(string $userUuid, string $permission, string $resource, array $context): bool
    {
        $permissionModel = $this->getPermissionRepository()->findPermissionBySlug($permission);
        if (!$permissionModel) {
            return false;
        }

        $userPermissions = $this->getUserPermissionRepository()->findByUser($userUuid, [
            'permission_uuid' => $permissionModel->getUuid(),
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
        string $permission,
        string $resource,
        array $context = []
    ): bool {
        $userRoles = $this->getUserRoles($userUuid);

        foreach ($userRoles as $role) {
            if ($this->roleHasPermission($role, $permission, $resource)) {
                return true;
            }

            // Check role hierarchy if enabled
            if ($this->config['enable_hierarchy'] && $this->config['enable_inheritance']) {
                $hierarchy = $this->getRoleRepository()->getRoleHierarchy($role->getUuid());
                foreach ($hierarchy as $parentRole) {
                    if ($this->roleHasPermission($parentRole, $permission, $resource)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function roleHasPermission(Role $role, string $permission, string $resource): bool
    {
        // Check if permission exists
        $permissionModel = $this->getPermissionRepository()->findPermissionBySlug($permission);
        if (!$permissionModel) {
            return false;
        }

        // Generate cache key for this role-permission check
        $cacheKey = $this->generateCacheKey('role_permission', $role->getUuid(), [
            'permission' => $permission,
            'resource' => $resource
        ]);

        // Check cache if enabled
        if ($this->cacheEnabled) {
            try {
                $cached = CacheEngine::get($cacheKey);
                if ($cached !== null) {
                    return (bool)$cached;
                }
            } catch (\Exception $e) {
                // Log but continue without cache
                error_log("Cache read failed for role permission check: " . $e->getMessage());
            }
        }

        // Check if role has this permission
        $context = $resource !== '*' ? ['resource' => $resource] : [];
        $result = $this->getRolePermissionRepository()->roleHasPermission(
            $role->getUuid(),
            $permissionModel->getUuid(),
            $context
        );

        // Cache the result
        if ($this->cacheEnabled) {
            try {
                // Cache role permission checks for 30 minutes
                CacheEngine::set($cacheKey, $result, 1800);
            } catch (\Exception $e) {
                error_log("Cache write failed for role permission check: " . $e->getMessage());
            }
        }

        return $result;
    }

    private function getDirectUserPermissions(string $userUuid): array
    {
        $userPermissions = $this->getUserPermissionRepository()->getUserPermissions($userUuid);
        $permissions = [];

        foreach ($userPermissions as $userPermission) {
            $permission = $this->getPermissionRepository()->findPermissionByUuid($userPermission->getPermissionUuid());
            if ($permission) {
                $resourceFilter = $userPermission->getResourceFilter();
                $resource = $resourceFilter['resource'] ?? '*';

                if (!isset($permissions[$resource])) {
                    $permissions[$resource] = [];
                }

                $permissions[$resource][] = $permission->getSlug();
            }
        }

        return $permissions;
    }

    private function getRoleBasedPermissions(string $userUuid): array
    {
        $permissions = [];

        // Get user's roles
        $userRoles = $this->getUserRoles($userUuid);

        foreach ($userRoles as $role) {
            // Get permissions for this role
            $rolePermissions = $this->getRolePermissionRepository()->getRolePermissions(
                $role->getUuid(),
                ['active_only' => true]
            );

            foreach ($rolePermissions as $rolePermission) {
                $permission = $this->getPermissionRepository()
                    ->findPermissionByUuid($rolePermission->getPermissionUuid());
                if (!$permission) {
                    continue;
                }

                // Get resource filter
                $resourceFilter = $rolePermission->getResourceFilter();
                $resource = $resourceFilter['resource'] ?? '*';

                if (!isset($permissions[$resource])) {
                    $permissions[$resource] = [];
                }

                // Add permission if not already present
                if (!in_array($permission->getSlug(), $permissions[$resource])) {
                    $permissions[$resource][] = $permission->getSlug();
                }
            }

            // Include inherited permissions if hierarchy is enabled
            if ($this->config['enable_hierarchy'] && $this->config['enable_inheritance']) {
                $hierarchy = $this->getRoleRepository()->getRoleHierarchy($role->getUuid());

                foreach ($hierarchy as $parentRole) {
                    $parentPermissions = $this->getRolePermissionRepository()->getRolePermissions(
                        $parentRole->getUuid(),
                        ['active_only' => true]
                    );

                    foreach ($parentPermissions as $parentPermission) {
                        $permission = $this->getPermissionRepository()
                            ->findPermissionByUuid($parentPermission->getPermissionUuid());
                        if (!$permission) {
                            continue;
                        }

                        $resourceFilter = $parentPermission->getResourceFilter();
                        $resource = $resourceFilter['resource'] ?? '*';

                        if (!isset($permissions[$resource])) {
                            $permissions[$resource] = [];
                        }

                        if (!in_array($permission->getSlug(), $permissions[$resource])) {
                            $permissions[$resource][] = $permission->getSlug();
                        }
                    }
                }
            }
        }

        return $permissions;
    }

    // Cache helper methods

    private function generateCacheKey(string $type, string $identifier, array $context = []): string
    {
        $key = $this->cachePrefix . $type . ':' . $identifier;

        if (!empty($context)) {
            $key .= ':' . md5(serialize($context));
        }

        return $key;
    }

    private function clearUserPermissionChecks(string $userUuid): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        try {
            // Clear all permission check cache keys for this user
            $pattern = $this->cachePrefix . 'check:' . $userUuid . ':*';
            CacheEngine::deletePattern($pattern);
        } catch (\Exception $e) {
            error_log("Failed to clear permission checks cache for user {$userUuid}: " . $e->getMessage());
        }
    }

    private function isContextCacheable(array $context): bool
    {
        // Don't cache if context contains time-sensitive data
        $nonCacheableKeys = ['ip', 'timestamp', 'session_id', 'request_id'];

        foreach ($nonCacheableKeys as $key) {
            if (isset($context[$key])) {
                return false;
            }
        }

        return true;
    }
}
