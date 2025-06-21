<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\{Request, ExtensionsManager};
use Glueful\Logging\AuditEvent;
use Glueful\Permissions\PermissionContext;

/**
 * Controller for managing extensions functionality
 *
 * Handles all extension-related operations including listing, enabling, disabling,
 * and retrieving extension health and dependency information.
 *
 * This controller extends BaseController to leverage:
 * - Permission-based access control
 * - Audit logging
 * - Rate limiting
 * - Response caching
 */
class ExtensionsController extends BaseController
{
    /**
     * Get all extensions with pagination and status
     *
     * @return mixed HTTP response
     */
    public function getExtensions(): mixed
    {
        // Check permission
        $this->requirePermission('extensions.list');

        // Apply rate limiting for read operation
        $this->rateLimitMethod(null, [
            'attempts' => 100,
            'window' => 60,
            'adaptive' => true
        ]);

        // Log access to extensions list
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'extensions_list_accessed',
            AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        // Cache extensions list with permission-aware TTL
        $data = $this->cacheByPermission('extensions_list', function () {
            // Get extension configuration file path
            $extensionConfigFile = ExtensionsManager::getConfigPath();

            // Read extensions from config file
            $content = file_get_contents($extensionConfigFile);
            $config = json_decode($content, true);

            if (!is_array($config) || !isset($config['extensions'])) {
                return ['extensions' => [], 'grouped' => [], 'summary' => []];
            }

            // Get core and optional lists from config
            $coreExtensions = $config['core'] ?? [];
            $optionalExtensions = $config['optional'] ?? [];

            $extensionData = [];

            // Process extensions from config without loading their classes
            foreach ($config['extensions'] as $extensionName => $extensionInfo) {
                $tierType = in_array($extensionName, $coreExtensions) ? 'core' : 'optional';

                // Get all metadata directly from config
                $description = $extensionInfo['description'] ?? 'No description available';
                $version = $extensionInfo['version'] ?? 'unknown';
                $author = $extensionInfo['author'] ?? 'unknown';

                $extensionData[] = [
                    'name' => $extensionName,
                    'description' => $description,
                    'version' => $version,
                    'author' => $author,
                    'enabled' => $extensionInfo['enabled'] ?? false,
                    'tier' => $tierType,  // Added tier type information
                    'isCoreExtension' => in_array($extensionName, $coreExtensions),  // Explicit flag
                    'extensionId' => $extensionName, // Include the extension ID for actions
                ];
            }

            // Group extensions by tier for clearer organization
            $groupedExtensions = [
                'core' => array_values(array_filter($extensionData, fn($ext) => $ext['tier'] === 'core')),
                'optional' => array_values(array_filter($extensionData, fn($ext) => $ext['tier'] === 'optional')),
                'all' => $extensionData
            ];

            // Count enabled extensions
            $enabledCount = count(array_filter($extensionData, fn($ext) => $ext['enabled']));

            return [
                'extensions' => $extensionData, // Return flat array for backward compatibility
                'grouped' => $groupedExtensions, // Also provide grouped data if needed
                'summary' => [
                    'total' => count($extensionData),
                    'enabled' => $enabledCount,
                    'core' => count($coreExtensions),
                    'optional' => count($optionalExtensions)
                ]
            ];
        }, 300); // 5 minutes default TTL

        return Response::ok($data, 'Extensions retrieved successfully')->send();
    }

