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
                // For example: Glueful\Extensions\GithubAuthProvider ->
                // extensions/SocialLogin/Providers/GithubAuthProvider
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
                    if (
                        !isset($metadata['type'])
                        && isset($metadata['requiredBy'])
                        && !empty($metadata['requiredBy'])
                    ) {
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
                'message' => "Cannot disable core extension '$extensionName'. "
                    . "This extension is required for core functionality.",
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

        // Create directories for extension assets
        $directories = [
            "$extensionDir/screenshots",
        ];

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

        return [
            'success' => true,
            'message' => "Extension '{$extensionName}' created successfully using '{$templateType}' template",
            'data' => [
                'name' => $extensionName,
                'path' => $extensionDir,
                'template' => $templateType,
                'files' => $filesCreated,
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
        $extensionClass = self::findExtension($extensionName);
        if (!$extensionClass) {
            return null;
        }

        $reflection = new \ReflectionClass($extensionClass);
        return dirname($reflection->getFileName());
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
}
