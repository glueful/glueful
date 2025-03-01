<?php
declare(strict_types=1);

namespace Glueful\Permissions;
use Glueful\APIEngine;

/**
 * Permission Types Enumeration
 * 
 * Defines the available permission types for resource access control.
 */
enum Permission: string {
    /** View/Read permission */
    case VIEW = 'A';
    
    /** Create/Save permission */
    case SAVE = 'B';
    
    /** Delete permission */
    case DELETE = 'C';
    
    /** Edit/Update permission */
    case EDIT = 'D';
}

/**
 * Permissions Manager
 * 
 * Handles permission checking and validation for both API and UI resources.
 * Implements role-based access control (RBAC) with session validation.
 */

class Permissions 
{
    /**
     * Check if user has specific permission
     * 
     * Validates user's permission for a given model based on their session token.
     * Handles both UI and API permission checks with appropriate strictness levels.
     * 
     * @param string $model Resource model name (e.g., 'ui.dashboard' or 'api.users')
     * @param Permission $permission Permission type to check
     * @param string $token User's session token
     * @return bool True if user has permission
     */
    public static function hasPermission(string $model, Permission $permission, string $token): bool 
    {
        // Use null for function and action parameters that aren't needed for validation
        $sessionInfo = APIEngine::validateSession(null, null, ['token' => $token]);
        
        if (!$sessionInfo || isset($sessionInfo['ERR'])) {
            return false;
        }

        if (config('security.enabled_permissions') !== true) {
            return true;
        }

        // Check if role exists for the model
        if (!isset($sessionInfo['role'][$model])) {
            return false;
        }

        // Implement strict permissions when accessing the API via REST
        if (str_contains($model, 'ui.')) {
            return self::checkUiPermissions($model, $permission, $sessionInfo);
        }

        return self::checkApiPermissions($model, $permission, $sessionInfo);
    }

    /**
     * Check UI-specific permissions
     * 
     * Handles permission validation for UI components with special rules
     * for non-REST mode access.
     * 
     * @param string $model UI model identifier
     * @param Permission $permission Required permission
     * @param array $sessionInfo User's session data
     * @return bool True if permission granted
     */

    private static function checkUiPermissions(string $model, Permission $permission, array $sessionInfo): bool 
    {
        // When utilizing API class via PHP, limit permissions to UI models
        if (!defined('REST_MODE') && !str_contains($sessionInfo['role'][$model], $permission->value)) {
            return false;
        }

        return true;
    }

    /**
     * Check API-specific permissions
     * 
     * Handles permission validation for API endpoints with strict
     * checking in REST mode.
     * 
     * @param string $model API model identifier
     * @param Permission $permission Required permission
     * @param array $sessionInfo User's session data
     * @return bool True if permission granted
     */

    private static function checkApiPermissions(string $model, Permission $permission, array $sessionInfo): bool 
    {
        // Strict permissions for REST API access
        $restMode = config('app.rest_mode');
        if (defined($restMode) && 
            $restMode === true && 
            !str_contains($sessionInfo['role'][$model], $permission->value)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Get all available permissions
     * 
     * Returns array of all defined permission values.
     * Used for permission assignment and validation.
     * 
     * @return array<string> Array of permission values
     */
    public static function getAvailablePermissions(): array 
    {
        return array_map(
            fn(Permission $permission) => $permission->value,
            Permission::cases()
        );
    }
}
?>