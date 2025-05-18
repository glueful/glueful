<?php

declare(strict_types=1);

namespace Glueful\Extensions;

// Import required classes with proper namespaces
use Glueful\Cache\CacheEngine;
use Glueful\Http\Client;
use Glueful\Helpers\ExtensionsManager;
use Glueful\Exceptions\ExtensionException;

// Define framework version constant if not already defined
defined('GLUEFUL_VERSION') or define('GLUEFUL_VERSION', config('app.version', '1.0.0'));

/**
 * Marketplace Extension
 *
 * @description Provides an extension marketplace for discovering, installing and managing extensions
 * @version 1.0.0
 */
class Marketplace extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];

    /**
     * The marketplace API endpoint
     */
    private const MARKETPLACE_API = 'https://marketplace.glueful.com/api/v1';

    /**
     * Cache TTL for marketplace data in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::$config = require __DIR__ . '/config.php';
        }
    }

    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        // Register any services provided by this extension
    }

    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // Register any middleware components
    }

    /**
     * Process extension requests
     *
     * @param array $queryParams GET parameters
     * @param array $bodyParams POST parameters
     * @return array Response data
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        return [
            'success' => true,
            'data' => [
                'extension' => 'Marketplace',
                'message' => 'Marketplace is working properly'
            ]
        ];
    }

    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'Marketplace',
            'description' => 'Provides an extension marketplace for discovering, installing and managing extensions',
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'type' => 'core',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => []
            ]
        ];
    }

    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];

        // Check if the marketplace API is accessible
        try {
            $client = new Client();
            $response = $client->get(self::MARKETPLACE_API . '/health', [
                'timeout' => 5,
                'headers' => [
                    'Glueful-Version' => GLUEFUL_VERSION,
                    'Accept' => 'application/json',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $healthy = false;
                $issues[] = "Marketplace API returned status code $statusCode";
            }
        } catch (\Throwable $e) {
            $healthy = false;
            $issues[] = "Cannot connect to Marketplace API: " . $e->getMessage();
        }

        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }

    /**
     * Get available extensions from the repository
     *
     * @param array $filters Optional filters for search and filtering
     *                       Supported filters:
     *                       - query: Search query string
     *                       - category: Category name
     *                       - author: Author name
     *                       - tags: Array of tags
     *                       - rating: Minimum rating (1-5)
     *                       - price: 'free', 'paid', or 'all'
     *                       - sort: 'popular', 'newest', 'rating', 'name'
     *                       - compatible_only: Boolean to show only compatible extensions
     * @return array List of available extensions with metadata
     */
    public static function getAvailableExtensions(array $filters = []): array
    {
        $cacheKey = 'marketplace_extensions_' . md5(json_encode($filters));

        // Try to get from cache first
        $cached = CacheEngine::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // Prepare request parameters
            $params = [];
            if (!empty($filters['query'])) {
                $params['q'] = $filters['query'];
            }
            if (!empty($filters['category'])) {
                $params['category'] = $filters['category'];
            }
            if (!empty($filters['author'])) {
                $params['author'] = $filters['author'];
            }
            if (!empty($filters['tags'])) {
                $params['tags'] = implode(',', (array)$filters['tags']);
            }
            if (!empty($filters['rating'])) {
                $params['rating'] = max(1, min(5, (int)$filters['rating']));
            }
            if (isset($filters['price'])) {
                $params['price'] = in_array($filters['price'], ['free', 'paid', 'all'])
                    ? $filters['price']
                    : 'all';
            }
            if (!empty($filters['sort'])) {
                $params['sort'] = in_array($filters['sort'], ['popular', 'newest', 'rating', 'name'])
                    ? $filters['sort']
                    : 'popular';
            }

            // Add current framework version to inform compatibility filtering
            $params['glueful_version'] = config('app.version', '1.0.0');

            // Fetch extensions from marketplace API
            $client = new Client();
            $response = $client->get(self::MARKETPLACE_API . '/extensions', [
                'query' => $params,
                'headers' => [
                    'Glueful-Version' => GLUEFUL_VERSION,
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            // Process extensions to add local information
            if (!empty($data['extensions']) && is_array($data['extensions'])) {
                foreach ($data['extensions'] as &$extension) {
                    // Mark extensions that are already installed
                    $extension['installed'] = ExtensionsManager::extensionExists($extension['name']);
                    $extension['enabled'] = ExtensionsManager::isExtensionEnabled($extension['name']);

                    // Add compatibility information
                    if (isset($extension['requires']['glueful'])) {
                        $currentVersion = config('app.version', '1.0.0');
                        $extension['compatible'] = self::checkVersionCompatibility(
                            $currentVersion,
                            $extension['requires']['glueful']
                        );
                    } else {
                        $extension['compatible'] = true;
                    }
                }
            }

            // Filter by compatibility if requested
            if (!empty($filters['compatible_only'])) {
                $data['extensions'] = array_filter($data['extensions'] ?? [], function ($extension) {
                    return $extension['compatible'] ?? false;
                });
                // Reindex array
                $data['extensions'] = array_values($data['extensions']);
            }

            // Cache the results
            CacheEngine::set($cacheKey, $data, self::CACHE_TTL);

            return $data;
        } catch (\Exception $e) {
            // If API is unavailable, try to return cached data even if expired
            $expiredCache = CacheEngine::get($cacheKey, true); // Set second parameter to true to get expired data
            if ($expiredCache !== null) {
                return array_merge($expiredCache, [
                    'warning' => 'Using cached data because marketplace is currently unavailable'
                ]);
            }

            // Return empty result with error info
            return [
                'extensions' => [],
                'error' => 'Could not connect to marketplace: ' . $e->getMessage(),
                'total' => 0,
            ];
        }
    }

    /**
     * Install extension from marketplace
     *
     * @param string $extensionId Marketplace extension ID
     * @param string|null $version Specific version to install or null for latest
     * @return array Installation result with status and messages
     * @throws ExtensionException When installation fails
     */
    public static function installExtension(string $extensionId, ?string $version = null): array
    {
        try {
            // 1. Get extension details from marketplace
            $client = new Client();
            $endpoint = self::MARKETPLACE_API . '/extensions/' . $extensionId;
            if ($version) {
                $endpoint .= '/versions/' . $version;
            }

            $response = $client->get($endpoint, [
                'headers' => [
                    'Glueful-Version' => GLUEFUL_VERSION,
                    'Accept' => 'application/json',
                ]
            ]);

            $extensionData = json_decode($response->getBody(), true);

            if (!isset($extensionData['download_url'])) {
                throw new ExtensionException("Extension download URL not found");
            }

            // 2. Check compatibility before download
            if (isset($extensionData['requires']['glueful'])) {
                $compatible = self::checkVersionCompatibility(
                    config('app.version', '1.0.0'),
                    $extensionData['requires']['glueful']
                );

                if (!$compatible) {
                    throw new ExtensionException(
                        "Extension {$extensionData['name']} version {$extensionData['version']} " .
                        "is not compatible with your Glueful version"
                    );
                }
            }

            // 3. Use ExtensionsManager to install the extension
            $result = ExtensionsManager::installExtension(
                $extensionData['download_url'],
                $extensionData['name']
            );

            if (!$result['success']) {
                throw new ExtensionException($result['message'] ?? "Failed to install extension");
            }

            // 4. Enable the extension
            $enableResult = ExtensionsManager::enableExtension($extensionData['name']);

            return [
                'success' => true,
                'extension' => $extensionData['name'],
                'version' => $extensionData['version'],
                'message' => "Successfully installed {$extensionData['name']} version {$extensionData['version']}",
                'enabled' => $enableResult['success'],
                'enable_message' => $enableResult['message'] ?? null
            ];
        } catch (\Exception $e) {
            throw new ExtensionException("Extension installation failed: " . $e->getMessage());
        }
    }

    /**
     * Update an extension from the marketplace
     *
     * @param string $extensionName Name of extension to update
     * @param string|null $version Specific version to update to (null for latest)
     * @param bool $preserveConfig Whether to preserve configuration
     * @return array Update result with status and messages
     */
    public static function updateExtension(
        string $extensionName,
        ?string $version = null,
        bool $preserveConfig = true
    ): array {
        try {
            // Verify extension exists
            if (!ExtensionsManager::extensionExists($extensionName)) {
                return [
                    'success' => false,
                    'message' => "Extension '$extensionName' is not installed"
                ];
            }

            // Get extension details from marketplace
            $client = new Client();
            $endpoint = self::MARKETPLACE_API . '/extensions/' . $extensionName;
            if ($version) {
                $endpoint .= '/versions/' . $version;
            }

            $response = $client->get($endpoint, [
                'headers' => [
                    'Glueful-Version' => GLUEFUL_VERSION,
                    'Accept' => 'application/json',
                ]
            ]);

            $extensionData = json_decode($response->getBody(), true);

            if (!isset($extensionData['download_url'])) {
                return [
                    'success' => false,
                    'message' => "Extension download URL not found"
                ];
            }

            // Use ExtensionsManager to update the extension
            $result = ExtensionsManager::updateExtension(
                $extensionName,
                $extensionData['download_url'],
                $preserveConfig
            );

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Extension update failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Check for available updates for installed extensions
     *
     * @param string|null $extensionName Specific extension to check, or null for all
     * @return array Available updates information
     */
    public static function checkForUpdates(?string $extensionName = null): array
    {
        try {
            $client = new Client();
            $params = [];

            if ($extensionName) {
                $params['extensions'] = $extensionName;
            } else {
                // Get all installed extensions
                $installedExtensions = ExtensionsManager::getLoadedExtensions();
                $extensionNames = [];

                foreach ($installedExtensions as $extensionClass) {
                    $reflection = new \ReflectionClass($extensionClass);
                    $extensionNames[] = $reflection->getShortName();
                }

                if (empty($extensionNames)) {
                    return [
                        'success' => true,
                        'updates' => [],
                        'message' => 'No extensions installed to check for updates'
                    ];
                }

                $params['extensions'] = implode(',', $extensionNames);
            }

            // Add current framework version for compatibility check
            $params['glueful_version'] = config('app.version', '1.0.0');

            // Call marketplace API to check for updates
            $response = $client->get(self::MARKETPLACE_API . '/updates', [
                'query' => $params,
                'headers' => [
                    'Glueful-Version' => GLUEFUL_VERSION,
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            // Process updates to add local information
            $updates = [];

            if (!empty($data['updates']) && is_array($data['updates'])) {
                foreach ($data['updates'] as $extensionName => $update) {
                    // Add information about local version
                    $localVersion = null;
                    $extensionClass = ExtensionsManager::findExtension($extensionName);

                    if ($extensionClass) {
                        try {
                            $metadata = $extensionClass::getMetadata();
                            $localVersion = $metadata['version'] ?? null;
                        } catch (\Throwable $e) {
                            // Failed to get metadata
                        }
                    }

                    $update['local_version'] = $localVersion;
                    $update['compatible'] = self::checkVersionCompatibility(
                        config('app.version', '1.0.0'),
                        $update['requires']['glueful'] ?? '*'
                    );

                    $updates[$extensionName] = $update;
                }
            }

            return [
                'success' => true,
                'updates' => $updates,
                'total' => count($updates)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to check for updates: ' . $e->getMessage(),
                'updates' => []
            ];
        }
    }

    /**
     * Check if version meets the constraint
     *
     * @param string $version Current version
     * @param string $constraint Version constraint (e.g. ">=1.0.0", "^2.0.0")
     * @return bool Whether the version meets the constraint
     */
    private static function checkVersionCompatibility(string $version, string $constraint): bool
    {
        // Basic semver constraint check
        // For a real implementation, use a proper version comparison library
        if (strpos($constraint, '>=') === 0) {
            $minVersion = trim(substr($constraint, 2));
            return version_compare($version, $minVersion, '>=');
        } elseif (strpos($constraint, '^') === 0) {
            $baseVersion = trim(substr($constraint, 1));
            $parts = explode('.', $baseVersion);
            $nextMajor = (int)$parts[0] + 1;
            return version_compare($version, $baseVersion, '>=') &&
                   version_compare($version, $nextMajor . '.0.0', '<');
        }

        // Exact match
        return $version === $constraint;
    }

    /**
     * Get compatibility status of an extension with current framework version
     *
     * @param string $extensionName Extension name
     * @return array Compatibility status and conflict information
     */
    public static function getCompatibilityStatus(string $extensionName): array
    {
        try {
            // Get extension metadata from marketplace
            $client = new Client();
            $response = $client->get(self::MARKETPLACE_API . '/extensions/' . $extensionName, [
                'headers' => [
                    'Glueful-Version' => GLUEFUL_VERSION,
                    'Accept' => 'application/json',
                ]
            ]);

            $extensionData = json_decode($response->getBody(), true);

            $currentVersion = config('app.version', '1.0.0');
            $compatible = true;
            $conflicts = [];

            // Check framework compatibility
            if (!empty($extensionData['requires']['glueful'])) {
                $constraint = $extensionData['requires']['glueful'];
                $compatible = self::checkVersionCompatibility($currentVersion, $constraint);

                if (!$compatible) {
                    $conflicts[] = [
                        'type' => 'framework',
                        'name' => 'glueful',
                        'constraint' => $constraint,
                        'actual' => $currentVersion,
                        'message' => "Extension requires Glueful $constraint, but you have $currentVersion"
                    ];
                }
            }

            // Check extension dependencies
            if (!empty($extensionData['requires']['extensions'])) {
                foreach ($extensionData['requires']['extensions'] as $dependencyName => $versionConstraint) {
                    $dependencyClass = ExtensionsManager::findExtension($dependencyName);

                    if (!$dependencyClass) {
                        $compatible = false;
                        $conflicts[] = [
                            'type' => 'extension',
                            'name' => $dependencyName,
                            'constraint' => $versionConstraint,
                            'actual' => null,
                            'message' => "Required extension $dependencyName is not installed"
                        ];
                        continue;
                    }

                    // Check if dependency is enabled
                    if (!ExtensionsManager::isExtensionEnabled($dependencyName)) {
                        $compatible = false;
                        $conflicts[] = [
                            'type' => 'extension_disabled',
                            'name' => $dependencyName,
                            'constraint' => $versionConstraint,
                            'actual' => null,
                            'message' => "Required extension $dependencyName is installed but not enabled"
                        ];
                        continue;
                    }

                    // Check version constraint
                    try {
                        $metadata = $dependencyClass::getMetadata();
                        $dependencyVersion = $metadata['version'] ?? '1.0.0';

                        $versionCompatible = self::checkVersionCompatibility($dependencyVersion, $versionConstraint);
                        if (!$versionCompatible) {
                            $compatible = false;
                            $conflicts[] = [
                                'type' => 'extension_version',
                                'name' => $dependencyName,
                                'constraint' => $versionConstraint,
                                'actual' => $dependencyVersion,
                                'message' => "Extension requires $dependencyName $versionConstraint, 
                                but you have $dependencyVersion"
                            ];
                        }
                    } catch (\Throwable $e) {
                        // Failed to get metadata, assume incompatible
                        $compatible = false;
                        $conflicts[] = [
                            'type' => 'metadata_error',
                            'name' => $dependencyName,
                            'message' => "Cannot verify version of $dependencyName: " . $e->getMessage()
                        ];
                    }
                }
            }

            return [
                'compatible' => $compatible,
                'extension' => $extensionName,
                'conflicts' => $conflicts,
                'suggestions' => $compatible ? [] : self::getSuggestedResolutions($conflicts)
            ];
        } catch (\Exception $e) {
            return [
                'compatible' => false,
                'extension' => $extensionName,
                'error' => $e->getMessage(),
                'conflicts' => [
                    [
                        'type' => 'api_error',
                        'message' => 'Failed to check compatibility: ' . $e->getMessage()
                    ]
                ]
            ];
        }
    }

    /**
     * Get suggested resolution actions for compatibility issues
     *
     * @param array $conflicts Conflicts from getCompatibilityStatus()
     * @return array List of suggested resolution actions
     */
    public static function getSuggestedResolutions(array $conflicts): array
    {
        $suggestions = [];

        foreach ($conflicts as $conflict) {
            switch ($conflict['type']) {
                case 'framework':
                    $suggestions[] = "Upgrade Glueful to version {$conflict['constraint']}";
                    break;

                case 'extension':
                    $suggestions[] = "Install extension {$conflict['name']} (version {$conflict['constraint']})";
                    break;

                case 'extension_disabled':
                    $suggestions[] = "Enable extension {$conflict['name']}";
                    break;

                case 'extension_version':
                    $suggestions[] = "Upgrade extension {$conflict['name']} to version {$conflict['constraint']}";
                    break;

                case 'metadata_error':
                    $suggestions[] = "Reinstall extension {$conflict['name']} to fix metadata issues";
                    break;

                default:
                    $suggestions[] = $conflict['message'] ?? "Fix conflict: {$conflict['type']}";
            }
        }

        return $suggestions;
    }
}
