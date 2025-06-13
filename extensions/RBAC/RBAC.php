<?php

namespace Glueful\Extensions\RBAC;

use Glueful\Extensions;
use Glueful\IExtensions;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\Extensions\RBAC\RBACPermissionProvider;
use Glueful\Extensions\RBAC\Services\RBACServiceProvider;
use Glueful\Permissions\PermissionManager;

/**
 * RBAC Extension
 *
 * Modern Role-Based Access Control extension for Glueful
 *
 * Features:
 * - Hierarchical role system with inheritance
 * - Direct user permissions (overrides)
 * - Resource-level permission filtering
 * - Temporal permissions with expiry
 * - Comprehensive audit trail
 * - Multi-layer caching
 * - Scoped permissions for multi-tenancy
 */
class RBAC extends Extensions implements IExtensions
{
    /** @var RBACPermissionProvider|null Permission provider instance */
    private static ?RBACPermissionProvider $permissionProvider = null;

    /** @var array Extension configuration */
    private static array $config = [];

    /**
     * Process extension request
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        // This method handles direct API calls to the extension
        // For RBAC, this might be used for permission checking endpoints

        $action = $queryParams['action'] ?? 'info';

        switch ($action) {
            case 'check':
                return self::handlePermissionCheck($queryParams, $bodyParams);
            case 'permissions':
                return self::handleGetUserPermissions($queryParams);
            case 'health':
                return self::checkHealth();
            case 'info':
            default:
                return self::getMetadata();
        }
    }

    /**
     * Initialize the RBAC extension
     *
     * Note: Permission provider registration is now handled by RBACServiceProvider
     * This method only handles extension-specific initialization
     */
    public static function initialize(): void
    {
        try {
            // Load configuration
            self::$config = self::loadConfiguration();

            // Register middleware if configured
            static::registerMiddleware();

            // Run database migrations if needed
            static::runMigrations();

            error_log("RBAC Extension initialized successfully");
        } catch (\Exception $e) {
            error_log("RBAC Extension initialization failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the extension's service provider
     */
    public static function getServiceProvider(): ServiceProviderInterface
    {
        return new RBACServiceProvider();
    }

    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'RBAC',
            'display_name' => 'Role-Based Access Control',
            'description' => 'Modern, hierarchical role-based access control system',
            'version' => '0.27.0',
            'author' => 'Glueful Team',
            'license' => 'MIT',
            'type' => 'permission',
            'priority' => 100,
            'capabilities' => [
                'hierarchical_roles',
                'direct_permissions',
                'temporal_permissions',
                'resource_filtering',
                'permission_inheritance',
                'scoped_permissions',
                'audit_trail',
                'caching'
            ],
            'api_endpoints' => [
                '/rbac/check' => 'Check user permissions',
                '/rbac/permissions' => 'Get user permissions',
                '/rbac/roles' => 'Manage roles',
                '/rbac/assign' => 'Assign permissions/roles'
            ],
            'database_tables' => [
                'roles',
                'permissions',
                'user_roles',
                'user_permissions',
                'role_permissions',
                'permission_audit'
            ],
            'configuration' => [
                'cache_enabled' => self::$config['cache_enabled'] ?? true,
                'cache_ttl' => self::$config['cache_ttl'] ?? 3600,
                'enable_hierarchy' => self::$config['enable_hierarchy'] ?? true,
                'enable_inheritance' => self::$config['enable_inheritance'] ?? true,
                'audit_enabled' => self::$config['audit_enabled'] ?? true
            ]
        ];
    }

    /**
     * Get extension dependencies
     */
    public static function getDependencies(): array
    {
        return [
            'core_permissions' // Requires the core permission system
        ];
    }

    /**
     * Validate extension security
     */
    public static function validateSecurity(): array
    {
        $security = [
            'status' => 'secure',
            'issues' => [],
            'recommendations' => []
        ];

        try {
            // Check for system roles protection
            if (!self::$config['protect_system_roles'] ?? true) {
                $security['issues'][] = 'System roles protection is disabled';
                $security['status'] = 'warning';
            }

            // Check for audit logging
            if (!self::$config['audit_enabled'] ?? true) {
                $security['recommendations'][] = 'Enable audit logging for security tracking';
            }

            // Check for permission expiry support
            if (!self::$config['support_expiry'] ?? true) {
                $security['recommendations'][] = 'Enable temporal permissions for enhanced security';
            }

            // Validate database permissions
            $dbCheck = self::validateDatabaseSecurity();
            if (!$dbCheck['secure']) {
                $security['issues'] = array_merge($security['issues'], $dbCheck['issues']);
                $security['status'] = 'error';
            }
        } catch (\Exception $e) {
            $security['status'] = 'error';
            $security['issues'][] = 'Security validation failed: ' . $e->getMessage();
        }

        return $security;
    }

    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        if (!self::$permissionProvider) {
            return [
                'status' => 'error',
                'message' => 'Permission provider not initialized',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }

        return self::$permissionProvider->healthCheck();
    }

    /**
     * Get permission provider instance
     */
    public static function getPermissionProvider(): ?RBACPermissionProvider
    {
        return self::$permissionProvider;
    }

    // Private helper methods

    private static function loadConfiguration(): array
    {
        $defaultConfig = [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'cache_prefix' => 'rbac:',
            'enable_hierarchy' => true,
            'enable_inheritance' => true,
            'max_hierarchy_depth' => 10,
            'protect_system_roles' => true,
            'audit_enabled' => true,
            'support_expiry' => true
        ];

        // Try to load from config file
        $configFile = __DIR__ . '/../config/rbac.php';
        if (file_exists($configFile)) {
            $fileConfig = require $configFile;
            return array_merge($defaultConfig, $fileConfig);
        }

        return $defaultConfig;
    }

    public static function registerMiddleware(): void
    {
        // TODO: Register RBAC middleware with the framework
        // This would typically register permission checking middleware
    }

    public static function runMigrations(): array
    {
        // Use parent implementation to run migrations
        return parent::runMigrations();
    }

    private static function handlePermissionCheck(array $queryParams, array $bodyParams): array
    {
        if (!self::$permissionProvider) {
            return [
                'error' => 'Permission provider not initialized',
                'allowed' => false
            ];
        }

        $userUuid = $queryParams['user'] ?? $bodyParams['user'] ?? '';
        $permission = $queryParams['permission'] ?? $bodyParams['permission'] ?? '';
        $resource = $queryParams['resource'] ?? $bodyParams['resource'] ?? '*';
        $context = $bodyParams['context'] ?? [];

        if (empty($userUuid) || empty($permission)) {
            return [
                'error' => 'Missing required parameters: user, permission',
                'allowed' => false
            ];
        }

        try {
            $allowed = self::$permissionProvider->can($userUuid, $permission, $resource, $context);
            return [
                'allowed' => $allowed,
                'user' => $userUuid,
                'permission' => $permission,
                'resource' => $resource,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Permission check failed: ' . $e->getMessage(),
                'allowed' => false
            ];
        }
    }

    private static function handleGetUserPermissions(array $queryParams): array
    {
        if (!self::$permissionProvider) {
            return [
                'error' => 'Permission provider not initialized',
                'permissions' => []
            ];
        }

        $userUuid = $queryParams['user'] ?? '';

        if (empty($userUuid)) {
            return [
                'error' => 'Missing required parameter: user',
                'permissions' => []
            ];
        }

        try {
            $permissions = self::$permissionProvider->getUserPermissions($userUuid);
            return [
                'user' => $userUuid,
                'permissions' => $permissions,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to get user permissions: ' . $e->getMessage(),
                'permissions' => []
            ];
        }
    }

    private static function validateDatabaseSecurity(): array
    {
        $result = [
            'secure' => true,
            'issues' => []
        ];

        try {
            // TODO: Implement database security validation
            // - Check table permissions
            // - Validate foreign key constraints
            // - Check for SQL injection vulnerabilities
            // - Validate data encryption if required
        } catch (\Exception $e) {
            $result['secure'] = false;
            $result['issues'][] = 'Database validation failed: ' . $e->getMessage();
        }

        return $result;
    }
}
