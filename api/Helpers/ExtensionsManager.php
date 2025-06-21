<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Composer\Autoload\ClassLoader;
use Glueful\Helpers\CDNAdapterManager;

/**
 * Extensions Manager
 *
 * Handles the dynamic loading and initialization of API extensions:
 * - Scans configured extension directories
 * - Loads extension classes
 * - Registers extension components
 * - Manages extension lifecycle
 *
 * @package Glueful\Helpers
 */
class ExtensionsManager
{
    use CDNAdapterManager;

    /** @var array Loaded extension instances */
    private static array $loadedExtensions = [];

    /** @var ClassLoader|null Composer's class loader instance */
    private static ?ClassLoader $classLoader = null;

    /**
     * Enable debug mode for extension loading
     *
     * @var bool
     */
    private static bool $debug = false;

    /**
     * Toggle debug mode for extension loading
     *
     * @param bool $enable Whether to enable debug mode
     * @return void
     */
    public static function setDebugMode(bool $enable = true): void
    {
        self::$debug = $enable;
    }

    /**
     * Log debug message if debug mode is enabled
     *
     * @param string $message Message to log
     * @return void
     */
    private static function debug(string $message): void
    {
        if (self::$debug) {
            error_log("[ExtensionsManager Debug] " . $message);
        }
    }

    /**
     * Get information about registered namespaces
     *
     * @return array Information about registered namespaces
     */
    public static function getRegisteredNamespaces(): array
    {
        $result = [];
        $classLoader = self::getClassLoader();

        if ($classLoader === null) {
            return ['error' => 'ClassLoader not available'];
        }

        $prefixesPsr4 = $classLoader->getPrefixesPsr4();

        foreach ($prefixesPsr4 as $namespace => $paths) {
            // Use str_starts_with for cleaner type handling
            // This avoids the strpos type error completely
            if (str_starts_with($namespace, 'Glueful\\Extensions')) {
                $result[$namespace] = $paths;
            }
        }

        return $result;
    }

    /**
     * Set the Composer ClassLoader instance
     *
     * @param ClassLoader $classLoader Composer's class loader
     * @return void
     */
    public static function setClassLoader(ClassLoader $classLoader): void
    {
        self::$classLoader = $classLoader;
    }

    /**
     * Get the Composer ClassLoader instance
     *
     * @return ClassLoader|null
     */
    public static function getClassLoader(): ?ClassLoader
    {
        if (self::$classLoader === null) {
            // Try to get the ClassLoader from the Composer autoloader
            foreach (spl_autoload_functions() as $function) {
                if (is_array($function) && $function[0] instanceof ClassLoader) {
                    self::$classLoader = $function[0];
                    break;
                }
            }
        }

        return self::$classLoader;
    }

    /**
     * Load API Extensions
     *
     * Dynamically discovers and loads all API extensions:
     * - Scans configured extension directories
     * - Autoloads extension classes
     * - Initializes extensions that implement standard interfaces
     * - Registers extension services in the container
     *
     * @return void
     */
    public static function loadExtensions(): void
    {
        // Register extension namespaces with Composer if available
        self::registerExtensionNamespaces();

        // Note: loadExtensions() is deprecated in favor of loadEnabledExtensions()
        // This method now simply delegates to the enabled extensions loader
        self::loadEnabledExtensions();
    }

    /**
     * Load only enabled API Extensions
     *
     * Dynamically discovers and loads only extensions that are enabled in the configuration:
     * - Scans configured extension directories
     * - Filters for enabled extensions only
     * - Autoloads extension classes
     * - Initializes extensions that implement standard interfaces
     * - Registers enabled extension services in the container
     *
     * @return void
     */
    public static function loadEnabledExtensions(): void
    {
         // Get the list of enabled extensions from config
        $enabledExtensions = self::getEnabledExtensions();
        // Short-circuit if no extensions are enabled
        if (empty($enabledExtensions)) {
            self::debug("No extensions are enabled");
            return;
        }
        // Register extension namespaces with Composer if available
        self::registerExtensionNamespaces();

        // Extensions are loaded automatically via namespace registration
        // We only need to ensure service providers and routes are loaded
        self::debug("Extension namespaces registered, service providers will be loaded next");

        // Load service providers from extensions.json manifest
        self::loadExtensionServiceProviders();
    }

