<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services;

use Glueful\Extensions\Services\Interfaces\ExtensionLoaderInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface;
use Glueful\Extensions\Enums\ExtensionStatus;
use Glueful\Extensions\ExtensionEventRegistry;
use Composer\Autoload\ClassLoader;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Glueful\DI\ContainerBootstrap;
use Psr\Log\LoggerInterface;

class ExtensionLoader implements ExtensionLoaderInterface
{
    private array $loadedExtensions = [];
    private array $registeredNamespaces = [];
    private ?ClassLoader $classLoader = null;
    private bool $debug = false;

    public function __construct(
        private ?FileFinder $fileFinder = null,
        private ?FileManager $fileManager = null,
        private ?LoggerInterface $logger = null,
        private ?ExtensionConfigInterface $extensionConfig = null
    ) {
        $this->initializeServices();
    }

    private function initializeServices(): void
    {
        if ($this->fileFinder === null || $this->fileManager === null || $this->extensionConfig === null) {
            try {
                $container = ContainerBootstrap::getContainer();
                $this->fileFinder ??= $container->get(FileFinder::class);
                $this->fileManager ??= $container->get(FileManager::class);
                $this->logger ??= $container->get(LoggerInterface::class);
                $this->extensionConfig ??= $container->get(ExtensionConfigInterface::class);
            } catch (\Exception $e) {
                // Fallback to creating directly if container not available
                $this->fileFinder ??= new FileFinder();
                $this->fileManager ??= new FileManager();
                if ($this->extensionConfig === null) {
                    $this->extensionConfig = new ExtensionConfig();
                }
            }
        }
    }

    public function setDebugMode(bool $enable = true): void
    {
        $this->debug = $enable;
    }

    public function setClassLoader(ClassLoader $classLoader): void
    {
        $this->classLoader = $classLoader;
    }

    public function getClassLoader(): ?ClassLoader
    {
        if ($this->classLoader === null) {
            // Try to get the ClassLoader from the Composer autoloader (like old system)
            foreach (spl_autoload_functions() as $function) {
                if (is_array($function) && $function[0] instanceof ClassLoader) {
                    $this->classLoader = $function[0];
                    break;
                }
            }
        }
        return $this->classLoader;
    }

    public function loadExtension(string $name): bool
    {
        if ($this->isLoaded($name)) {
            return true;
        }

        $this->debugLog("Loading extension: {$name}");

        $extensionPath = $this->getExtensionPath($name);
        if (!$extensionPath) {
            $this->debugLog("Extension path not found for: {$name}");
            return false;
        }

        // Validate structure first
        if (!$this->validateStructure($extensionPath)) {
            $this->debugLog("Extension structure validation failed for: {$name}");
            return false;
        }

        try {
            // Register namespace with autoloader (like current system)
            $this->registerNamespace($name, $extensionPath);

            // Load service providers (skip during bootstrap to avoid DI container issues)
            // $this->loadServiceProviders($name);

            // Load routes (skip during bootstrap to avoid DI container issues)
            // $this->loadRoutes($name);

            // Load the extension class
            $extensionClass = $this->loadExtensionClass($name, $extensionPath);

            // Mark as loaded
            $this->loadedExtensions[$name] = [
                'path' => $extensionPath,
                'class' => $extensionClass,
                'loaded_at' => time()
            ];

            $this->debugLog("Successfully loaded extension: {$name}");
            return true;
        } catch (\Exception $e) {
            $this->debugLog("Failed to load extension {$name}: " . $e->getMessage());
            return false;
        }
    }

