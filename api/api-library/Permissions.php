<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

enum Permission: string {
    case VIEW = 'A';
    case SAVE = 'B';
    case DELETE = 'C';
    case EDIT = 'D';
}

class Permissions 
{
    public static function hasPermission(string $model, Permission $permission, string $token): bool 
    {
        // Use null for function and action parameters that aren't needed for validation
        $sessionInfo = APIEngine::validateSession(null, null, ['token' => $token]);
        
        if (!$sessionInfo || isset($sessionInfo['ERR'])) {
            return false;
        }

        if (!defined('ENABLE_PERMISSIONS') || config('security.permissions_enabled') !== true) {
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

    private static function checkUiPermissions(string $model, Permission $permission, array $sessionInfo): bool 
    {
        // When utilizing API class via PHP, limit permissions to UI models
        if (!defined('REST_MODE') && !str_contains($sessionInfo['role'][$model], $permission->value)) {
            return false;
        }

        return true;
    }

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
     * @return array<string]
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