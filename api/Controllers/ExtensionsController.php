<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\{Request, ExtensionsManager};

/**
 * Controller for managing extensions functionality
 *
 * Handles all extension-related operations including listing, enabling, disabling,
 * and retrieving extension health and dependency information.
 */
class ExtensionsController
{
    /**
     * Constructor for ExtensionsController
     */
    public function __construct()
    {
        // Initialize any dependencies
    }

    /**
     * Get all extensions with pagination and status
     *
     * @return mixed HTTP response
     */
    public function getExtensions(): mixed
    {
        try {
            $extensions = ExtensionsManager::getLoadedExtensions();
            $extensionData = [];
            $extensionConfigFile = ExtensionsManager::getConfigPath();
            $enabledExtensions = ExtensionsManager::getEnabledExtensions($extensionConfigFile);
            $coreExtensions = ExtensionsManager::getCoreExtensions($extensionConfigFile);
            $optionalExtensions = ExtensionsManager::getOptionalExtensions($extensionConfigFile);

            if (empty($extensions)) {
                return Response::ok([], 'No extensions found')->send();
            }

            foreach ($extensions as $extension) {
                $reflection = new \ReflectionClass($extension);
                $shortName = $reflection->getShortName();

                // Get metadata from the extension class if the method exists
                $metadata = [];
                if (method_exists($extension, 'getMetadata')) {
                    $metadata = $extension::getMetadata();
                }

                // Use name from metadata if available, otherwise use the short class name
                $extensionName = isset($metadata['name']) ? $metadata['name'] : $shortName;

                $isEnabled = in_array($shortName, $enabledExtensions);
                $tierType = in_array($shortName, $coreExtensions) ? 'core' : 'optional';

                $extensionData[] = [
                    'name' => $extensionName,
                    'description' => $metadata['description'] ?? ExtensionsManager::getExtensionMetadata($shortName, 'description'),
                    'version' => $metadata['version'] ?? ExtensionsManager::getExtensionMetadata($shortName, 'version'),
                    'author' => $metadata['author'] ?? ExtensionsManager::getExtensionMetadata($shortName, 'author'),
                    'enabled' => $isEnabled,
                    'tier' => $tierType,  // Added tier type information
                    'isCoreExtension' => in_array($shortName, $coreExtensions),  // Explicit flag for core extensions
                    'extensionId' => $shortName, // Include the extension ID for actions
                ];
            }

            // Group extensions by tier for clearer organization
            $groupedExtensions = [
                'core' => array_filter($extensionData, fn($ext) => $ext['tier'] === 'core'),
                'optional' => array_filter($extensionData, fn($ext) => $ext['tier'] === 'optional'),
                'all' => $extensionData
            ];

            return Response::ok([
                'extensions' => $groupedExtensions,
                'summary' => [
                    'total' => count($extensionData),
                    'enabled' => count($enabledExtensions),
                    'core' => count($coreExtensions),
                    'optional' => count($optionalExtensions)
                ]
            ], 'Extensions retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get extensions error: " . $e->getMessage());
            return Response::error(
                'Failed to get extensions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Enable an extension
     *
     * @return mixed HTTP response
     */
    public function enableExtension(): mixed
    {
        try {
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

            $result = ExtensionsManager::enableExtension($extensionName);

            if (!$result['success']) {
                $statusCode = Response::HTTP_BAD_REQUEST;

                // If the issue is related to missing dependencies, return detailed information
                if (isset($result['details']) && isset($result['details']['missing_dependencies'])) {
                    return Response::error(
                        $result['message'],
                        $statusCode,
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

            return Response::ok(
                [
                    'extension' => $extensionName,
                    'tier' => $tierType,
                    'isCoreExtension' => $isCoreExtension
                ],
                $result['message']
            )->send();
        } catch (\Exception $e) {
            error_log("Enable extension error: " . $e->getMessage());
            return Response::error(
                'Failed to enable extension: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Disable an extension
     *
     * @return mixed HTTP response
     */
    public function disableExtension(): mixed
    {
        try {
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

            $result = ExtensionsManager::disableExtension($extensionName, $force);

            if (!$result['success']) {
                $statusCode = Response::HTTP_BAD_REQUEST;

                // If the issue is related to dependent extensions, return detailed information
                if (isset($result['details']) && isset($result['details']['dependent_extensions'])) {
                    return Response::error(
                        $result['message'],
                        $statusCode,
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

            return Response::ok(
                [
                    'extension' => $extensionName,
                    'tier' => $tierType,
                    'isCoreExtension' => $isCoreExtension,
                    'wasForced' => $force
                ],
                $result['message']
            )->send();
        } catch (\Exception $e) {
            error_log("Disable extension error: " . $e->getMessage());
            return Response::error(
                'Failed to disable extension: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get health status for a specific extension
     *
     * @param array|null $extension Extension data from route params
     * @return mixed HTTP response
     */
    public function getExtensionHealth(?array $extension): mixed
    {
        try {
            if (!isset($extension['name'])) {
                return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $extensionName = $extension['name'];

            if (!ExtensionsManager::extensionExists($extensionName)) {
                return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
            }

            $health = ExtensionsManager::checkExtensionHealth($extensionName);

            // Get the tier information
            $isCoreExtension = ExtensionsManager::isCoreExtension($extensionName);
            $tierType = $isCoreExtension ? 'core' : 'optional';
            $isEnabled = ExtensionsManager::isExtensionEnabled($extensionName);

            return Response::ok([
                'extension' => $extensionName,
                'health' => $health,
                'tier' => $tierType,
                'isCoreExtension' => $isCoreExtension,
                'enabled' => $isEnabled,
                'criticality' => $isCoreExtension ? 'critical' : 'standard',
                'healthImpact' => $isCoreExtension && !$health['healthy'] ? 'system-critical' : 'extension-only'
            ], 'Extension health status retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get extension health error: " . $e->getMessage());
            return Response::error(
                'Failed to get extension health: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
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
        try {
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
                $fromType = $this->getNodeType($graph['nodes'], $edge['from']);
                $toType = $this->getNodeType($graph['nodes'], $edge['to']);

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
            $tieredGraph = [
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

            return Response::ok(
                $tieredGraph,
                'Extension dependencies retrieved successfully'
            )->send();
        } catch (\Exception $e) {
            error_log("Get extension dependencies error: " . $e->getMessage());
            return Response::error(
                'Failed to get extension dependencies: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
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
        try {
            $metrics = ExtensionsManager::getExtensionMetrics();

            // Get extension tier information
            $coreExtensions = ExtensionsManager::getCoreExtensions();
            $optionalExtensions = ExtensionsManager::getOptionalExtensions();

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
                $tieredMetrics['by_tier']['core']['memory_percentage'] = round(($totalCoreMemory / $metrics['total_memory_usage']) * 100, 2);
                $tieredMetrics['by_tier']['optional']['memory_percentage'] = round(($totalOptionalMemory / $metrics['total_memory_usage']) * 100, 2);
            }

            if ($metrics['total_execution_time'] > 0) {
                $tieredMetrics['by_tier']['core']['execution_time_percentage'] = round(($totalCoreExecutionTime / $metrics['total_execution_time']) * 100, 2);
                $tieredMetrics['by_tier']['optional']['execution_time_percentage'] = round(($totalOptionalExecutionTime / $metrics['total_execution_time']) * 100, 2);
            }

            return Response::ok(
                $tieredMetrics,
                'Extension metrics retrieved successfully'
            )->send();
        } catch (\Exception $e) {
            error_log("Get extension metrics error: " . $e->getMessage());
            return Response::error(
                'Failed to get extension metrics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
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
        try {
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

            $result = ExtensionsManager::deleteExtension($extensionName, $force);

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
                    )->send();
                }

                // If it's a core extension without force
                if (isset($result['details']) && isset($result['details']['is_core'])) {
                    return Response::error(
                        $result['message'],
                        $statusCode,
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
        } catch (\Exception $e) {
            error_log("Delete extension error: " . $e->getMessage());
            return Response::error(
                'Failed to delete extension: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}
