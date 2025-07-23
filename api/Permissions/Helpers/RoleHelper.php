<?php

declare(strict_types=1);

namespace Glueful\Permissions\Helpers;

use Glueful\Interfaces\Permission\RoleStandards;

/**
 * Role Helper Class
 *
 * Provides utility methods for working with role levels and categories.
 * These methods help determine role privileges and categorization based
 * on the standard role levels defined in RoleStandards interface.
 *
 * @package Glueful\Permissions\Helpers
 */
class RoleHelper
{
    /**
     * Check if a role level indicates administrative privileges
     *
     * @param int $level The role level to check
     * @return bool True if the level indicates admin privileges
     */
    public static function isAdminLevel(int $level): bool
    {
        return $level >= RoleStandards::LEVEL_ADMINISTRATOR;
    }

    /**
     * Check if a role level indicates system privileges
     *
     * @param int $level The role level to check
     * @return bool True if the level indicates system privileges
     */
    public static function isSystemLevel(int $level): bool
    {
        return $level >= RoleStandards::LEVEL_RANGE_SYSTEM[0];
    }

    /**
     * Get the category name for a role level
     *
     * @param int $level The role level
     * @return string The category name
     */
    public static function getLevelCategory(int $level): string
    {
        if ($level >= RoleStandards::LEVEL_RANGE_SYSTEM[0]) {
            return 'system';
        } elseif ($level >= RoleStandards::LEVEL_RANGE_ADMIN[0]) {
            return 'admin';
        } elseif ($level >= RoleStandards::LEVEL_RANGE_STAFF[0]) {
            return 'staff';
        } else {
            return 'user';
        }
    }

    /**
     * Check if a role level indicates moderator privileges
     *
     * @param int $level The role level to check
     * @return bool True if the level indicates moderator privileges
     */
    public static function isModeratorLevel(int $level): bool
    {
        return $level >= RoleStandards::LEVEL_MODERATOR;
    }

    /**
     * Check if a role level indicates staff privileges
     *
     * @param int $level The role level to check
     * @return bool True if the level indicates staff privileges or higher
     */
    public static function isStaffLevel(int $level): bool
    {
        return $level >= RoleStandards::LEVEL_RANGE_STAFF[0];
    }

    /**
     * Compare two role levels
     *
     * @param int $level1 First role level
     * @param int $level2 Second role level
     * @return int Returns -1 if level1 < level2, 0 if equal, 1 if level1 > level2
     */
    public static function compareLevels(int $level1, int $level2): int
    {
        return $level1 <=> $level2;
    }

    /**
     * Check if a role level can manage another role level
     *
     * Typically, a role can manage roles with lower levels
     *
     * @param int $managerLevel The level of the managing role
     * @param int $targetLevel The level of the role being managed
     * @return bool True if the manager level can manage the target level
     */
    public static function canManageRole(int $managerLevel, int $targetLevel): bool
    {
        // System roles can manage any role
        if (self::isSystemLevel($managerLevel)) {
            return true;
        }

        // Otherwise, can only manage roles with lower levels
        return $managerLevel > $targetLevel;
    }

    /**
     * Get a human-readable name for a role level
     *
     * @param int $level The role level
     * @return string Human-readable role level name
     */
    public static function getLevelName(int $level): string
    {
        return match (true) {
            $level >= RoleStandards::LEVEL_SUPERUSER => 'Superuser',
            $level >= RoleStandards::LEVEL_ADMINISTRATOR => 'Administrator',
            $level >= RoleStandards::LEVEL_MANAGER => 'Manager',
            $level >= RoleStandards::LEVEL_MODERATOR => 'Moderator',
            $level >= RoleStandards::LEVEL_USER => 'User',
            default => 'Guest'
        };
    }

    /**
     * Validate if a role level is within valid ranges
     *
     * @param int $level The role level to validate
     * @return bool True if the level is valid
     */
    public static function isValidLevel(int $level): bool
    {
        return $level >= 0 && $level <= 100;
    }
}
