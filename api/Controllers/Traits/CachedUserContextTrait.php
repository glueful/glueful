<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Http\RequestUserContext;
use Glueful\Models\User;
use Glueful\Permissions\Exceptions\UnauthorizedException;

/**
 * Cached User Context Trait
 *
 * Provides request-level user context caching to avoid repeated
 * authentication queries and improve performance.
 *
 * Features:
 * - Single authentication check per request
 * - Cached permission results
 * - Cached user roles and capabilities
 * - Request-scoped caching
 *
 * Usage:
 * ```php
 * class MyController extends BaseController
 * {
 *     use CachedUserContextTrait;
 *
 *     public function myAction()
 *     {
 *         $user = $this->getCachedUser();
 *         $isAdmin = $this->isCachedAdmin();
 *         $hasPermission = $this->hasCachedPermission('read', 'users');
 *     }
 * }
 * ```
 *
 * @package Glueful\Controllers\Traits
 */
trait CachedUserContextTrait
{
    /** @var RequestUserContext|null Cached user context instance */
    private ?RequestUserContext $cachedUserContext = null;

    /**
     * Get request user context instance
     *
     * @return RequestUserContext User context instance
     */
    protected function getUserContext(): RequestUserContext
    {
        if ($this->cachedUserContext === null) {
            $this->cachedUserContext = RequestUserContext::getInstance();
            // Don't call initialize() here - it should already be initialized in BaseController
        }

        return $this->cachedUserContext;
    }

    /**
     * Get cached authenticated user
     *
     * @return User|null Authenticated user or null
     */
    protected function getCachedUser(): ?User
    {
        return $this->getUserContext()->getUser();
    }

    /**
     * Get cached user UUID
     *
     * @return string|null User UUID or null
     */
    protected function getCachedUserUuid(): ?string
    {
        return $this->getUserContext()->getUserUuid();
    }

    /**
     * Check if user is authenticated (cached)
     *
     * @return bool True if authenticated
     */
    protected function isCachedAuthenticated(): bool
    {
        return $this->getUserContext()->isAuthenticated();
    }

    /**
     * Check if user is admin (cached)
     *
     * @return bool True if user is admin
     */
    protected function isCachedAdmin(): bool
    {
        return $this->getUserContext()->isAdmin();
    }

    /**
     * Check permission with caching
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has permission
     */
    protected function hasCachedPermission(string $permission, string $resource = 'system', array $context = []): bool
    {
        return $this->getUserContext()->hasPermission($permission, $resource, $context);
    }

    /**
     * Get cached user roles
     *
     * @return array User roles
     */
    protected function getCachedUserRoles(): array
    {
        return $this->getUserContext()->getUserRoles();
    }

    /**
     * Get cached user permissions
     *
     * @return array User permissions
     */
    protected function getCachedUserPermissions(): array
    {
        return $this->getUserContext()->getUserPermissions();
    }

    /**
     * Get cached authentication token
     *
     * @return string|null Authentication token or null
     */
    protected function getCachedToken(): ?string
    {
        return $this->getUserContext()->getToken();
    }

    /**
     * Get cached session data
     *
     * @return array|null Session data or null
     */
    protected function getCachedSessionData(): ?array
    {
        return $this->getUserContext()->getSessionData();
    }

    /**
     * Get audit context with cached user data
     *
     * @param array $additionalContext Additional context to merge
     * @return array Complete audit context
     */
    protected function getCachedAuditContext(array $additionalContext = []): array
    {
        $baseContext = $this->getUserContext()->getAuditContext();
        return array_merge($baseContext, $additionalContext);
    }

    /**
     * Cache custom user data for this request
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return self Fluent interface
     */
    protected function cacheUserData(string $key, mixed $value): self
    {
        $this->getUserContext()->cacheUserData($key, $value);
        return $this;
    }

