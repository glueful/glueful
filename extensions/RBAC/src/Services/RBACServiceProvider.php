<?php

namespace Glueful\Extensions\RBAC\Services;

use Glueful\DI\ServiceProviders\BaseExtensionServiceProvider;
use Glueful\DI\Container;
use Glueful\Extensions\RBAC\RBACPermissionProvider;
use Glueful\Extensions\RBAC\Repositories\RoleRepository;
use Glueful\Extensions\RBAC\Repositories\PermissionRepository;
use Glueful\Extensions\RBAC\Repositories\UserRoleRepository;
use Glueful\Extensions\RBAC\Repositories\UserPermissionRepository;
use Glueful\Extensions\RBAC\Repositories\RolePermissionRepository;
use Glueful\Extensions\RBAC\Services\RoleService;
use Glueful\Extensions\RBAC\Services\PermissionAssignmentService;
use Glueful\Extensions\RBAC\Services\AuditService;

/**
 * RBAC Service Provider
 *
 * Registers all RBAC services with the dependency injection container
 *
 * Services registered:
 * - RBAC repositories
 * - Permission provider
 * - RBAC services
 * - Middleware components
 */
class RBACServiceProvider extends BaseExtensionServiceProvider
{
    /**
     * Register extension services using abstraction methods
     */
    protected function registerExtensionServices(): void
    {
        // Register repositories
        $this->singleton('rbac.repository.role', RoleRepository::class);
        $this->singleton('rbac.repository.permission', PermissionRepository::class);
        $this->singleton('rbac.repository.user_role', UserRoleRepository::class);
        $this->singleton('rbac.repository.user_permission', UserPermissionRepository::class);
        $this->singleton('rbac.repository.role_permission', RolePermissionRepository::class);

        // Register permission provider
        $this->singleton('rbac.permission_provider', RBACPermissionProvider::class);

        // Register RBAC services
        $this->service('rbac.role_service', RoleService::class, [
            $this->ref('rbac.repository.role'),
            $this->ref('rbac.repository.user_role')
        ]);

        $this->service('rbac.permission_service', PermissionAssignmentService::class, [
            $this->ref('rbac.repository.permission'),
            $this->ref('rbac.repository.user_permission'),
            $this->ref('rbac.repository.role'),
            $this->ref('rbac.repository.user_role'),
            $this->ref('rbac.repository.role_permission')
        ]);

        // Register utility services
        $this->singleton('rbac.audit_service', AuditService::class);

        // Register controllers
        $this->service(
            'Glueful\\Extensions\\RBAC\\Controllers\\PermissionController',
            \Glueful\Extensions\RBAC\Controllers\PermissionController::class,
            [
                $this->ref('rbac.permission_service'),
                $this->ref('rbac.repository.permission')
            ]
        );

        $this->service(
            'Glueful\\Extensions\\RBAC\\Controllers\\RoleController',
            \Glueful\Extensions\RBAC\Controllers\RoleController::class,
            [
                $this->ref('rbac.role_service'),
                $this->ref('rbac.repository.role')
            ]
        );

        $this->service(
            'Glueful\\Extensions\\RBAC\\Controllers\\UserRoleController',
            \Glueful\Extensions\RBAC\Controllers\UserRoleController::class,
            [
                $this->ref('rbac.role_service'),
                $this->ref('rbac.permission_service'),
                $this->ref('rbac.repository.user_role')
            ]
        );
    }

    /**
     * Boot services after registration
     */
    public function boot(Container $container): void
    {
        try {
            // Get permission provider
            $permissionProvider = $container->get('rbac.permission_provider');

            // Load RBAC configuration
            $config = $this->loadConfiguration();

            // Initialize the provider with configuration
            $permissionProvider->initialize($config);

            // Get PermissionManager from container
            if ($container->has('permission.manager')) {
                $permissionManager = $container->get('permission.manager');

                // Register the provider
                $permissionManager->registerProviders(['rbac' => $permissionProvider]);

                // Set as active provider
                $permissionManager->setProvider($permissionProvider, $config);
            }
        } catch (\Exception $e) {
            error_log("RBAC: Failed to initialize permission provider: " . $e->getMessage());
        }
    }

    /**
     * Get service metadata
     */
    public function getMetadata(): array
    {
        return [
            'name' => 'RBAC Service Provider',
            'version' => '1.0.0',
            'services' => [
                'rbac.repository.role',
                'rbac.repository.permission',
                'rbac.repository.user_role',
                'rbac.repository.user_permission',
                'rbac.repository.role_permission',
                'rbac.permission_provider',
                'rbac.role_service',
                'rbac.permission_service',
                'rbac.audit_service'
            ],
            'dependencies' => [
                'core_permissions'
            ]
        ];
    }

    /**
     * Load RBAC configuration
     */
    private function loadConfiguration(): array
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
        $configFile = dirname(__DIR__, 2) . '/src/config.php';
        if (file_exists($configFile)) {
            $fileConfig = require $configFile;
            return array_merge($defaultConfig, $fileConfig);
        }

        return $defaultConfig;
    }
}