    public function unloadExtension(string $name): bool
    {
        if (!$this->isLoaded($name)) {
            return true;
        }

        $this->debugLog("Unloading extension: {$name}");

        try {
            // Remove from loaded extensions
            unset($this->loadedExtensions[$name]);

            // Note: We don't remove namespaces or routes as they might be needed
            // This is a limitation of PHP - true unloading requires process restart

            $this->debugLog("Successfully unloaded extension: {$name}");
            return true;
        } catch (\Exception $e) {
            $this->debugLog("Failed to unload extension {$name}: " . $e->getMessage());
            return false;
        }
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->loadedExtensions[$name]);
    }

    public function getLoadedExtensions(): array
    {
        return array_keys($this->loadedExtensions);
    }

    public function validateStructure(string $path): bool
    {
        // Check if path exists and is directory
        if (!is_dir($path)) {
            return false;
        }

        // Check for manifest.json
        $manifestPath = $path . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return false;
        }

        // Validate manifest JSON
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // Check for main extension file
        $mainFile = $manifest['main'] ?? null;
        if (!$mainFile || !file_exists($path . '/' . $mainFile)) {
            return false;
        }

        return true;
    }

    public function registerNamespace(string $name, string $path): void
    {
        $classLoader = $this->getClassLoader();
        if (!$classLoader) {
            $this->debugLog("ClassLoader not available for namespace registration");
            return;
        }

        // Get autoload config from ExtensionConfig service (reduces duplication)
        $extensionConfig = $this->extensionConfig->getExtensionConfig($name);
        if (!empty($extensionConfig) && isset($extensionConfig['autoload']['psr-4'])) {
            $autoloadConfig = $extensionConfig['autoload']['psr-4'];

            // Use same approach as old ExtensionsManager: dirname(__DIR__, 2) but adjusted for our location
            $projectRoot = dirname(__DIR__, 3);

            foreach ($autoloadConfig as $namespace => $namespacePath) {
                $fullPath = $projectRoot . '/' . trim($namespacePath, '/');
                if (is_dir($fullPath)) {
                    $classLoader->addPsr4($namespace, $fullPath);
                    $this->registeredNamespaces[$namespace] = $fullPath;
                    $this->debugLog("Registered namespace {$namespace} => {$fullPath}");
                }
            }
            return;
        }

        // Fallback to default namespace registration
        $classLoader->addPsr4("{$name}\\", $path . '/src/');
        $this->registeredNamespaces[$name] = $path . '/src/';
        $this->debugLog("Registered default namespace for extension: {$name}");
    }

    public function getRegisteredNamespaces(): array
    {
        return $this->registeredNamespaces;
    }

    public function loadRoutes(string $name): void
    {
        $this->debugLog("Loading routes for extension: {$name}");

        $extensionConfig = $this->extensionConfig->getExtensionConfig($name);
        if (empty($extensionConfig)) {
            $this->debugLog("Extension '{$name}' not found in config");
            return;
        }

        // Use same approach as old ExtensionsManager: dirname(__DIR__, 2) but adjusted for our location
        $projectRoot = dirname(__DIR__, 3);

        // Load routes from the "provides.routes" section
        if (isset($extensionConfig['provides']['routes']) && is_array($extensionConfig['provides']['routes'])) {
            $this->debugLog("Found " . count($extensionConfig['provides']['routes']) . " route files for {$name}");
            foreach ($extensionConfig['provides']['routes'] as $routeFile) {
                $fullRoutePath = $projectRoot . '/' . $routeFile;
                if (file_exists($fullRoutePath)) {
                    $this->debugLog("Loading extension routes from: {$routeFile}");
                    require_once $fullRoutePath;
                    $this->debugLog("Successfully included route file: {$routeFile}");
                } else {
                    $this->debugLog("Extension route file not found: {$fullRoutePath}");
                }
            }
        } else {
            $this->debugLog("No 'provides.routes' section found for {$name}, trying fallback");
            // Fallback to old hardcoded approach if no routes in manifest
            $extensionPath = $this->getExtensionPath($name);
            if (!$extensionPath) {
                $this->debugLog("No extension path found for {$name}");
                return;
            }
            $routesFiles = [
                $extensionPath . '/routes.php',
                $extensionPath . '/src/routes.php'
            ];
            foreach ($routesFiles as $routesFile) {
                if (file_exists($routesFile)) {
                    $this->debugLog("Loading routes from fallback location: {$routesFile}");
                    require_once $routesFile;
                    $this->debugLog("Successfully included fallback route file: {$routesFile}");
                    break;
                } else {
                    $this->debugLog("Fallback route file not found: {$routesFile}");
                }
            }
        }
    }

    public function loadServiceProviders(string $name): void
    {
        $this->debugLog("Loading service providers for extension: {$name}");

        $extensionConfig = $this->extensionConfig->getExtensionConfig($name);
        if (empty($extensionConfig)) {
            $this->debugLog("Extension '{$name}' not found in config");
            return;
        }

        $serviceProviders = $extensionConfig['provides']['services'] ?? [];

        if (empty($serviceProviders)) {
            $this->debugLog("No service providers defined for extension '{$name}'");
            return;
        }

        $this->debugLog("Found " . count($serviceProviders) . " service providers for {$name}");

        // Use same approach as old ExtensionsManager: dirname(__DIR__, 2) but adjusted for our location
        $projectRoot = dirname(__DIR__, 3);

        foreach ($serviceProviders as $serviceProviderPath) {
            $absolutePath = $projectRoot . '/' . $serviceProviderPath;

            if (file_exists($absolutePath)) {
                $this->debugLog("Loading service provider: {$absolutePath}");
                require_once $absolutePath;

                // Register with container using the same logic as old system
                $this->registerServiceProviderFromPath($name, $serviceProviderPath);
            } else {
                $this->debugLog("Service provider file not found: {$absolutePath}");
            }
        }
    }

    public function initializeExtension(string $name): bool
    {
        $extensionClass = $this->getExtensionClass($name);
        if (!$extensionClass) {
            return false;
        }

        try {
            if (method_exists($extensionClass, 'initialize')) {
                $extensionClass::initialize();
                $this->debugLog("Initialized extension: {$name}");
            }
            return true;
        } catch (\Exception $e) {
            $this->debugLog("Failed to initialize extension {$name}: " . $e->getMessage());
            return false;
        }
    }

    public function discoverExtensions(?string $extensionsPath = null): array
    {
        $extensionsPath = $extensionsPath ?: $this->getDefaultExtensionsPath();

        if (!is_dir($extensionsPath)) {
            return [];
        }

        $extensions = [];
        $directories = glob($extensionsPath . '/*', GLOB_ONLYDIR);

        foreach ($directories as $directory) {
            $extensionName = basename($directory);

            // Skip hidden directories and common non-extension directories
            if (
                str_starts_with($extensionName, '.') ||
                in_array($extensionName, ['vendor', 'node_modules', 'tests'])
            ) {
                continue;
            }

            if ($this->validateStructure($directory)) {
                $extensions[] = $extensionName;
            }
        }

        return $extensions;
    }

    private function loadExtensionClass(string $name, string $path): ?string
    {
        $manifest = $this->loadManifest($path);
        if (!$manifest) {
            return null;
        }

        $mainFile = $manifest['main'] ?? "{$name}.php";
        $extensionFile = $path . '/' . $mainFile;

        if (!file_exists($extensionFile)) {
            return null;
        }

        require_once $extensionFile;

        // Get class name from manifest (preferred) or assume it matches extension name
        $extensionClass = $manifest['main_class'] ?? "Glueful\\Extensions\\{$name}";

        if (!class_exists($extensionClass)) {
            $this->debugLog("Extension class {$extensionClass} not found after loading {$extensionFile}");
            return null;
        }

        return $extensionClass;
    }

    private function getExtensionClass(string $name): ?string
    {
        return $this->loadedExtensions[$name]['class'] ?? null;
    }

    private function getExtensionPath(string $name): ?string
    {
        $extensionsPath = $this->getDefaultExtensionsPath();
        $path = $extensionsPath . '/' . $name;

        return is_dir($path) ? $path : null;
    }

    private function getDefaultExtensionsPath(): string
    {
        // Go up from api/Extensions/Services to the project root
        $projectRoot = dirname(__DIR__, 3); // Up 3 levels from api/Extensions/Services/
        return $projectRoot . '/extensions';
    }

    private function loadManifest(string $path): ?array
    {
        $manifestPath = $path . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        return json_last_error() === JSON_ERROR_NONE ? $manifest : null;
    }

    private function registerServiceProviderFromPath(string $extensionName, string $serviceProviderPath): void
    {
        try {
            // Use app() helper like the old system
            if (!function_exists('app')) {
                throw new \RuntimeException("DI container not initialized. Cannot load extension service providers.");
            }
            $container = app();

            // Extract class name from path (like old system)
            $pathInfo = pathinfo($serviceProviderPath);
            $className = $pathInfo['filename'];

            // Build full class name based on path structure (like old system)
            $pathParts = explode('/', $serviceProviderPath);
            $fullClassName = null;

            // Pattern: extensions/ExtensionName/src/Services/ServiceProvider.php
            if (count($pathParts) >= 5 && $pathParts[2] === 'src') {
                $subNamespace = $pathParts[3]; // e.g., "Services"
                $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$subNamespace}\\{$className}";
            } else {
                $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$className}";
            }

            $this->debugLog("Attempting to register service provider class: {$fullClassName}");

            if (class_exists($fullClassName)) {
                $serviceProvider = new $fullClassName();

                // Set extension properties if this is a BaseExtensionServiceProvider
                if ($serviceProvider instanceof \Glueful\DI\ServiceProviders\BaseExtensionServiceProvider) {
                    $extensionPath = $this->getExtensionPath($extensionName);
                    if ($extensionPath) {
                        // Use reflection to set the protected properties
                        $reflection = new \ReflectionClass($serviceProvider);

                        $nameProperty = $reflection->getProperty('extensionName');
                        $nameProperty->setAccessible(true);
                        $nameProperty->setValue($serviceProvider, $extensionName);

                        $pathProperty = $reflection->getProperty('extensionPath');
                        $pathProperty->setAccessible(true);
                        $pathProperty->setValue($serviceProvider, $extensionPath);

                        $this->debugLog("Set extension properties for service provider: {$fullClassName}");
                    }
                }

                $container->register($serviceProvider);

                // Boot the service provider immediately since container is already booted (like old system)
                if (method_exists($serviceProvider, 'boot')) {
                    $serviceProvider->boot($container);
                    $this->debugLog("Booted service provider: {$fullClassName}");
                }

                $this->debugLog("Successfully registered service provider: {$fullClassName}");
            } else {
                $this->debugLog("Service provider class not found: {$fullClassName}");
            }
        } catch (\Exception $e) {
            $this->debugLog("Failed to register service provider: " . $e->getMessage());
        }
    }

    private function registerServiceProvider(string $name, string $providerPath): void
    {
        try {
            $container = ContainerBootstrap::getContainer();
            if (!$container) {
                return;
            }
            // Service provider registration logic would go here
            // This is framework-specific implementation
        } catch (\Exception $e) {
            // Container not available or registration failed
            $this->debugLog("Could not register service provider for {$name}: " . $e->getMessage());
        }
    }


    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($this->logger) {
            $this->logger->debug("[ExtensionLoader] {$message}");
        } else {
            error_log("[ExtensionLoader] {$message}");
        }
    }
}
