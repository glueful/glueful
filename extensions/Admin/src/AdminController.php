<?php

declare(strict_types=1);

namespace Glueful\Extensions\Admin;

use Glueful\Http\Response;
use Glueful\Auth\{AuthBootstrap};
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Glueful\Controllers\ConfigController;

class AdminController
{
    private ?ConfigController $configController = null;
    private $authManager = null;
    private bool $authInitialized = false;

    public function __construct()
    {
        // Lightweight constructor - dependencies are loaded lazily when needed
    }

    /**
     * Lazy load ConfigController
     */
    private function getConfigController(): ConfigController
    {
        if ($this->configController === null) {
            $this->configController = new ConfigController();
        }
        return $this->configController;
    }

    /**
     * Lazy load AuthManager
     */
    private function getAuthManager()
    {
        if ($this->authManager === null || !$this->authInitialized) {
            AuthBootstrap::initialize();
            $this->authManager = AuthBootstrap::getManager();
            $this->authInitialized = true;
        }
        return $this->authManager;
    }


    /**
     * Get all configurations
     */
    public function getAllConfigs(SymfonyRequest $request): mixed
    {
        try {
            // Use ConfigController to get all configs
            $configs = $this->getConfigController()->getConfigs();

            // Transform the data to match API response format
            $configList = [];
            foreach ($configs as $config) {
                $configList[] = [
                    'name' => $config['name'],
                    'path' => $config['name'] . '.php'
                ];
            }

            return Response::ok($configList, 'Configuration files retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to get configurations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_FETCH_FAILED'
            )->send();
        }
    }

    /**
     * Get configuration by filename
     */
    public function getConfig($filename): mixed
    {
        try {
            if (!$filename) {
                return Response::error(
                    'Filename is required',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'MISSING_FILENAME'
                )->send();
            }

            // Use ConfigController to get the config
            $content = $this->getConfigController()->getConfigByFile($filename);

            if ($content === null) {
                return Response::notFound('Configuration file not found')->send();
            }

            return Response::ok([
                'name' => $filename,
                'content' => $content
            ], 'Configuration retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to get configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_GET_FAILED'
            )->send();
        }
    }


    /**
     * Update configuration
     */
    public function updateConfig(SymfonyRequest $request): mixed
    {
        try {
            $filename = $request->attributes->get('filename');
            $data = json_decode($request->getContent(), true);

            if (!$filename) {
                return Response::error(
                    'Filename is required',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'MISSING_FILENAME'
                )->send();
            }

            if (!isset($data['content']) || !is_array($data['content'])) {
                return Response::error(
                    'Configuration content is required and must be an array',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'INVALID_CONTENT'
                )->send();
            }

            // Use ConfigController to update the config
            // Note: updateConfig expects the config data directly, not wrapped in 'content'
            $success = $this->getConfigController()->updateConfig($filename, $data['content']);

            if (!$success) {
                return Response::error(
                    'Failed to update configuration',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_SERVER,
                    'CONFIG_UPDATE_FAILED'
                )->send();
            }

            // Clear configuration cache
            if (class_exists('\Glueful\Helpers\ConfigManager')) {
                \Glueful\Helpers\ConfigManager::clearCache();
            }

            return Response::ok([
                'success' => true
            ], 'Configuration updated successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to update configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_UPDATE_EXCEPTION'
            )->send();
        }
    }

