<?php

declare(strict_types=1);

namespace Glueful\Permissions\Helpers;

use Glueful\Permissions\PermissionManager;
use Glueful\Interfaces\Permission\PermissionStandards;

/**
 * Permission Helper Class
 *
 * Provides utility methods for common permission checks using standardized
 * permission names. This class acts as a convenient wrapper around the
 * PermissionManager for frequently used permission operations.
 *
 * @package Glueful\Permissions\Helpers
 */
class PermissionHelper
{
    /**
     * Check if user can access admin system
     *
     * @param string $userUuid User UUID
     * @param array $context Additional context
     * @return bool True if user has admin access
     */
    public static function canAccessAdmin(string $userUuid, array $context = []): bool
    {
        return self::checkPermission(
            $userUuid,
            PermissionStandards::PERMISSION_SYSTEM_ACCESS,
            'system',
            array_merge($context, ['check_type' => 'admin_access'])
        );
    }

    /**
     * Check if user can view users
     *
     * @param string $userUuid User UUID
     * @param array $context Additional context
     * @return bool True if user can view users
     */
    public static function canViewUsers(string $userUuid, array $context = []): bool
    {
        return self::checkPermission(
            $userUuid,
            PermissionStandards::PERMISSION_USERS_VIEW,
            PermissionStandards::CATEGORY_USERS,
            array_merge($context, ['check_type' => 'user_view'])
        );
    }

    /**
     * Check if user can create users
     *
     * @param string $userUuid User UUID
     * @param array $context Additional context
     * @return bool True if user can create users
     */
    public static function canCreateUsers(string $userUuid, array $context = []): bool
    {
        return self::checkPermission(
            $userUuid,
            PermissionStandards::PERMISSION_USERS_CREATE,
            PermissionStandards::CATEGORY_USERS,
            array_merge($context, ['check_type' => 'user_create'])
        );
    }

    /**
     * Check if user can edit users
     *
     * @param string $userUuid User UUID
     * @param array $context Additional context
     * @return bool True if user can edit users
     */
    public static function canEditUsers(string $userUuid, array $context = []): bool
    {
        return self::checkPermission(
            $userUuid,
            PermissionStandards::PERMISSION_USERS_EDIT,
            PermissionStandards::CATEGORY_USERS,
            array_merge($context, ['check_type' => 'user_edit'])
        );
    }

    /**
     * Check if user can delete users
     *
     * @param string $userUuid User UUID
     * @param array $context Additional context
     * @return bool True if user can delete users
     */
    public static function canDeleteUsers(string $userUuid, array $context = []): bool
    {
        return self::checkPermission(
            $userUuid,
            PermissionStandards::PERMISSION_USERS_DELETE,
            PermissionStandards::CATEGORY_USERS,
            array_merge($context, ['check_type' => 'user_delete'])
        );
    }

    /**
     * Check if user can manage users (all user operations)
     *
     * @param string $userUuid User UUID
     * @param array $context Additional context
     * @return bool True if user has all user management permissions
     */
    public static function canManageUsers(string $userUuid, array $context = []): bool
    {
        $baseContext = array_merge($context, ['check_type' => 'user_manage']);

        return self::canViewUsers($userUuid, $baseContext) &&
               self::canCreateUsers($userUuid, $baseContext) &&
               self::canEditUsers($userUuid, $baseContext) &&
               self::canDeleteUsers($userUuid, $baseContext);
    }

    /**
     * Check if user has a specific permission
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has permission
     */
    public static function hasPermission(
        string $userUuid,
        string $permission,
        string $resource = 'system',
        array $context = []
    ): bool {
        return self::checkPermission($userUuid, $permission, $resource, $context);
    }

    /**
     * Check if user has any of the specified permissions
     *
     * @param string $userUuid User UUID
     * @param array $permissions Array of permissions to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has at least one permission
     */
    public static function hasAnyPermission(
        string $userUuid,
        array $permissions,
        string $resource = 'system',
        array $context = []
    ): bool {
        foreach ($permissions as $permission) {
            if (self::checkPermission($userUuid, $permission, $resource, $context)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the specified permissions
     *
     * @param string $userUuid User UUID
     * @param array $permissions Array of permissions to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has all permissions
     */
    public static function hasAllPermissions(
        string $userUuid,
        array $permissions,
        string $resource = 'system',
        array $context = []
    ): bool {
        foreach ($permissions as $permission) {
            if (!self::checkPermission($userUuid, $permission, $resource, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get permission manager instance
     *
     * @return PermissionManager
     */
    public static function getManager(): PermissionManager
    {
        return PermissionManager::getInstance();
    }

    /**
     * Check if permission system is available
     *
     * @return bool True if permission system is available
     */
    public static function isAvailable(): bool
    {
        try {
            return self::getManager()->hasActiveProvider();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Internal permission check with error handling
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has permission, false on error or no permission
     */
    private static function checkPermission(
        string $userUuid,
        string $permission,
        string $resource,
        array $context
    ): bool {
        try {
            $manager = self::getManager();

            // If permission system is not available, deny access
            if (!$manager->hasActiveProvider()) {
                return false;
            }

            return $manager->can($userUuid, $permission, $resource, $context);
        } catch (\Exception $e) {
            // Log error but don't expose it
            error_log("Permission check failed for user {$userUuid}, permission {$permission}: " . $e->getMessage());
            return false;
        }
    }
}