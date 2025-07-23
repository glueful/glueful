<?php

namespace Glueful\Extensions\RBAC;

use Glueful\Extensions\BaseExtension;
use Glueful\Extensions\Traits\ExtensionDocumentationTrait;
use Glueful\Extensions\RBAC\RBACPermissionProvider;

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
class RBAC extends BaseExtension
{
    use ExtensionDocumentationTrait;

    /** @var RBACPermissionProvider|null Permission provider instance */
    private static ?RBACPermissionProvider $permissionProvider = null;

    /** @var array Extension configuration */
    private static array $config = [];

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

            error_log("RBAC Extension initialized successfully");
        } catch (\Exception $e) {
            error_log("RBAC Extension initialization failed: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];

        // Check if permission provider is initialized
        if (!self::$permissionProvider) {
            $healthy = false;
            $issues[] = 'Permission provider not initialized';
        }

        // Check configuration
        if (empty(self::$config)) {
            $healthy = false;
            $issues[] = 'Configuration not loaded';
        }

        // Check database tables exist
        try {
            // TODO: Add database table existence checks
        } catch (\Exception $e) {
            $healthy = false;
            $issues[] = 'Database check failed: ' . $e->getMessage();
        }

        return [
            'healthy' => $healthy,
            'issues' => $issues,
            'metrics' => [
                'memory_usage' => memory_get_usage(true),
                'execution_time' => 0,
                'database_queries' => 0,
                'cache_usage' => 0
            ]
        ];
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
}
