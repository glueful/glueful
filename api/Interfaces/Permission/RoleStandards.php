<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Role Standards Interface
 *
 * Defines standard role levels and naming conventions that permission
 * providers can use for consistency. These are recommendations, not
 * requirements, as different applications may need different role structures.
 *
 * The level system allows for hierarchical role comparison where higher
 * levels generally indicate more privileges. Extensions can define their
 * own levels between these standard levels.
 *
 * @package Glueful\Interfaces\Permission
 */
interface RoleStandards
{
    /**
     * Standard Role Levels
     *
     * These levels provide a consistent hierarchy for role comparison.
     * Higher numbers indicate higher privileges.
     * Extensions can use intermediate values (e.g., 90, 70, 50) for custom roles.
     */
    public const LEVEL_SUPERUSER = 100;         // Full system access, typically system owner
    public const LEVEL_ADMINISTRATOR = 80;      // Site/application administration
    public const LEVEL_MANAGER = 60;            // Department/section management
    public const LEVEL_MODERATOR = 40;          // Content moderation privileges
    public const LEVEL_USER = 20;               // Standard authenticated user
    public const LEVEL_GUEST = 10;              // Minimal access, unauthenticated

    /**
     * Standard Role Slugs
     *
     * Common role identifiers that extensions can use for consistency.
     * These are suggestions - extensions can define their own.
     */
    public const ROLE_SUPERUSER = 'superuser';
    public const ROLE_ADMINISTRATOR = 'administrator';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_USER = 'user';
    public const ROLE_GUEST = 'guest';

    /**
     * Role Properties
     *
     * Standard properties that roles might have
     */
    public const PROPERTY_SYSTEM = 'is_system';     // Whether role is system-defined
    public const PROPERTY_ASSIGNABLE = 'is_assignable'; // Whether role can be assigned to users
    public const PROPERTY_DELETABLE = 'is_deletable';   // Whether role can be deleted
    public const PROPERTY_EDITABLE = 'is_editable';     // Whether role can be edited

    /**
     * Role Status Values
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Level ranges for categorization
     * Useful for determining role categories programmatically
     */
    public const LEVEL_RANGE_SYSTEM = [90, 100];    // System-level roles
    public const LEVEL_RANGE_ADMIN = [70, 89];      // Administrative roles
    public const LEVEL_RANGE_STAFF = [30, 69];      // Staff/moderator roles
    public const LEVEL_RANGE_USER = [1, 29];        // Regular user roles
}
