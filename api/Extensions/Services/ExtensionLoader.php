<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services;

use Glueful\Extensions\Services\Interfaces\ExtensionLoaderInterface;
use Glueful\Extensions\Enums\ExtensionStatus;
use Glueful\Extensions\ExtensionEventRegistry;
use Composer\Autoload\ClassLoader;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Glueful\DI\ContainerBootstrap;
use Glueful\Validation\ValidationExtensionLoader;
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
        private ?LoggerInterface $logger = null
    ) {
        $this->initializeServices();
    }

    private function initializeServices(): void
    {
        if ($this->fileFinder === null || $this->fileManager === null) {
            try {
                $container = ContainerBootstrap::getContainer();
                $this->fileFinder ??= $container->get(FileFinder::class);
                $this->fileManager ??= $container->get(FileManager::class);
                $this->logger ??= $container->get(LoggerInterface::class);
            } catch (\Exception $e) {
                // Fallback to creating directly if container not available
                $this->fileFinder ??= new FileFinder();
                $this->fileManager ??= new FileManager();
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

            // Load service providers
            $this->loadServiceProviders($name);

            // Load routes
            $this->loadRoutes($name);

            // Load validation constraints
            $this->loadValidationConstraints($name, $extensionPath);

            // Mark as loaded (without instantiating the main class)
            $this->loadedExtensions[$name] = [
                'path' => $extensionPath,
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

        // Get autoload config from extensions.json (like old system)
        $projectRoot = dirname($path, 2); // Go up 2 levels from extension path to get project root
        $configPath = $projectRoot . '/extensions/extensions.json';
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            if (isset($config['extensions'][$name]['autoload']['psr-4'])) {
                $autoloadConfig = $config['extensions'][$name]['autoload']['psr-4'];

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
        $extensionPath = $this->getExtensionPath($name);
        if (!$extensionPath) {
            return;
        }

        $routesFiles = [
            $extensionPath . '/routes.php',
            $extensionPath . '/src/routes.php'
        ];

        foreach ($routesFiles as $routesFile) {
            if (file_exists($routesFile)) {
                $this->debugLog("Loading routes from: {$routesFile}");
                require_once $routesFile;
                break;
            }
        }
    }

    public function loadServiceProviders(string $name): void
    {
        $extensionPath = $this->getExtensionPath($name);
        if (!$extensionPath) {
            return;
        }

        // Look for service provider files
        $serviceProviderPaths = [
            $extensionPath . '/src/' . $name . 'ServiceProvider.php',
            $extensionPath . '/src/ServiceProvider.php',
            $extensionPath . '/' . $name . 'ServiceProvider.php'
        ];

        foreach ($serviceProviderPaths as $providerPath) {
            if (file_exists($providerPath)) {
                $this->debugLog("Loading service provider from: {$providerPath}");
                require_once $providerPath;

                // Try to register with container if available
                $this->registerServiceProvider($name, $providerPath);
                break;
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

        // Assume class name matches extension name
        $extensionClass = $name;

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

    private function registerServiceProvider(string $name, string $providerPath): void
    {
        try {
            $container = ContainerBootstrap::getContainer();
            // Service provider registration logic would go here
            // This is framework-specific implementation
        } catch (\Exception $e) {
            // Container not available or registration failed
            $this->debugLog("Could not register service provider for {$name}: " . $e->getMessage());
        }
    }

    /**
     * Load validation constraints from extension
     *
     * @param string $name Extension name
     * @param string $extensionPath Extension path
     */
    private function loadValidationConstraints(string $name, string $extensionPath): void
    {
        try {
            $container = ContainerBootstrap::getContainer();

            // Skip if validation system is not available
            if (!$container->has(ValidationExtensionLoader::class)) {
                $this->debugLog("Validation system not available for extension: {$name}");
                return;
            }

            $validationLoader = $container->get(ValidationExtensionLoader::class);
            $result = $validationLoader->loadExtensionConstraints($name, $extensionPath);

            if ($result['success']) {
                $this->debugLog("Loaded {$result['constraints_loaded']} validation constraints for extension: {$name}");
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $this->debugLog("Failed to load validation constraints for extension {$name}: " . $error);
            }
        } catch (\Exception $e) {
            $this->debugLog("Could not load validation constraints for {$name}: " . $e->getMessage());
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
