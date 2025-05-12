<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Composer\Autoload\ClassLoader;

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
    /** @var array Loaded extension instances */
    private static array $loadedExtensions = [];

    /** @var array Extension namespaces and their directories */
    private static array $extensionNamespaces = [
        'Glueful\\Extensions\\' => ['extensions']
    ];

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

        foreach (self::$extensionNamespaces as $namespace => $directories) {
            foreach ($directories as $directory) {
                $dir = dirname(__DIR__, 2) . '/' . $directory;
                self::scanAndLoadExtensions($dir, $namespace);
            }
        }

        // Initialize all loaded extensions
        self::initializeExtensions();
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

        // First, register the base Extensions namespace
        // This is now required since we've removed it from composer.json
        foreach (self::$extensionNamespaces as $namespace => $directories) {
            foreach ($directories as $directory) {
                $dir = dirname(__DIR__, 2) . '/' . $directory;
                if (!is_dir($dir)) {
                    continue;
                }

                // Register main namespace
                $classLoader->addPsr4($namespace, $dir);
                self::debug("Registered base namespace {$namespace} to {$dir}");
            }
        }

        // Then register each extension's specific namespace
        foreach (self::$extensionNamespaces as $namespace => $directories) {
            foreach ($directories as $directory) {
                $dir = dirname(__DIR__, 2) . '/' . $directory;
                if (!is_dir($dir)) {
                    continue;
                }

                // Register specific extensions with their full namespaces
                foreach (glob($dir . '/*', GLOB_ONLYDIR) as $extensionDir) {
                    $extensionName = basename($extensionDir);
                    $fullNamespace = $namespace . $extensionName . '\\';
                    $classLoader->addPsr4($fullNamespace, $extensionDir . '/');
                    self::debug("Registered extension namespace {$fullNamespace} to {$extensionDir}/");

                    // Register all subdirectories with their matching namespaces
                    self::registerSubdirectoryNamespaces($classLoader, $extensionDir, $fullNamespace);

                    // Also register alias for common subdirectories to help with misreferenced classes
                    self::registerLegacyAliases($classLoader, $extensionDir, $namespace, $extensionName);
                }
            }
        }
    }

    /**
     * Register all subdirectories within an extension with their proper namespaces
     *
     * @param ClassLoader $classLoader Composer's class loader
     * @param string $extensionPath Path to the extension
     * @param string $baseNamespace Base namespace for the extension
     * @return void
     */
    private static function registerSubdirectoryNamespaces(
        ClassLoader $classLoader,
        string $extensionPath,
        string $baseNamespace
    ): void {
        // Use RecursiveDirectoryIterator to find all subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extensionPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $subdir = $item->getPathname();
                $relativePath = substr($subdir, strlen($extensionPath) + 1);

                if (empty($relativePath)) {
                    continue; // Skip the root extension directory
                }

                // Convert directory path to namespace format
                $namespaceSegment = str_replace('/', '\\', $relativePath);
                $subdirNamespace = $baseNamespace . $namespaceSegment . '\\';

                // Register this subdirectory with its corresponding namespace
                $classLoader->addPsr4($subdirNamespace, $subdir . '/');
            }
        }
    }

    /**
     * Register legacy namespace aliases for backwards compatibility
     *
     * @param ClassLoader $classLoader Composer's class loader
     * @param string $extensionPath Path to the extension
     * @param string $namespace Base namespace for extensions
     * @param string $extensionName Name of the extension
     * @return void
     */
    private static function registerLegacyAliases(
        ClassLoader $classLoader,
        string $extensionPath,
        string $namespace,
        string $extensionName
    ): void {
        // Common directories within extensions that might contain classes
        $commonDirs = ['Providers', 'migrations', 'Models', 'Controllers', 'Services'];

        foreach ($commonDirs as $dir) {
            $subdirPath = $extensionPath . '/' . $dir;
            if (is_dir($subdirPath)) {
                // Register a fallback namespace to catch misreferenced classes
                // For example: Glueful\Extensions\GithubAuthProvider -> extensions/SocialLogin/Providers/GithubAuthProvider
                $aliasNamespace = "Glueful\\Extensions\\";
                $classLoader->addPsr4(
                    $aliasNamespace,
                    $extensionPath . '/' . $dir . '/'
                );
            }
        }
    }

    /**
     * Register a new extension namespace
     *
     * Allows plugins to register additional extension namespaces
     *
     * @param string $namespace Base namespace for extensions
     * @param array $directories Directories to scan for extensions
     * @return void
     */
    public static function registerExtensionNamespace(string $namespace, array $directories): void
    {
        self::$extensionNamespaces[$namespace] = $directories;
    }

    /**
     * Scan directory and load extension classes
     *
     * Recursively scans directory for PHP files and loads any
     * classes that extend the Extensions base class.
     *
     * @param string $dir Directory to scan
     * @param string $namespace Base namespace for discovered classes
     * @return void
     */
    private static function scanAndLoadExtensions(string $dir, string $namespace): void
    {
        if (!is_dir($dir)) {
            error_log("Directory does not exist: $dir");
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen($dir));
                $filename = basename($relativePath);
                $className = str_replace('.php', '', $filename);
                $fullClassName = $namespace . $className;

                // Only attempt to load the class if we haven't already loaded it
                if (!class_exists($fullClassName, false)) {
                    try {
                        include_once $file->getPathname();
                    } catch (\Throwable $e) {
                        error_log("Error including file: " . $e->getMessage());
                    }
                }

                // Check if the class exists and extends the base Extensions class
                if (class_exists($fullClassName, false)) {
                    $reflection = new \ReflectionClass($fullClassName);

                    // Only register classes that extend the Extensions base class
                    if ($reflection->isSubclassOf(\Glueful\Extensions::class)) {
                        if (!in_array($fullClassName, self::$loadedExtensions, true)) {
                            self::$loadedExtensions[] = $fullClassName;
                            self::debug("Loaded extension: {$fullClassName}");
                        } else {
                            self::debug("Skipping duplicate extension: {$fullClassName}");
                        }
                    }
                }
            }
        }
    }

    /**
     * Initialize all loaded extensions
     *
     * Calls appropriate lifecycle methods on extensions based on
     * what interfaces they implement:
     * - For service providers: registerServices()
     * - For middleware providers: registerMiddleware()
     * - For all extensions: initialize()
     *
     * @return void
     */
    private static function initializeExtensions(): void
    {
        foreach (self::$loadedExtensions as $extensionClass) {
            try {
                $reflection = new \ReflectionClass($extensionClass);

                // Call general initialize method if it exists
                if ($reflection->hasMethod('initialize')) {
                    $extensionClass::initialize();
                }

                // Register services if method exists
                if ($reflection->hasMethod('registerServices')) {
                    $extensionClass::registerServices();
                }

                // Register middleware if method exists
                if ($reflection->hasMethod('registerMiddleware')) {
                    $extensionClass::registerMiddleware();
                }
            } catch (\Exception $e) {
                error_log("Error initializing extension {$extensionClass}: " . $e->getMessage());
            }
        }
    }

    /**
     * Get all loaded extensions
     *
     * @return array List of loaded extension class names
     */
    public static function getLoadedExtensions(): array
    {
        self::loadExtensions();
        return self::$loadedExtensions;
    }

    /**
     * Check if extension is enabled
     *
     * @param string $extensionName Extension name
     * @return bool True if extension is enabled
     */
    public static function isExtensionEnabled(string $extensionName): bool
    {
        $configFile = dirname(__DIR__) . '/../../config/extensions.php';
        if (!file_exists($configFile)) {
            return false;
        }

        $config = include $configFile;
        return in_array($extensionName, $config['enabled'] ?? []);
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

        // Get config file
        $configFile = self::getConfigPath();
        $config = file_exists($configFile) ? include $configFile : ['enabled' => [], 'core' => [], 'optional' => []];

        // Ensure config has the required arrays for tiered structure
        if (!isset($config['core'])) {
            $config['core'] = [];
        }
        if (!isset($config['optional'])) {
            $config['optional'] = [];
        }
        if (!isset($config['enabled'])) {
            $config['enabled'] = [];
        }

        // Check if already enabled
        if (in_array($extensionName, $config['enabled'] ?? [])) {
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

        // Determine if the extension is core or optional if not already categorized
        if (!in_array($extensionName, $config['core']) && !in_array($extensionName, $config['optional'])) {
            // Get metadata to see if extension type is specified
            $isCore = false;
            try {
                if (method_exists($extensionClass, 'getMetadata')) {
                    $metadata = $extensionClass::getMetadata();
                    $isCore = isset($metadata['type']) && strtolower($metadata['type']) === 'core';

                    // If the type isn't explicitly specified, check if other core APIs depend on it
                    if (!isset($metadata['type']) 
                        && isset($metadata['requiredBy']) 
                        && !empty($metadata['requiredBy'])) {
                        $isCore = true;
                    }
                }
            } catch (\Throwable $e) {
                self::debug("Error getting metadata for $extensionName: " . $e->getMessage());
            }

            // Add to appropriate category
            if ($isCore) {
                $config['core'][] = $extensionName;
                self::debug("Adding $extensionName to core extensions");
            } else {
                $config['optional'][] = $extensionName;
                self::debug("Adding $extensionName to optional extensions");
            }
        }

        // All checks passed, enable the extension
        $config['enabled'][] = $extensionName;
        $saveSuccess = self::saveConfig($configFile, $config);

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
        // Get config file
        $configFile = self::getConfigPath();

        if (!file_exists($configFile)) {
            return [
                'success' => true,
                'message' => "No extensions are currently enabled"
            ];
        }

        $config = include $configFile;
        $enabledExtensions = $config['enabled'] ?? [];

        // Check if already disabled
        if (!in_array($extensionName, $enabledExtensions)) {
            return [
                'success' => true,
                'message' => "Extension '$extensionName' is already disabled"
            ];
        }

        // Check if this is a core extension
        $isCoreExtension = in_array($extensionName, $config['core'] ?? []);

        if ($isCoreExtension && !$force) {
            // This is a core extension and we're not forcing disable
            return [
                'success' => false,
                'message' => "Cannot disable core extension '$extensionName'. This extension is required for core functionality.",
                'details' => [
                    'is_core' => true,
                    'can_force' => true,
                    'warning' => "Disabling this extension may break core system functionality. " .
                        "Use force=true parameter to override."
                ]
            ];
        }

        // Check for dependent extensions
        $dependencyResults = self::checkDependenciesForDisable($extensionName);
        if (!$dependencyResults['success']) {
            return $dependencyResults;
        }

        // All checks passed, disable the extension
        $config['enabled'] = array_diff($enabledExtensions, [$extensionName]);
        $saveSuccess = self::saveConfig($configFile, $config);

        if (!$saveSuccess) {
            return [
                'success' => false,
                'message' => "Failed to save configuration when disabling '$extensionName'"
            ];
        }

        $message = "Extension '$extensionName' has been disabled successfully";

        // Add warning for core extensions that were force-disabled
        if ($isCoreExtension) {
            $message .= " (WARNING: This is a core extension and some system functionality may not work properly)";
        }

        return [
            'success' => true,
            'message' => $message,
            'is_core' => $isCoreExtension
        ];
    }

    /**
     * Save configuration to file
     *
     * @param string $file Config file path
     * @param array $config Configuration array
     * @return bool Success status
     */
    private static function saveConfig(string $file, array $config): bool
    {
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        return file_put_contents($file, $content) !== false;
    }

    /**
     * Find extension by name
     *
     * @param string $extensionName Extension name
     * @return string|null Full class name or null if not found
     */
    public static function findExtension(string $extensionName): ?string
    {
        foreach (self::$loadedExtensions as $extensionClass) {
            $reflection = new \ReflectionClass($extensionClass);
            if ($reflection->getShortName() === $extensionName) {
                return $extensionClass;
            }
        }

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
        $configDir = dirname(__DIR__) . '/../../config';

        // Ensure config directory exists
        if (!is_dir($configDir)) {
            // Only attempt to create if it doesn't exist
            if (!mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                // This is a more robust check: try to create it, and if that fails, check again if it exists
                throw new \RuntimeException("Failed to create config directory: $configDir");
            }
        }

        $configFile = $configDir . '/extensions.php';

        // Create the extensions.php file if it doesn't exist
        if (!file_exists($configFile)) {
            self::createConfigFile($configFile);
        }

        return $configFile;
    }

    private static function createConfigFile(string $configFile): bool
    {
        $defaultConfig = [
            'core' => [],
            'optional' => [],
            'enabled' => [],
            'paths' => [
                'extensions' => config('paths.project_extensions'),
            ]
        ];
        // Create the config file with default values
        return self::saveConfig($configFile, $defaultConfig);
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
    private static function checkDependenciesForDisable(string $extensionName): array
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
     * between all installed extensions.
     *
     * @return array The dependency graph
     */
    public static function buildDependencyGraph(): array
    {
        $graph = [
            'nodes' => [],
            'edges' => []
        ];

        $extensions = self::getLoadedExtensions();
        $enabledExtensions = self::getEnabledExtensions();
        $coreExtensions = self::getCoreExtensions();
        $optionalExtensions = self::getOptionalExtensions();

        // Create nodes for all extensions
        foreach ($extensions as $extensionClass) {
            $reflection = new \ReflectionClass($extensionClass);
            $shortName = $reflection->getShortName();

            try {
                $metadata = $extensionClass::getMetadata();

                // Determine if core or optional
                $extensionType = in_array($shortName, $coreExtensions) ? 'core' : 'optional';

                $graph['nodes'][] = [
                    'id' => $shortName,
                    'name' => $metadata['name'] ?? $shortName,
                    'enabled' => in_array($shortName, $enabledExtensions),
                    'type' => $extensionType,
                    'version' => $metadata['version'] ?? '1.0.0',
                    'description' => $metadata['description'] ?? ''
                ];

                // Get dependencies and create edges
                $dependencies = $extensionClass::getDependencies();
                foreach ($dependencies as $dependency) {
                    $graph['edges'][] = [
                        'from' => $shortName,
                        'to' => $dependency,
                        'type' => 'depends_on'
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
     * @param string|null $configFile Optional config file path
     * @return array List of enabled extension names
     */
    public static function getEnabledExtensions(?string $configFile = null): array
    {
        if ($configFile === null) {
            $configFile = self::getConfigPath();
        }

        if (!file_exists($configFile)) {
            return [];
        }

        $config = include $configFile;
        return $config['enabled'] ?? [];
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
    ): array
    {
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

        // If no target name provided, try to derive it from the source
        if (empty($targetName)) {
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                // Extract from URL
                $urlPath = parse_url($source, PHP_URL_PATH);
                $fileName = pathinfo($urlPath, PATHINFO_FILENAME);
                $targetName = self::sanitizeExtensionName($fileName);
            } else {
                // Extract from file path
                $fileName = pathinfo($source, PATHINFO_FILENAME);
                $targetName = self::sanitizeExtensionName($fileName);
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
        $extensionDir = config('paths.project_extensions') . $targetName;

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

        // Force reload the extensions to include the newly installed one
        self::$loadedExtensions = [];
        self::loadExtensions();

        return [
            'success' => true,
            'message' => "Extension '$targetName' installed successfully",
            'name' => $targetName
        ];
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
        $tempFile = $tempDir . '/' . md5($source . time()) . '.zip';

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
                if (!rename($extractedPath, $destDir)) {
                    self::debug("Failed to rename extracted folder to target name");
                    return false;
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

        // Combine results
        $result = [
            'success' => $structureValidation['success'] && $dependencyValidation['success'],
            'name' => $extensionName,
            'structureValidation' => $structureValidation,
            'dependencyValidation' => $dependencyValidation
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
    private static function validateExtensionStructure(\ReflectionClass $reflection): array
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
    private static function validateExtensionDependencies(\ReflectionClass $reflection): array
    {
        $extensionClass = $reflection->getName();
        $extensionName = $reflection->getShortName();
        $enabledExtensions = self::getEnabledExtensions();
        $issues = [];
        $dependencies = [];

        try {
            // Get dependencies from the extension
            if ($reflection->hasMethod('getDependencies')) {
                $dependencies = $extensionClass::getDependencies();
            }

            // Check if the extension implements getMetadata() method
            if ($reflection->hasMethod('getMetadata')) {
                $metadata = $extensionClass::getMetadata();
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
                    if (isset($rules['validate']) 
                        && $rules['validate'] === 'url' 
                        && !filter_var($metadata[$field], FILTER_VALIDATE_URL)) {
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

                    if ($screenshotsCount > 0 && (!isset($metadata['screenshots']) || empty($metadata['screenshots']))) {
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
            if (!isset($metadata['type'])) {
                $metadata['type'] = $extensionType;
            }

            // Create placeholder for marketplace data (to be populated by external system)
            if (!isset($metadata['rating'])) {
                $metadata['rating'] = [
                    'average' => 0,
                    'count' => 0,
                    'distribution' => []
                ];
            }

            if (!isset($metadata['stats'])) {
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
     * Generate extension class content
     *
     * Creates a new extension class with all required methods
     * and metadata following the Glueful Extension Metadata Standard.
     *
     * @param string $extensionName Extension name
     * @param string $extensionType Type of extension (core or optional)
     * @return string Generated class content
     */
    public static function generateExtensionClass(string $extensionName, string $extensionType = 'optional'): string
    {
        // Validate extension type
        if (!in_array($extensionType, ['core', 'optional'])) {
            $extensionType = 'optional'; // Default to optional if invalid type provided
        }

        return "<?php
    declare(strict_types=1);

    namespace Glueful\\Extensions;

    use Glueful\\Http\\Response;
    use Glueful\\Http\\Router;
    use Glueful\\Helpers\\Request;

/**
 * $extensionName Extension
 * 
 * @description Add your extension description here
 * @version 1.0.0
 * @author Your Name <your.email@example.com>
 */
class $extensionName extends \\Glueful\\Extensions
{
    /**
     * Extension configuration
     */
    private static array \$config = [];
    
    /**
     * Initialize extension
     * 
     * Called when the extension is loaded
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::\$config = require __DIR__ . '/config.php';
        }
        
        // Additional initialization code here
    }
    
    /**
     * Register extension-provided services
     * 
     * @return void
     */
    public static function registerServices(): void
    {
        // Register services here
    }
    
    /**
     * Register extension-provided middleware
     * 
     * @return void
     */
    public static function registerMiddleware(): void
    {
        // Register middleware here
    }
    
    /**
     * Process extension request
     * 
     * Main request handler for extension endpoints.
     * 
     * @param array \$getParams Query parameters
     * @param array \$postParams Post data
     * @return array Extension response
     */
    public static function process(array \$getParams, array \$postParams): array
    {
        // Example implementation of the process method
        \$action = \$getParams['action'] ?? 'default';
        
        return match(\$action) {
            'greet' => [
                'success' => true,
                'code' => 200,
                'data' => [
                    'message' => self::greet(\$getParams['name'] ?? 'World')
                ]
            ],
            'default' => [
                'success' => true,
                'code' => 200,
                'data' => [
                    'extension' => '$extensionName',
                    'message' => 'Extension is working properly'
                ]
            ],
            default => [
                'success' => false,
                'code' => 400,
                'error' => 'Unknown action: ' . \$action
            ]
        };
    }
    
    /**
     * Get extension metadata
     * 
     * This method follows the Glueful Extension Metadata Standard.
     * 
     * @return array Extension metadata for admin interface and marketplace
     */
    public static function getMetadata(): array
    {
        return [
            // Required fields
            'name' => '$extensionName',
            'description' => 'Add your extension description here',
            'version' => '1.0.0',
            'author' => 'Your Name',
            'type' => '$extensionType', // Indicates whether this is a core or optional extension
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => [],
                'dependencies' => []
            ],
            
            // Optional fields - uncomment and customize as needed
            // 'homepage' => 'https://example.com/$extensionName',
            // 'documentation' => 'https://docs.example.com/extensions/$extensionName',
            // 'license' => 'MIT',
            // 'keywords' => ['keyword1', 'keyword2', 'keyword3'],
            // 'category' => 'utilities',
            
            'features' => [
                'Feature 1 description',
                'Feature 2 description',
                'Feature 3 description'
            ],
            
            'compatibility' => [
                'browsers' => ['Chrome', 'Firefox', 'Safari', 'Edge'],
                'environments' => ['production', 'development'],
                'conflicts' => []
            ],
            
            'settings' => [
                'configurable' => true,
                'has_admin_ui' => false,
                'setup_required' => false,
                'default_config' => [
                    // Default configuration values
                    'setting1' => 'default_value',
                    'setting2' => true
                ]
            ],
            
            'support' => [
                'email' => 'your.email@example.com',
                'issues' => 'https://github.com/yourusername/$extensionName/issues'
            ]
        ];
    }
    
    /**
     * Get extension dependencies
     * 
     * Returns a list of other extensions this extension depends on.
     * 
     * @return array List of extension dependencies
     */
    public static function getDependencies(): array
    {
        // By default, get dependencies from metadata
        \$metadata = self::getMetadata();
        return \$metadata['requires']['extensions'] ?? [];
    }
    
    /**
     * Check environment-specific configuration
     * 
     * Determines if the extension should be enabled in the current environment.
     * 
     * @param string \$environment Current environment (dev, staging, production)
     * @return bool Whether the extension should be enabled in this environment
     */
    public static function isEnabledForEnvironment(string \$environment): bool
    {
        // By default, enable in all environments
        // Override this method to enable only in specific environments
        return true;
    }
    
    /**
     * Validate extension health
     * 
     * Checks if the extension is functioning correctly.
     * 
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        \$healthy = true;
        \$issues = [];
        
        // Example health check - verify config is loaded correctly
        if (empty(self::\$config) && file_exists(__DIR__ . '/config.php')) {
            \$healthy = false;
            \$issues[] = 'Configuration could not be loaded properly';
        }
        
        // Add your own health checks here
        
        return [
            'healthy' => \$healthy,
            'issues' => \$issues,
            'metrics' => [
                'memory_usage' => memory_get_usage(true),
                'execution_time' => 0, // You could track this with microtime()
                'database_queries' => 0, // Track queries if your extension uses the database
                'cache_usage' => 0 // Track cache usage if applicable
            ]
        ];
    }
    
    /**
     * Get extension resource usage
     * 
     * Returns information about resources used by this extension.
     * 
     * @return array Resource usage metrics
     */
    public static function getResourceUsage(): array
    {
        // Customize with your own resource metrics
        return [
            'memory_usage' => memory_get_usage(true),
            'execution_time' => 0,
            'database_queries' => 0,
            'cache_usage' => 0
        ];
    }
    
    /**
     * Get extension configuration
     * 
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::\$config;
    }
    
    /**
     * Set extension configuration
     * 
     * @param array \$config New configuration
     * @return void
     */
    public static function setConfig(array \$config): void
    {
        self::\$config = \$config;
    }
    
    /**
     * Example extension method
     * 
     * @param string \$name Name parameter
     * @return string Greeting message
     */
    public static function greet(string \$name): string
    {
        return \"Hello, {\$name}! Welcome to the $extensionName extension.\";
    }
}
";
    }

    /**
     * Generate README.md content for an extension
     *
     * @param string $extensionName Extension name
     * @return string Generated README.md content
     */
    public static function generateReadme(string $extensionName): string
    {
        return "# $extensionName Extension

This is a Glueful API extension.

## Features

- Add your features here

## Installation

1. Copy this directory to your `extensions/` folder
2. Enable the extension using:
   ```
   php glueful extensions enable $extensionName
   ```

## Usage

Add usage instructions here.

## Configuration

Add configuration instructions if needed.

## License

Add license information here.
";
    }

    /**
     * Generate config.php content for an extension
     *
     * @return string Generated config.php content
     */
    public static function generateConfig(): string
    {
        return "<?php
/**
 * Extension Configuration
 * 
 * Edit this file to customize your extension's behavior.
 */
return [
    // Add your configuration settings here
    'enabled' => true,
    'debug' => false,
    
    // Example settings
    'setting1' => 'default_value',
    'setting2' => true,
];
";
    }

    /**
     * Create a new extension
     *
     * Creates a new extension directory and scaffolds all necessary files.
     *
     * @param string $extensionName Extension name
     * @return array Result with success status and messages
     */
    public static function createExtension(string $extensionName): array
    {
        // Check if extension name is valid
        if (!self::isValidExtensionName($extensionName)) {
            return [
                'success' => false,
                'message' => "Invalid extension name '$extensionName'. Use PascalCase naming (e.g. MyExtension)"
            ];
        }

        $extensionsPath = config('paths.project_extensions');
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

        // Create directories for extension assets
        $directories = [
            "$extensionDir/screenshots",
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Create the main extension class file
        $mainClassFile = "$extensionDir/$extensionName.php";
        $mainClassContent = self::generateExtensionClass($extensionName);

        if (!file_put_contents($mainClassFile, $mainClassContent)) {
            // Clean up if failed
            self::rrmdir($extensionDir);
            return [
                'success' => false,
                'message' => "Failed to create extension class file"
            ];
        }

        // Create README.md
        $readmeFile = "$extensionDir/README.md";
        if (!file_put_contents($readmeFile, self::generateReadme($extensionName))) {
            return [
                'success' => false,
                'message' => "Failed to create README.md file"
            ];
        }

        // Create config.php
        $configFile = "$extensionDir/config.php";
        if (!file_put_contents($configFile, self::generateConfig())) {
            return [
                'success' => false,
                'message' => "Failed to create config.php file"
            ];
        }

        // Create CHANGELOG.md
        $changelogFile = "$extensionDir/CHANGELOG.md";
        $changelogContent = "# Changelog\n\n## 1.0.0 - " . date('Y-m-d') . "\n\n- Initial release\n";
        if (!file_put_contents($changelogFile, $changelogContent)) {
            return [
                'success' => false,
                'message' => "Failed to create CHANGELOG.md file"
            ];
        }

        // Force reload the extensions to include the new one
        self::$loadedExtensions = [];
        self::loadExtensions();

        return [
            'success' => true,
            'message' => "Extension scaffold created at: $extensionDir",
            'files' => [
                "$extensionName.php",
                "README.md",
                "config.php",
                "CHANGELOG.md",
                "screenshots/"
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

        $config = include $configFile;
        return $config['core'] ?? [];
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

        $config = include $configFile;
        return $config['optional'] ?? [];
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
        $extensionsPath = config('paths.project_extensions');
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
                'message' => "Cannot delete core extension '$extensionName'. This extension is required for core functionality.",
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
        $updateResult = [
            'success' => true,
            'message' => "Extension '$extensionName' has been updated successfully",
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
            }
        }

        // Clean up the backup directory if everything went well
        if ($updateResult['success']) {
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
        $configSources = config('extensions.update_sources', []);
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
                        $result['download_url'] = $updateInfo['download_url'] ?? null;
                        $result['release_notes'] = $updateInfo['release_notes'] ?? null;
                        $result['release_date'] = $updateInfo['release_date'] ?? null;
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
        try {
            $data = json_decode($response, true);

            // Validate response format
            if (!isset($data['version'])) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            self::debug("Failed to parse update API response: " . $e->getMessage());
            return null;
        }
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
}
