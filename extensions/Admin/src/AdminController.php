<?php

declare(strict_types=1);

namespace Glueful\Extensions\Admin;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Controllers\ConfigController;
use Glueful\Extensions\ExtensionManager;

class AdminController
{
    private ?ConfigController $configController = null;
    private $authManager = null;
    private bool $authInitialized = false;
    private $container;

    public function __construct()
    {
        // Get the DI container
        $this->container = app();
    }

    /**
     * Lazy load ConfigController
     */
    private function getConfigController(): ConfigController
    {
        if ($this->configController === null) {
            $this->configController = $this->container->get(ConfigController::class);
        }
        return $this->configController;
    }


    /**
     * Update configuration
     */
    public function updateConfig(Request $request): mixed
    {
        try {
            $filename = $request->attributes->get('filename');
            $data = json_decode($request->getContent(), true);

            if (!$filename) {
                return Response::validation(['filename' => ['Filename is required']], 'Validation failed');
            }

            if (!isset($data['content']) || !is_array($data['content'])) {
                return Response::validation(
                    ['content' => ['Configuration content is required and must be an array']],
                    'Validation failed'
                );
            }

            // Use ConfigController to update the config
            // Note: updateConfig expects the config data directly, not wrapped in 'content'
            $success = $this->getConfigController()->updateConfig($filename, $data['content']);

            if (!$success) {
                return Response::serverError('Failed to update configuration');
            }

            // Clear configuration cache
            if (class_exists('\Glueful\Helpers\ConfigManager')) {
                \Glueful\Helpers\ConfigManager::clearCache();
            }

            return Response::success([
                'success' => true
            ], 'Configuration updated successfully');
        } catch (\Exception $e) {
            return Response::serverError('Failed to update configuration: ' . $e->getMessage());
        }
    }