    /**
     * Load service providers from enabled extensions
     *
     * Loads service provider files specified in the "provides.services" section
     * of extension manifests for all currently enabled extensions.
     *
     * @return void
     */
    public static function loadExtensionServiceProviders(): void
    {
        $config = self::loadExtensionsConfig();

        // Check if this is the new schema version
        if (!isset($config['schema_version']) || $config['schema_version'] !== '2.0') {
            self::debug("Extensions config is not schema v2.0, skipping service provider loading");
            return;
        }

        // Get enabled extensions for current environment
        $environment = env('APP_ENV', 'production');
        $enabledExtensions = $config['environments'][$environment]['enabledExtensions'] ?? [];

        if (empty($enabledExtensions)) {
            self::debug("No extensions enabled for environment: {$environment}");
            return;
        }

        // Get DI container
        if (!function_exists('app')) {
            throw new \RuntimeException("DI container not initialized. Cannot load extension service providers.");
        }

        $container = app();
        $projectRoot = dirname(__DIR__, 2);
        $loadedProviderCount = 0;

        // Load service providers for each enabled extension
        foreach ($enabledExtensions as $extensionName) {
            if (!isset($config['extensions'][$extensionName])) {
                self::debug("Extension '{$extensionName}' not found in config");
                continue;
            }

            $extension = $config['extensions'][$extensionName];

            // Skip if extension is disabled
            if (!($extension['enabled'] ?? true)) {
                self::debug("Extension '{$extensionName}' is disabled");
                continue;
            }

            // Load service providers
            $serviceProviders = $extension['provides']['services'] ?? [];
            if (!empty($serviceProviders)) {
                foreach ($serviceProviders as $serviceProviderPath) {
                    $absolutePath = $projectRoot . '/' . $serviceProviderPath;

                    if (file_exists($absolutePath)) {
                        try {
                            // Load the service provider file
                            require_once $absolutePath;

                            // Extract class name from path
                            $pathInfo = pathinfo($serviceProviderPath);
                            $className = $pathInfo['filename'];

                            // Try to determine the namespace from the path structure
                            $pathParts = explode('/', $serviceProviderPath);

                            // Build full class name based on path structure
                            $fullClassName = null;

                            // Pattern: extensions/ExtensionName/src/Services/ServiceProvider.php
                            if (count($pathParts) >= 5 && $pathParts[2] === 'src') {
                                $subNamespace = $pathParts[3]; // e.g., "Services"
                                $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$subNamespace}\\{$className}";
                            } else {
                                $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$className}";
                            }

                            if (class_exists($fullClassName)) {
                                $serviceProvider = new $fullClassName();
                                $container->register($serviceProvider);

                                // Boot the service provider immediately since container is already booted
                                if (method_exists($serviceProvider, 'boot')) {
                                    $serviceProvider->boot($container);
                                    self::debug("Booted service provider: {$fullClassName}");
                                }

                                $loadedProviderCount++;
                                self::debug("Loaded service provider: {$fullClassName}");
                            } else {
                                self::debug("Service provider class not found: {$fullClassName}");
                            }
                        } catch (\Exception $e) {
                            error_log("Error loading service provider {$serviceProviderPath}: " . $e->getMessage());
                        }
                    } else {
                        self::debug("Service provider file not found: {$absolutePath}");
                    }
                }
            } else {
                self::debug("No service providers defined for extension: {$extensionName}");
            }
        }

        self::debug("Loaded {$loadedProviderCount} service provider(s) from extensions");
    }

    /**
     * Load extension routes from enabled extensions
     *
     * Loads route files specified in the "provides.routes" section of extension manifests
     * for all currently enabled extensions.
     *
     * @return void
     */
    public static function loadExtensionRoutes(): void
    {
        $config = self::loadExtensionsConfig();

        // Check if this is the new schema version
        if (!isset($config['schema_version']) || $config['schema_version'] !== '2.0') {
            self::debug("Extensions config is not schema v2.0, skipping extension route loading");
            return;
        }

        // Get enabled extensions for current environment
        $environment = env('APP_ENV', 'production');
        $enabledExtensions = $config['environments'][$environment]['enabledExtensions'] ?? [];

        if (empty($enabledExtensions)) {
            self::debug("No extensions enabled for environment: {$environment}");
            return;
        }

        $projectRoot = dirname(__DIR__, 2);
        $loadedRouteCount = 0;

        // Load routes for each enabled extension
        foreach ($enabledExtensions as $extensionName) {
            if (!isset($config['extensions'][$extensionName])) {
                self::debug("Extension '{$extensionName}' not found in config");
                continue;
            }

            $extension = $config['extensions'][$extensionName];

            // Skip if extension is disabled
            if (!($extension['enabled'] ?? false)) {
                continue;
            }

            // Load routes from the "provides.routes" section
            if (isset($extension['provides']['routes']) && is_array($extension['provides']['routes'])) {
                foreach ($extension['provides']['routes'] as $routeFile) {
                    $fullRoutePath = $projectRoot . '/' . $routeFile;

                    if (file_exists($fullRoutePath)) {
                        self::debug("Loading extension routes from: {$routeFile}");
                        require_once $fullRoutePath;
                        $loadedRouteCount++;
                    } else {
                        self::debug("Extension route file not found: {$fullRoutePath}");
                    }
                }
            }
        }

        self::debug("Loaded {$loadedRouteCount} extension route files");
    }

    /**
     * Register all extension namespaces with Composer ClassLoader
     *
     * @return void
     */
    private static function registerExtensionNamespaces(): void
    {
        $classLoader = self::getClassLoader();
        if ($classLoader === null) {
            error_log("ClassLoader not available, falling back to include_once");
            return;
        }

        // Use new fast loading method
        if (!self::registerExtensionNamespacesFromConfig($classLoader)) {
            error_log("Failed to register extension namespaces from config");
        }
    }

    /**
     * Fast extension namespace registration using pre-computed mappings from extensions.json
     *
     * @param \Composer\Autoload\ClassLoader $classLoader
     * @return bool Success status
     */
    private static function registerExtensionNamespacesFromConfig(\Composer\Autoload\ClassLoader $classLoader): bool
    {
        try {
            $config = self::loadExtensionsConfig();

            // Check if this is the new schema version
            if (!isset($config['schema_version']) || $config['schema_version'] !== '2.0') {
                self::debug("Extensions config is not schema v2.0, falling back to legacy method");
                return false;
            }

            // Get enabled extensions for current environment
            $environment = env('APP_ENV', 'production');
            $enabledExtensions = $config['environments'][$environment]['enabledExtensions'] ?? [];

            if (empty($enabledExtensions)) {
                self::debug("No extensions enabled for environment: {$environment}");
                return true;
            }

            $projectRoot = dirname(__DIR__, 2);
            $registeredCount = 0;

            // Register autoload mappings for enabled extensions only
            foreach ($enabledExtensions as $extensionName) {
                if (!isset($config['extensions'][$extensionName])) {
                    self::debug("Extension '{$extensionName}' not found in config");
                    continue;
                }

                $extension = $config['extensions'][$extensionName];

                // Skip if extension is disabled
                if (!($extension['enabled'] ?? false)) {
                    continue;
                }

                // Register PSR-4 mappings
                if (isset($extension['autoload']['psr-4'])) {
                    foreach ($extension['autoload']['psr-4'] as $namespace => $path) {
                        $absolutePath = $projectRoot . '/' . ltrim($path, '/');

                        if (is_dir($absolutePath)) {
                            $classLoader->addPsr4($namespace, $absolutePath);
                            self::debug("Registered namespace {$namespace} -> {$absolutePath}");
                            $registeredCount++;
                        } else {
                            self::debug("Warning: Autoload path does not exist: {$absolutePath}");
                        }
                    }
                }

                // Register files if specified
                if (isset($extension['autoload']['files'])) {
                    foreach ($extension['autoload']['files'] as $file) {
                        $absoluteFile = $projectRoot . '/' . ltrim($file, '/');
                        if (file_exists($absoluteFile)) {
                            require_once $absoluteFile;
                            self::debug("Included autoload file: {$absoluteFile}");
                        }
                    }
                }
            }

            self::debug(
                "Fast loading: Registered {$registeredCount} namespace mappings for " .
                count($enabledExtensions) . " extensions"
            );
            return true;
        } catch (\Exception $e) {
            self::debug("Fast loading failed: " . $e->getMessage());
            return false;
        }
    }







    /**
     * Get all available extensions from extensions.json
     *
     * @return array The available extensions with their metadata
     */
    public static function getLoadedExtensions(): array
    {
        $config = self::loadExtensionsConfig();

        if (!isset($config['extensions'])) {
            return [];
        }

        $extensions = [];
        foreach ($config['extensions'] as $name => $extensionData) {
            $extensions[] = [
                'name' => $name,
                'class' => "Glueful\\Extensions\\{$name}\\{$name}Extension", // Expected class pattern
                'metadata' => $extensionData
            ];
        }

        return $extensions;
    }

    /**
     * Check if extension is enabled
     *
     * @param string $extensionName Extension name
     * @return bool True if extension is enabled
     */
    public static function isExtensionEnabled(string $extensionName): bool
    {
        $config = self::loadExtensionsConfig();

        // Check if extension exists in the configuration
        if (!isset($config['extensions'][$extensionName])) {
            return false;
        }

        // Return the enabled status from the extension config
        return $config['extensions'][$extensionName]['enabled'] === true;
    }

    /**
     * Enable an extension
     *
     * Enables an extension and verifies that all dependencies are met.
     * Also properly categorizes it as core or optional based on metadata.
     *
     * @param string $extensionName Extension name
     * @return array Success status and any messages
     */
    public static function enableExtension(string $extensionName): array
    {
        // Check if extension exists
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' not found"
            ];
        }

        // Load JSON config
        $config = self::loadExtensionsConfig();

        // Check if already enabled
        if (
            isset($config['extensions'][$extensionName]['enabled']) &&
            $config['extensions'][$extensionName]['enabled']
        ) {
            return [
                'success' => true,
                'message' => "Extension '$extensionName' is already enabled"
            ];
        }

        // Check dependencies
        $dependencyResults = self::checkDependenciesForEnable($extensionName);
        if (!$dependencyResults['success']) {
            return $dependencyResults;
        }

        // Get extension metadata with caching
        $version = '1.0.0';
        $installPath = "extensions/$extensionName";
        $metadata = self::getExtensionMetadataWithCaching($extensionName, $extensionClass);
        if ($metadata) {
            $version = $metadata['version'] ?? $version;
            $installPath = $metadata['installPath'] ?? $installPath;
        }

        // Add/update extension in config
        $config['extensions'][$extensionName] = [
            'version' => $version,
            'enabled' => true,
            'installPath' => $installPath
        ];

        // Add to current environment's enabled extensions
        $env = config('app.environment', 'production');
        $envMapping = config('services.extensions.environments', []);
        $mappedEnv = $envMapping[$env] ?? $env;

        if (!isset($config['environments'][$mappedEnv])) {
            $config['environments'][$mappedEnv] = ['enabledExtensions' => []];
        }

        if (!in_array($extensionName, $config['environments'][$mappedEnv]['enabledExtensions'])) {
            $config['environments'][$mappedEnv]['enabledExtensions'][] = $extensionName;
        }

        // Save JSON config
        $saveSuccess = self::saveExtensionsJson($config);

        if (!$saveSuccess) {
            return [
                'success' => false,
                'message' => "Failed to save configuration when enabling '$extensionName'"
            ];
        }

        return [
            'success' => true,
            'message' => "Extension '$extensionName' has been enabled successfully"
        ];
    }

    /**
     * Disable an extension
     *
     * Disables an extension and checks if any enabled extensions depend on it.
     * Provides warnings when attempting to disable core extensions.
     *
     * @param string $extensionName Extension name
     * @param bool $force Force disable even if it's a core extension
     * @return array Success status and any messages
     */
    public static function disableExtension(string $extensionName, bool $force = false): array
    {
        // Load JSON config
        $config = self::loadExtensionsConfig();

        // Check if extension exists and is enabled
        if (!isset($config['extensions'][$extensionName])) {
            return [
                'success' => true,
                'message' => "Extension '$extensionName' not found in configuration"
            ];
        }

        if (!$config['extensions'][$extensionName]['enabled']) {
            return [
                'success' => true,
                'message' => "Extension '$extensionName' is already disabled"
            ];
        }

        // Check for dependent extensions
        $dependencyResults = self::checkDependenciesForDisable($extensionName);
        if (!$dependencyResults['success']) {
            return $dependencyResults;
        }

        // Disable the extension in base config
        $config['extensions'][$extensionName]['enabled'] = false;

        // Remove from all environment enabled lists
        if (isset($config['environments'])) {
            foreach ($config['environments'] as $env => $envConfig) {
                if (isset($envConfig['enabledExtensions'])) {
                    $config['environments'][$env]['enabledExtensions'] = array_values(
                        array_diff($envConfig['enabledExtensions'], [$extensionName])
                    );
                }
            }
        }

        // Save JSON config
        $saveSuccess = self::saveExtensionsJson($config);

        if (!$saveSuccess) {
            return [
                'success' => false,
                'message' => "Failed to save configuration when disabling '$extensionName'"
            ];
        }

        return [
            'success' => true,
            'message' => "Extension '$extensionName' has been disabled successfully"
        ];
    }

    /**
     * Save configuration to file
     *
     * @param string $file Config file path
     * @param array $config Configuration array
     * @return bool Success status
     */
    public static function saveConfig(string $file, array $config): bool
    {
       // Start with the PHP opening tag
        $content = "<?php\n\nreturn [\n";

        // Process each top-level key
        foreach ($config as $key => $value) {
            $content .= "    '$key' => ";

            if (is_array($value)) {
                if (empty($value)) {
                    $content .= "[\n\n    ],\n";
                    continue;
                }

                $content .= "[\n";

                // Check if this is a simple list or an associative array
                $isNumericIndexed = array_keys($value) === range(0, count($value) - 1);

                if ($isNumericIndexed) {
                    // For simple lists, format each item on a new line
                    foreach ($value as $item) {
                        if (is_string($item)) {
                            $content .= "        '$item',\n";
                        } elseif (is_array($item)) {
                            // Handle nested arrays
                            $content .= self::formatNestedArray($item, 8);
                        } else {
                            $content .= "        $item,\n";
                        }
                    }
                } else {
                    // For associative arrays, use key => value format
                    foreach ($value as $subKey => $subValue) {
                        if (is_string($subKey)) {
                            $content .= "        '$subKey' => ";
                        } else {
                            $content .= "        $subKey => ";
                        }

                        if (is_string($subValue)) {
                            $content .= "'$subValue',\n";
                        } elseif (is_array($subValue)) {
                            // Handle nested arrays
                            $content .= self::formatNestedArray($subValue, 12);
                        } else {
                            $content .= "$subValue,\n";
                        }
                    }
                }

                $content .= "    ],\n";
            } elseif (is_string($value)) {
                $content .= "'$value',\n";
            } else {
                $content .= "$value,\n";
            }
        }

        $content .= "];\n";

        return file_put_contents($file, $content) !== false;
    }

    /**
     * Helper method to format nested arrays with proper indentation
     *
     * @param array $array The array to format
     * @param int $indentLevel Number of spaces to indent
     * @return string Formatted array string
     */
    private static function formatNestedArray(array $array, int $indentLevel): string
    {
        if (empty($array)) {
            return "[],\n";
        }

        $indent = str_repeat(' ', $indentLevel);
        $result = "[\n";

        foreach ($array as $key => $value) {
            $result .= $indent;

            if (is_string($key)) {
                $result .= "'$key' => ";
            } else {
                $result .= "$key => ";
            }

            if (is_array($value)) {
                $result .= self::formatNestedArray($value, $indentLevel + 4);
            } elseif (is_string($value)) {
                $result .= "'$value',\n";
            } else {
                $result .= "$value,\n";
            }
        }

        $result .= str_repeat(' ', $indentLevel - 4) . "],\n";
        return $result;
    }

    /**
     * Find extension by name
     *
     * @param string $extensionName Extension name
     * @return string|null Full class name or null if not found
     */
    public static function findExtension(string $extensionName, bool $checkFilesOnly = false): ?string
    {
        // Validate extension name format
        if (!self::isValidExtensionName($extensionName)) {
            return null;
        }

        // Check cache first
        $cacheKey = $extensionName . ($checkFilesOnly ? ':files' : ':full');
        if (isset(self::$extensionExistsCache[$cacheKey])) {
            return self::$extensionExistsCache[$cacheKey];
        }

        // Get extensions path
        $extensionsPath = config('services.extensions.extensions_dir');
        if (empty($extensionsPath)) {
            $extensionsPath = dirname(__DIR__, 2) . '/extensions/';
        }

        // Build expected extension directory and class file paths
        $extensionDir = rtrim($extensionsPath, '/') . '/' . $extensionName;
        $mainClassFile = $extensionDir . '/' . $extensionName . '.php';

        // Check if directory and main class file exist
        if (is_dir($extensionDir) && file_exists($mainClassFile)) {
            // Extension files exist
            $expectedClass = 'Glueful\\Extensions\\' . $extensionName . '\\' . $extensionName;

            // If we're only checking files, cache and return the expected class name
            if ($checkFilesOnly) {
                self::$extensionExistsCache[$cacheKey] = $expectedClass;
                return $expectedClass;
            }

            // If we need to check if the class is loaded/loadable,
            // we can try to load it or verify it exists
            if (class_exists($expectedClass)) {
                self::$extensionExistsCache[$cacheKey] = $expectedClass;
                return $expectedClass;
            }
        }

        // Cache null result and return
        self::$extensionExistsCache[$cacheKey] = null;
        return null;
    }

    /**
     * Get extension metadata
     *
     * @param string $extensionName Extension name
     * @param string $metadataKey Metadata key to retrieve
     * @param mixed $default Default value if not found
     * @return mixed Metadata value
     */
    public static function getExtensionMetadata(string $extensionName, string $metadataKey, $default = null)
    {
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return $default;
        }

        $reflection = new \ReflectionClass($extensionClass);
        $docComment = $reflection->getDocComment();

        if ($docComment) {
            preg_match('/@' . $metadataKey . '\s+(.*)\s*$/m', $docComment, $matches);
            return $matches[1] ?? $default;
        }

        return $default;
    }

    /**
     * Get the path to the extensions configuration file
     *
     * @return string Path to extensions.json
     */
    public static function getExtensionsConfigPath(): string
    {
        return config('services.extensions.config_file');
    }

    /** @var array|null Static cache for extensions config */
    private static ?array $configCache = null;

    /** @var string|null Cached config file path */
    private static ?string $configFilePath = null;

    /** @var int|null Cached config file modification time */
    private static ?int $configFileMtime = null;

    /** @var array Cache for extension metadata */
    private static array $metadataCache = [];

    /** @var array Cache for extension existence checks */
    private static array $extensionExistsCache = [];

    public static function loadExtensionsConfig(): array
    {
        $configFile = config('services.extensions.config_file');

        // Check if we have cached config and if file hasn't changed
        if (self::$configCache !== null && self::$configFilePath === $configFile) {
            // Verify file hasn't been modified (lightweight check)
            if (file_exists($configFile)) {
                $currentMtime = filemtime($configFile);
                if ($currentMtime === self::$configFileMtime) {
                    return self::$configCache;
                }
            }
        }

        // File doesn't exist, return default
        if (!file_exists($configFile)) {
            $defaultConfig = self::createDefaultExtensionsJson();
            self::cacheConfig($configFile, $defaultConfig, 0);
            return $defaultConfig;
        }

        // Load and parse config file
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in extensions config: ' . json_last_error_msg());
        }

        $config = $config ?: [];

        // Cache the result
        self::cacheConfig($configFile, $config, filemtime($configFile));
        return $config;
    }

    /**
     * Cache configuration data in memory
     */
    private static function cacheConfig(string $configFile, array $config, int $mtime): void
    {
        self::$configCache = $config;
        self::$configFilePath = $configFile;
        self::$configFileMtime = $mtime;
    }

    /**
     * Clear the configuration cache
     */
    public static function clearConfigCache(): void
    {
        self::$configCache = null;
        self::$configFilePath = null;
        self::$configFileMtime = null;
    }

    /**
     * Get cached extension metadata
     *
     * @param string $extensionName Extension name
     * @param string $extensionClass Extension class name
     * @return array|null Cached metadata or null if not cached
     */
    private static function getCachedMetadata(string $extensionName, string $extensionClass): ?array
    {
        $cacheKey = $extensionName . '::' . $extensionClass;
        return self::$metadataCache[$cacheKey] ?? null;
    }

    /**
     * Cache extension metadata
     *
     * @param string $extensionName Extension name
     * @param string $extensionClass Extension class name
     * @param array $metadata Metadata to cache
     */
    private static function cacheMetadata(string $extensionName, string $extensionClass, array $metadata): void
    {
        $cacheKey = $extensionName . '::' . $extensionClass;
        self::$metadataCache[$cacheKey] = $metadata;
    }

    /**
     * Get extension metadata with caching
     *
     * @param string $extensionName Extension name
     * @param string $extensionClass Extension class name
     * @return array|null Extension metadata
     */
    private static function getExtensionMetadataWithCaching(string $extensionName, string $extensionClass): ?array
    {
        // Check cache first
        $cached = self::getCachedMetadata($extensionName, $extensionClass);
        if ($cached !== null) {
            return $cached;
        }

        // Load metadata from extension class
        if (method_exists($extensionClass, 'getMetadata')) {
            try {
                $metadata = $extensionClass::getMetadata();
                if (is_array($metadata)) {
                    self::cacheMetadata($extensionName, $extensionClass, $metadata);
                    return $metadata;
                }
            } catch (\Exception $e) {
                // Log error but continue
                error_log("Failed to load metadata for extension {$extensionName}: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Clear all extension caches
     */
    public static function clearMetadataCache(): void
    {
        self::$metadataCache = [];
        self::$extensionExistsCache = [];
    }

    /**
     * Clear all caches
     */
    public static function clearAllCaches(): void
    {
        self::clearConfigCache();
        self::clearMetadataCache();
    }

    /**
     * Check if an extension exists
     *
     * @param string $extensionName Extension name to check
     * @return bool True if extension exists
     */
    public static function extensionExists(string $extensionName): bool
    {
        return self::findExtension($extensionName) !== null;
    }

    public static function getConfigPath(): string
    {
        return config('services.extensions.config_file');
    }

    /**
     * Create default extensions.json configuration
     *
     * @return array Default configuration structure
     */
    private static function createDefaultExtensionsJson(): array
    {
        $defaultConfig = [
            'extensions' => [],
            'environments' => [
                'development' => [
                    'enabledExtensions' => []
                ],
                'production' => [
                    'enabledExtensions' => []
                ]
            ]
        ];

        $configFile = config('services.extensions.config_file');
        self::saveExtensionsJson($defaultConfig, $configFile);

        return $defaultConfig;
    }

    /**
     * Save extensions configuration to JSON file
     *
     * @param array $config Configuration to save
     * @param string|null $file Optional file path, uses default if not provided
     * @return bool Success status
     */
    private static function saveExtensionsJson(array $config, ?string $file = null): bool
    {
        $file = $file ?: config('services.extensions.config_file');

        // Ensure directory exists
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($file, $json) !== false;
    }


    /**
     * Check dependencies when enabling an extension
     *
     * Verifies that all dependencies required by the extension are met.
     *
     * @param string $extensionName Extension name to check
     * @return array Result with success status and messages
     */
    private static function checkDependenciesForEnable(string $extensionName): array
    {
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' not found"
            ];
        }

        // Get dependencies from extension metadata
        $dependencies = [];
        try {
            $dependencies = $extensionClass::getDependencies();
        } catch (\Throwable $e) {
            self::debug("Error getting dependencies for $extensionName: " . $e->getMessage());
        }

        if (empty($dependencies)) {
            return ['success' => true, 'message' => 'No dependencies required'];
        }

        // Check each dependency
        $missingDependencies = [];
        $enabledExtensions = self::getEnabledExtensions();

        foreach ($dependencies as $dependency) {
            // Check if dependency exists
            $dependencyClass = self::findExtension($dependency);
            if (!$dependencyClass) {
                $missingDependencies[] = "$dependency (not installed)";
                continue;
            }

            // Check if dependency is enabled
            if (!in_array($dependency, $enabledExtensions)) {
                $missingDependencies[] = "$dependency (disabled)";
            }
        }

        if (!empty($missingDependencies)) {
            return [
                'success' => false,
                'message' => "Dependencies not met for extension '$extensionName'",
                'details' => [
                    'missing_dependencies' => $missingDependencies,
                    'required_dependencies' => $dependencies
                ]
            ];
        }

        return ['success' => true, 'message' => 'All dependencies are met'];
    }

    /**
     * Check dependencies when disabling an extension
     *
     * Verifies that no enabled extensions depend on the one being disabled.
     *
     * @param string $extensionName Extension name to check
     * @return array Result with success status and messages
     */
    public static function checkDependenciesForDisable(string $extensionName): array
    {
        $enabledExtensions = self::getEnabledExtensions();
        $dependentExtensions = [];

        // Check each enabled extension for dependencies on the one being disabled
        foreach ($enabledExtensions as $enabledExt) {
            if ($enabledExt === $extensionName) {
                continue; // Skip self
            }

            $extensionClass = self::findExtension($enabledExt);
            if (!$extensionClass) {
                continue; // Skip if not found
            }

            try {
                $dependencies = $extensionClass::getDependencies();
                if (in_array($extensionName, $dependencies)) {
                    $dependentExtensions[] = $enabledExt;
                }
            } catch (\Throwable $e) {
                self::debug("Error checking dependencies for $enabledExt: " . $e->getMessage());
            }
        }

        if (!empty($dependentExtensions)) {
            return [
                'success' => false,
                'message' => "Cannot disable extension '$extensionName' because other extensions depend on it",
                'details' => [
                    'dependent_extensions' => $dependentExtensions
                ]
            ];
        }

        return ['success' => true, 'message' => 'No dependency conflicts found'];
    }

    /**
     * Build a dependency graph for all extensions
     *
     * Creates a directed graph showing the dependency relationships
     * between all installed extensions. Includes visual attributes
     * for frontend rendering, conflict detection, and metadata.
     *
     * @return array The dependency graph with visual attributes
     */
    public static function buildDependencyGraph(): array
    {
        $graph = [
            'nodes' => [],
            'edges' => [],
            'metadata' => [
                'conflicts' => [],
                'unresolved' => [],
                'stats' => [
                    'total' => 0,
                    'enabled' => 0,
                    'core' => 0,
                    'optional' => 0
                ]
            ]
        ];

        $extensions = self::getLoadedExtensions();
        $enabledExtensions = self::getEnabledExtensions();
        $coreExtensions = self::getCoreExtensions();
        $optionalExtensions = self::getOptionalExtensions();

        // Track all dependencies for conflict detection
        $allDependencies = [];
        $existingExtensions = [];

        // Create nodes for all extensions with visual attributes
        foreach ($extensions as $extensionClass) {
            $reflection = new \ReflectionClass($extensionClass);
            $shortName = $reflection->getShortName();
            $existingExtensions[] = $shortName;

            try {
                $metadata = $extensionClass::getMetadata();

                // Determine if core or optional
                $extensionType = in_array($shortName, $coreExtensions) ? 'core' : 'optional';
                $enabled = in_array($shortName, $enabledExtensions);

                // Update stats
                $graph['metadata']['stats']['total']++;
                if ($enabled) {
                    $graph['metadata']['stats']['enabled']++;
                }

                if ($extensionType === 'core') {
                    $graph['metadata']['stats']['core']++;
                } else {
                    $graph['metadata']['stats']['optional']++;
                }

                // Set visual attributes based on type and status
                $nodeSize = $extensionType === 'core' ? 16 : 12;
                $nodeColor = $extensionType === 'core' ? '#ff6b6b' : '#48dbfb';
                if (!$enabled) {
                    $nodeColor = '#d2dae2'; // Muted color for disabled extensions
                    $nodeSize -= 2; // Slightly smaller
                }

                $graph['nodes'][] = [
                    'id' => $shortName,
                    'name' => $metadata['name'] ?? $shortName,
                    'enabled' => $enabled,
                    'type' => $extensionType,
                    'version' => $metadata['version'] ?? '1.0.0',
                    'description' => $metadata['description'] ?? '',
                    // Visual attributes for UI rendering
                    'size' => $nodeSize,
                    'color' => $nodeColor,
                    'shape' => $extensionType === 'core' ? 'diamond' : 'circle',
                    'borderWidth' => $enabled ? 2 : 1,
                    'borderColor' => $enabled ? '#1e272e' : '#d2dae2',
                    'font' => [
                        'color' => $enabled ? '#000000' : '#808e9b'
                    ]
                ];

                // Get dependencies and create edges
                $dependencies = $extensionClass::getDependencies();
                $allDependencies[$shortName] = $dependencies;

                foreach ($dependencies as $dependency) {
                    // Edge visual attributes
                    $edgeWidth = 1;
                    $edgeColor = '#a5b1c2';
                    $edgeDashed = false;

                    // Check if dependency exists and is enabled
                    if (!self::findExtension($dependency)) {
                        // Dependency doesn't exist in system
                        $graph['metadata']['unresolved'][] = [
                            'extension' => $shortName,
                            'missing' => $dependency
                        ];
                        $edgeColor = '#ff4757'; // Red for missing dependencies
                        $edgeDashed = true;
                    } elseif (!in_array($dependency, $enabledExtensions) && in_array($shortName, $enabledExtensions)) {
                        // Extension is enabled but its dependency is disabled
                        $graph['metadata']['conflicts'][] = [
                            'extension' => $shortName,
                            'disabled_dependency' => $dependency
                        ];
                        $edgeColor = '#ffa502'; // Orange for disabled dependencies
                    }

                    // If both extensions are core, make the connection stronger
                    if (in_array($shortName, $coreExtensions) && in_array($dependency, $coreExtensions)) {
                        $edgeWidth = 2;
                    }

                    $graph['edges'][] = [
                        'from' => $shortName,
                        'to' => $dependency,
                        'type' => 'depends_on',
                        // Visual attributes
                        'width' => $edgeWidth,
                        'color' => $edgeColor,
                        'dashed' => $edgeDashed,
                        'arrows' => 'to'
                    ];
                }
            } catch (\Throwable $e) {
                self::debug("Error building dependency graph for $shortName: " . $e->getMessage());
            }
        }

        return $graph;
    }

    /**
     * Get all enabled extensions from config
     *
     * @return array List of enabled extension names
     */
    public static function getEnabledExtensions(): array
    {
        $config = self::loadExtensionsConfig();
        $enabled = [];

        // Get base enabled extensions
        if (isset($config['extensions'])) {
            foreach ($config['extensions'] as $name => $ext) {
                if ($ext['enabled'] === true) {
                    $enabled[] = $name;
                }
            }
        }

        // Check for environment-specific filtering
        $env = config('app.environment', 'production');
        $envMapping = config('services.extensions.environments', []);
        $mappedEnv = $envMapping[$env] ?? $env;

        if (isset($config['environments'][$mappedEnv]['enabledExtensions'])) {
            // Only include extensions that are both individually enabled AND listed in environment config
            $envEnabled = $config['environments'][$mappedEnv]['enabledExtensions'];
            return array_intersect($enabled, $envEnabled);
        }

        return $enabled;
    }

    /**
     * Get environment-specific configuration for extensions
     *
     * Retrieves configuration for extensions that may vary by environment.
     * Supports dev, staging, and production environment overrides.
     *
     * @param string|null $environment Environment name (dev, staging, production)
     * @return array Environment-specific extension configuration
     */
    public static function getEnvironmentConfig(?string $environment = null): array
    {
        // If no environment specified, detect from app config
        if ($environment === null) {
            $environment = config('app.environment', 'production');
        }

        $configFile = self::getConfigPath();
        if (!file_exists($configFile)) {
            return [];
        }

        $config = include $configFile;

        // Check for environment-specific settings
        $envKey = 'environments';
        if (isset($config[$envKey]) && isset($config[$envKey][$environment])) {
            return $config[$envKey][$environment];
        }

        // Return empty array if no environment specific config exists
        return [];
    }

    /**
     * Get extensions enabled for specific environment
     *
     * @param string|null $environment Environment name (dev, staging, production)
     * @param bool $includeForcedCoreExtensions Whether to include all core extensions in production
     * @return array List of extensions enabled for the environment
     */
    public static function getEnabledExtensionsForEnvironment(
        ?string $environment = null,
        bool $includeForcedCoreExtensions = true
    ): array {
        // If no environment specified, detect from app config
        if ($environment === null) {
            $environment = config('app.environment', 'production');
        }

        // Get default enabled extensions
        $enabledExtensions = self::getEnabledExtensions();

        // Check for environment-specific overrides
        $envConfig = self::getEnvironmentConfig($environment);

        if (!empty($envConfig) && isset($envConfig['enabled'])) {
            // Environment config completely overrides the default if specified
            $enabledExtensions = $envConfig['enabled'];
        }

        // For production environment, ensure all core extensions are enabled
        if ($includeForcedCoreExtensions && $environment === 'production') {
            $coreExtensions = self::getCoreExtensions();
            $enabledExtensions = array_unique(array_merge($enabledExtensions, $coreExtensions));
        }

        return $enabledExtensions;
    }

    /**
     * Check health status of all enabled extensions
     *
     * Verifies that all enabled extensions are functioning properly.
     *
     * @return array Health status information for all extensions
     */
    public static function checkExtensionsHealth(): array
    {
        $results = [
            'healthy' => true,
            'extensions' => [],
            'issues_found' => 0
        ];

        $enabledExtensions = self::getEnabledExtensions();

        foreach ($enabledExtensions as $extensionName) {
            $extensionHealth = self::checkExtensionHealth($extensionName);
            $results['extensions'][$extensionName] = $extensionHealth;

            // If any extension is unhealthy, mark the whole system as unhealthy
            if (!$extensionHealth['healthy']) {
                $results['healthy'] = false;
                $results['issues_found'] += count($extensionHealth['issues']);
            }
        }

        return $results;
    }

    /**
     * Check health status of a specific extension
     *
     * @param string $extensionName Extension name
     * @return array Health status information for the extension
     */
    public static function checkExtensionHealth(string $extensionName): array
    {
        $extensionClass = self::findExtension($extensionName);

        // Default health status if extension not found
        if (!$extensionClass) {
            return [
                'healthy' => false,
                'issues' => ["Extension '$extensionName' not found"],
                'metrics' => []
            ];
        }

        try {
            // Try to call checkHealth if the method exists
            if (method_exists($extensionClass, 'checkHealth')) {
                return $extensionClass::checkHealth();
            }

            // Check if required files exist
            $reflection = new \ReflectionClass($extensionClass);
            $classPath = $reflection->getFileName();
            $extensionDir = dirname($classPath);

            $issues = [];

            // Check for common required files
            $configFile = $extensionDir . '/config.php';
            if (!file_exists($configFile)) {
                $issues[] = "Missing configuration file: config.php";
            }

            // Check for README.md (good practice)
            $readmeFile = $extensionDir . '/README.md';
            if (!file_exists($readmeFile)) {
                $issues[] = "Missing documentation: README.md";
            }

            // Basic health check passed if no issues found
            return [
                'healthy' => empty($issues),
                'issues' => $issues,
                'metrics' => [
                    'files_count' => count(glob($extensionDir . '/*.php')),
                    'last_modified' => filemtime($classPath)
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'issues' => ["Error checking extension health: " . $e->getMessage()],
                'metrics' => []
            ];
        }
    }

    /**
     * Get resource usage metrics for extensions
     *
     * Collects performance metrics for enabled extensions including:
     * - Memory usage
     * - Execution time
     * - Database queries
     * - Cache usage
     *
     * @return array Resource usage metrics by extension
     */
    public static function getExtensionMetrics(): array
    {
        $metrics = [
            'total_memory_usage' => 0,
            'total_execution_time' => 0,
            'extensions' => []
        ];

        $enabledExtensions = self::getEnabledExtensions();

        foreach ($enabledExtensions as $extensionName) {
            $extensionClass = self::findExtension($extensionName);
            if (!$extensionClass) {
                continue;
            }

            try {
                // Get health data which includes metrics
                $healthData = self::checkExtensionHealth($extensionName);
                $extensionMetrics = $healthData['metrics'] ?? [];

                // Add to metrics collection
                $metrics['extensions'][$extensionName] = $extensionMetrics;

                // Add to totals
                $metrics['total_memory_usage'] += ($extensionMetrics['memory_usage'] ?? 0);
                $metrics['total_execution_time'] += ($extensionMetrics['execution_time'] ?? 0);
            } catch (\Throwable $e) {
                error_log("Error collecting metrics for extension $extensionName: " . $e->getMessage());
            }
        }

        return $metrics;
    }

    /**
     * Install an extension from URL or archive file
     *
     * @param string $source Source URL or file path
     * @param string $targetName Target extension name (optional)
     * @return array Result with success status and messages
     */
    public static function installExtension(string $source, string $targetName = ''): array
    {
        // Validate source URL or file path
        if (!filter_var($source, FILTER_VALIDATE_URL) && !file_exists($source)) {
            return [
                'success' => false,
                'message' => "Invalid source URL or file path: $source"
            ];
        }

        // Validate .gluex extension
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        if ($extension !== 'gluex') {
            return [
                'success' => false,
                'message' => "Invalid file format. Expected .gluex extension, got .$extension"
            ];
        }

        // If no target name provided, try to derive it from the source
        if (empty($targetName)) {
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                // Extract from URL
                $urlPath = parse_url($source, PHP_URL_PATH);
                $fileName = pathinfo($urlPath, PATHINFO_FILENAME);
                $targetName = self::extractExtensionNameFromGluex($fileName);
            } else {
                // Extract from file path
                $fileName = pathinfo($source, PATHINFO_FILENAME);
                $targetName = self::extractExtensionNameFromGluex($fileName);
            }
        }

        // Check if the target name is valid
        if (!self::isValidExtensionName($targetName)) {
            return [
                'success' => false,
                'message' => "Invalid extension name: $targetName. Use PascalCase naming (e.g. MyExtension)"
            ];
        }

        // Determine target directory
        $extensionDir = config('app.paths.project_extensions') . $targetName;

        if (is_dir($extensionDir)) {
            return [
                'success' => false,
                'message' => "Extension directory already exists: $extensionDir"
            ];
        }

        // Download or copy the extension archive
        $archiveFile = self::downloadOrCopyArchive($source);

        if (!$archiveFile) {
            return [
                'success' => false,
                'message' => "Failed to download or copy archive from: $source"
            ];
        }

        // Extract the archive
        $extractResult = self::extractArchive($archiveFile, $extensionDir);

        // Clean up temporary file
        @unlink($archiveFile);

        if (!$extractResult) {
            return [
                'success' => false,
                'message' => "Failed to extract archive"
            ];
        }

        // Check if the main class file exists
        $mainClassFile = "$extensionDir/$targetName.php";
        if (!file_exists($mainClassFile)) {
            // Try to find any PHP file that might be the main extension file
            $phpFiles = glob("$extensionDir/*.php");

            if (empty($phpFiles)) {
                return [
                    'success' => false,
                    'message' => "No PHP files found in the extracted archive."
                ];
            }

            // If we find a single PHP file, assume it's the main file and rename it
            if (count($phpFiles) === 1) {
                $existingFile = $phpFiles[0];
                if (!rename($existingFile, $mainClassFile)) {
                    return [
                        'success' => false,
                        'message' => "Failed to rename main extension file to $targetName.php"
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => "Multiple PHP files found but none matches the expected name $targetName.php"
                ];
            }
        }

        // Verify installation and update extensions.json
        $installResult = self::postInstallSetup($targetName, $extensionDir);
        if (!$installResult['success']) {
            return $installResult;
        }

        // Force reload the extensions to include the newly installed one
        self::$loadedExtensions = [];
        self::loadExtensions();

        return [
            'success' => true,
            'message' => "Extension '$targetName' installed successfully",
            'name' => $targetName,
            'version' => $installResult['version'] ?? '1.0.0'
        ];
    }

    /**
     * Extract extension name from .gluex filename, removing version
     *
     * Examples:
     * - "SocialLogin-0.18.0" -> "SocialLogin"
     * - "EmailNotification-1.2.3" -> "EmailNotification"
     * - "MyExtension-v2.0.0" -> "MyExtension"
     *
     * @param string $fileName Filename without extension
     * @return string Clean extension name
     */
    private static function extractExtensionNameFromGluex(string $fileName): string
    {
        // Remove common version patterns:
        // - ExtensionName-1.2.3
        // - ExtensionName-v1.2.3
        // - ExtensionName_1.2.3
        $patterns = [
            '/^(.+)-v?\d+\.\d+\.\d+.*$/',  // Remove version like -1.2.3 or -v1.2.3
            '/^(.+)_v?\d+\.\d+\.\d+.*$/',  // Remove version like _1.2.3 or _v1.2.3
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fileName, $matches)) {
                return $matches[1];
            }
        }

        // If no version pattern found, return the original filename
        return $fileName;
    }

    /**
     * Post-installation setup and validation
     *
     * @param string $extensionName Extension name
     * @param string $extensionDir Extension directory path
     * @return array Result with success status and metadata
     */
    private static function postInstallSetup(string $extensionName, string $extensionDir): array
    {
        // Check for extension.json file
        $extensionJsonPath = "$extensionDir/extension.json";
        $version = '1.0.0';
        $installPath = "extensions/$extensionName";

        if (file_exists($extensionJsonPath)) {
            $extensionJson = json_decode(file_get_contents($extensionJsonPath), true);
            if ($extensionJson && isset($extensionJson['version'])) {
                $version = $extensionJson['version'];
            }
        }

        // Update extensions.json configuration
        $config = self::loadExtensionsConfig();

        // Create full extension entry for schema v2.0
        $extensionEntry = self::createExtensionConfigEntry($extensionName, $version, $installPath);
        $config['extensions'][$extensionName] = $extensionEntry;

        // Ensure schema version is set
        if (!isset($config['schema_version'])) {
            $config['schema_version'] = '2.0';
        }

        // Save updated configuration
        if (!self::saveExtensionsJson($config)) {
            return [
                'success' => false,
                'message' => "Extension files installed but failed to update configuration"
            ];
        }

        return [
            'success' => true,
            'version' => $version
        ];
    }

    /**
     * Uninstall an extension
     *
     * @param string $extensionName Extension name to uninstall
     * @param bool $removeFiles Whether to remove extension files (default: true)
     * @return array Result with success status and messages
     */
    public static function uninstallExtension(string $extensionName, bool $removeFiles = true): array
    {
        // Check if extension exists
        if (!self::extensionExists($extensionName)) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' is not installed"
            ];
        }

        // Disable extension first if it's enabled
        if (self::isExtensionEnabled($extensionName)) {
            $disableResult = self::disableExtension($extensionName);
            if (!$disableResult['success']) {
                return [
                    'success' => false,
                    'message' => "Failed to disable extension before uninstall: " . $disableResult['message']
                ];
            }
        }

        // Remove from extensions.json
        $config = self::loadExtensionsConfig();
        if (isset($config['extensions'][$extensionName])) {
            unset($config['extensions'][$extensionName]);
        }

        // Remove from all environment configurations
        if (isset($config['environments'])) {
            foreach ($config['environments'] as $env => $envConfig) {
                if (isset($envConfig['enabledExtensions'])) {
                    $config['environments'][$env]['enabledExtensions'] = array_values(
                        array_diff($envConfig['enabledExtensions'], [$extensionName])
                    );
                }
            }
        }

        // Save updated configuration
        if (!self::saveExtensionsJson($config)) {
            return [
                'success' => false,
                'message' => "Failed to update configuration during uninstall"
            ];
        }

        // Remove files if requested
        if ($removeFiles) {
            $extensionDir = config('app.paths.project_extensions') . $extensionName;
            if (is_dir($extensionDir)) {
                if (!self::removeDirectory($extensionDir)) {
                    return [
                        'success' => false,
                        'message' => "Extension removed from configuration but failed to delete files from " .
                                    $extensionDir
                    ];
                }
            }
        }

        // Reload extensions
        self::$loadedExtensions = [];
        self::loadExtensions();

        return [
            'success' => true,
            'message' => "Extension '$extensionName' uninstalled successfully"
        ];
    }

    /**
     * Recursively remove a directory and its contents
     *
     * @param string $dir Directory to remove
     * @return bool Success status
     */
    private static function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                if (!self::removeDirectory($path)) {
                    return false;
                }
            } else {
                if (!unlink($path)) {
                    return false;
                }
            }
        }

        return rmdir($dir);
    }

    /**
     * Download or copy an archive file
     *
     * @param string $source Source URL or file path
     * @return string|false Path to the temporary archive file or false on failure
     */
    private static function downloadOrCopyArchive(string $source): string|false
    {
        // Create temporary directory if it doesn't exist
        $tempDir = sys_get_temp_dir() . '/glueful_extensions';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true)) {
            self::debug("Failed to create temporary directory: $tempDir");
            return false;
        }

        // Generate a temporary file name
        $tempFile = $tempDir . '/' . md5($source . time()) . '.gluex';

        // Handle URL or local file
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            // It's a URL, download it
            $ch = curl_init($source);
            $fp = fopen($tempFile, 'wb');

            if (!$ch || !$fp) {
                self::debug("Failed to initialize download");
                return false;
            }

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            if (!curl_exec($ch)) {
                self::debug("Download failed: " . curl_error($ch));
                curl_close($ch);
                fclose($fp);
                return false;
            }

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($statusCode !== 200) {
                self::debug("Download failed with HTTP status code: $statusCode");
                return false;
            }
        } else {
            // It's a local file, copy it
            if (!copy($source, $tempFile)) {
                self::debug("Failed to copy file");
                return false;
            }
        }

        return $tempFile;
    }

    /**
     * Extract an archive to the destination directory
     *
     * @param string $archiveFile Path to the archive file
     * @param string $destDir Destination directory
     * @return bool Success status
     */
    private static function extractArchive(string $archiveFile, string $destDir): bool
    {
        // Ensure destination directory exists
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
            self::debug("Failed to create destination directory: $destDir");
            return false;
        }

        // Extract using ZipArchive
        $zip = new \ZipArchive();
        if ($zip->open($archiveFile) !== true) {
            self::debug("Failed to open archive: $archiveFile");
            return false;
        }

        // Check if the archive has a single root folder
        $rootFolderName = null;
        $hasSingleRoot = self::hasSingleRootFolder($zip, $rootFolderName);

        // Extract the archive
        if (!$zip->extractTo($hasSingleRoot ? dirname($destDir) : $destDir)) {
            self::debug("Failed to extract archive");
            $zip->close();
            return false;
        }

        $zip->close();

        // If the archive had a single root folder, rename it to the target name
        if ($hasSingleRoot && $rootFolderName) {
            $extractedPath = dirname($destDir) . '/' . $rootFolderName;
            if (is_dir($extractedPath) && $extractedPath !== $destDir) {
                // Clean up any version numbers from the extracted folder name
                $cleanRootName = self::extractExtensionNameFromGluex($rootFolderName);
                $targetBaseName = basename($destDir);
                // Only rename if the clean name doesn't match the target
                if ($cleanRootName !== $targetBaseName || $extractedPath !== $destDir) {
                    if (!rename($extractedPath, $destDir)) {
                        self::debug("Failed to rename extracted folder from '$rootFolderName' to target name");
                        return false;
                    }
                    self::debug("Renamed extracted folder from '$rootFolderName' to '$targetBaseName'");
                }
            }
        }

        return true;
    }

    /**
     * Check if a zip archive has a single root folder
     *
     * @param \ZipArchive $zip Zip archive
     * @param string|null &$rootFolderName Variable to store the root folder name
     * @return bool True if the archive has a single root folder
     */
    private static function hasSingleRootFolder(\ZipArchive $zip, ?string &$rootFolderName): bool
    {
        $rootFolders = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip directories that are not at the root level
            if (substr_count($name, '/') > 1) {
                continue;
            }

            // If it's a root-level file
            if (strpos($name, '/') === false) {
                return false; // Archive doesn't have a single root folder
            }

            // Extract the root folder name
            $rootFolder = substr($name, 0, strpos($name, '/'));
            if (!empty($rootFolder) && !in_array($rootFolder, $rootFolders)) {
                $rootFolders[] = $rootFolder;
            }
        }

        if (count($rootFolders) === 1) {
            $rootFolderName = $rootFolders[0];
            return true;
        }

        return false;
    }

    /**
     * Sanitize a string to a valid extension name
     *
     * @param string $name Input name
     * @return string Sanitized extension name
     */
    public static function sanitizeExtensionName(string $name): string
    {
        // Remove non-alphanumeric characters
        $name = preg_replace('/[^a-zA-Z0-9]/', '', $name);

        // Ensure it starts with uppercase letter
        $name = ucfirst($name);

        return $name;
    }

    /**
     * Check if an extension name is valid
     *
     * @param string $name Extension name
     * @return bool True if valid
     */
    public static function isValidExtensionName(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name) === 1;
    }

    /**
     * Validate an extension structure and dependencies
     *
     * @param string $extensionName Extension name
     * @return array Result with success status and messages
     */
    /**
     * Validate an extension structure and dependencies
     *
     * Comprehensive validation includes:
     * - Structure validation (required files and methods)
     * - Dependency validation (extension dependencies)
     * - Metadata validation (completeness and correctness)
     * - Coding standards validation (PSR-12 compliance)
     * - Security validation (potential vulnerabilities)
     * - Performance assessment (impact on system resources)
     *
     * @param string $extensionName Extension name
     * @return array Result with success status and validation results
     */
    public static function validateExtension(string $extensionName): array
    {
        $extensionClass = self::findExtension($extensionName);

        if (!$extensionClass) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' not found"
            ];
        }

        $reflection = new \ReflectionClass($extensionClass);

        // Validate structure
        $structureValidation = self::validateExtensionStructure($reflection);

        // Validate dependencies
        $dependencyValidation = self::validateExtensionDependencies($reflection);

        // Validate metadata
        $metadataValidation = self::validateExtensionMetadata($extensionName);

        // Validate coding standards
        $codingStandardsValidation = self::validateCodingStandards($extensionName);

        // Validate security
        $securityValidation = self::validateExtensionSecurity($extensionName);

        // Assess performance impact
        $performanceAssessment = self::assessPerformanceImpact($extensionName);

        // Combine results
        $result = [
            'success' => $structureValidation['success'] &&
                        $dependencyValidation['success'] &&
                        $metadataValidation['success'] &&
                        $codingStandardsValidation['success'] &&
                        $securityValidation['success'],
            'name' => $extensionName,
            'structureValidation' => $structureValidation,
            'dependencyValidation' => $dependencyValidation,
            'metadataValidation' => $metadataValidation,
            'codingStandardsValidation' => $codingStandardsValidation,
            'securityValidation' => $securityValidation,
            'performanceAssessment' => $performanceAssessment
        ];

        if (!$result['success']) {
            $result['message'] = "Validation failed for extension '$extensionName'";
        } else {
            $result['message'] = "Extension '$extensionName' passed validation";
        }

        return $result;
    }

    /**
     * Validate extension structure
     *
     * @param \ReflectionClass $reflection Extension class reflection
     * @return array Result with success status and messages
     */
    public static function validateExtensionStructure(\ReflectionClass $reflection): array
    {
        $extensionName = $reflection->getShortName();
        $extensionDir = dirname($reflection->getFileName());
        $issues = [];
        $warnings = [];

        // Required files
        $requiredFiles = [
            "$extensionName.php" => "Main extension class",
            "README.md" => "Documentation",
        ];

        // Optional but recommended files
        $recommendedFiles = [
            "config.php" => "Configuration file",
            "routes.php" => "Routes definition",
        ];

        // Check required files
        $hasAllRequired = true;
        foreach ($requiredFiles as $file => $description) {
            if (!file_exists("$extensionDir/$file")) {
                $issues[] = "Missing required file: $file - $description";
                $hasAllRequired = false;
            }
        }

        // Check recommended files
        foreach ($recommendedFiles as $file => $description) {
            if (!file_exists("$extensionDir/$file")) {
                $warnings[] = "Missing recommended file: $file - $description";
            }
        }

        // Check class structure
        $requiredMethods = [
            'initialize' => 'Extension initialization',
            'registerServices' => 'Service registration',
            'registerMiddleware' => 'Middleware registration'
        ];

        foreach ($requiredMethods as $method => $description) {
            if (!$reflection->hasMethod($method)) {
                $issues[] = "Missing required method: $method() - $description";
                $hasAllRequired = false;
            }
        }

        // Metadata check
        $metadataTags = [
            'description' => self::getExtensionMetadata($extensionName, 'description'),
            'version' => self::getExtensionMetadata($extensionName, 'version'),
            'author' => self::getExtensionMetadata($extensionName, 'author')
        ];

        foreach ($metadataTags as $tag => $value) {
            if (empty($value)) {
                $warnings[] = "Missing @$tag tag in class docblock";
            }
        }

        return [
            'success' => $hasAllRequired,
            'issues' => $issues,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate extension dependencies
     *
     * @param \ReflectionClass $reflection Extension class reflection
     * @return array Result with success status and messages
     */
    public static function validateExtensionDependencies(\ReflectionClass $reflection): array
    {
        $extensionClass = $reflection->getName();
        $extensionName = $reflection->getShortName();
        $enabledExtensions = self::getEnabledExtensions();
        $issues = [];
        $dependencies = [];

        try {
            // Get dependencies from the extension
            if ($reflection->hasMethod('getDependencies') && class_exists($extensionClass)) {
                $dependencies = call_user_func([$extensionClass, 'getDependencies']);
            }

            // Check if the extension implements getMetadata() method
            if ($reflection->hasMethod('getMetadata') && class_exists($extensionClass)) {
                $metadata = call_user_func([$extensionClass, 'getMetadata']);
                if (isset($metadata['requires']) && isset($metadata['requires']['extensions'])) {
                    $dependencies = array_merge($dependencies, $metadata['requires']['extensions']);
                }
            }

            // Remove duplicates
            $dependencies = array_unique($dependencies);

            if (!empty($dependencies)) {
                foreach ($dependencies as $dependency) {
                    // Check if dependency exists
                    $dependencyClass = self::findExtension($dependency);

                    if (!$dependencyClass) {
                        $issues[] = "Dependency not found: $dependency";
                    } elseif (!in_array($dependency, $enabledExtensions)) {
                        $issues[] = "Dependency not enabled: $dependency";
                    }
                }
            }

            // Check if other extensions depend on this one
            $dependentExtensions = [];
            foreach (self::$loadedExtensions as $otherExtension) {
                if ($otherExtension === $extensionClass) {
                    continue; // Skip self
                }

                $otherReflection = new \ReflectionClass($otherExtension);
                $otherName = $otherReflection->getShortName();

                // Only check enabled extensions
                if (!in_array($otherName, $enabledExtensions)) {
                    continue;
                }

                try {
                    // Check if the extension implements getDependencies() method
                    if ($otherReflection->hasMethod('getDependencies')) {
                        $otherDependencies = $otherExtension::getDependencies();
                        if (in_array($extensionName, $otherDependencies)) {
                            $dependentExtensions[] = $otherName;
                        }
                    }

                    // Check if the extension implements getMetadata() method
                    if ($otherReflection->hasMethod('getMetadata')) {
                        $otherMetadata = $otherExtension::getMetadata();
                        if (
                            isset($otherMetadata['requires']) &&
                            isset($otherMetadata['requires']['extensions']) &&
                            in_array($extensionName, $otherMetadata['requires']['extensions'])
                        ) {
                            if (!in_array($otherName, $dependentExtensions)) {
                                $dependentExtensions[] = $otherName;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip extensions with errors
                    self::debug("Error checking dependencies for $otherName: " . $e->getMessage());
                }
            }

            return [
                'success' => empty($issues),
                'issues' => $issues,
                'dependencies' => $dependencies,
                'dependentExtensions' => $dependentExtensions
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'issues' => ["Error validating dependencies: " . $e->getMessage()],
                'dependencies' => [],
                'dependentExtensions' => []
            ];
        }
    }

    /**
     * Validate extension metadata
     *
     * Checks that an extension's metadata adheres to the Glueful Extension Metadata Standard.
     *
     * @param string $extensionName Extension name
     * @return array Validation result with success status and messages
     */
    public static function validateExtensionMetadata(string $extensionName): array
    {
        $extensionClass = self::findExtension($extensionName);

        if (!$extensionClass) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' not found"
            ];
        }

        try {
            // Get metadata
            $metadata = $extensionClass::getMetadata();
            $issues = [];
            $warnings = [];

            // Check required fields
            $requiredFields = [
                'name' => 'string',
                'description' => 'string',
                'version' => 'string',
                'author' => 'string',
                'requires' => 'array'
            ];

            foreach ($requiredFields as $field => $type) {
                if (!isset($metadata[$field])) {
                    $issues[] = "Missing required field: '$field'";
                } elseif (gettype($metadata[$field]) !== $type) {
                    $issues[] = "Field '$field' must be of type $type";
                }
            }

            // Validate version format (semver)
            if (isset($metadata['version']) && !preg_match('/^\d+\.\d+\.\d+$/', $metadata['version'])) {
                $issues[] = "Version must follow semantic versioning (X.Y.Z)";
            }

            // Validate requires structure
            if (isset($metadata['requires'])) {
                $requiresFields = [
                    'glueful' => 'Required Glueful version constraint',
                    'php' => 'Required PHP version constraint'
                ];

                foreach ($requiresFields as $field => $description) {
                    if (!isset($metadata['requires'][$field])) {
                        $warnings[] = "Missing recommended requires field: $field ($description)";
                    }
                }

                // Validate extensions dependency array
                if (!isset($metadata['requires']['extensions']) || !is_array($metadata['requires']['extensions'])) {
                    $warnings[] = "Missing or invalid 'extensions' array in 'requires'";
                }
            }

            // Validate optional fields if present
            $optionalFields = [
                'homepage' => ['type' => 'string', 'validate' => 'url'],
                'documentation' => ['type' => 'string', 'validate' => 'url'],
                'license' => ['type' => 'string'],
                'keywords' => ['type' => 'array'],
                'category' => ['type' => 'string'],
                'screenshots' => ['type' => 'array'],
                'features' => ['type' => 'array'],
                'compatibility' => ['type' => 'array'],
                'settings' => ['type' => 'array'],
                'support' => ['type' => 'array'],
                'changelog' => ['type' => 'array']
            ];

            foreach ($optionalFields as $field => $rules) {
                if (isset($metadata[$field])) {
                    if (gettype($metadata[$field]) !== $rules['type']) {
                        $warnings[] = "Field '$field' must be of type {$rules['type']}";
                    }

                    // URL validation
                    if (
                        isset($rules['validate'])
                        && $rules['validate'] === 'url'
                        && !filter_var($metadata[$field], FILTER_VALIDATE_URL)
                    ) {
                        $warnings[] = "Field '$field' must be a valid URL";
                    }
                }
            }

            // Validate screenshots if present
            if (isset($metadata['screenshots']) && is_array($metadata['screenshots'])) {
                foreach ($metadata['screenshots'] as $index => $screenshot) {
                    if (!isset($screenshot['title'])) {
                        $warnings[] = "Screenshot #$index missing 'title'";
                    }
                    if (!isset($screenshot['url'])) {
                        $warnings[] = "Screenshot #$index missing 'url'";
                    }
                }
            }

            // Get actual screenshots from extension directory
            $screenshotsCount = 0;
            if (method_exists($extensionClass, 'getScreenshots')) {
                $screenshotsDir = dirname((new \ReflectionClass($extensionClass))->getFileName()) . '/screenshots';
                if (is_dir($screenshotsDir)) {
                    $screenshotsCount = count(glob($screenshotsDir . '/*.{png,jpg,jpeg,gif}', GLOB_BRACE));

                    if (
                        $screenshotsCount > 0 &&
                        (!isset($metadata['screenshots']) || empty($metadata['screenshots']))
                    ) {
                        $warnings[] = "Extension has $screenshotsCount screenshots in directory " .
                            "but none defined in metadata";
                    }
                }
            }

            // Check changelog
            $hasChangelogFile = false;
            $changelogFile = dirname((new \ReflectionClass($extensionClass))->getFileName()) . '/CHANGELOG.md';
            if (file_exists($changelogFile)) {
                $hasChangelogFile = true;

                if (!isset($metadata['changelog']) || empty($metadata['changelog'])) {
                    $warnings[] = "Extension has CHANGELOG.md file but no changelog in metadata";
                }
            }

            return [
                'success' => empty($issues),
                'issues' => $issues,
                'warnings' => $warnings,
                'metadata' => $metadata,
                'extras' => [
                    'screenshots_count' => $screenshotsCount,
                    'has_changelog_file' => $hasChangelogFile
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'issues' => ["Error validating metadata: " . $e->getMessage()],
                'warnings' => [],
                'metadata' => []
            ];
        }
    }

    /**
     * Get enhanced extension metadata
     *
     * Returns comprehensive metadata for an extension following the
     * Glueful Extension Metadata Standard, including auto-populated fields.
     *
     * @param string $extensionName Extension name
     * @return array Enhanced metadata or null if extension not found
     */
    public static function getEnhancedExtensionMetadata(string $extensionName): ?array
    {
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return null;
        }

        try {
            // Get base metadata
            $metadata = $extensionClass::getMetadata();

            // Add screenshots if not already included
            if (
                (!isset($metadata['screenshots']) || empty($metadata['screenshots'])) &&
                method_exists($extensionClass, 'getScreenshots')
            ) {
                $screenshots = $extensionClass::getScreenshots();
                if (!empty($screenshots)) {
                    $metadata['screenshots'] = $screenshots;
                }
            }

            // Add changelog if not already included
            if (
                (!isset($metadata['changelog']) || empty($metadata['changelog'])) &&
                method_exists($extensionClass, 'getChangelog')
            ) {
                $changelog = $extensionClass::getChangelog();
                if (!empty($changelog)) {
                    $metadata['changelog'] = $changelog;
                }
            }

            // Add system-populated fields (store these in database eventually)
            $reflection = new \ReflectionClass($extensionClass);

            // Determine if the extension is core or optional
            $extensionType = self::isCoreExtension($extensionName) ? 'core' : 'optional';

            $metadata['_system'] = [
                'class_name' => $extensionClass,
                'file_path' => $reflection->getFileName(),
                'directory' => dirname($reflection->getFileName()),
                'enabled' => self::isExtensionEnabled($extensionName),
                'type' => $extensionType,
                'installed_date' => filemtime($reflection->getFileName()),
                'last_updated' => filemtime($reflection->getFileName())
            ];
            // Ensure the type is consistently available in both the main metadata and _system
            // Initialize metadata as an array if it's not already
            if (!is_array($metadata)) {
                $metadata = [];
            }
            // Use array_key_exists to check if the key exists before accessing it
            if (is_array($metadata) && !array_key_exists('type', $metadata)) {
                $metadata['type'] = $extensionType;
            }
            // Create placeholder for marketplace data (to be populated by external system)
            // Check if rating key exists and set default if it doesn't
            if (!array_key_exists('rating', $metadata)) {
                $metadata['rating'] = [
                    'average' => 0,
                    'count' => 0,
                    'distribution' => []
                ];
            }
            // Check if stats key exists and set default if it doesn't
            if (!array_key_exists('stats', $metadata)) {
                $metadata['stats'] = [
                    'downloads' => 0,
                    'active_installations' => 0,
                    'first_published' => null,
                    'last_updated' => null
                ];
            }

            return $metadata;
        } catch (\Throwable $e) {
            error_log("Error getting enhanced metadata for $extensionName: " . $e->getMessage());
            return null;
        }
    }



    /**
     * Get the path to the extension configuration file
     *
     * @return string Path to the configuration file
     */


    private static function getTemplatesPath(): string
    {
        return dirname(__DIR__) . '/Console/Templates/Extensions';
    }

    /**
     * Create a new extension
     *
     * Creates a new extension directory and scaffolds all necessary files using templates.
     *
     * @param string $extensionName Extension name
     * @param string $extensionType Extension type (optional by default)
     * @param string $templateType Template type to use (Basic by default)
     * @param array $templateData Additional template data for substitutions
     * @return array Result with success status and messages
     */
    public static function createExtension(
        string $extensionName,
        string $extensionType = 'optional',
        string $templateType = 'Basic',
        array $templateData = []
    ): array {
        // Check if extension name is valid
        if (!self::isValidExtensionName($extensionName)) {
            return [
                'success' => false,
                'message' => "Invalid extension name '$extensionName'. Use PascalCase naming (e.g. MyExtension)"
            ];
        }

        $extensionsPath = config('app.paths.project_extensions');
        $extensionDir = $extensionsPath . $extensionName;

        if (is_dir($extensionDir)) {
            return [
                'success' => false,
                'message' => "Extension directory already exists: $extensionDir"
            ];
        }

        // Create extension directory
        if (!mkdir($extensionDir, 0755, true)) {
            return [
                'success' => false,
                'message' => "Failed to create directory: $extensionDir"
            ];
        }

        // Get template path
        $templatePath = self::getTemplatesPath();
        $templateSourceDir = "$templatePath/$templateType";

        // If the specified template doesn't exist, fall back to Basic
        if (!is_dir($templateSourceDir)) {
            $templateType = 'Basic';
            $templateSourceDir = "$templatePath/$templateType";
            // If even the Basic template doesn't exist, return an error
            if (!is_dir($templateSourceDir)) {
                // Clean up the created directory
                self::rrmdir($extensionDir);
                return [
                    'success' => false,
                    'message' => "Extension templates not found. " .
                        "Please ensure template directories exist at: $templatePath"
                ];
            }
        }

        // Create directories for extension structure (matching existing extensions)
        $directories = [
            "$extensionDir/src",
            "$extensionDir/assets",
            "$extensionDir/screenshots",
            "$extensionDir/migrations",
        ];

        // Add additional directories for Advanced template
        if ($templateType === 'Advanced') {
            $directories = array_merge($directories, [
                "$extensionDir/src/Middleware",
                "$extensionDir/src/Services",
                "$extensionDir/src/Providers",
                "$extensionDir/src/Listeners",
                "$extensionDir/src/Templates",
            ]);
        }

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $filesCreated = [];

        // Prepare template substitution values
        $substitutions = array_merge([
            'EXTENSION_NAME' => $extensionName,
            'EXTENSION_TYPE' => $extensionType,
            'EXTENSION_DESCRIPTION' => $templateData['description'] ?? "A Glueful API extension",
            'AUTHOR_NAME' => $templateData['author'] ?? "Glueful User",
            'AUTHOR_EMAIL' => $templateData['email'] ?? "",
            'CURRENT_DATE' => date('Y-m-d')
        ], $templateData);

        // Copy template files with proper substitutions
        self::copyTemplateFiles($templateSourceDir, $extensionDir, $substitutions);
        // Get list of created files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extensionDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $filesCreated[] = substr($file->getPathname(), strlen($extensionDir) + 1);
        }

        // Add extension to extensions.json configuration
        $setupResult = self::postInstallSetup($extensionName, $extensionDir);
        if (!$setupResult['success']) {
            // Clean up on failure
            self::removeDirectory($extensionDir);
            return [
                'success' => false,
                'message' => "Extension created but failed to update configuration: " . $setupResult['message']
            ];
        }

        return [
            'success' => true,
            'message' => "Extension '{$extensionName}' created successfully using '{$templateType}' template",
            'data' => [
                'name' => $extensionName,
                'path' => $extensionDir,
                'template' => $templateType,
                'files' => $filesCreated,
                'version' => $setupResult['version'] ?? '1.0.0'
            ]
        ];
    }

    /**
     * Recursively remove a directory
     *
     * @param string $dir Directory path
     * @return bool Success status
     */
    private static function rrmdir(string $dir): bool
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    $path = $dir . '/' . $object;
                    if (is_dir($path)) {
                        self::rrmdir($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            return rmdir($dir);
        }
        return false;
    }

    /**
     * Get all core extensions from config
     *
     * @param string|null $configFile Optional config file path
     * @return array List of core extension names
     */
    public static function getCoreExtensions(?string $configFile = null): array
    {
        if ($configFile === null) {
            $configFile = self::getConfigPath();
        }

        if (!file_exists($configFile)) {
            return [];
        }

        // Read JSON file
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);

        if (!is_array($config)) {
            return [];
        }

        // Check for explicit core array first
        if (isset($config['core']) && is_array($config['core'])) {
            return $config['core'];
        }

        // Fallback: look for extensions with type="core"
        $coreExtensions = [];
        if (isset($config['extensions']) && is_array($config['extensions'])) {
            foreach ($config['extensions'] as $name => $ext) {
                if (isset($ext['type']) && $ext['type'] === 'core') {
                    $coreExtensions[] = $name;
                }
            }
        }

        return $coreExtensions;
    }

    /**
     * Get all optional extensions from config
     *
     * @param string|null $configFile Optional config file path
     * @return array List of optional extension names
     */
    public static function getOptionalExtensions(?string $configFile = null): array
    {
        if ($configFile === null) {
            $configFile = self::getConfigPath();
        }

        if (!file_exists($configFile)) {
            return [];
        }

        // Read JSON file
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);

        if (!is_array($config)) {
            return [];
        }

        // Check for explicit optional array first
        if (isset($config['optional']) && is_array($config['optional'])) {
            return $config['optional'];
        }

        // Fallback: look for extensions with type="optional" or no type (default to optional)
        $optionalExtensions = [];
        if (isset($config['extensions']) && is_array($config['extensions'])) {
            foreach ($config['extensions'] as $name => $ext) {
                if (!isset($ext['type']) || $ext['type'] === 'optional') {
                    $optionalExtensions[] = $name;
                }
            }
        }

        return $optionalExtensions;
    }

    /**
     * Check if an extension is a core extension
     *
     * @param string $extensionName Extension name to check
     * @return bool True if it's a core extension
     */
    public static function isCoreExtension(string $extensionName): bool
    {
        $coreExtensions = self::getCoreExtensions();
        return in_array($extensionName, $coreExtensions);
    }

    /**
     * Delete an extension from the filesystem
     *
     * Completely removes an extension directory and its files.
     * This also updates the configuration to remove references to the extension.
     *
     * @param string $extensionName Extension name to delete
     * @param bool $force Force deletion even if the extension is enabled or is a core extension
     * @return array Success status and any messages
     */
    public static function deleteExtension(string $extensionName, bool $force = false): array
    {
        // Check if extension exists
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' not found"
            ];
        }

        $reflection = new \ReflectionClass($extensionClass);
        $extensionDir = dirname($reflection->getFileName());

        // Verify this is a real extension directory under the extensions path
        $extensionsPath = config('app.paths.project_extensions');
        if (!str_starts_with($extensionDir, $extensionsPath)) {
            return [
                'success' => false,
                'message' => "Cannot delete extension: Directory '$extensionDir' is not in the extensions path"
            ];
        }

        // Check if extension is enabled
        $isEnabled = self::isExtensionEnabled($extensionName);

        if ($isEnabled && !$force) {
            return [
                'success' => false,
                'message' => "Cannot delete extension '$extensionName': Extension is currently enabled. " .
                    "Disable it first or use force=true parameter.",
                'details' => [
                    'is_enabled' => true,
                    'can_force' => true
                ]
            ];
        }

        // Check if this is a core extension
        $isCoreExtension = self::isCoreExtension($extensionName);

        if ($isCoreExtension && !$force) {
            return [
                'success' => false,
                'message' => "Cannot delete core extension '$extensionName'. "
                    . "This extension is required for core functionality.",
                'details' => [
                    'is_core' => true,
                    'can_force' => true,
                    'warning' => "Deleting this extension may break core system functionality. " .
                        "Use force=true parameter to override."
                ]
            ];
        }

        // Check for dependent extensions
        $dependencyResults = self::checkDependenciesForDisable($extensionName);
        if (!$dependencyResults['success'] && !$force) {
            return [
                'success' => false,
                'message' => "Cannot delete extension '$extensionName': Other extensions depend on it. " .
                             "Disable dependent extensions first or use force=true parameter.",
                'details' => $dependencyResults['details'] ?? []
            ];
        }

        // Remove from configuration
        $configUpdated = false;
        $configFile = self::getConfigPath();
        if (file_exists($configFile)) {
            $config = include $configFile;

            if (isset($config['enabled'])) {
                $config['enabled'] = array_diff($config['enabled'], [$extensionName]);
                $configUpdated = true;
            }

            if (isset($config['core'])) {
                $config['core'] = array_diff($config['core'], [$extensionName]);
                $configUpdated = true;
            }

            if (isset($config['optional'])) {
                $config['optional'] = array_diff($config['optional'], [$extensionName]);
                $configUpdated = true;
            }

            if ($configUpdated) {
                self::saveConfig($configFile, $config);
            }
        }

        // Delete the extension directory
        try {
            $success = self::rrmdir($extensionDir);

            if (!$success) {
                return [
                    'success' => false,
                    'message' => "Failed to delete extension directory: $extensionDir",
                    'details' => [
                        'directory' => $extensionDir,
                        'config_updated' => $configUpdated
                    ]
                ];
            }

            // Reload extensions after deletion
            self::$loadedExtensions = [];

            $message = "Extension '$extensionName' has been deleted successfully";
            if ($isCoreExtension) {
                $message .= " (WARNING: This was a core extension and some system functionality may not work properly)";
            }

            return [
                'success' => true,
                'message' => $message,
                'details' => [
                    'directory_deleted' => $extensionDir,
                    'was_enabled' => $isEnabled,
                    'was_core' => $isCoreExtension,
                    'config_updated' => $configUpdated
                ]
                       ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Error deleting extension: " . $e->getMessage(),
                'details' => [
                    'directory' => $extensionDir,
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Update an extension from URL or archive file
     *
     * Updates an existing extension with new files from the provided source.
     * Maintains extension configuration when possible.
     *
     * @param string $extensionName Extension name to update
     * @param string $source Source URL or file path for updated files
     * @param bool $preserveConfig Whether to preserve existing configuration
     * @return array Result with success status and messages
     */
    public static function updateExtension(string $extensionName, string $source, bool $preserveConfig = true): array
    {
        // Check if extension exists
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return [
                'success' => false,
                'message' => "Extension '$extensionName' not found"
            ];
        }

        $reflection = new \ReflectionClass($extensionClass);
        $extensionDir = dirname($reflection->getFileName());

        // Validate source URL or file path
        if (!filter_var($source, FILTER_VALIDATE_URL) && !file_exists($source)) {
            return [
                'success' => false,
                'message' => "Invalid source URL or file path: $source"
            ];
        }

        // Backup existing configuration if needed
        $configBackup = null;
        if ($preserveConfig) {
            $configFile = "$extensionDir/config.php";
            if (file_exists($configFile)) {
                try {
                    $configBackup = include $configFile;
                    self::debug("Backed up configuration for $extensionName");
                } catch (\Throwable $e) {
                    self::debug("Failed to back up configuration: " . $e->getMessage());
                }
            }
        }

        // Create a temporary directory for the update
        $tempDir = sys_get_temp_dir() . '/glueful_update_' . md5($extensionName . time());
        if (!mkdir($tempDir, 0755, true)) {
            return [
                'success' => false,
                'message' => "Failed to create temporary directory for update"
            ];
        }

        // Download or copy the update archive
        $archiveFile = self::downloadOrCopyArchive($source);

        if (!$archiveFile) {
            // Clean up temporary directory
            self::rrmdir($tempDir);

            return [
                'success' => false,
                'message' => "Failed to download or copy archive from: $source"
            ];
        }

        // Extract the archive to the temporary directory
        $extractResult = self::extractArchive($archiveFile, $tempDir);

        // Clean up temporary archive file
        @unlink($archiveFile);

        if (!$extractResult) {
            // Clean up temporary directory
            self::rrmdir($tempDir);

            return [
                'success' => false,
                'message' => "Failed to extract update archive"
            ];
        }

        // Check if the update contains the necessary files
        $mainClassFile = "$tempDir/$extensionName.php";
        $foundMainFile = file_exists($mainClassFile);

        if (!$foundMainFile) {
            // Try to find any PHP file that might be the main extension file
            $phpFiles = glob("$tempDir/*.php");

            if (empty($phpFiles)) {
                // Clean up temporary directory
                self::rrmdir($tempDir);

                return [
                    'success' => false,
                    'message' => "No PHP files found in the update archive."
                ];
            }

            // If we find a single PHP file, assume it's the main file
            if (count($phpFiles) === 1) {
                $existingFile = $phpFiles[0];
                rename($existingFile, $mainClassFile);
                $foundMainFile = true;
            } else {
                // Multiple PHP files found, check if any has the extension name
                foreach ($phpFiles as $phpFile) {
                    $fileName = basename($phpFile);
                    if (stripos($fileName, $extensionName) !== false) {
                        rename($phpFile, $mainClassFile);
                        $foundMainFile = true;
                        break;
                    }
                }
            }
        }

        if (!$foundMainFile) {
            // Clean up temporary directory
            self::rrmdir($tempDir);

            return [
                'success' => false,
                'message' => "Could not find main extension file in the update archive."
            ];
        }

        // Check if the extension was enabled before updating
        $wasEnabled = self::isExtensionEnabled($extensionName);

        // Backup the old extension directory
        $backupDir = $extensionDir . '_backup_' . date('YmdHis');
        if (!rename($extensionDir, $backupDir)) {
            // Clean up temporary directory
            self::rrmdir($tempDir);

            return [
                'success' => false,
                'message' => "Failed to backup existing extension directory"
            ];
        }

        // Move the updated files to the extension directory
        if (!rename($tempDir, $extensionDir)) {
            // Restore from backup if the move fails
            rename($backupDir, $extensionDir);

            return [
                'success' => false,
                'message' => "Failed to move updated files to extension directory"
            ];
        }

        // Restore configuration if needed
        if ($preserveConfig && $configBackup !== null) {
            $configFile = "$extensionDir/config.php";
            $content = "<?php\nreturn " . var_export($configBackup, true) . ";\n";

            if (file_put_contents($configFile, $content) === false) {
                self::debug("Failed to restore configuration for $extensionName");
            } else {
                self::debug("Successfully restored configuration for $extensionName");
            }
        }

        // Force reload the extensions to include the updated one
        self::$loadedExtensions = [];
        self::loadExtensions();

        // Validate the updated extension
        $validationResult = self::validateExtension($extensionName);
        $updateSuccess = $validationResult['success'] ?? true;
        $updateResult = [
            'success' => $updateSuccess,
            'message' => $updateSuccess
                ? "Extension '$extensionName' has been updated successfully"
                : "Extension '$extensionName' was updated but has validation issues",
            'was_enabled' => $wasEnabled,
            'validation' => $validationResult
        ];

        // Try to re-enable the extension if it was enabled before
        if ($wasEnabled) {
            $enableResult = self::enableExtension($extensionName);
            $updateResult['enabled'] = $enableResult['success'];

            if (!$enableResult['success']) {
                $updateResult['warning'] = "Extension was updated but could not be re-enabled: " .
                $enableResult['message'];
                $updateSuccess = false;
            }
        }

        // Clean up the backup directory if everything went well
        if ($updateSuccess) {
            self::rrmdir($backupDir);
        } else {
            $updateResult['backup'] = "The previous version was backed up to: $backupDir";
        }

        return $updateResult;
    }

    /**
     * Check for available extension updates
     *
     * Queries update sources to determine if any installed extensions
     * have updates available. Compares installed version with latest
     * available version for each extension.
     *
     * @param bool $checkAll Whether to check all extensions or only enabled ones
     * @param string|null $extensionName Optional specific extension to check
     * @return array Results with available updates information
     */
    public static function checkExtensionUpdates(bool $checkAll = false, ?string $extensionName = null): array
    {
        $results = [
            'updates_available' => false,
            'extensions' => [],
            'last_checked' => date('Y-m-d H:i:s'),
            'total_updates' => 0
        ];

        // Determine which extensions to check
        $extensionsToCheck = [];

        if ($extensionName !== null) {
            // Check specific extension only
            $extensionClass = self::findExtension($extensionName);
            if ($extensionClass) {
                $extensionsToCheck[] = $extensionName;
            } else {
                return [
                    'success' => false,
                    'message' => "Extension '$extensionName' not found",
                    'updates_available' => false,
                    'extensions' => []
                ];
            }
        } else {
            // Check all enabled extensions or all installed extensions
            if ($checkAll) {
                $extensionsToCheck = array_map(function ($class) {
                    $reflection = new \ReflectionClass($class);
                    return $reflection->getShortName();
                }, self::getLoadedExtensions());
            } else {
                $extensionsToCheck = self::getEnabledExtensions();
            }
        }

        // Fetch update information from extension registry/repository
        $updateSources = self::getUpdateSources();

        // Check each extension for updates
        foreach ($extensionsToCheck as $extName) {
            $extensionClass = self::findExtension($extName);

            if (!$extensionClass) {
                continue; // Skip if class not found
            }

            try {
                // Get current version from extension metadata
                $metadata = $extensionClass::getMetadata();
                $currentVersion = $metadata['version'] ?? '1.0.0';

                // Check each update source for this extension
                $latestVersion = $currentVersion;
                $updateInfo = null;
                $updateAvailable = false;

                foreach ($updateSources as $source) {
                    $sourceInfo = self::checkUpdateSource($extName, $currentVersion, $source);

                    // If this source has a newer version than what we've found so far
                    if (
                        $sourceInfo['has_update'] &&
                        version_compare($sourceInfo['latest_version'], $latestVersion, '>')
                    ) {
                        $latestVersion = $sourceInfo['latest_version'];
                        $updateInfo = $sourceInfo;
                        $updateAvailable = true;
                    }
                }

                // Add to results
                $results['extensions'][$extName] = [
                    'current_version' => $currentVersion,
                    'latest_version' => $latestVersion,
                    'update_available' => $updateAvailable,
                    'update_info' => $updateInfo
                ];

                // Increment total if update is available
                if ($updateAvailable) {
                    $results['total_updates']++;
                    $results['updates_available'] = true;
                }
            } catch (\Throwable $e) {
                self::debug("Error checking updates for $extName: " . $e->getMessage());

                $results['extensions'][$extName] = [
                    'error' => "Failed to check updates: " . $e->getMessage(),
                    'update_available' => false
                ];
            }
        }

        return $results;
    }

    /**
     * Get update sources for extensions
     *
     * Returns a list of configured update sources where
     * the system can check for extension updates.
     *
     * @return array List of update sources (URLs and custom handlers)
     */
    private static function getUpdateSources(): array
    {
        // Default update sources
        $sources = [
            [
                'name' => 'Official Glueful Repository',
                'url' => 'https://extensions.glueful.dev/api/v1/updates',
                'type' => 'api'
            ]
        ];

        // Get additional sources from config
        $configSources = config('services.extensions.update_sources', []);
        if (!empty($configSources) && is_array($configSources)) {
            $sources = array_merge($sources, $configSources);
        }

        return $sources;
    }

    /**
     * Check a specific update source for extension updates
     *
     * @param string $extensionName Extension name to check
     * @param string $currentVersion Current installed version
     * @param array $source Update source information
     * @return array Update information
     */
    private static function checkUpdateSource(
        string $extensionName,
        string $currentVersion,
        array $source
    ): array {
        $result = [
            'name' => $source['name'] ?? 'Unknown Source',
            'has_update' => false,
            'latest_version' => $currentVersion,
            'download_url' => null,
            'release_notes' => null,
            'release_date' => null,
            'source' => $source['url'] ?? null
        ];

        try {
            // Different handling based on source type
            $sourceType = $source['type'] ?? 'api';

            switch ($sourceType) {
                case 'api':
                    // Query API endpoint for update information
                    $updateInfo = self::queryUpdateAPI($extensionName, $currentVersion, $source);

                    if ($updateInfo && isset($updateInfo['version'])) {
                        $result['latest_version'] = $updateInfo['version'];
                        $result['has_update'] = version_compare($updateInfo['version'], $currentVersion, '>');
                        $result['download_url'] = isset($updateInfo['download_url'])
                            ? $updateInfo['download_url']
                            : null;
                        $result['release_notes'] = isset($updateInfo['release_notes'])
                            ? $updateInfo['release_notes']
                            : null;
                        $result['release_date'] = isset($updateInfo['release_date'])
                            ? $updateInfo['release_date']
                            : null;
                    }
                    break;

                case 'github':
                    // Check GitHub repository for latest release
                    $updateInfo = self::checkGitHubRelease($extensionName, $currentVersion, $source);

                    if ($updateInfo && isset($updateInfo['version'])) {
                        $result['latest_version'] = $updateInfo['version'];
                        $result['has_update'] = version_compare($updateInfo['version'], $currentVersion, '>');
                        $result['download_url'] = $updateInfo['download_url'] ?? null;
                        $result['release_notes'] = $updateInfo['release_notes'] ?? null;
                        $result['release_date'] = $updateInfo['release_date'] ?? null;
                    }
                    break;

                case 'local':
                    // Check local directory for updates
                    $updateInfo = self::checkLocalSource($extensionName, $currentVersion, $source);
                    if ($updateInfo && isset($updateInfo['version'])) {
                        $result['latest_version'] = $updateInfo['version'];
                        $result['has_update'] = version_compare($updateInfo['version'], $currentVersion, '>');
                        $result['download_url'] = $updateInfo['download_url'] ?? null;
                        $result['release_notes'] = $updateInfo['release_notes'] ?? null;
                        $result['release_date'] = $updateInfo['release_date'] ?? null;
                    }
                    break;

                default:
                    self::debug("Unknown update source type: $sourceType");
                    break;
            }
        } catch (\Throwable $e) {
            self::debug("Error checking update source {$source['name']}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Query an update API for extension update information
     *
     * @param string $extensionName Extension name to check
     * @param string $currentVersion Current installed version
     * @param array $source Update source information
     * @return array|null Update information or null if no update found
     */
    private static function queryUpdateAPI(
        string $extensionName,
        string $currentVersion,
        array $source
    ): ?array {
        if (empty($source['url'])) {
            return null;
        }

        $url = $source['url'];

        // Add query parameters
        $url = rtrim($url, '?&') . '?' . http_build_query([
            'extension' => $extensionName,
            'version' => $currentVersion,
            'glueful_version' => config('app.version', '1.0.0'),
            'php_version' => PHP_VERSION
        ]);

        // Set up curl request
        $ch = curl_init($url);

        if (!$ch) {
            self::debug("Failed to initialize curl for update check");
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Glueful Extension Manager ' . config('app.version', '1.0.0'));

        // Set authorization header if provided
        if (isset($source['auth_token'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $source['auth_token']
            ]);
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode !== 200 || empty($response)) {
            self::debug("Update API returned status code $statusCode");
            return null;
        }

        // Parse response
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::debug("Failed to parse update API response: " . json_last_error_msg());
            return null;
        }

        // Validate response format
        if (!isset($data['version'])) {
            return null;
        }

        return $data;
    }

    /**
     * Check GitHub repository for extension updates
     *
     * @param string $extensionName Extension name to check
     * @param string $currentVersion Current installed version
     * @param array $source Update source with GitHub repository information
     * @return array|null Update information or null if no update found
     */
    private static function checkGitHubRelease(
        string $extensionName,
        string $currentVersion,
        array $source
    ): ?array {
        if (empty($source['repository'])) {
            return null;
        }

        $repo = $source['repository'];
        $apiUrl = "https://api.github.com/repos/$repo/releases/latest";

        // Set up curl request
        $ch = curl_init($apiUrl);

        if (!$ch) {
            self::debug("Failed to initialize curl for GitHub update check");
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Glueful Extension Manager ' . config('app.version', '1.0.0'));

        // Set GitHub token if provided
        if (isset($source['github_token'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: token ' . $source['github_token']
            ]);
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode !== 200 || empty($response)) {
            self::debug("GitHub API returned status code $statusCode");
            return null;
        }

        // Parse response
        try {
            $data = json_decode($response, true);

            // GitHub releases use tag_name for version
            if (!isset($data['tag_name'])) {
                return null;
            }

            // Remove 'v' prefix if present
            $version = ltrim($data['tag_name'], 'v');

            // Find zip asset
            $downloadUrl = null;
            if (isset($data['assets']) && is_array($data['assets'])) {
                foreach ($data['assets'] as $asset) {
                    if (
                        isset($asset['browser_download_url']) &&
                        (str_ends_with($asset['browser_download_url'], '.zip') ||
                         str_ends_with($asset['browser_download_url'], '.tar.gz'))
                    ) {
                        $downloadUrl = $asset['browser_download_url'];
                        break;
                    }
                }
            }

            // If no specific asset found, use the source code zip
            if ($downloadUrl === null && isset($data['zipball_url'])) {
                $downloadUrl = $data['zipball_url'];
            }

            return [
                'version' => $version,
                'download_url' => $downloadUrl,
                'release_notes' => $data['body'] ?? null,
                'release_date' => $data['published_at'] ?? null
            ];
        } catch (\Throwable $e) {
            self::debug("Failed to parse GitHub API response: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check local directory for extension updates
     *
     * @param string $extensionName Extension name to check
     * @param string $currentVersion Current installed version
     * @param array $source Local source directory information
     * @return array|null Update information or null if no update found
     */
    private static function checkLocalSource(
        string $extensionName,
        string $currentVersion,
        array $source
    ): ?array {
        if (empty($source['directory']) || !is_dir($source['directory'])) {
            return null;
        }

        $directory = rtrim($source['directory'], '/\\');

        // Look for the extension in the directory
        $extensionDir = "$directory/$extensionName";

        if (!is_dir($extensionDir)) {
            return null;
        }

        // Look for metadata file or main extension file
        $metadataFile = "$extensionDir/metadata.json";
        $mainClassFile = "$extensionDir/$extensionName.php";

        if (file_exists($metadataFile)) {
            // Parse metadata file
            try {
                $metadata = json_decode(file_get_contents($metadataFile), true);

                if (isset($metadata['version'])) {
                    return [
                        'version' => $metadata['version'],
                        'download_url' => "file://$extensionDir",
                        'release_notes' => $metadata['release_notes'] ?? null,
                        'release_date' => $metadata['release_date'] ?? date('Y-m-d', filemtime($metadataFile))
                    ];
                }
            } catch (\Throwable $e) {
                self::debug("Failed to parse local metadata: " . $e->getMessage());
            }
        }

        // Try to extract version from main class file
        if (file_exists($mainClassFile)) {
            $content = file_get_contents($mainClassFile);

            if (preg_match('/@version\s+([0-9.]+)/', $content, $matches)) {
                $version = $matches[1];

                if (version_compare($version, $currentVersion, '>')) {
                    return [
                        'version' => $version,
                        'download_url' => "file://$extensionDir",
                        'release_notes' => null,
                        'release_date' => date('Y-m-d', filemtime($mainClassFile))
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Resolve version constraints for an extension
     *
     * Checks if the extension's version constraints are compatible with
     * the current framework version and its dependencies.
     *
     * @param string $extensionName Extension name to check
     * @return array Results showing compatibility status and conflicts
     */
    public static function resolveVersionConstraints(string $extensionName): array
    {
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return ['compatible' => false, 'conflicts' => [
                'type' => 'extension',
                'name' => $extensionName,
                'error' => 'Extension not found'
            ]];
        }

        try {
            $metadata = $extensionClass::getMetadata();
        } catch (\Throwable $e) {
            return ['compatible' => false, 'conflicts' => [
                'type' => 'metadata',
                'name' => $extensionName,
                'error' => 'Failed to get metadata: ' . $e->getMessage()
            ]];
        }

        $requires = $metadata['requires'] ?? [];

        $results = ['compatible' => true, 'conflicts' => []];

        // Check Glueful version compatibility
        if (isset($requires['glueful'])) {
            $gluefulVersion = config('app.version', '1.0.0');
            $compatible = self::checkVersionConstraint($gluefulVersion, $requires['glueful']);
            if (!$compatible) {
                $results['compatible'] = false;
                $results['conflicts'][] = [
                    'type' => 'framework',
                    'name' => 'glueful',
                    'constraint' => $requires['glueful'],
                    'actual' => $gluefulVersion
                ];
            }
        }

        // Check PHP version compatibility
        if (isset($requires['php'])) {
            $compatible = self::checkVersionConstraint(PHP_VERSION, $requires['php']);
            if (!$compatible) {
                $results['compatible'] = false;
                $results['conflicts'][] = [
                    'type' => 'language',
                    'name' => 'php',
                    'constraint' => $requires['php'],
                    'actual' => PHP_VERSION
                ];
            }
        }
        // Check extension dependencies versions
        if (isset($requires['extensions']) && is_array($requires['extensions'])) {
            foreach ($requires['extensions'] as $dependency) {
                // If dependency is specified as an array with version constraint
                if (is_array($dependency) && isset($dependency['name']) && isset($dependency['version'])) {
                    $depName = $dependency['name'];
                    $depConstraint = $dependency['version'];
                    $depClass = self::findExtension($depName);
                    if (!$depClass) {
                        $results['compatible'] = false;
                        $results['conflicts'][] = [
                            'type' => 'dependency',
                            'name' => $depName,
                            'constraint' => $depConstraint,
                            'error' => 'Dependency not installed'
                        ];
                        continue;
                    }

                    try {
                        $depMetadata = $depClass::getMetadata();
                        $depVersion = $depMetadata['version'] ?? '0.0.0';

                        $compatible = self::checkVersionConstraint($depVersion, $depConstraint);
                        if (!$compatible) {
                            $results['compatible'] = false;
                            $results['conflicts'][] = [
                                'type' => 'dependency',
                                'name' => $depName,
                                'constraint' => $depConstraint,
                                'actual' => $depVersion
                            ];
                        }
                    } catch (\Throwable $e) {
                        $results['compatible'] = false;
                        $results['conflicts'][] = [
                            'type' => 'dependency',
                            'name' => $depName,
                            'error' => 'Failed to check version: ' . $e->getMessage()
                        ];
                    }
                } elseif (is_string($dependency)) {
                    if (!self::findExtension($dependency)) {
                        $results['compatible'] = false;
                        $results['conflicts'][] = [
                            'type' => 'dependency',
                            'name' => $dependency,
                            'error' => 'Dependency not installed'
                        ];
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Check if a version satisfies a constraint
     *
     * Supports semantic versioning constraints:
     * - Exact: 1.0.0
     * - Greater than: >1.0.0
     * - Greater than or equal: >=1.0.0
     * - Less than: <1.0.0
     * - Less than or equal: <=1.0.0
     * - Range: >=1.0.0 <2.0.0
     *
     * @param string $version Version to check
     * @param string $constraint Version constraint to check against
     * @return bool Whether the version satisfies the constraint
     */
    private static function checkVersionConstraint(string $version, string $constraint): bool
    {
        // Exact version match
        if (preg_match('/^[0-9.]+$/', $constraint)) {
            return version_compare($version, $constraint, '==');
        }

        // Handle multiple constraints (space-separated)
        if (strpos($constraint, ' ') !== false) {
            $constraints = explode(' ', $constraint);
            foreach ($constraints as $singleConstraint) {
                if (!self::checkVersionConstraint($version, $singleConstraint)) {
                    return false;
                }
            }
            return true;
        }

        // Simple comparison operators
        if (preg_match('/^([<>=]+)([0-9.]+)$/', $constraint, $matches)) {
            $operator = $matches[1];
            $constraintVersion = $matches[2];

            switch ($operator) {
                case '>':
                    return version_compare($version, $constraintVersion, '>');
                case '>=':
                    return version_compare($version, $constraintVersion, '>=');
                case '<':
                    return version_compare($version, $constraintVersion, '<');
                case '<=':
                    return version_compare($version, $constraintVersion, '<=');
                case '==':
                    return version_compare($version, $constraintVersion, '==');
                case '!=':
                    return version_compare($version, $constraintVersion, '!=');
                default:
                    return false;
            }
        }

        // Caret range (^1.2.3 = >=1.2.3 <2.0.0)
        if (preg_match('/^\^([0-9.]+)$/', $constraint, $matches)) {
            $minVersion = $matches[1];
            $parts = explode('.', $minVersion);
            $parts[0]++;
            $maxVersion = implode('.', $parts) . '.0';

            return version_compare($version, $minVersion, '>=') &&
                   version_compare($version, $maxVersion, '<');
        }

        // Tilde range (~1.2.3 = >=1.2.3 <1.3.0)
        if (preg_match('/^~([0-9.]+)$/', $constraint, $matches)) {
            $minVersion = $matches[1];
            $parts = explode('.', $minVersion);
            if (count($parts) >= 2) {
                $parts[1]++;
                $parts[2] = '0';
                $maxVersion = $parts[0] . '.' . $parts[1] . '.0';

                return version_compare($version, $minVersion, '>=') &&
                       version_compare($version, $maxVersion, '<');
            }
        }
        // Default to exact match if we can't parse the constraint
        return version_compare($version, $constraint, '==');
    }

    /**
     * Get suggested resolutions for extension conflicts
     *
     * Analyzes conflict data from resolveVersionConstraints() and provides
     * actionable recommendations to resolve each conflict type.
     *
     * @param array $conflicts Array of conflicts from resolveVersionConstraints()
     * @return array List of suggested resolution actions for each conflict
     */
    public static function getSuggestedResolutions(array $conflicts): array
    {
        $suggestions = [];
        foreach ($conflicts as $conflict) {
            $type = $conflict['type'] ?? 'unknown';
            switch ($type) {
                case 'framework':
                    // Framework version conflicts (Glueful version)
                    $constraint = $conflict['constraint'] ?? 'unknown';
                    $actual = $conflict['actual'] ?? 'unknown';
                    $name = $conflict['name'] ?? 'glueful';
                    // Parse constraint to provide appropriate upgrade/downgrade suggestion
                    if (preg_match('/^([<>=^~]+)([0-9.]+)$/', $constraint, $matches)) {
                        $operator = $matches[1];
                        $version = $matches[2];
                        if (in_array($operator, ['>', '>=', '^', '~'])) {
                            $suggestions[] = [
                                'action' => 'upgrade',
                                'message' => "Upgrade {$name} to version {$version} or later (current: {$actual})",
                                'target' => $name,
                                'type' => 'framework',
                                'current_version' => $actual,
                                'required_version' => $version
                            ];
                        } elseif (in_array($operator, ['<', '<='])) {
                            $suggestions[] = [
                                'action' => 'downgrade',
                                'message' => "Downgrade {$name} to version below {$version} (current: {$actual})",
                                'target' => $name,
                                'type' => 'framework',
                                'current_version' => $actual,
                                'required_version' => "< {$version}"
                            ];
                        } else {
                            $suggestions[] = [
                                'action' => 'change_version',
                                'message' => "Change {$name} to version {$constraint} (current: {$actual})",
                                'target' => $name,
                                'type' => 'framework',
                                'current_version' => $actual,
                                'required_version' => $constraint
                            ];
                        }
                    } else {
                        $suggestions[] = [
                            'action' => 'change_version',
                            'message' => "Change {$name} to version that satisfies: {$constraint} (current: {$actual})",
                            'target' => $name,
                            'type' => 'framework',
                            'current_version' => $actual,
                            'required_version' => $constraint
                        ];
                    }
                    break;
                case 'language':
                    // Language version conflicts (PHP version)
                    $constraint = $conflict['constraint'] ?? 'unknown';
                    $actual = $conflict['actual'] ?? 'unknown';
                    $name = $conflict['name'] ?? 'php';
                    $suggestions[] = [
                        'action' => 'change_environment',
                        'message' => "Update PHP to version that satisfies: {$constraint} (current: {$actual})",
                        'target' => $name,
                        'type' => 'language',
                        'current_version' => $actual,
                        'required_version' => $constraint
                    ];
                    break;
                case 'dependency':
                    // Extension dependency conflicts
                    $name = $conflict['name'] ?? 'unknown extension';
                    if (isset($conflict['error']) && $conflict['error'] === 'Dependency not installed') {
                        $suggestions[] = [
                            'action' => 'install',
                            'message' => "Install missing extension: {$name}",
                            'target' => $name,
                            'type' => 'extension'
                        ];
                    } elseif (isset($conflict['constraint']) && isset($conflict['actual'])) {
                        $constraint = $conflict['constraint'];
                        $actual = $conflict['actual'];
                        // Check if we need to upgrade or downgrade
                        if (preg_match('/^([<>=^~]+)([0-9.]+)$/', $constraint, $matches)) {
                            $operator = $matches[1];
                            $version = $matches[2];
                            if (in_array($operator, ['>', '>=', '^', '~'])) {
                                $suggestions[] = [
                                    'action' => 'upgrade_extension',
                                    'message' => "Upgrade {$name} to version {$version} or later (current: {$actual})",
                                    'target' => $name,
                                    'type' => 'extension',
                                    'current_version' => $actual,
                                    'required_version' => $version
                                ];
                            } elseif (in_array($operator, ['<', '<='])) {
                                $suggestions[] = [
                                    'action' => 'downgrade_extension',
                                    'message' => "Downgrade {$name} to version below {$version} (current: {$actual})",
                                    'target' => $name,
                                    'type' => 'extension',
                                    'current_version' => $actual,
                                    'required_version' => "< {$version}"
                                ];
                            } else {
                                $suggestions[] = [
                                    'action' => 'change_extension_version',
                                    'message' => "Change {$name} to version {$constraint} (current: {$actual})",
                                    'target' => $name,
                                    'type' => 'extension',
                                    'current_version' => $actual,
                                    'required_version' => $constraint
                                ];
                            }
                        } else {
                            $suggestions[] = [
                                'action' => 'change_extension_version',
                                'message' => "Update {$name} to version that satisfies: {$constraint} " .
                                             "(current: {$actual})",
                                'target' => $name,
                                'type' => 'extension',
                                'current_version' => $actual,
                                'required_version' => $constraint
                            ];
                        }
                    } else {
                        $suggestions[] = [
                            'action' => 'fix_extension',
                            'message' => "Fix issues with extension dependency: {$name}",
                            'target' => $name,
                            'type' => 'extension',
                            'error' => $conflict['error'] ?? 'Unknown error'
                        ];
                    }
                    break;
                case 'extension':
                    // Extension not found conflicts
                    $name = $conflict['name'] ?? 'unknown';
                    $error = $conflict['error'] ?? 'Unknown error';
                    $suggestions[] = [
                        'action' => 'fix_extension',
                        'message' => "Extension issue: {$name} - {$error}",
                        'target' => $name,
                        'type' => 'extension',
                        'error' => $error
                    ];
                    break;
                case 'metadata':
                    // Metadata extraction conflicts
                    $name = $conflict['name'] ?? 'unknown';
                    $error = $conflict['error'] ?? 'Unknown error';
                    $suggestions[] = [
                        'action' => 'fix_metadata',
                        'message' => "Fix metadata in extension: {$name} - {$error}",
                        'target' => $name,
                        'type' => 'metadata',
                        'error' => $error
                    ];
                    break;
                default:
                    // Unknown conflict type
                    $suggestions[] = [
                        'action' => 'investigate',
                        'message' => "Investigate unknown conflict type: {$type}",
                        'type' => 'unknown',
                        'details' => $conflict
                    ];
                    break;
            }
        }
        return $suggestions;
    }

    /**
     * Resolve a CDN adapter from available extensions
     *
     * This method looks for extensions that have registered CDN adapters
     * and returns an instance of the requested provider's adapter.
     *
     * @param string $provider The name of the CDN provider to resolve
     * @param array $config Configuration for the adapter
     * @return \Glueful\Cache\CDN\CDNAdapterInterface|null
     */
    public function resolveCDNAdapter(string $provider, array $config = []): ?\Glueful\Cache\CDN\CDNAdapterInterface
    {
        // Normalize provider name for consistent lookup
        $normalizedProvider = strtolower($provider);

        // Look for extensions with CDN adapters
        foreach (self::$loadedExtensions as $extension) {
            if (!method_exists($extension, 'registerCDNAdapters')) {
                continue;
            }

            // Get the adapters this extension provides
            $adapters = $extension::registerCDNAdapters();

            // Check if this extension provides the requested adapter
            foreach ($adapters as $adapterProvider => $adapterClass) {
                if (strtolower($adapterProvider) === $normalizedProvider) {
                    // Found the adapter, try to instantiate it
                    if (
                        class_exists($adapterClass) &&
                        is_subclass_of($adapterClass, \Glueful\Cache\CDN\CDNAdapterInterface::class)
                    ) {
                        return new $adapterClass($config);
                    }
                }
            }
        }

        // No adapter found for this provider
        return null;
    }

    /**
     * Copy template files from source to target directory
     *
     * @param string $sourceDir Source directory containing template files
     * @param string $targetDir Target directory to copy files to
     * @param array $replacements Array of replacements for template placeholders
     */
    private static function copyTemplateFiles(string $sourceDir, string $targetDir, array $replacements): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = substr($sourcePath, strlen($sourceDir) + 1);

            // Handle special file renaming cases first
            $targetRelativePath = $relativePath;

            // Special case: Extension.php.tpl should become {EXTENSION_NAME}.php
            if (basename($relativePath) === 'Extension.php.tpl') {
                $extensionName = $replacements['EXTENSION_NAME'] ?? '';
                if (!empty($extensionName)) {
                    $targetRelativePath = dirname($relativePath) . '/' . $extensionName . '.php';
                } else {
                    $targetRelativePath = dirname($relativePath) . '/Extension.php';
                }
            } elseif (str_ends_with($relativePath, '.tpl')) {
                $targetRelativePath = substr($relativePath, 0, -4); // Remove .tpl extension
            }

            // Replace template placeholders in path
            $targetRelativePath =
            preg_replace_callback('/\{\{(\w+)\}\}|\{(\w+)\}/', function ($matches) use ($replacements) {
                // Get the placeholder name (either from first or second capturing group)
                $key = !empty($matches[1]) ? $matches[1] : $matches[2];

                // Look up replacement value (case-insensitive)
                foreach ($replacements as $placeholder => $value) {
                    if (strtolower($placeholder) === strtolower($key)) {
                        return is_array($value) ? json_encode($value) : (string)$value;
                    }
                }

                return $matches[0];
            }, $targetRelativePath);

            $targetPath = $targetDir . '/' . $targetRelativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // Read file contents using a specific encoding to avoid issues
                $content = @file_get_contents($sourcePath);

                if ($content === false) {
                    self::debug("Failed to read template file: $sourcePath");
                    continue;
                }

                // Detect BOM and remove if present
                if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                    $content = substr($content, 3);
                }

                // Process content with regex pattern replacement
                $content = preg_replace_callback('/\{\{(\w+)\}\}|\{(\w+)\}/', function ($matches) use ($replacements) {
                    // Get the placeholder name (either from first or second capturing group)
                    $key = !empty($matches[1]) ? $matches[1] : $matches[2];

                    // Look up replacement value (case-insensitive)
                    foreach ($replacements as $placeholder => $value) {
                        if (strtolower($placeholder) === strtolower($key)) {
                            return is_array($value) ? json_encode($value) : (string)$value;
                        }
                    }

                    // Return original if no replacement found
                    return $matches[0];
                }, $content);

                // Ensure proper line endings (LF)
                $content = str_replace("\r\n", "\n", $content);

                // Write content to file
                if (file_put_contents($targetPath, $content) === false) {
                    self::debug("Failed to write file: $targetPath");
                }
            }
        }
    }

    /**
     * Get the file system path for an extension
     *
     * @param string $extensionName Extension name
     * @return string|null Path to extension directory or null if not found
    */
    public static function getExtensionPath(string $extensionName): ?string
    {
        // First try to find extension using class loading
        $extensionClass = self::findExtension($extensionName);
        if ($extensionClass) {
            $reflection = new \ReflectionClass($extensionClass);
            return dirname($reflection->getFileName());
        }

        // Fall back to filesystem check for documentation generation
        // when classes haven't been loaded yet
        $extensionsPath = config('services.extensions.paths.extensions_dir');
        if (empty($extensionsPath)) {
            $extensionsPath = dirname(__DIR__, 2) . '/extensions/';
        }

        $extensionDir = rtrim($extensionsPath, '/') . '/' . $extensionName;
        if (is_dir($extensionDir)) {
            return $extensionDir;
        }

        return null;
    }

    /**
     * Validate coding standards for an extension
     *
     * Checks extension code for adherence to coding standards:
     * - PHP syntax validation
     * - PSR-12 compliance checks (if PHP_CodeSniffer is available)
     * - Common coding style issues
     * - Naming conventions
     *
     * @param string $extensionName Name of the extension to validate
     * @return array Result with success status and detected issues
     */
    public static function validateCodingStandards(string $extensionName): array
    {
        $extensionDir = self::getExtensionPath($extensionName);
        $issues = [];

        if (!$extensionDir || !is_dir($extensionDir)) {
            $issues[] = [
                'type' => 'error',
                'message' => "Extension directory not found: $extensionDir"
            ];
            return [
                'success' => false,
                'issues' => $issues
            ];
        }

        // Check for PHP syntax errors
        exec("find $extensionDir -name '*.php' -exec php -l {} \\; 2>&1", $syntaxOutput, $returnCode);

        if ($returnCode !== 0) {
            foreach ($syntaxOutput as $line) {
                if (strpos($line, 'Parse error') !== false || strpos($line, 'Fatal error') !== false) {
                    $issues[] = [
                        'type' => 'syntax',
                        'message' => $line
                    ];
                }
            }
        }

        // Check for files with mixed PHP and HTML without proper separation
        exec("grep -l '<?php.*?>' --include='*.php' -r $extensionDir", $mixedOutput);
        if (!empty($mixedOutput)) {
            $issues[] = [
                'type' => 'style',
                'message' => 'Files with mixed PHP and HTML should separate logic from presentation',
                'files' => $mixedOutput
            ];
        }

        // Check for inconsistent indentation
        exec("grep -l -E '^\t* {1,3}[^ ]' --include='*.php' -r $extensionDir", $indentOutput);
        if (!empty($indentOutput)) {
            $issues[] = [
                'type' => 'style',
                'message' => 'Inconsistent indentation detected (mixing spaces and tabs)',
                'files' => $indentOutput
            ];
        }

        // Check for too long lines (PSR-12 recommends 120 chars)
        exec("grep -l -E '^.{121,}$' --include='*.php' -r $extensionDir", $longLines);
        if (!empty($longLines)) {
            $issues[] = [
                'type' => 'style',
                'message' => 'Lines exceeding recommended length of 120 characters',
                'files' => $longLines
            ];
        }

        // Check for camelCase method names (PSR-12)
        exec("grep -l -E 'function [A-Z]|function [a-z]+_[a-z]' --include='*.php' -r $extensionDir", $methodNameOutput);
        if (!empty($methodNameOutput)) {
            $issues[] = [
                'type' => 'style',
                'message' => 'Method names should use camelCase as per PSR-12',
                'files' => $methodNameOutput
            ];
        }

        // Check against PSR-12 standards using PHP_CodeSniffer (if available)
        if (class_exists('\PHP_CodeSniffer\Runner')) {
            self::debug("PHP_CodeSniffer detected, running PSR-12 checks");

            // Create temporary file for output
            $tempFile = tempnam(sys_get_temp_dir(), 'phpcs_');

            // Run PHPCS with PSR-12 standard
            exec("phpcs --standard=PSR12 --report=json $extensionDir > $tempFile 2>/dev/null", $output, $returnCode);

            if ($returnCode !== 0 && file_exists($tempFile)) {
                $phpcsOutput = file_get_contents($tempFile);
                $phpcsResults = json_decode($phpcsOutput, true);

                if ($phpcsResults && isset($phpcsResults['files'])) {
                    foreach ($phpcsResults['files'] as $file => $data) {
                        foreach ($data['messages'] as $msg) {
                            $issues[] = [
                                'type' => 'phpcs',
                                'file' => $file,
                                'line' => $msg['line'],
                                'message' => $msg['message']
                            ];
                        }
                    }
                }
            }

            // Clean up
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        } else {
            self::debug("PHP_CodeSniffer not available, skipping detailed PSR-12 checks");
        }

        return [
            'success' => empty($issues),
            'issues' => $issues
        ];
    }

    /**
     * Assess the performance impact of an extension
     *
     * Measures the initialization time and memory usage of the extension.
     *
     * @param string $extensionName Extension name to check
     * @return array Performance metrics and rating
     */

    public static function assessPerformanceImpact(string $extensionName): array
    {
        $extensionClass = self::findExtension($extensionName);
        $metrics = [];

        // Measure initialization time
        $startTime = microtime(true);
        $extensionClass::initialize();
        $initTime = microtime(true) - $startTime;
        $metrics['initialization_time'] = $initTime * 1000; // ms

        // Measure memory usage
        $memoryBefore = memory_get_usage();
        $extensionClass::initialize();
        $memoryAfter = memory_get_usage();
        $metrics['memory_impact'] = $memoryAfter - $memoryBefore;

        // Additional impact assessments

        return [
            'metrics' => $metrics,
            'rating' => self::calculatePerformanceRating($metrics)
        ];
    }

    /**
     * Calculate performance rating based on extension metrics
     *
     * @param array $metrics Performance metrics
     * @return string Performance rating (Excellent, Good, Average, Poor)
     */
    private static function calculatePerformanceRating(array $metrics): string
    {
        $initTime = $metrics['initialization_time'] ?? 0;
        $memoryImpact = $metrics['memory_impact'] ?? 0;
        // Calculate rating based on initialization time and memory impact
        if ($initTime < 5 && $memoryImpact < 10240) { // Less than 5ms and 10KB
            return 'Excellent';
        } elseif ($initTime < 20 && $memoryImpact < 102400) { // Less than 20ms and 100KB
            return 'Good';
        } elseif ($initTime < 100 && $memoryImpact < 1048576) { // Less than 100ms and 1MB
            return 'Average';
        } else {
            return 'Poor';
        }
    }

    /**
     * Validate extension security
     *
     * Scans extension code for potential security issues:
     * - Dangerous functions like eval()
     * - Untrusted inputs in critical operations
     * - Insecure file operations
     * - SQL injection vulnerabilities
     *
     * @param string $extensionName Name of the extension to validate
     * @return array Result with success status and detected issues
     */
    public static function validateExtensionSecurity(string $extensionName): array
    {
        $extensionDir = self::getExtensionPath($extensionName);
        $issues = [];

        // Skip if extension directory doesn't exist
        if (!is_dir($extensionDir)) {
            return [
                'success' => false,
                'issues' => [['type' => 'error', 'message' => "Extension directory not found: $extensionDir"]]
            ];
        }

        // Check for obvious security issues
        $patterns = [
            'eval\s*\(' => 'Avoid using eval() as it can lead to code injection',
            'file_get_contents\s*\(\s*\$_' => 'Untrusted user input used in file operations',
            'include\s*\(\s*\$_' => 'Dynamic includes with user input are vulnerable to inclusion attacks',
            'exec\s*\(\s*\$_' => 'Command injection vulnerability with unfiltered user input',
            'system\s*\(\s*\$_' => 'Command injection vulnerability with unfiltered user input',
            'shell_exec\s*\(\s*\$_' => 'Command injection vulnerability with unfiltered user input',
            'unserialize\s*\(\s*\$_' => 'Unserializing user input can lead to code execution',
            'mysql_query\s*\(\s*["\']\s*SELECT.+\$_' => 'Possible SQL injection with unfiltered user input',
            'mysqli.*->query\s*\(\s*["\']\s*SELECT.+\$_' => 'Possible SQL injection with unfiltered user input',
        ];

        foreach ($patterns as $pattern => $message) {
            exec("grep -r '$pattern' $extensionDir --include='*.php'", $matches);
            if (!empty($matches)) {
                $issues[] = [
                    'type' => 'security',
                    'message' => $message,
                    'matches' => $matches
                ];
            }
        }

        // Check for user input directly reflected in HTML/JS context
        exec("grep -r 'echo\s*\$_' $extensionDir --include='*.php'", $reflectionMatches);
        if (!empty($reflectionMatches)) {
            $issues[] = [
                'type' => 'security',
                'message' => 'Possible XSS vulnerability with direct output of user input',
                'matches' => $reflectionMatches
            ];
        }

        // Check for hardcoded credentials
        exec("grep -r '(password|secret|key|token)\\s*=\\s*[\"\\'\"][^\"\\'\\\$]+[\"\\'\"]' " .
             "$extensionDir --include='*.php'", $credentialMatches);
        if (!empty($credentialMatches)) {
            $issues[] = [
                'type' => 'security',
                'message' => 'Potential hardcoded credentials found',
                'matches' => $credentialMatches
            ];
        }

        return [
            'success' => empty($issues),
            'issues' => $issues
        ];
    }

    /**
     * Create a complete extension configuration entry for schema v2.0
     *
     * @param string $extensionName Extension name
     * @param string $version Extension version
     * @param string $installPath Installation path
     * @return array Complete extension configuration
     */
    private static function createExtensionConfigEntry(
        string $extensionName,
        string $version,
        string $installPath
    ): array {
        // Extract autoload mappings from extension's composer.json
        $autoload = self::extractExtensionAutoloadMappings($extensionName, $installPath);

        // Extract dependencies
        $dependencies = self::extractExtensionDependencies($extensionName, $installPath);

        // Extract provides information
        $provides = self::extractExtensionProvides($extensionName, $installPath);

        // Extract config information
        $config = self::extractExtensionConfig($extensionName, $installPath);

        return [
            'version' => $version,
            'enabled' => false, // Start disabled by default
            'type' => 'optional', // Default type, can be updated later
            'description' => $config['description'] ?? "Extension: {$extensionName}",
            'author' => $config['author'] ?? 'Unknown',
            'license' => $config['license'] ?? 'MIT',
            'installPath' => $installPath,
            'autoload' => $autoload,
            'dependencies' => $dependencies,
            'provides' => $provides,
            'config' => $config
        ];
    }

    /**
     * Extract autoload mappings from extension's composer.json
     */
    private static function extractExtensionAutoloadMappings(string $extensionName, string $installPath): array
    {
        $autoload = ['psr-4' => []];
        $composerPath = dirname(__DIR__, 2) . "/{$installPath}/composer.json";

        if (!file_exists($composerPath)) {
            // Fallback: assume standard structure
            $autoload['psr-4']["Glueful\\Extensions\\{$extensionName}\\"] = "{$installPath}/src/";
            return $autoload;
        }

        try {
            $composerConfig = json_decode(file_get_contents($composerPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON in composer.json');
            }

            // Extract PSR-4 mappings
            if (isset($composerConfig['autoload']['psr-4'])) {
                foreach ($composerConfig['autoload']['psr-4'] as $namespace => $path) {
                    $autoload['psr-4'][$namespace] = "{$installPath}/" . ltrim($path, '/');
                }
            }

            // Extract dev PSR-4 mappings
            if (isset($composerConfig['autoload-dev']['psr-4'])) {
                foreach ($composerConfig['autoload-dev']['psr-4'] as $namespace => $path) {
                    $autoload['psr-4'][$namespace] = "{$installPath}/" . ltrim($path, '/');
                }
            }

            // Extract files
            if (isset($composerConfig['autoload']['files'])) {
                $autoload['files'] = array_map(
                    fn($file) => "{$installPath}/" . ltrim($file, '/'),
                    $composerConfig['autoload']['files']
                );
            }
        } catch (\Exception $e) {
            // Fallback on error
            $autoload['psr-4']["Glueful\\Extensions\\{$extensionName}\\"] = "{$installPath}/src/";
        }

        return $autoload;
    }

    /**
     * Extract dependencies from extension's composer.json
     */
    private static function extractExtensionDependencies(string $extensionName, string $installPath): array
    {
        $dependencies = [
            'php' => '>=8.2',
            'extensions' => [],
            'packages' => []
        ];

        $composerPath = dirname(__DIR__, 2) . "/{$installPath}/composer.json";
        if (!file_exists($composerPath)) {
            return $dependencies;
        }

        try {
            $composerConfig = json_decode(file_get_contents($composerPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $dependencies;
            }

            // Extract PHP version
            if (isset($composerConfig['require']['php'])) {
                $dependencies['php'] = $composerConfig['require']['php'];
            }

            // Extract package dependencies
            foreach ($composerConfig['require'] ?? [] as $package => $version) {
                if ($package !== 'php' && !str_starts_with($package, 'ext-')) {
                    $dependencies['packages'][$package] = $version;
                }
            }

            // Extract extension dependencies
            if (isset($composerConfig['extra']['glueful']['requires']['extensions'])) {
                $dependencies['extensions'] = $composerConfig['extra']['glueful']['requires']['extensions'];
            }
        } catch (\Exception $e) {
            // Return defaults on error
        }

        return $dependencies;
    }

    /**
     * Extract provides information from extension structure
     */
    private static function extractExtensionProvides(string $extensionName, string $installPath): array
    {
        $provides = [
            'main' => "{$installPath}/{$extensionName}.php",
            'services' => [],
            'routes' => [],
            'middleware' => [],
            'commands' => [],
            'migrations' => []
        ];

        $extensionPath = dirname(__DIR__, 2) . "/{$installPath}";

        // Look for common files
        $commonFiles = [
            'routes' => ['routes.php', 'src/routes.php'],
            'services' => ['services.php', 'src/services.php', "src/Services/{$extensionName}ServiceProvider.php"],
        ];

        foreach ($commonFiles as $type => $files) {
            foreach ($files as $file) {
                $fullPath = "{$extensionPath}/{$file}";
                if (file_exists($fullPath)) {
                    $provides[$type][] = "{$installPath}/{$file}";
                }
            }
        }

        // Find migrations
        $migrationDirs = ['migrations', 'database/migrations'];
        foreach ($migrationDirs as $dir) {
            $fullDir = "{$extensionPath}/{$dir}";
            if (is_dir($fullDir)) {
                foreach (glob("{$fullDir}/*.php") as $migrationFile) {
                    $provides['migrations'][] = "{$installPath}/{$dir}/" . basename($migrationFile);
                }
            }
        }

        return $provides;
    }

    /**
     * Extract config information from extension's composer.json
     */
    private static function extractExtensionConfig(string $extensionName, string $installPath): array
    {
        $config = [
            'categories' => [],
            'publisher' => 'unknown',
            'icon' => "{$installPath}/assets/icon.png",
            'description' => "Extension: {$extensionName}",
            'author' => 'Unknown',
            'license' => 'MIT'
        ];

        $composerPath = dirname(__DIR__, 2) . "/{$installPath}/composer.json";
        if (!file_exists($composerPath)) {
            return $config;
        }

        try {
            $composerConfig = json_decode(file_get_contents($composerPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $config;
            }

            // Extract basic info
            $config['description'] = $composerConfig['description'] ?? $config['description'];
            $config['license'] = $composerConfig['license'] ?? $config['license'];

            if (isset($composerConfig['authors'][0]['name'])) {
                $config['author'] = $composerConfig['authors'][0]['name'];
            }

            // Extract from extra.glueful section
            if (isset($composerConfig['extra']['glueful'])) {
                $extra = $composerConfig['extra']['glueful'];

                $config['categories'] = $extra['categories'] ?? [];
                $config['publisher'] = $extra['publisher'] ?? 'unknown';

                if (isset($extra['icon'])) {
                    $config['icon'] = "{$installPath}/" . ltrim($extra['icon'], './');
                }

                if (isset($extra['galleryBanner'])) {
                    $config['galleryBanner'] = $extra['galleryBanner'];
                }

                if (isset($extra['features'])) {
                    $config['features'] = $extra['features'];
                }
            }
        } catch (\Exception $e) {
            // Return defaults on error
        }

        return $config;
    }

    /**
     * Fetch extensions catalog from GitHub repository
     *
     * Retrieves the extension catalog data from the official Glueful catalog repository.
     * This method provides access to the latest available extensions with their metadata,
     * including versions, descriptions, download URLs, and compatibility information.
     *
     * @param int $timeout Request timeout in seconds (default: 30)
     * @param bool $useCache Whether to use cached data if available (default: true)
     * @return array|null Returns catalog data array on success, null on failure
     * @throws \Exception When the request fails or returns invalid data
     */
    public static function fetchExtensionsCatalog(int $timeout = 30, bool $useCache = true): ?array
    {
        $catalogUrl = 'https://raw.githubusercontent.com/glueful/catalog/main/catalog.json';
        $cacheKey = 'extensions_catalog';
        $cacheTtl = 3600; // Cache for 1 hour

        // Try to get from cache first if enabled
        if ($useCache && function_exists('apcu_exists') && apcu_exists($cacheKey)) {
            self::debug("Loading extensions catalog from cache");
            return apcu_fetch($cacheKey) ?: null;
        }

        self::debug("Fetching extensions catalog from: {$catalogUrl}");

        // Initialize cURL
        $curl = curl_init();
        if ($curl === false) {
            self::debug("Failed to initialize cURL");
            return null;
        }

        try {
            // Configure cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $catalogUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Glueful-Framework/' . (config('app.version_full') ?? '1.0.0'),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ]
            ]);

            // Execute request
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);

            if ($response === false || !empty($error)) {
                self::debug("cURL error: {$error}");
                return null;
            }

            if ($httpCode !== 200) {
                self::debug("HTTP error: {$httpCode}");
                return null;
            }

            // Parse JSON response
            $catalogData = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::debug("JSON decode error: " . json_last_error_msg());
                return null;
            }

            // Validate catalog structure
            if (
                !is_array($catalogData) ||
                !isset($catalogData['extensions']) ||
                !is_array($catalogData['extensions'])
            ) {
                self::debug("Invalid catalog structure");
                return null;
            }

            // Validate extension entries
            foreach ($catalogData['extensions'] as $extension) {
                if (!is_array($extension) || !isset($extension['name']) || !isset($extension['version'])) {
                    self::debug("Invalid extension entry found in catalog");
                    return null;
                }
            }

            self::debug("Successfully fetched catalog with " . count($catalogData['extensions']) . " extensions");

            // Cache the result if caching is enabled
            if ($useCache && function_exists('apcu_store')) {
                apcu_store($cacheKey, $catalogData, $cacheTtl);
                self::debug("Cached catalog data for {$cacheTtl} seconds");
            }

            return $catalogData;
        } catch (\Exception $e) {
            self::debug("Exception while fetching catalog: " . $e->getMessage());
            return null;
        } finally {
            curl_close($curl);
        }
    }

    /**
     * Get available extensions from catalog
     *
     * Returns a filtered list of extensions from the catalog, optionally
     * filtered by tags, compatibility, or other criteria.
     *
     * @param array $filters Optional filters to apply
     * @param bool $useCache Whether to use cached catalog data
     * @return array Array of extension information
     */
    public static function getAvailableExtensions(array $filters = [], bool $useCache = true): array
    {
        $catalog = self::fetchExtensionsCatalog(30, $useCache);
        if (!$catalog || !isset($catalog['extensions'])) {
            return [];
        }

        $extensions = $catalog['extensions'];

        // Apply filters if provided
        if (!empty($filters)) {
            $extensions = array_filter($extensions, function ($extension) use ($filters) {
                // Filter by tags
                if (isset($filters['tags']) && is_array($filters['tags'])) {
                    $extensionTags = $extension['tags'] ?? [];
                    if (!array_intersect($filters['tags'], $extensionTags)) {
                        return false;
                    }
                }

                // Filter by minimum rating
                if (isset($filters['min_rating']) && is_numeric($filters['min_rating'])) {
                    $rating = $extension['rating'] ?? 0;
                    if ($rating < $filters['min_rating']) {
                        return false;
                    }
                }

                // Filter by publisher
                if (isset($filters['publisher']) && is_string($filters['publisher'])) {
                    $publisher = $extension['publisher'] ?? '';
                    if (stripos($publisher, $filters['publisher']) === false) {
                        return false;
                    }
                }

                // Filter by search term
                if (isset($filters['search']) && is_string($filters['search'])) {
                    $searchTerm = strtolower($filters['search']);
                    $searchableText = strtolower(
                        ($extension['name'] ?? '') . ' ' .
                        ($extension['displayName'] ?? '') . ' ' .
                        ($extension['description'] ?? '') . ' ' .
                        implode(' ', $extension['tags'] ?? [])
                    );
                    if (strpos($searchableText, $searchTerm) === false) {
                        return false;
                    }
                }

                return true;
            });
        }

        return array_values($extensions);
    }

    /**
     * Clear the extensions catalog cache
     *
     * Removes the cached catalog data, forcing a fresh fetch on the next request.
     *
     * @return bool True if cache was cleared successfully
     */
    public static function clearCatalogCache(): bool
    {
        $cacheKey = 'extensions_catalog';

        if (function_exists('apcu_exists') && apcu_exists($cacheKey)) {
            $result = apcu_delete($cacheKey);
            self::debug($result ? "Catalog cache cleared successfully" : "Failed to clear catalog cache");
            return $result;
        }

        return true; // No cache to clear
    }

    /**
     * Get synchronized catalog data with local extension status
     *
     * Fetches the GitHub catalog and enriches each extension with local status information:
     * - "installed": whether the extension files exist locally
     * - "enabled": whether the extension is currently enabled
     *
     * This provides a complete view of available extensions and their local status,
     * useful for extension management interfaces and API endpoints.
     *
     * @param array $filters Optional filters to apply to the catalog
     * @param bool $useCache Whether to use cached catalog data
     * @return array Enriched catalog data with local status information
     */
    public static function getSynchronizedCatalog(array $filters = [], bool $useCache = true): array
    {
        // Fetch the remote catalog
        $catalog = self::fetchExtensionsCatalog(30, $useCache);
        if (!$catalog || !isset($catalog['extensions'])) {
            self::debug("Failed to fetch catalog or no extensions found");
            return ['extensions' => [], 'metadata' => ['source' => 'empty', 'synchronized_at' => date('c')]];
        }

        // Get local extension configuration
        $localConfig = self::loadExtensionsConfig();
        $localExtensions = $localConfig['extensions'] ?? [];

        self::debug("Synchronizing " . count($catalog['extensions']) . " catalog extensions with local status");

        // Enrich each catalog extension with local status
        $enrichedExtensions = [];
        foreach ($catalog['extensions'] as $extension) {
            $extensionName = $extension['name'] ?? '';

            if (empty($extensionName)) {
                self::debug("Skipping extension with empty name");
                continue;
            }

            // Check if extension is installed (files exist locally)
            $isInstalled = self::findExtension($extensionName, true) !== null;

            // Check if extension is enabled in configuration
            $isEnabled = self::isExtensionEnabled($extensionName);

            // Get local metadata if available
            $localMetadata = [];
            if (isset($localExtensions[$extensionName])) {
                $localMetadata = [
                    'local_version' => $localExtensions[$extensionName]['version'] ?? null,
                    'installed_at' => $localExtensions[$extensionName]['installed_at'] ?? null,
                    'install_path' => $localExtensions[$extensionName]['install_path'] ?? null,
                    'local_config' => $localExtensions[$extensionName] ?? []
                ];
            }

            // Create enriched extension data
            $enrichedExtension = array_merge($extension, [
                'installed' => $isInstalled,
                'enabled' => $isEnabled,
                'local_metadata' => $localMetadata,
                'status' => self::getExtensionStatusSummary($isInstalled, $isEnabled),
                'actions_available' => self::getAvailableActions($extensionName, $isInstalled, $isEnabled)
            ]);

            $enrichedExtensions[] = $enrichedExtension;
        }

        // Apply filters if provided
        if (!empty($filters)) {
            $enrichedExtensions = array_filter($enrichedExtensions, function ($extension) use ($filters) {
                // Filter by installation status
                if (isset($filters['installed']) && is_bool($filters['installed'])) {
                    if ($extension['installed'] !== $filters['installed']) {
                        return false;
                    }
                }

                // Filter by enabled status
                if (isset($filters['enabled']) && is_bool($filters['enabled'])) {
                    if ($extension['enabled'] !== $filters['enabled']) {
                        return false;
                    }
                }

                // Filter by status
                if (isset($filters['status']) && is_string($filters['status'])) {
                    if ($extension['status'] !== $filters['status']) {
                        return false;
                    }
                }

                // Apply other filters from parent method
                return self::applyStandardFilters($extension, $filters);
            });
        }

        // Generate metadata about the synchronization
        $metadata = [
            'source' => 'github_catalog',
            'catalog_url' => 'https://raw.githubusercontent.com/glueful/catalog/main/catalog.json',
            'synchronized_at' => date('c'),
            'total_available' => count($catalog['extensions']),
            'total_after_filters' => count($enrichedExtensions),
            'summary' => [
                'installed' => count(array_filter($enrichedExtensions, fn($ext) => $ext['installed'])),
                'enabled' => count(array_filter($enrichedExtensions, fn($ext) => $ext['enabled'])),
                'available_for_install' => count(array_filter($enrichedExtensions, fn($ext) => !$ext['installed'])),
                'disabled' => count(array_filter(
                    $enrichedExtensions,
                    fn($ext) => $ext['installed'] && !$ext['enabled']
                ))
            ]
        ];

        self::debug("Synchronization complete: " . json_encode($metadata['summary']));

        return [
            'extensions' => array_values($enrichedExtensions),
            'metadata' => $metadata
        ];
    }

    /**
     * Get extension status summary
     *
     * @param bool $isInstalled Whether the extension is installed
     * @param bool $isEnabled Whether the extension is enabled
     * @return string Status summary
     */
    private static function getExtensionStatusSummary(bool $isInstalled, bool $isEnabled): string
    {
        if (!$isInstalled) {
            return 'available';
        }

        if ($isEnabled) {
            return 'active';
        }

        return 'inactive';
    }

    /**
     * Get available actions for an extension
     *
     * @param string $extensionName Extension name
     * @param bool $isInstalled Whether the extension is installed
     * @param bool $isEnabled Whether the extension is enabled
     * @return array List of available actions
     */
    private static function getAvailableActions(string $extensionName, bool $isInstalled, bool $isEnabled): array
    {
        $actions = [];

        if (!$isInstalled) {
            $actions[] = 'install';
        } else {
            if ($isEnabled) {
                $actions[] = 'disable';
                $actions[] = 'uninstall';
                $actions[] = 'update';
            } else {
                $actions[] = 'enable';
                $actions[] = 'uninstall';
                $actions[] = 'update';
            }
        }

        $actions[] = 'view_details';
        $actions[] = 'view_readme';

        return $actions;
    }

    /**
     * Apply standard catalog filters
     *
     * @param array $extension Extension data
     * @param array $filters Filters to apply
     * @return bool Whether the extension passes the filters
     */
    private static function applyStandardFilters(array $extension, array $filters): bool
    {
        // Filter by tags
        if (isset($filters['tags']) && is_array($filters['tags'])) {
            $extensionTags = $extension['tags'] ?? [];
            if (!array_intersect($filters['tags'], $extensionTags)) {
                return false;
            }
        }

        // Filter by minimum rating
        if (isset($filters['min_rating']) && is_numeric($filters['min_rating'])) {
            $rating = $extension['rating'] ?? 0;
            if ($rating < $filters['min_rating']) {
                return false;
            }
        }

        // Filter by publisher
        if (isset($filters['publisher']) && is_string($filters['publisher'])) {
            $publisher = $extension['publisher'] ?? '';
            if (stripos($publisher, $filters['publisher']) === false) {
                return false;
            }
        }

        // Filter by search term
        if (isset($filters['search']) && is_string($filters['search'])) {
            $searchTerm = strtolower($filters['search']);
            $searchableText = strtolower(
                ($extension['name'] ?? '') . ' ' .
                ($extension['displayName'] ?? '') . ' ' .
                ($extension['description'] ?? '') . ' ' .
                implode(' ', $extension['tags'] ?? [])
            );
            if (strpos($searchableText, $searchTerm) === false) {
                return false;
            }
        }

        return true;
    }
}