    /**
     * Get cached custom user data
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    protected function getCachedUserData(string $key, mixed $default = null): mixed
    {
        return $this->getUserContext()->getCachedUserData($key, $default);
    }

    /**
     * Require authentication with cached check
     *
     * @param string|null $message Custom error message
     * @throws UnauthorizedException If not authenticated
     */
    protected function requireCachedAuthentication(?string $message = null): void
    {
        if (!$this->isCachedAuthenticated()) {
            throw new UnauthorizedException(
                'anonymous',
                'authentication',
                'system',
                $message ?? 'Authentication required'
            );
        }
    }

    /**
     * Require admin privileges with cached check
     *
     * @param string|null $message Custom error message
     * @throws UnauthorizedException If not admin
     */
    protected function requireCachedAdmin(?string $message = null): void
    {
        $this->requireCachedAuthentication();

        if (!$this->isCachedAdmin()) {
            throw new UnauthorizedException(
                $this->getCachedUserUuid(),
                'admin',
                'system',
                $message ?? 'Administrator privileges required'
            );
        }
    }

    /**
     * Require specific permission with cached check
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @param string|null $message Custom error message
     * @throws UnauthorizedException If permission denied
     */
    protected function requireCachedPermission(
        string $permission,
        string $resource = 'system',
        array $context = [],
        ?string $message = null
    ): void {
        $this->requireCachedAuthentication();

        if (!$this->hasCachedPermission($permission, $resource, $context)) {
            throw new UnauthorizedException(
                $this->getCachedUserUuid(),
                $permission,
                $resource,
                $message ?? sprintf(
                    'Permission "%s" required for resource "%s"',
                    $permission,
                    $resource
                )
            );
        }
    }

    /**
     * Check multiple permissions with OR logic (cached)
     *
     * @param array $permissions Array of permissions to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has at least one permission
     */
    protected function hasCachedAnyPermission(
        array $permissions,
        string $resource = 'system',
        array $context = []
    ): bool {
        foreach ($permissions as $permission) {
            if ($this->hasCachedPermission($permission, $resource, $context)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check multiple permissions with AND logic (cached)
     *
     * @param array $permissions Array of permissions to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has all permissions
     */
    protected function hasCachedAllPermissions(
        array $permissions,
        string $resource = 'system',
        array $context = []
    ): bool {
        foreach ($permissions as $permission) {
            if (!$this->hasCachedPermission($permission, $resource, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get user context cache statistics
     *
     * @return array Cache statistics
     */
    protected function getUserContextStats(): array
    {
        return $this->getUserContext()->getCacheStats();
    }

    /**
     * Clear permission cache for current request
     *
     * @param string|null $key Specific cache key or null for all
     * @return self Fluent interface
     */
    protected function clearPermissionCache(?string $key = null): self
    {
        $this->getUserContext()->clearPermissionCache($key);
        return $this;
    }

    /**
     * Refresh user context data
     *
     * @return self Fluent interface
     */
    protected function refreshUserContext(): self
    {
        $this->getUserContext()->refresh();
        $this->cachedUserContext = null; // Force recreation
        return $this;
    }

    /**
     * Check if user belongs to specific role (cached)
     *
     * @param string $role Role name or slug to check
     * @return bool True if user has role
     */
    protected function hasCachedRole(string $role): bool
    {
        $roles = $this->getCachedUserRoles();

        if (empty($roles)) {
            return false;
        }

        // Roles are stored as arrays with 'name' and 'slug' properties from RBAC
        foreach ($roles as $userRole) {
            if (
                is_array($userRole) &&
                (($userRole['name'] ?? null) === $role || ($userRole['slug'] ?? null) === $role)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user belongs to any of the specified roles (cached)
     *
     * @param array $roles Array of role names to check
     * @return bool True if user has any of the roles
     */
    protected function hasCachedAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasCachedRole($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get user's cached capabilities summary
     *
     * @return array User capabilities summary
     */
    protected function getCachedUserCapabilities(): array
    {
        return [
            'uuid' => $this->getCachedUserUuid(),
            'is_authenticated' => $this->isCachedAuthenticated(),
            'is_admin' => $this->isCachedAdmin(),
            'roles' => $this->getCachedUserRoles(),
            'permissions' => $this->getCachedUserPermissions(),
            'session_active' => $this->getCachedSessionData() !== null
        ];
    }
}
