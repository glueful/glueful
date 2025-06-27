<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Permissions\Exceptions\UnauthorizedException;
use Glueful\Permissions\PermissionManager;
use Glueful\Models\User;
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Permissions\PermissionContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authorization Trait
 *
 * Provides authorization and permission checking functionality for controllers.
 * Handles permission validation, admin checks, and user context extraction.
 *
 * @package Glueful\Controllers\Traits
 */
trait AuthorizationTrait
{
    /**
     * Check if current user has a specific permission
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array $context Additional context for permission check
     * @return bool True if user has permission
     */
    protected function can(string $permission, string $resource = 'system', array $context = []): bool
    {
        // Use cached permission check from CachedUserContextTrait
        return $this->hasCachedPermission($permission, $resource, $context);
    }

    /**
     * Require specific permission for the current user
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array $context Additional context for permission check
     * @throws UnauthorizedException If permission is denied
     */
    protected function requirePermission(
        string $permission,
        string $resource = 'system',
        array $context = []
    ): void {
        // Use cached authentication check from CachedUserContextTrait
        $this->requireCachedAuthentication();

        // Check if permission provider is available
        $permissionManager = PermissionManager::getInstance();
        if (!$permissionManager->hasActiveProvider()) {
            throw new UnauthorizedException(
                'Permission system unavailable',
                '503',
                'The permission system is currently unavailable. Please try again later.'
            );
        }

        if (!$this->can($permission, $resource, $context)) {
            throw new UnauthorizedException(
                $this->getCachedUserUuid(),
                $permission,
                $resource,
                sprintf('You do not have permission to %s on %s', $permission, $resource)
            );
        }
    }

    /**
     * Check if current user has any of the specified permissions
     *
     * @param array $permissions Array of permissions to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array $context Additional context for permission check
     * @return bool True if user has at least one permission
     */
    protected function canAny(array $permissions, string $resource = 'system', array $context = []): bool
    {
        if (!$this->isCachedAuthenticated()) {
            return false;
        }

        // Admin users have all permissions
        if ($this->isCachedAdmin()) {
            return true;
        }

        // Check if permission provider is available
        if (!$this->hasPermissionProvider()) {
            return false;
        }

        $permissionContext = new PermissionContext(
            data: $context,
            ipAddress: $this->request->getClientIp(),
            userAgent: $this->request->headers->get('User-Agent'),
            requestId: $this->request->headers->get('X-Request-ID')
        );

        return PermissionHelper::hasAnyPermission(
            $this->getCachedUserUuid(),
            $permissions,
            $resource,
            $permissionContext->toArray()
        );
    }

    /**
     * Check if a permission provider is available
     *
     * @return bool True if provider is available, false otherwise
     */
    protected function hasPermissionProvider(): bool
    {
        $permissionManager = PermissionManager::getInstance();

        if (!$permissionManager->hasActiveProvider()) {
            return false;
        }

        return true;
    }

    /**
     * Check if the current user is an admin
     *
     * @return bool True if user is an admin
     */
    protected function isAdmin(): bool
    {
        // Use cached admin check from CachedUserContextTrait
        return $this->isCachedAdmin();
    }

    /**
     * Get current authenticated user data
     *
     * @return User|null Current user data
     */
    protected function getCurrentUser(): ?User
    {
        // Use cached user from CachedUserContextTrait for consistency
        return $this->getCachedUser();
    }

    /**
     * Get current authenticated user UUID
     *
     * @return string|null Current user UUID
     */
    protected function getCurrentUserUuid(): ?string
    {
        // Use cached UUID from CachedUserContextTrait for consistency
        return $this->getCachedUserUuid();
    }

    /**
     * Extract token from request
     *
     * @param Request $request
     * @return string|null
     */
    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader) {
            return null;
        }

        return strpos($authHeader, 'Bearer ') === 0
            ? substr($authHeader, 7)
            : $authHeader;
    }
}
