<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Permission Standards Interface
 *
 * Defines the core permission standards that all permission providers
 * must implement for framework compatibility. These are the minimum
 * required permissions for basic framework functionality.
 *
 * Permission providers can and should add additional permissions
 * beyond these core standards to support their specific features.
 *
 * Permission Naming Convention:
 * - Use dot notation: category.action
 * - Categories: system, users, content, roles, etc.
 * - Actions: view, create, edit, delete, manage, etc.
 *
 * @package Glueful\Interfaces\Permission
 */
interface PermissionStandards
{
    /**
     * Core System Permissions
     */
    public const PERMISSION_SYSTEM_ACCESS = 'system.access';    // Basic admin panel access
    public const PERMISSION_SYSTEM_CONFIG = 'system.config';    // System configuration changes

    /**
     * Core User Management Permissions
     * These are the minimum permissions needed for user CRUD operations
     */
    public const PERMISSION_USERS_VIEW = 'users.view';          // View user list and details
    public const PERMISSION_USERS_CREATE = 'users.create';      // Create new users
    public const PERMISSION_USERS_EDIT = 'users.edit';          // Edit user information
    public const PERMISSION_USERS_DELETE = 'users.delete';      // Delete users

    /**
     * Permission Categories
     * Standard categories that permissions are grouped under
     */
    public const CATEGORY_SYSTEM = 'system';                    // System-wide operations
    public const CATEGORY_USERS = 'users';                      // User management
    public const CATEGORY_CONTENT = 'content';                  // Content management
    public const CATEGORY_ROLES = 'roles';                      // Role management
    public const CATEGORY_API = 'api';                          // API access

    /**
     * Common Permission Actions
     * Standard actions that can be applied to resources
     */
    public const ACTION_VIEW = 'view';                          // Read/list resources
    public const ACTION_CREATE = 'create';                      // Create new resources
    public const ACTION_EDIT = 'edit';                          // Modify existing resources
    public const ACTION_DELETE = 'delete';                      // Remove resources
    public const ACTION_MANAGE = 'manage';                      // Full control over resources
    public const ACTION_ASSIGN = 'assign';                      // Assign resources to others
    public const ACTION_EXPORT = 'export';                      // Export resources
    public const ACTION_IMPORT = 'import';                      // Import resources

    /**
     * Core permissions array for easy iteration
     * These are the absolute minimum permissions that providers must implement
     */
    public const CORE_PERMISSIONS = [
        self::PERMISSION_SYSTEM_ACCESS,
        self::PERMISSION_USERS_VIEW,
        self::PERMISSION_USERS_CREATE,
        self::PERMISSION_USERS_EDIT,
        self::PERMISSION_USERS_DELETE
    ];

    /**
     * Permission naming pattern
     * Used for validation and consistency
     */
    public const PERMISSION_PATTERN = '/^[a-z]+(\.[a-z]+)+$/';
}