    /**
     * Create new configuration
     */
    public function createConfig(SymfonyRequest $request): mixed
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['name']) || !isset($data['content'])) {
                return Response::error(
                    'Name and content are required',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'MISSING_REQUIRED_FIELDS'
                )->send();
            }

            if (!is_array($data['content'])) {
                return Response::error(
                    'Configuration content must be an array',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_VALIDATION,
                    'INVALID_CONTENT'
                )->send();
            }

            $success = $this->getConfigController()->createConfig($data['name'], $data['content']);

            if (!$success) {
                return Response::error(
                    'Failed to create configuration',
                    Response::HTTP_BAD_REQUEST,
                    Response::ERROR_SERVER,
                    'CONFIG_CREATE_FAILED'
                )->send();
            }

            return Response::ok([
                'success' => true
            ], 'Configuration created successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to create configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'CONFIG_CREATE_EXCEPTION'
            )->send();
        }
    }

    /**
     * Authenticate a request using multiple authentication methods
     *
     * @param SymfonyRequest $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticate(SymfonyRequest $request): ?array
    {
        // For admin routes, try admin provider first, then either jwt OR api_key (not both)
        $userData = $this->getAuthManager()->authenticateWithProvider('admin', $request);

        if (!$userData) {
            // If admin auth fails, try jwt
            $userData = $this->getAuthManager()->authenticateWithProvider('jwt', $request);

            // If jwt fails, try api_key as a last resort
            if (!$userData) {
                $userData = $this->getAuthManager()->authenticateWithProvider('api_key', $request);
            }
        }

        return $userData;
    }

    /**
     * Get comprehensive dashboard data in a single request
     */
    public function getDashboardData(SymfonyRequest $request): mixed
    {
        try {
            $dashboard = [
                'timestamp' => date('c')
            ];

            // Get all controllers we need - instantiate directly like other methods in this class
            $dbController = new \Glueful\Controllers\DatabaseController();
            $migrationsController = new \Glueful\Controllers\MigrationsController();
            $jobsController = new \Glueful\Controllers\JobsController();
            $metricsController = new \Glueful\Controllers\MetricsController();

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
                // Get extension configuration data using ExtensionsManager
                $extensionConfigFile = \Glueful\Helpers\ExtensionsManager::getConfigPath();
                $content = file_get_contents($extensionConfigFile);
                $config = json_decode($content, true);

                // Get core and optional extension lists
                $coreExtensions = \Glueful\Helpers\ExtensionsManager::getCoreExtensions();
                $optionalExtensions = \Glueful\Helpers\ExtensionsManager::getOptionalExtensions();
                $enabledExtensions = \Glueful\Helpers\ExtensionsManager::getEnabledExtensions();

                // Process extension data
                $extensionsList = [];
                $enabledCount = 0;
                $disabledCount = 0;

                if (isset($config['extensions']) && is_array($config['extensions'])) {
                    foreach ($config['extensions'] as $name => $ext) {
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
                $rbacEnabled = \Glueful\Helpers\ExtensionsManager::isExtensionEnabled('RBAC');
                if ($rbacEnabled) {
                    // Get the DI container
                    $container = app();

                    // Get RBAC repositories from the container
                    $permissionRepository = $container->get('rbac.repository.permission');
                    $roleRepository = $container->get('rbac.repository.role');

                    // Get permissions data using repository methods
                    $allPermissions = $permissionRepository->findAll(['exclude_deleted' => true]);
                    $permissionTotal = count($allPermissions);

                    // Get permissions by category
                    $byCategory = [];
                    $byResourceType = [];
                    $systemPermissions = 0;

                    foreach ($allPermissions as $permission) {
                        // Count by category
                        $category = $permission->getCategory() ?? 'uncategorized';
                        if (!isset($byCategory[$category])) {
                            $byCategory[$category] = 0;
                        }
                        $byCategory[$category]++;

                        // Count by resource type
                        $resourceType = $permission->getResourceType() ?? 'general';
                        if (!isset($byResourceType[$resourceType])) {
                            $byResourceType[$resourceType] = 0;
                        }
                        $byResourceType[$resourceType]++;

                        // Count system permissions
                        if ($permission->isSystem()) {
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
                        $recentPermissionsList[] = [
                            'uuid' => $permission->getUuid(),
                            'name' => $permission->getName(),
                            'slug' => $permission->getSlug(),
                            'category' => $permission->getCategory(),
                            'resource_type' => $permission->getResourceType(),
                            'is_system' => $permission->isSystem()
                        ];
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
                    $allRoles = $roleRepository->findAll(['exclude_deleted' => true]);
                    $roleTotal = count($allRoles);

                    $activeRoles = 0;
                    $systemRoles = 0;
                    $byLevel = [];

                    foreach ($allRoles as $role) {
                        // Count active roles
                        if ($role->isActive()) {
                            $activeRoles++;
                        }

                        // Count system roles
                        if ($role->isSystem()) {
                            $systemRoles++;
                        }

                        // Count by level
                        $level = $role->getLevel();
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

            return Response::ok($dashboard, 'Dashboard data retrieved successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve dashboard data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_SERVER,
                'DASHBOARD_FETCH_FAILED'
            )->send();
        }
    }
}
