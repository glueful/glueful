<?php

namespace Glueful\Extensions\RBAC\Services;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
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
class RBACServiceProvider implements ServiceProviderInterface
{
    /**
     * Register services with the container
     */
    public function register(ContainerInterface $container): void
    {
        // Register repositories
        $container->bind('rbac.repository.role', function () {
            return new RoleRepository();
        });

        $container->bind('rbac.repository.permission', function () {
            return new PermissionRepository();
        });

        $container->bind('rbac.repository.user_role', function () {
            return new UserRoleRepository();
        });

        $container->bind('rbac.repository.user_permission', function () {
            return new UserPermissionRepository();
        });

        $container->bind('rbac.repository.role_permission', function () {
            return new RolePermissionRepository();
        });

        // Register permission provider
        $container->bind('rbac.permission_provider', function () {
            return new RBACPermissionProvider();
        });

        // Register RBAC services
        $container->bind('rbac.role_service', function ($container) {
            return new RoleService(
                $container->get('rbac.repository.role'),
                $container->get('rbac.repository.user_role')
            );
        });

        $container->bind('rbac.permission_service', function ($container) {
            return new PermissionAssignmentService(
                $container->get('rbac.repository.permission'),
                $container->get('rbac.repository.user_permission'),
                $container->get('rbac.repository.role'),
                $container->get('rbac.repository.user_role'),
                $container->get('rbac.repository.role_permission')
            );
        });

        // Register utility services
        $container->bind('rbac.audit_service', function () {
            return new AuditService();
        });

        // Register controllers
        $container->bind('Glueful\\Extensions\\RBAC\\Controllers\\PermissionController', function ($container) {
            return new \Glueful\Extensions\RBAC\Controllers\PermissionController(
                $container->get('rbac.permission_service'),
                $container->get('rbac.repository.permission')
            );
        });

        $container->bind('Glueful\\Extensions\\RBAC\\Controllers\\RoleController', function ($container) {
            return new \Glueful\Extensions\RBAC\Controllers\RoleController(
                $container->get('rbac.role_service'),
                $container->get('rbac.repository.role')
            );
        });

        $container->bind('Glueful\\Extensions\\RBAC\\Controllers\\UserRoleController', function ($container) {
            return new \Glueful\Extensions\RBAC\Controllers\UserRoleController(
                $container->get('rbac.role_service'),
                $container->get('rbac.permission_service'),
                $container->get('rbac.repository.user_role')
            );
        });
    }

    /**
     * Boot services after registration
     */
    public function boot(ContainerInterface $container): void
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

                error_log("RBAC: Successfully registered and activated permission provider");
            } else {
                error_log("RBAC: Permission manager not found in container");
            }
        } catch (\Exception $e) {
            error_log("RBAC: Failed to initialize permission provider: " . $e->getMessage());
            error_log("RBAC: Stack trace: " . $e->getTraceAsString());
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
