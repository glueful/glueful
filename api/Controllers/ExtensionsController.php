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
class ExtensionsController {
    /**
     * Constructor for ExtensionsController
     */
    public function __construct() {
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
                $extensionData[] = [
                    'name' => $extensionName,
                    'description' => $metadata['description'] ?? ExtensionsManager::getExtensionMetadata($shortName, 'description'),
                    'version' => $metadata['version'] ?? ExtensionsManager::getExtensionMetadata($shortName, 'version'),
                    'author' => $metadata['author'] ?? ExtensionsManager::getExtensionMetadata($shortName, 'author'),
                    'enabled' => $isEnabled,
                ];
            }
            return Response::ok(['extensions' => $extensionData], 'Extensions retrieved successfully')->send();

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
                            'required_dependencies' => $result['details']['required_dependencies'] ?? []
                        ]
                    )->send();
                }
                
                return Response::error($result['message'], $statusCode)->send();
            }

            return Response::ok(
                ['extension' => $extensionName], 
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

            $result = ExtensionsManager::disableExtension($extensionName);

            if (!$result['success']) {
                $statusCode = Response::HTTP_BAD_REQUEST;
                
                // If the issue is related to dependent extensions, return detailed information
                if (isset($result['details']) && isset($result['details']['dependent_extensions'])) {
                    return Response::error(
                        $result['message'], 
                        $statusCode,
                        [
                            'dependent_extensions' => $result['details']['dependent_extensions']
                        ]
                    )->send();
                }
                
                return Response::error($result['message'], $statusCode)->send();
            }

            return Response::ok(
                ['extension' => $extensionName], 
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

            return Response::ok([
                'extension' => $extensionName,
                'health' => $health
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

            return Response::ok(
                $graph, 
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

            return Response::ok(
                $metrics,
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
}