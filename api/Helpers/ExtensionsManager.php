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
class ExtensionsManager {
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
            foreach($directories as $directory) {
                $dir = dirname(__DIR__,2) . '/' . $directory;
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
    ): void
    {
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
    ): void
    {
        // Common directories within extensions that might contain classes
        $commonDirs = ['Providers', 'migrations', 'Models', 'Controllers', 'Services'];
        
        foreach ($commonDirs as $dir) {
            $subdirPath = $extensionPath . '/' . $dir;
            if (is_dir($subdirPath)) {
                // Register a fallback namespace to catch misreferenced classes
                // For example: Glueful\Extensions\GithubAuthProvider -> extensions/SocialLogin/Providers/GithubAuthProvider
                $aliasNamespace = "Glueful\\Extensions\\";
                $classLoader->addPsr4($aliasNamespace, $extensionPath . '/' . $dir . '/');
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
     * @param string $extensionName Extension name
     * @return bool Success status
     */
    public static function enableExtension(string $extensionName): bool
    {
        $configFile = dirname(__DIR__) . '/../../config/extensions.php';
        $config = file_exists($configFile) ? include $configFile : ['enabled' => []];
        
        if (in_array($extensionName, $config['enabled'] ?? [])) {
            return true; // Already enabled
        }
        
        $config['enabled'][] = $extensionName;
        return self::saveConfig($configFile, $config);
    }

    /**
     * Disable an extension
     * 
     * @param string $extensionName Extension name
     * @return bool Success status
     */
    public static function disableExtension(string $extensionName): bool
    {
        $configFile = dirname(__DIR__) . '/../../config/extensions.php';
        
        if (!file_exists($configFile)) {
            return true; // Nothing to disable
        }
        
        $config = include $configFile;
        $enabledExtensions = $config['enabled'] ?? [];
        
        if (!in_array($extensionName, $enabledExtensions)) {
            return true; // Already disabled
        }
        
        $config['enabled'] = array_diff($enabledExtensions, [$extensionName]);
        return self::saveConfig($configFile, $config);
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
     * Get all enabled extensions
     * 
     * @return array List of enabled extension names
     */
    public static function getEnabledExtensions(): array
    {
        $configFile = dirname(__DIR__) . '/../../config/extensions.php';
        if (!file_exists($configFile)) {
            return [];
        }
        
        $config = include $configFile;
        return $config['enabled'] ?? [];
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
            'enabled' => [],
            'paths' => [
                'extensions' => config('paths.project_extensions'),
            ]
        ];
        // Create the config file with default values
        return self::saveConfig($configFile, $defaultConfig);
    }
}