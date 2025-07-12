<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\{RequestHelper};
use Glueful\Extensions\ExtensionManager;
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
    public function __construct(
        private ExtensionManager $extensionManager
    ) {
        parent::__construct();
    }

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

        // Cache extensions list with permission-aware TTL
        $data = $this->cacheByPermission('extensions_list', function () {
            // Get installed extensions
            $installedExtensions = $this->extensionManager->listInstalled();
            $coreExtensions = $this->extensionManager->getCoreExtensions();

            $extensionData = [];

            // Process installed extensions
            foreach ($installedExtensions as $extensionName) {
                $tierType = $this->extensionManager->isCoreExtension($extensionName) ? 'core' : 'optional';
                // Get metadata for each extension
                $metadata = $this->extensionManager->getExtensionMetadata($extensionName) ?? [];

                $description = $metadata['description'] ?? 'No description available';
                $version = $metadata['version'] ?? 'unknown';
                $author = $metadata['author'] ?? 'unknown';

                $extensionData[] = [
                    'name' => $extensionName,
                    'description' => $description,
                    'version' => $version,
                    'author' => $author,
                    'enabled' => $this->extensionManager->isEnabled($extensionName),
                    'tier' => $tierType,  // Added tier type information
                    'isCoreExtension' => $this->extensionManager->isCoreExtension($extensionName),  // Explicit flag
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
                    'optional' => count($installedExtensions) - count($coreExtensions)
                ]
            ];
        }, 300); // 5 minutes default TTL

        // Use public caching for extension list (changes infrequently)
        return $this->publicSuccess($data, 'Extensions retrieved successfully', 1800); // 30 minutes
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

        $data = RequestHelper::getRequestData();

        if (!isset($data['extension'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST);
        }

        $extensionName = $data['extension'];

        if (!$this->extensionManager->isInstalled($extensionName)) {
            return Response::notFound('Extension not found');
        }

        // Check if it's a core or optional extension before enabling
        $isCoreExtension = $this->extensionManager->isCoreExtension($extensionName);
        $tierType = $isCoreExtension ? 'core' : 'optional';

        // Additional permission check for core extensions
        if ($isCoreExtension) {
            $this->requirePermission('extensions.core.manage');
        }

        try {
            $success = $this->extensionManager->enable($extensionName);

            if (!$success) {
                return Response::error(
                    "Failed to enable extension '$extensionName'",
                    Response::HTTP_BAD_REQUEST
                );
            }
        } catch (\Exception $e) {
            return Response::error(
                $e->getMessage(),
                Response::HTTP_BAD_REQUEST
            );
        }

        // Log successful extension enable

        // Invalidate extensions cache after modification
        $this->invalidateCache(['extensions', 'user:' . $this->getCurrentUserUuid()]);

        return Response::success(
            [
                'extension' => $extensionName,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension
            ],
            "Extension '$extensionName' enabled successfully"
        );
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

        $data = RequestHelper::getRequestData();

        if (!isset($data['extension'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST);
        }

        $extensionName = $data['extension'];

        if (!$this->extensionManager->isInstalled($extensionName)) {
            return Response::notFound('Extension not found');
        }

        // Check if it's a core extension
        $isCoreExtension = $this->extensionManager->isCoreExtension($extensionName);
        $tierType = $isCoreExtension ? 'core' : 'optional';

        // If it's a core extension, we may need to handle differently
        $force = isset($data['force']) && $data['force'] === true;

        // Additional permission check for core extensions with force
        if ($isCoreExtension && $force) {
            $this->requirePermission('extensions.core.manage');
        }

        try {
            $success = $this->extensionManager->disable($extensionName);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        if (!$success) {
            return Response::error(
                "Failed to disable extension '$extensionName'",
                Response::HTTP_BAD_REQUEST
            );
        }

        // Log successful extension disable

        // Invalidate extensions cache after modification
        $this->invalidateCache(['extensions', 'user:' . $this->getCurrentUserUuid()]);

        return Response::success(
            [
                'extension' => $extensionName,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension,
                'wasForced' => $force
            ],
            "Extension '$extensionName' disabled successfully"
        );
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
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST);
        }

        $extensionName = $extension['name'];

        if (!$this->extensionManager->isInstalled($extensionName)) {
            return Response::notFound('Extension not found');
        }

        // Cache health check results with short TTL
        $healthData = $this->cacheResponse(
            'extension_health_' . $extensionName,
            function () use ($extensionName) {
                $health = $this->extensionManager->checkHealth($extensionName);

                // Get the tier information
                $isCoreExtension = $this->extensionManager->isCoreExtension($extensionName);
                $tierType = $isCoreExtension ? 'core' : 'optional';
                $isEnabled = $this->extensionManager->isEnabled($extensionName);

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

        return Response::success($healthData, 'Extension health status retrieved successfully');
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
                // TODO: Implement buildDependencyGraph in new ExtensionManager
                $graph = ['nodes' => [], 'edges' => []]; // Placeholder with expected structure

                // Add summary information about the tiered nature of the dependencies
                $coreNodes = [];
                $optionalNodes = [];

                // Categorize edges by tier
                $coreToCoreEdges = [];
                $coreToOptionalEdges = [];
                $optionalToCoreEdges = [];
                $optionalToOptionalEdges = [];

                // Since this is placeholder data, skip edge processing
                // When buildDependencyGraph is implemented, this logic will work with real data

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

        return Response::success(
            $tieredGraph,
            'Extension dependencies retrieved successfully'
        );
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
                // TODO: Implement getExtensionMetrics in new ExtensionManager
                // Return placeholder structure until method is implemented
                return [
                    'overall' => [
                        'total_memory_usage' => 0,
                        'total_execution_time' => 0,
                    ],
                    'by_tier' => [
                        'core' => [
                            'total_memory_usage' => 0,
                            'total_execution_time' => 0,
                            'extensions_count' => 0,
                            'extensions' => []
                        ],
                        'optional' => [
                            'total_memory_usage' => 0,
                            'total_execution_time' => 0,
                            'extensions_count' => 0,
                            'extensions' => []
                        ]
                    ],
                    'all_extensions' => []
                ];
            },
            120 // 2 minutes TTL for metrics
        );

        return Response::success(
            $tieredMetrics,
            'Extension metrics retrieved successfully'
        );
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

        $data = RequestHelper::getRequestData();

        if (!isset($data['extension'])) {
            return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST);
        }

        $extensionName = $data['extension'];

        if (!$this->extensionManager->isInstalled($extensionName)) {
            return Response::notFound('Extension not found');
        }

        // Check if it's a core extension
        $isCoreExtension = $this->extensionManager->isCoreExtension($extensionName);
        $tierType = $isCoreExtension ? 'core' : 'optional';

        // Use force parameter if provided
        $force = isset($data['force']) && $data['force'] === true;

        // Additional permission check for force deletion
        if ($force) {
            $this->requirePermission('extensions.force.delete');
        }

        // Log deletion attempt with context
        new PermissionContext(
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


        try {
            $result = $this->extensionManager->delete($extensionName, $force);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        if (!$result['success']) {
            $statusCode = Response::HTTP_BAD_REQUEST;

            // If the deletion failed due to extension being enabled
            if (isset($result['details']) && isset($result['details']['is_enabled'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    [
                        'is_enabled' => true,
                        'can_force' => $result['details']['can_force'] ?? false,
                        'tier' => $tierType,
                        'isCoreExtension' => $isCoreExtension
                    ]
                );
            }

            // If it's a core extension without force
            if (isset($result['details']) && isset($result['details']['is_core'])) {
                return Response::forbidden(
                    $result['message'] . ' (Core extension)'
                );
            }

            // If there are dependent extensions
            if (isset($result['details']) && isset($result['details']['dependent_extensions'])) {
                return Response::error(
                    $result['message'],
                    $statusCode,
                    [
                        'dependent_extensions' => $result['details']['dependent_extensions'],
                        'tier' => $tierType,
                        'isCoreExtension' => $isCoreExtension,
                        'can_force' => true
                    ]
                );
            }

            return Response::error($result['message'], $statusCode);
        }

        // Log successful deletion

        // Invalidate all extension-related caches after deletion
        $this->invalidateCache([
            'extensions',
            'extension_dependencies',
            'extension_metrics',
            'extension_health_' . $extensionName,
            'user:' . $this->getCurrentUserUuid()
        ]);

        return Response::success(
            [
                'extension' => $extensionName,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension,
                'wasForced' => $force,
                'details' => $result['details'] ?? []
            ],
            $result['message']
        );
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
            $catalogData = $this->cacheByPermission($cacheKey, function () {
                // TODO: Implement getSynchronizedCatalog in new ExtensionManager
                return ['extensions' => []]; // Placeholder
            }, 300); // 5 minutes TTL

            // Log catalog access
            $meta = [
                'request_filters' => $filters,
                'cache_used' => $useCache
            ];
            return Response::successWithMeta($catalogData, $meta, 'Extensions catalog retrieved successfully');
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log("Extensions catalog API error: " . $e->getMessage());

            // Log failed catalog access

            return Response::serverError(
                'Failed to retrieve extensions catalog: ' . $e->getMessage()
            );
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
