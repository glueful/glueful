<?php

declare(strict_types=1);

namespace Glueful\Permissions;

/**
 * Permission Types Enumeration
 *
 * Defines the available permission types for resource access control.
 * Used for role-based access control (RBAC) throughout the application.
 */
enum Permission: string
{
    /** View/Read permission - Allows reading/viewing resource data */
    case VIEW = 'A';
    
    /** Save/Create permission - Allows creating new resources */
    case SAVE = 'B';
    
    /** Delete permission - Allows deleting existing resources */
    case DELETE = 'C';
    
    /** Edit/Update permission - Allows modifying existing resources */
    case EDIT = 'D';
    
    /**
     * Get all available permissions
     *
     * Returns array of all permission cases in defined order.
     * Used for permission assignment and validation.
     *
     * @return array<Permission> Array of permission cases
     */
    public static function getAll(): array
    {
        return [
            self::VIEW,
            self::SAVE,
            self::EDIT,
            self::DELETE
        ];
    }
}