    /**
     * Create new configuration
     */
    public function createConfig(Request $request): mixed
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['name']) || !isset($data['content'])) {
                return Response::validation(
                    ['name' => ['Name is required'], 'content' => ['Content is required']],
                    'Validation failed'
                );
            }

            if (!is_array($data['content'])) {
                return Response::validation(
                    ['content' => ['Configuration content must be an array']],
                    'Validation failed'
                );
            }

            $success = $this->getConfigController()->createConfig($data['name'], $data['content']);

            if (!$success) {
                return Response::serverError('Failed to create configuration');
            }

            return Response::success([
                'success' => true
            ], 'Configuration created successfully');
        } catch (\Exception $e) {
            return Response::serverError('Failed to create configuration: ' . $e->getMessage());
        }
    }


    /**
     * Get comprehensive dashboard data in a single request
     */
    public function getDashboardData(Request $request): mixed
    {
        try {
            $dashboard = [
                'timestamp' => date('c')
            ];

            // Get all controllers from DI container
            $dbController = $this->container->get(\Glueful\Controllers\DatabaseController::class);
            $migrationsController = $this->container->get(\Glueful\Controllers\MigrationsController::class);
            $jobsController = $this->container->get(\Glueful\Controllers\JobsController::class);
            $metricsController = $this->container->get(\Glueful\Controllers\MetricsController::class);

            // Fetch all data (using existing controller methods)

            // Database stats - Use the new public method that doesn't send response
            try {
                $dbData = $dbController->getDatabaseStatsData();
                $dashboard['database'] = [
                    'total_tables' => $dbData['total_tables'],
                    'tables' => $dbData['tables'],
                    'error' => null
                ];
            } catch (\Exception $e) {
                $dashboard['database'] = ['error' => $e->getMessage()];
            }

            // System health - Use the new public method that doesn't send response
            try {
                $healthData = $metricsController->getSystemHealthData();
                $dashboard['system_health'] = $healthData;
            } catch (\Exception $e) {
                $dashboard['system_health'] = ['error' => $e->getMessage()];
            }

            // Migrations - Use the new public method that doesn't send response
            try {
                $migrationsData = $migrationsController->getPendingMigrationsData();
                $dashboard['migrations'] = [
                    'pending' => $migrationsData['migrations'],
                    'total_pending' => $migrationsData['pending_count']
                ];
            } catch (\Exception $e) {
                $dashboard['migrations'] = ['error' => $e->getMessage()];
            }

            // Jobs - Use the new public method that doesn't send response
            try {
                $allJobs = $jobsController->getScheduledJobsData();
                $now = new \DateTime();

                $dashboard['jobs'] = [
                    'upcoming' => array_filter($allJobs, function ($job) use ($now) {
                        return isset($job['next_run']) && new \DateTime($job['next_run']) > $now;
                    }),
                    'recently_run' => array_filter($allJobs, function ($job) use ($now) {
                        if (!isset($job['last_run'])) {
                            return false;
                        }
                        $lastRun = new \DateTime($job['last_run']);
                        $dayAgo = (clone $now)->modify('-24 hours');
                        return $lastRun > $dayAgo;
                    }),
                    'failed' => array_filter($allJobs, function ($job) {
                        return isset($job['status']) && $job['status'] === 'failed';
                    })
                ];
            } catch (\Exception $e) {
                $dashboard['jobs'] = ['error' => $e->getMessage()];
            }

            // API Metrics - Use the new public method that doesn't send response
            try {
                $apiData = $metricsController->getApiMetricsData();
                $dashboard['api_metrics'] = $apiData;
            } catch (\Exception $e) {
                $dashboard['api_metrics'] = ['error' => $e->getMessage()];
            }

            // Extensions
            try {
                error_log("Fetching enabled extensions data for dashboard");
                // Get extension configuration data using ExtensionManager
                $extensionManager = $this->container->get(ExtensionManager::class);
                $globalConfig = $extensionManager->getGlobalConfig();

                // Get core and optional extension lists
                $coreExtensions = $extensionManager->getCoreExtensions();
                $optionalExtensions = $extensionManager->getOptionalExtensions();
                $enabledExtensions = $extensionManager->listEnabled();

                // Process extension data
                $extensionsList = [];
                $enabledCount = 0;
                $disabledCount = 0;

                if (isset($globalConfig['extensions']) && is_array($globalConfig['extensions'])) {
                    foreach ($globalConfig['extensions'] as $name => $ext) {
                        $isEnabled = in_array($name, $enabledExtensions);
                        $isCoreExtension = in_array($name, $coreExtensions);

                        if ($isEnabled) {
                            $enabledCount++;
                        } else {
                            $disabledCount++;
                        }

                        $extensionsList[] = [
                            'name' => $name,
                            'enabled' => $isEnabled,
                            'tier' => $isCoreExtension ? 'core' : 'optional',
                            'version' => $ext['version'] ?? 'unknown',
                            'description' => $ext['description'] ?? null
                        ];
                    }
                }
                $dashboard['extensions'] = [
                    'total' => count($extensionsList),
                    'enabled' => $enabledCount,
                    'disabled' => $disabledCount,
                    'core' => count($coreExtensions),
                    'optional' => count($optionalExtensions),
                    'list' => $extensionsList
                ];
            } catch (\Exception $e) {
                $dashboard['extensions'] = ['error' => $e->getMessage()];
            }

            // RBAC Permissions & Roles (if available)
            try {
                // Check if RBAC extension is enabled
                $extensionManager = $this->container->get(ExtensionManager::class);
                $rbacEnabled = $extensionManager->isExtensionEnabled('RBAC');
                if ($rbacEnabled) {
                    // Get RBAC repositories from the container
                    $permissionRepository = $this->container->get('rbac.repository.permission');
                    $roleRepository = $this->container->get('rbac.repository.role');

                    // Get permissions data using repository methods
                    $allPermissions = $permissionRepository->findAll();
                    $permissionTotal = count($allPermissions);

                    // Get permissions by category
                    $byCategory = [];
                    $byResourceType = [];
                    $systemPermissions = 0;

                    foreach ($allPermissions as $permission) {
                        // Handle both array and object formats
                        $category = is_array($permission)
                            ? ($permission['category'] ?? 'uncategorized')
                            : ($permission->getCategory() ?? 'uncategorized');

                        if (!isset($byCategory[$category])) {
                            $byCategory[$category] = 0;
                        }
                        $byCategory[$category]++;

                        // Count by resource type
                        $resourceType = is_array($permission)
                            ? ($permission['resource_type'] ?? 'general')
                            : ($permission->getResourceType() ?? 'general');

                        if (!isset($byResourceType[$resourceType])) {
                            $byResourceType[$resourceType] = 0;
                        }
                        $byResourceType[$resourceType]++;

                        // Count system permissions
                        $isSystem = is_array($permission)
                            ? ($permission['is_system'] ?? false)
                            : $permission->isSystem();

                        if ($isSystem) {
                            $systemPermissions++;
                        }
                    }

                    // Sort by count descending
                    arsort($byCategory);
                    arsort($byResourceType);

                    // Get recent permissions (limited list)
                    $recentPermissions = array_slice($allPermissions, 0, 5);
                    $recentPermissionsList = [];
                    foreach ($recentPermissions as $permission) {
                        if (is_array($permission)) {
                            $recentPermissionsList[] = [
                                'uuid' => $permission['uuid'] ?? null,
                                'name' => $permission['name'] ?? null,
                                'slug' => $permission['slug'] ?? null,
                                'category' => $permission['category'] ?? null,
                                'resource_type' => $permission['resource_type'] ?? null,
                                'is_system' => $permission['is_system'] ?? false
                            ];
                        } else {
                            $recentPermissionsList[] = [
                                'uuid' => $permission->getUuid(),
                                'name' => $permission->getName(),
                                'slug' => $permission->getSlug(),
                                'category' => $permission->getCategory(),
                                'resource_type' => $permission->getResourceType(),
                                'is_system' => $permission->isSystem()
                            ];
                        }
                    }

                    $dashboard['permissions'] = [
                        'total' => $permissionTotal,
                        'list' => $recentPermissionsList,
                        'stats' => [
                            'by_category' => $byCategory,
                            'by_resource_type' => $byResourceType,
                            'system_permissions' => $systemPermissions
                        ]
                    ];

                    // Get roles data using repository methods
                    $allRoles = $roleRepository->findAll();
                    $roleTotal = count($allRoles);

                    $activeRoles = 0;
                    $systemRoles = 0;
                    $byLevel = [];

                    foreach ($allRoles as $role) {
                        // Handle both array and object formats
                        // Count active roles
                        $isActive = is_array($role)
                            ? ($role['is_active'] ?? false)
                            : $role->isActive();

                        if ($isActive) {
                            $activeRoles++;
                        }

                        // Count system roles
                        $isSystem = is_array($role)
                            ? ($role['is_system'] ?? false)
                            : $role->isSystem();

                        if ($isSystem) {
                            $systemRoles++;
                        }

                        // Count by level
                        $level = is_array($role)
                            ? ($role['level'] ?? 0)
                            : $role->getLevel();

                        if (!isset($byLevel[$level])) {
                            $byLevel[$level] = 0;
                        }
                        $byLevel[$level]++;
                    }

                    // Sort levels
                    ksort($byLevel);

                    // Get recent roles
                    $recentRoles = array_slice($allRoles, 0, 5);
                    $recentRolesList = [];
                    foreach ($recentRoles as $role) {
                        if (is_array($role)) {
                            $recentRolesList[] = [
                                'uuid' => $role['uuid'] ?? null,
                                'name' => $role['name'] ?? null,
                                'slug' => $role['slug'] ?? null,
                                'description' => $role['description'] ?? null,
                                'level' => $role['level'] ?? 0,
                                'is_active' => $role['is_active'] ?? false,
                                'is_system' => $role['is_system'] ?? false
                            ];
                        } else {
                            $recentRolesList[] = [
                                'uuid' => $role->getUuid(),
                                'name' => $role->getName(),
                                'slug' => $role->getSlug(),
                                'description' => $role->getDescription(),
                                'level' => $role->getLevel(),
                                'is_active' => $role->isActive(),
                                'is_system' => $role->isSystem()
                            ];
                        }
                    }

                    $dashboard['roles'] = [
                        'total' => $roleTotal,
                        'active' => $activeRoles,
                        'system' => $systemRoles,
                        'list' => $recentRolesList,
                        'stats' => [
                            'by_level' => $byLevel
                        ]
                    ];
                } else {
                    // RBAC not enabled, return empty data
                    $dashboard['permissions'] = [
                        'total' => 0,
                        'list' => [],
                        'stats' => [
                            'by_category' => [],
                            'by_resource_type' => [],
                            'system_permissions' => 0
                        ]
                    ];

                    $dashboard['roles'] = [
                        'total' => 0,
                        'active' => 0,
                        'system' => 0,
                        'list' => [],
                        'stats' => [
                            'by_level' => []
                        ]
                    ];
                }
            } catch (\Exception $e) {
                $dashboard['permissions'] = ['error' => $e->getMessage()];
                $dashboard['roles'] = ['error' => $e->getMessage()];
            }

            return Response::success($dashboard, 'Dashboard data retrieved successfully');
        } catch (\Exception $e) {
            return Response::serverError('Failed to retrieve dashboard data: ' . $e->getMessage());
        }
    }
}