    /**
     * Enable an extension
     *
     * @return mixed HTTP response
     */
    public function enableExtension(): mixed
    {
        // Check base permission
        $this->requirePermission('extensions.enable');

        // Apply rate limiting for write operation with adaptive behavior
        $this->rateLimitMethod(null, [
            'attempts' => 30,
            'window' => 60,
            'adaptive' => true
        ]);

        $data = Request::getPostData();

        if (!isset($data['extension'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $extensionName = $data['extension'];

        if (!ExtensionsManager::extensionExists($extensionName)) {
            return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
        }

        // Check if it's a core or optional extension before enabling
        $isCoreExtension = ExtensionsManager::isCoreExtension($extensionName);
        $tierType = $isCoreExtension ? 'core' : 'optional';

        // Additional permission check for core extensions
        if ($isCoreExtension) {
            $this->requirePermission('extensions.core.manage');
        }

        $result = ExtensionsManager::enableExtension($extensionName);

        if (!$result['success']) {
            $statusCode = Response::HTTP_BAD_REQUEST;

            // If the issue is related to missing dependencies, return detailed information
            if (isset($result['details']) && isset($result['details']['missing_dependencies'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    Response::ERROR_VALIDATION,
                    'EXTENSION_DEPENDENCY_ERROR',
                    [
                        'missing_dependencies' => $result['details']['missing_dependencies'],
                        'required_dependencies' => $result['details']['required_dependencies'] ?? [],
                        'tier' => $tierType,
                        'isCoreExtension' => $isCoreExtension
                    ]
                )->send();
            }

            return Response::error($result['message'], $statusCode)->send();
        }

        // Log successful extension enable
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'extension_enabled',
            AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'extension' => $extensionName,
                'tier' => $tierType,
                'is_core' => $isCoreExtension,
                'ip_address' => $this->request->getClientIp()
            ]
        );

        // Invalidate extensions cache after modification
        $this->invalidateCache(['extensions', 'user:' . $this->getCurrentUserUuid()]);

        return Response::ok(
            [
                'extension' => $extensionName,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension
            ],
            $result['message']
        )->send();
    }

    /**
     * Disable an extension
     *
     * @return mixed HTTP response
     */
    public function disableExtension(): mixed
    {
        // Check base permission
        $this->requirePermission('extensions.disable');

        // Apply rate limiting for write operation
        $this->rateLimitMethod(null, [
            'attempts' => 30,
            'window' => 60,
            'adaptive' => true
        ]);

        $data = Request::getPostData();

        if (!isset($data['extension'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $extensionName = $data['extension'];

        if (!ExtensionsManager::extensionExists($extensionName)) {
            return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
        }

        // Check if it's a core extension
        $isCoreExtension = ExtensionsManager::isCoreExtension($extensionName);
        $tierType = $isCoreExtension ? 'core' : 'optional';

        // If it's a core extension, we may need to handle differently
        $force = isset($data['force']) && $data['force'] === true;

        // Additional permission check for core extensions with force
        if ($isCoreExtension && $force) {
            $this->requirePermission('extensions.core.manage');
        }

        $result = ExtensionsManager::disableExtension($extensionName, $force);

        if (!$result['success']) {
            $statusCode = Response::HTTP_BAD_REQUEST;

            // If the issue is related to dependent extensions, return detailed information
            if (isset($result['details']) && isset($result['details']['dependent_extensions'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    Response::ERROR_VALIDATION,
                    'EXTENSION_DEPENDENT_ERROR',
                    [
                        'dependent_extensions' => $result['details']['dependent_extensions'],
                        'tier' => $tierType,
                        'isCoreExtension' => $isCoreExtension
                    ]
                )->send();
            }

            // If it's a core extension without force
            if (isset($result['details']) && isset($result['details']['is_core'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    Response::ERROR_AUTHORIZATION,
                    'CORE_EXTENSION_DISABLE_ERROR',
                    [
                        'is_core' => true,
                        'can_force' => $result['details']['can_force'] ?? false,
                        'warning' => $result['details']['warning'] ?? 'This is a core extension',
                        'tier' => 'core'
                    ]
                )->send();
            }

            return Response::error($result['message'], $statusCode)->send();
        }

        // Log successful extension disable
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'extension_disabled',
            AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'extension' => $extensionName,
                'tier' => $tierType,
                'is_core' => $isCoreExtension,
                'was_forced' => $force,
                'ip_address' => $this->request->getClientIp()
            ]
        );

        // Invalidate extensions cache after modification
        $this->invalidateCache(['extensions', 'user:' . $this->getCurrentUserUuid()]);

        return Response::ok(
            [
                'extension' => $extensionName,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension,
                'wasForced' => $force
            ],
            $result['message']
        )->send();
    }

    /**
     * Get health status for a specific extension
     *
     * @param array|null $extension Extension data from route params
     * @return mixed HTTP response
     */
    public function getExtensionHealth(?array $extension): mixed
    {
        // Check permission
        $this->requirePermission('extensions.health.view');

        // Apply rate limiting for read operation
        $this->rateLimitMethod(null, [
            'attempts' => 60,
            'window' => 60,
            'adaptive' => true
        ]);

        if (!isset($extension['name'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $extensionName = $extension['name'];

        if (!ExtensionsManager::extensionExists($extensionName)) {
            return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
        }

        // Cache health check results with short TTL
        $healthData = $this->cacheResponse(
            'extension_health_' . $extensionName,
            function () use ($extensionName) {
                $health = ExtensionsManager::checkExtensionHealth($extensionName);

                // Get the tier information
                $isCoreExtension = ExtensionsManager::isCoreExtension($extensionName);
                $tierType = $isCoreExtension ? 'core' : 'optional';
                $isEnabled = ExtensionsManager::isExtensionEnabled($extensionName);

                return [
                    'extension' => $extensionName,
                    'health' => $health,
                    'tier' => $tierType,
                    'isCoreExtension' => $isCoreExtension,
                    'enabled' => $isEnabled,
                    'criticality' => $isCoreExtension ? 'critical' : 'standard',
                    'healthImpact' => $isCoreExtension && !$health['healthy'] ? 'system-critical' : 'extension-only'
                ];
            },
            60 // 1 minute TTL for health checks
        );

        return Response::ok($healthData, 'Extension health status retrieved successfully')->send();
    }

    /**
     * Get extension dependency graph
     *
     * Returns the dependency graph for all extensions showing
     * which extensions depend on each other.
     *
     * @return mixed HTTP response
     */
    public function getExtensionDependencies(): mixed
    {
        // Check permission
        $this->requirePermission('extensions.dependencies.view');

        // Apply rate limiting for read operation
        $this->rateLimitMethod(null, [
            'attempts' => 60,
            'window' => 60,
            'adaptive' => true
        ]);

        // Cache dependency graph with longer TTL
        $tieredGraph = $this->cacheResponse(
            'extension_dependencies',
            function () {
                $graph = ExtensionsManager::buildDependencyGraph();

                // Add summary information about the tiered nature of the dependencies
                $coreNodes = array_filter($graph['nodes'], fn($node) => $node['type'] === 'core');
                $optionalNodes = array_filter($graph['nodes'], fn($node) => $node['type'] === 'optional');

                // Categorize edges by tier
                $coreToCoreEdges = [];
                $coreToOptionalEdges = [];
                $optionalToCoreEdges = [];
                $optionalToOptionalEdges = [];

                foreach ($graph['edges'] as $edge) {
                    // Helper function to get node type
                    $getNodeType = function ($nodes, $nodeId) {
                        foreach ($nodes as $node) {
                            if ($node['id'] === $nodeId) {
                                return $node['type'];
                            }
                        }
                        return 'optional';
                    };

                    $fromType = $getNodeType($graph['nodes'], $edge['from']);
                    $toType = $getNodeType($graph['nodes'], $edge['to']);

                    if ($fromType === 'core' && $toType === 'core') {
                        $coreToCoreEdges[] = $edge;
                    } elseif ($fromType === 'core' && $toType === 'optional') {
                        $coreToOptionalEdges[] = $edge;
                    } elseif ($fromType === 'optional' && $toType === 'core') {
                        $optionalToCoreEdges[] = $edge;
                    } elseif ($fromType === 'optional' && $toType === 'optional') {
                        $optionalToOptionalEdges[] = $edge;
                    }
                }

                // Enhance the graph with tiered information
                return [
                    'graph' => $graph,
                    'summary' => [
                        'nodes' => [
                            'total' => count($graph['nodes']),
                            'core' => count($coreNodes),
                            'optional' => count($optionalNodes)
                        ],
                        'edges' => [
                            'total' => count($graph['edges']),
                            'coreToCore' => count($coreToCoreEdges),
                            'coreToOptional' => count($coreToOptionalEdges),
                            'optionalToCore' => count($optionalToCoreEdges),
                            'optionalToOptional' => count($optionalToOptionalEdges)
                        ]
                    ],
                    'tieredEdges' => [
                        'coreToCore' => $coreToCoreEdges,
                        'coreToOptional' => $coreToOptionalEdges,
                        'optionalToCore' => $optionalToCoreEdges,
                        'optionalToOptional' => $optionalToOptionalEdges
                    ]
                ];
            },
            900 // 15 minutes TTL
        );

        return Response::ok(
            $tieredGraph,
            'Extension dependencies retrieved successfully'
        )->send();
    }

    /**
     * Helper method to get node type from nodes array by ID
     *
     * @param array $nodes Array of nodes
     * @param string $nodeId Node ID to find
     * @return string Node type (core or optional)
     */
    private function getNodeType(array $nodes, string $nodeId): string
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $nodeId) {
                return $node['type'];
            }
        }
        return 'optional'; // Default to optional if not found
    }

    /**
     * Get resource usage metrics for extensions
     *
     * Returns detailed performance metrics for enabled extensions
     * including memory usage, execution time, and resource utilization
     *
     * @return mixed HTTP response
     */
    public function getExtensionMetrics(): mixed
    {
        // Check permission
        $this->requirePermission('extensions.metrics.view');

        // Apply rate limiting for metrics endpoint
        $this->rateLimitMethod(null, [
            'attempts' => 60,
            'window' => 60,
            'adaptive' => true
        ]);

        // Cache metrics with short TTL due to dynamic nature
        $tieredMetrics = $this->cacheResponse(
            'extension_metrics',
            function () {
                $metrics = ExtensionsManager::getExtensionMetrics();

                // Get extension tier information
                $coreExtensions = ExtensionsManager::getCoreExtensions();

                // Group metrics by tier
                $coreMetrics = [];
                $optionalMetrics = [];
                $totalCoreMemory = 0;
                $totalOptionalMemory = 0;
                $totalCoreExecutionTime = 0;
                $totalOptionalExecutionTime = 0;

                foreach ($metrics['extensions'] as $extName => $extMetrics) {
                    if (in_array($extName, $coreExtensions)) {
                        $coreMetrics[$extName] = $extMetrics;
                        $totalCoreMemory += ($extMetrics['memory_usage'] ?? 0);
                        $totalCoreExecutionTime += ($extMetrics['execution_time'] ?? 0);
                    } else {
                        $optionalMetrics[$extName] = $extMetrics;
                        $totalOptionalMemory += ($extMetrics['memory_usage'] ?? 0);
                        $totalOptionalExecutionTime += ($extMetrics['execution_time'] ?? 0);
                    }
                }

                // Create tiered metrics response
                $tieredMetrics = [
                    'overall' => [
                        'total_memory_usage' => $metrics['total_memory_usage'],
                        'total_execution_time' => $metrics['total_execution_time'],
                    ],
                    'by_tier' => [
                        'core' => [
                            'total_memory_usage' => $totalCoreMemory,
                            'total_execution_time' => $totalCoreExecutionTime,
                            'extensions_count' => count($coreMetrics),
                            'extensions' => $coreMetrics
                        ],
                        'optional' => [
                            'total_memory_usage' => $totalOptionalMemory,
                            'total_execution_time' => $totalOptionalExecutionTime,
                            'extensions_count' => count($optionalMetrics),
                            'extensions' => $optionalMetrics
                        ]
                    ],
                    'all_extensions' => $metrics['extensions']
                ];

                // Add percentage distributions
                if ($metrics['total_memory_usage'] > 0) {
                    $coreMemPct = ($totalCoreMemory / $metrics['total_memory_usage']) * 100;
                    $optMemPct = ($totalOptionalMemory / $metrics['total_memory_usage']) * 100;
                    $tieredMetrics['by_tier']['core']['memory_percentage'] = round($coreMemPct, 2);
                    $tieredMetrics['by_tier']['optional']['memory_percentage'] = round($optMemPct, 2);
                }

                if ($metrics['total_execution_time'] > 0) {
                    $coreTimePct = ($totalCoreExecutionTime / $metrics['total_execution_time']) * 100;
                    $optTimePct = ($totalOptionalExecutionTime / $metrics['total_execution_time']) * 100;
                    $tieredMetrics['by_tier']['core']['execution_time_percentage'] = round($coreTimePct, 2);
                    $tieredMetrics['by_tier']['optional']['execution_time_percentage'] = round($optTimePct, 2);
                }

                return $tieredMetrics;
            },
            120 // 2 minutes TTL for metrics
        );

        return Response::ok(
            $tieredMetrics,
            'Extension metrics retrieved successfully'
        )->send();
    }

    /**
     * Delete an extension
     *
     * Completely removes an extension from the filesystem and
     * updates the configuration to remove references to it.
     *
     * @return mixed HTTP response
     */
    public function deleteExtension(): mixed
    {
        // Check base permission
        $this->requirePermission('extensions.delete');

        // Apply strict rate limiting for deletion operations
        $this->rateLimitMethod(null, [
            'attempts' => 5,
            'window' => 300,  // 5 minutes
            'adaptive' => true
        ]);

        // Require low risk behavior for deletion
        $this->requireLowRiskBehavior(0.5, 'extension_deletion');

        $data = Request::getPostData();

        if (!isset($data['extension'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $extensionName = $data['extension'];

        if (!ExtensionsManager::extensionExists($extensionName)) {
            return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
        }

        // Check if it's a core extension
        $isCoreExtension = ExtensionsManager::isCoreExtension($extensionName);
        $tierType = $isCoreExtension ? 'core' : 'optional';

        // Use force parameter if provided
        $force = isset($data['force']) && $data['force'] === true;

        // Additional permission check for force deletion
        if ($force) {
            $this->requirePermission('extensions.force.delete');
        }

        // Log deletion attempt with context
        $context = new PermissionContext(
            data: [
                'extension' => $extensionName,
                'tier' => $tierType,
                'is_core' => $isCoreExtension,
                'force' => $force,
                'action' => 'delete_attempt'
            ],
            ipAddress: $this->request->getClientIp(),
            userAgent: $this->request->headers->get('User-Agent')
        );

        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'extension_delete_attempt',
            AuditEvent::SEVERITY_WARNING,
            array_merge($context->toArray(), ['user_uuid' => $this->getCurrentUserUuid()])
        );

        $result = ExtensionsManager::deleteExtension($extensionName, $force);

        if (!$result['success']) {
            $statusCode = Response::HTTP_BAD_REQUEST;

            // If the deletion failed due to extension being enabled
            if (isset($result['details']) && isset($result['details']['is_enabled'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    Response::ERROR_VALIDATION,
                    'EXTENSION_ALREADY_ENABLED',
                    [
                        'is_enabled' => true,
                        'can_force' => $result['details']['can_force'] ?? false,
                        'tier' => $tierType,
                        'isCoreExtension' => $isCoreExtension
                    ]
                )->send();
            }

            // If it's a core extension without force
            if (isset($result['details']) && isset($result['details']['is_core'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    Response::ERROR_AUTHORIZATION,
                    'CORE_EXTENSION_ENABLE_ERROR',
                    [
                        'is_core' => true,
                        'can_force' => $result['details']['can_force'] ?? false,
                        'warning' => $result['details']['warning'] ?? 'This is a core extension',
                        'tier' => 'core'
                    ]
                )->send();
            }

            // If there are dependent extensions
            if (isset($result['details']) && isset($result['details']['dependent_extensions'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    Response::ERROR_VALIDATION,
                    'EXTENSION_ENABLE_DEPENDENT_ERROR',
                    [
                        'dependent_extensions' => $result['details']['dependent_extensions'],
                        'tier' => $tierType,
                        'isCoreExtension' => $isCoreExtension,
                        'can_force' => true
                    ]
                )->send();
            }

            return Response::error($result['message'], $statusCode)->send();
        }

        // Log successful deletion
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'extension_deleted',
            AuditEvent::SEVERITY_CRITICAL,
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'extension' => $extensionName,
                'tier' => $tierType,
                'is_core' => $isCoreExtension,
                'was_forced' => $force,
                'ip_address' => $this->request->getClientIp(),
                'details' => $result['details'] ?? []
            ]
        );

        // Invalidate all extension-related caches after deletion
        $this->invalidateCache([
            'extensions',
            'extension_dependencies',
            'extension_metrics',
            'extension_health_' . $extensionName,
            'user:' . $this->getCurrentUserUuid()
        ]);

        return Response::ok(
            [
                'extension' => $extensionName,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension,
                'wasForced' => $force,
                'details' => $result['details'] ?? []
            ],
            $result['message']
        )->send();
    }

    /**
     * Get synchronized extensions catalog from GitHub
     *
     * Retrieves the GitHub extensions catalog and enriches it with local extension status,
     * including installation and enablement information for each extension.
     * Supports comprehensive filtering and search capabilities.
     *
     * @return mixed HTTP response
     */
    public function getCatalog(): mixed
    {
        // Check permission
        $this->requirePermission('extensions.catalog.view');

        // Apply rate limiting for catalog endpoint
        $this->rateLimitMethod(null, [
            'attempts' => 60,
            'window' => 60,
            'adaptive' => true
        ]);

        try {
            // Build filters from query parameters
            $filters = $this->buildCatalogFilters();

            // Get cache preference
            $useCache = $this->request->query->get('useCache', 'true');
            $useCache = filter_var($useCache, FILTER_VALIDATE_BOOLEAN);

            // Cache catalog data with permission-aware TTL
            $cacheKey = 'extensions_catalog_' . md5(serialize($filters)) . '_' . ($useCache ? '1' : '0');
            $catalogData = $this->cacheByPermission($cacheKey, function () use ($filters, $useCache) {
                return ExtensionsManager::getSynchronizedCatalog($filters, $useCache);
            }, 300); // 5 minutes TTL

            // Log catalog access
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'extensions_catalog_accessed',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'filters_applied' => count($filters),
                    'results_count' => count($catalogData['extensions'] ?? []),
                    'cache_used' => $useCache,
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            return Response::ok([
                'data' => $catalogData,
                'request_filters' => $filters,
                'cache_used' => $useCache
            ], 'Extensions catalog retrieved successfully')->send();
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log("Extensions catalog API error: " . $e->getMessage());

            // Log failed catalog access
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'extensions_catalog_error',
                AuditEvent::SEVERITY_ERROR,
                [
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'error_message' => $e->getMessage(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            return Response::error(
                'Failed to retrieve extensions catalog',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::ERROR_VALIDATION,
                'CATALOG_FETCH_ERROR',
                ['message' => $e->getMessage()]
            )->send();
        }
    }

    /**
     * Build catalog filters from request query parameters
     *
     * @return array Validated filters array
     */
    private function buildCatalogFilters(): array
    {
        $filters = [];

        // Boolean filters
        if ($this->request->query->has('installed')) {
            $filters['installed'] = filter_var(
                $this->request->query->get('installed'),
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if ($this->request->query->has('enabled')) {
            $filters['enabled'] = filter_var(
                $this->request->query->get('enabled'),
                FILTER_VALIDATE_BOOLEAN
            );
        }

        // Status filter
        if ($this->request->query->has('status')) {
            $status = $this->request->query->get('status');
            $validStatuses = ['available', 'active', 'inactive'];
            if (in_array($status, $validStatuses)) {
                $filters['status'] = $status;
            }
        }

        // Tags filter (comma-separated)
        if ($this->request->query->has('tags')) {
            $tags = $this->request->query->get('tags');
            if (is_string($tags) && !empty(trim($tags))) {
                $filters['tags'] = array_map('trim', explode(',', $tags));
                // Remove empty tags
                $filters['tags'] = array_filter($filters['tags'], fn($tag) => !empty($tag));
            }
        }

        // Search filter
        if ($this->request->query->has('search')) {
            $search = trim($this->request->query->get('search'));
            if (!empty($search)) {
                $filters['search'] = $search;
            }
        }

        // Rating filter
        if ($this->request->query->has('min_rating')) {
            $minRating = $this->request->query->get('min_rating');
            if (is_numeric($minRating)) {
                $rating = (float) $minRating;
                // Validate rating range (0-5)
                if ($rating >= 0 && $rating <= 5) {
                    $filters['min_rating'] = $rating;
                }
            }
        }

        // Publisher filter
        if ($this->request->query->has('publisher')) {
            $publisher = trim($this->request->query->get('publisher'));
            if (!empty($publisher)) {
                $filters['publisher'] = $publisher;
            }
        }

        return $filters;
    }
}
