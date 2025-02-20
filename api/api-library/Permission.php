<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

/**
 * Permission Constants
 * 
 * Defines standard permission types for API resources.
 * Used for role-based access control (RBAC) throughout the application.
 */
class Permission 
{
    /**
     * View/Read permission
     * Allows reading/viewing resource data
     */
    public const VIEW = 'A';

    /**
     * Save/Create permission
     * Allows creating new resources
     */
    public const SAVE = 'B';

    /**
     * Delete permission
     * Allows deleting existing resources
     */
    public const DELETE = 'C';

    /**
     * Edit/Update permission
     * Allows modifying existing resources
     */
    public const EDIT = 'D';
    
    /**
     * Get all available permissions
     * 
     * Returns array of all permission constants in defined order.
     * Used for permission assignment and validation.
     * 
     * @return array<string> Array of permission constants
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


