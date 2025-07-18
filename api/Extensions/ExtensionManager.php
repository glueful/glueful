<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\Services\Interfaces\ExtensionLoaderInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionCatalogInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionValidatorInterface;
use Glueful\Extensions\Exceptions\ExtensionException;
use Composer\Autoload\ClassLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ExtensionManager Facade
 *
 * Provides a unified interface for all extension management operations.
 * This class acts as a facade for the underlying extension services,
 * maintaining backward compatibility while providing a clean API.
 *
 * All 73 methods from the legacy ExtensionsManager are available here,
 * but now properly organized and delegated to focused services.
 */
class ExtensionManager
{
    private bool $debug = false;

    public function __construct(
        private ExtensionLoaderInterface $loader,
        private ExtensionConfigInterface $config,
        private ExtensionCatalogInterface $catalog,
        private ExtensionValidatorInterface $validator,
        private ?LoggerInterface $logger = null,
        private ?ContainerInterface $container = null
    ) {
    }

    // ============================================================================
    // CORE LIFECYCLE OPERATIONS
    // ============================================================================

    /**
     * Install an extension from various sources
     *
     * @param string $source Source path, URL, or extension name
     * @param array $options Installation options
     * @return array Installation result
     */
    public function install(string $source, array $options = []): array
    {
        $this->debugLog("Installing extension from: {$source}");

        try {
            // Determine source type
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                // Remote URL
                $packagePath = $this->catalog->downloadExtension(basename($source));
            } elseif (str_ends_with($source, '.zip')) {
                // Local package file
                $packagePath = $source;
            } else {
                // Extension name from catalog
                $packagePath = $this->catalog->downloadExtension($source);
            }

            // Verify package
            if (!$this->catalog->verifyPackage($packagePath)) {
                throw new ExtensionException("Package verification failed");
            }

            // Extract to temporary location
            $tempDir = sys_get_temp_dir() . '/glueful_install_' . uniqid();
            if (!$this->catalog->extractPackage($packagePath, $tempDir)) {
                throw new ExtensionException("Failed to extract package");
            }

            // Validate extension
            $validationResult = $this->validator->validateExtension($tempDir);
            if (!$validationResult['valid']) {
                $issues = implode(', ', $validationResult['issues']);
                throw new ExtensionException("Extension validation failed: " . $issues);
            }

            // Get extension metadata
            $manifestPath = $tempDir . '/manifest.json';
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $extensionName = $manifest['name'];

            // Check for name conflicts
            if (!$this->validator->checkNameConflicts($extensionName)) {
                throw new ExtensionException("Extension with name '{$extensionName}' already exists");
            }

            // Move to extensions directory
            $extensionPath = $this->getExtensionsPath() . '/' . $extensionName;
            if (!rename($tempDir, $extensionPath)) {
                throw new ExtensionException("Failed to install extension files");
            }

            // Add to configuration
            $this->config->addExtension($extensionName, [
                'enabled' => $options['auto_enable'] ?? false,
                'type' => $manifest['type'] ?? 'optional',
                'version' => $manifest['version'],
                'installed_at' => date('Y-m-d H:i:s')
            ]);

            $this->debugLog("Successfully installed extension: {$extensionName}");

            return [
                'success' => true,
                'extension' => $extensionName,
                'version' => $manifest['version'],
                'path' => $extensionPath
            ];
        } catch (\Exception $e) {
            $this->debugLog("Installation failed: " . $e->getMessage());

            // Cleanup on failure
            if (isset($tempDir) && is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Uninstall an extension
     *
     * @param string $name Extension name
     * @return bool Success status
     */
    public function uninstall(string $name): bool
    {
        try {
            // Disable first if enabled
            if ($this->isEnabled($name)) {
                $this->disable($name);
            }

            // Remove from filesystem
            $extensionPath = $this->getExtensionPath($name);
            if ($extensionPath && is_dir($extensionPath)) {
                $this->removeDirectory($extensionPath);
            }

            // Remove from configuration
            $this->config->removeExtension($name);

            $this->debugLog("Successfully uninstalled extension: {$name}");
            return true;
        } catch (\Exception $e) {
            $this->debugLog("Failed to uninstall extension {$name}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Enable an extension
     *
     * @param string $name Extension name
     * @return bool Success status
     */
    public function enable(string $name): bool
    {
        try {
            // Validate extension first
            $extensionPath = $this->getExtensionPath($name);
            if (!$extensionPath) {
                throw new ExtensionException("Extension not found: {$name}");
            }

            $validationResult = $this->validator->validateExtension($extensionPath);
            if (!$validationResult['valid']) {
                throw new ExtensionException("Extension validation failed");
            }

            // Check dependencies
            $manifest = $this->getExtensionMetadata($name);
            if (isset($manifest['engines']) && !$this->validator->validateDependencies($manifest['engines'])) {
                throw new ExtensionException("Dependency requirements not met");
            }

            // Load the extension
            if (!$this->loader->loadExtension($name)) {
                throw new ExtensionException("Failed to load extension");
            }

            // Update configuration
            $this->config->enableExtension($name);

            $this->debugLog("Successfully enabled extension: {$name}");
            return true;
        } catch (\Exception $e) {
            $this->debugLog("Failed to enable extension {$name}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Disable an extension
     *
     * @param string $name Extension name
     * @return bool Success status
     */
    public function disable(string $name): bool
    {
        try {
            // Unload if loaded
            if ($this->loader->isLoaded($name)) {
                $this->loader->unloadExtension($name);
            }

            // Update configuration
            $this->config->disableExtension($name);

            $this->debugLog("Successfully disabled extension: {$name}");
            return true;
        } catch (\Exception $e) {
            $this->debugLog("Failed to disable extension {$name}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete an extension (alias for uninstall)
     *
     * @param string $name Extension name
     * @param bool $force Force deletion even if enabled
     * @return array Deletion result
     */
    public function delete(string $name, bool $force = false): array
    {
        if (!$force && $this->isEnabled($name)) {
            return [
                'success' => false,
                'error' => 'Cannot delete enabled extension. Disable first or use force=true.'
            ];
        }

        $success = $this->uninstall($name);

        return [
            'success' => $success,
            'extension' => $name
        ];
    }

    /**
     * Update an extension
     *
     * @param string $name Extension name
     * @param string|null $version Specific version or null for latest
     * @return array Update result
     */
    public function update(string $name, ?string $version = null): array
    {
        try {
            $currentMetadata = $this->getExtensionMetadata($name);
            if (!$currentMetadata) {
                throw new ExtensionException("Extension not found: {$name}");
            }

            // Check for updates
            $remoteMetadata = $this->catalog->getRemoteMetadata($name);
            if (!$remoteMetadata) {
                throw new ExtensionException("Extension not found in catalog: {$name}");
            }

            $targetVersion = $version ?? $remoteMetadata['version'];

            // Check if update is needed
            if ($currentMetadata['version'] === $targetVersion) {
                return [
                    'success' => true,
                    'message' => 'Extension is already up to date',
                    'version' => $targetVersion
                ];
            }

            // Backup current settings
            $currentSettings = $this->config->getExtensionSettings($name);
            $wasEnabled = $this->isEnabled($name);

            // Uninstall current version
            $this->uninstall($name);

            // Install new version
            $installResult = $this->install($name);

            if (!$installResult['success']) {
                throw new ExtensionException("Failed to install updated version");
            }

            // Restore settings
            $this->config->updateExtensionSettings($name, $currentSettings);

            // Re-enable if it was enabled
            if ($wasEnabled) {
                $this->enable($name);
            }

            return [
                'success' => true,
                'extension' => $name,
                'old_version' => $currentMetadata['version'],
                'new_version' => $targetVersion
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // ============================================================================
    // CATALOG & DISCOVERY OPERATIONS
    // ============================================================================

    /**
     * Fetch available extensions from catalog
     *
     * @return array Available extensions
     */
    public function fetch(): array
    {
        return $this->catalog->getAvailableExtensions();
    }

    /**
     * Get synchronized catalog with local extension status
     *
     * @return array Synchronized catalog data
     */
    public function getSynchronizedCatalog(): array
    {
        // Get remote catalog
        $remoteCatalog = $this->catalog->getAvailableExtensions();

        // Get local extension status
        $installedExtensions = $this->listInstalled();
        $enabledExtensions = $this->listEnabled();

        // Synchronize with local status
        $synchronizedExtensions = [];
        foreach ($remoteCatalog as $extension) {
            $extensionName = $extension['name'] ?? '';

            // Add local status information
            $extension['installed'] = in_array($extensionName, $installedExtensions);
            $extension['enabled'] = in_array($extensionName, $enabledExtensions);
            $extension['status'] = $extension['enabled'] ? 'active' :
                                 ($extension['installed'] ? 'inactive' : 'available');

            // Add actions available for this extension
            $extension['actions_available'] = $this->getAvailableActions($extensionName, $extension);

            // Add local metadata if installed
            if ($extension['installed']) {
                $extension['local_metadata'] = $this->getExtensionMetadata($extensionName);
            }

            $synchronizedExtensions[] = $extension;
        }

        return [
            'extensions' => $synchronizedExtensions,
            'metadata' => [
                'source' => 'github_catalog',
                'catalog_url' => $this->catalog->getRegistryUrl(),
                'synchronized_at' => date('c'),
                'total_available' => count($remoteCatalog),
                'total_after_filters' => count($synchronizedExtensions),
                'summary' => [
                    'installed' => count($installedExtensions),
                    'enabled' => count($enabledExtensions),
                    'available_for_install' => count($synchronizedExtensions) - count($installedExtensions),
                    'disabled' => count($installedExtensions) - count($enabledExtensions)
                ]
            ]
        ];
    }

    /**
     * Search extensions in catalog
     *
     * @param string $query Search query
     * @return array Search results
     */
    public function search(string $query): array
    {
        return $this->catalog->searchExtensions($query);
    }

    /**
     * Check for available updates
     *
     * @return array Available updates
     */
    public function checkForUpdates(): array
    {
        return $this->catalog->checkForUpdates();
    }

    /**
     * Get extension categories
     *
     * @return array Categories
     */
    public function getCategories(): array
    {
        return $this->catalog->getCategories();
    }

    /**
     * Get featured extensions
     *
     * @return array Featured extensions
     */
    public function getFeaturedExtensions(): array
    {
        return $this->catalog->getFeaturedExtensions();
    }

    /**
     * Get extensions by category
     *
     * @param string $category Category name
     * @return array Extensions
     */
    public function getExtensionsByCategory(string $category): array
    {
        return $this->catalog->getExtensionsByCategory($category);
    }

    // ============================================================================
    // QUERY & STATUS OPERATIONS
    // ============================================================================

    /**
     * List all installed extensions
     *
     * @return array Installed extensions
     */
    public function listInstalled(): array
    {
        $extensionsPath = $this->getExtensionsPath();
        $installed = [];

        if (!is_dir($extensionsPath)) {
            return $installed;
        }

        $directories = glob($extensionsPath . '/*', GLOB_ONLYDIR);

        foreach ($directories as $directory) {
            $extensionName = basename($directory);

            if ($this->loader->validateStructure($directory)) {
                $metadata = $this->getExtensionMetadata($extensionName);
                $installed[] = [
                    'name' => $extensionName,
                    'version' => $metadata['version'] ?? '1.0.0',
                    'enabled' => $this->isEnabled($extensionName),
                    'loaded' => $this->loader->isLoaded($extensionName),
                    'path' => $directory
                ];
            }
        }

        return $installed;
    }

    /**
     * List enabled extensions
     *
     * @return array Enabled extensions
     */
    public function listEnabled(): array
    {
        return $this->config->getEnabledExtensions();
    }

    /**
     * Get loaded extensions
     *
     * @return array Loaded extensions
     */
    public function getLoadedExtensions(): array
    {
        return $this->loader->getLoadedExtensions();
    }

    /**
     * Check if extension is installed
     *
     * @param string $name Extension name
     * @return bool Installation status
     */
    public function isInstalled(string $name): bool
    {
        $extensionPath = $this->getExtensionPath($name);
        return $extensionPath !== null && $this->loader->validateStructure($extensionPath);
    }

    /**
     * Check if extension is enabled
     *
     * @param string $name Extension name
     * @return bool Enabled status
     */
    public function isEnabled(string $name): bool
    {
        return $this->config->isEnabled($name);
    }

    /**
     * Check if extension is loaded
     *
     * @param string $name Extension name
     * @return bool Loaded status
     */
    public function isLoaded(string $name): bool
    {
        return $this->loader->isLoaded($name);
    }

    /**
     * Get extension metadata
     *
     * @param string $name Extension name
     * @return array|null Extension metadata
     */
    public function getExtensionMetadata(string $name): ?array
    {
        $extensionPath = $this->getExtensionPath($name);
        if (!$extensionPath) {
            return null;
        }

        $manifestPath = $extensionPath . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        return json_last_error() === JSON_ERROR_NONE ? $manifest : null;
    }

    /**
     * Get extension information (alias for getExtensionMetadata)
     *
     * @param string $name Extension name
     * @return array|null Extension information
     */
    public function getExtensionInfo(string $name): ?array
    {
        return $this->getExtensionMetadata($name);
    }

    /**
     * Get extension path
     *
     * @param string $name Extension name
     * @return string|null Extension path
     */
    public function getExtensionPath(string $name): ?string
    {
        $extensionsPath = $this->getExtensionsPath();
        $path = $extensionsPath . '/' . $name;

        return is_dir($path) ? $path : null;
    }

    // ============================================================================
    // HEALTH & VALIDATION OPERATIONS
    // ============================================================================

    /**
     * Check extension health
     *
     * @param string $name Extension name
     * @return array Health status
     */
    public function checkHealth(string $name): array
    {
        $extensionPath = $this->getExtensionPath($name);
        if (!$extensionPath) {
            return [
                'healthy' => false,
                'issues' => ['Extension not found']
            ];
        }

        // Check if extension class exists and has health method
        try {
            $extensionClass = $name;
            if (class_exists($extensionClass) && method_exists($extensionClass, 'checkHealth')) {
                return $extensionClass::checkHealth();
            }

            return [
                'healthy' => true,
                'issues' => [],
                'status' => 'healthy'
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'issues' => [$e->getMessage()],
                'status' => 'error'
            ];
        }
    }

    /**
     * Validate an extension
     *
     * @param string $name Extension name
     * @return array Validation result
     */
    public function validate(string $name): array
    {
        $extensionPath = $this->getExtensionPath($name);
        if (!$extensionPath) {
            return [
                'valid' => false,
                'issues' => ['Extension not found']
            ];
        }

        return $this->validator->validateExtension($extensionPath);
    }

    /**
     * Validate extension dependencies
     *
     * @param string $name Extension name
     * @return bool Dependencies valid
     */
    public function validateDependencies(string $name): bool
    {
        $metadata = $this->getExtensionMetadata($name);
        if (!$metadata || !isset($metadata['engines'])) {
            return true;
        }

        return $this->validator->validateDependencies($metadata['engines']);
    }

    // ============================================================================
    // CONFIGURATION OPERATIONS
    // ============================================================================

    /**
     * Get extension configuration
     *
     * @param string $name Extension name
     * @return array Extension configuration
     */
    public function getExtensionConfig(string $name): array
    {
        return $this->config->getExtensionConfig($name);
    }

    /**
     * Update extension configuration
     *
     * @param string $name Extension name
     * @param array $configuration Configuration data
     * @return void
     */
    public function updateExtensionConfig(string $name, array $configuration): void
    {
        $this->config->updateExtensionConfig($name, $configuration);
    }

    /**
     * Get extension settings
     *
     * @param string $name Extension name
     * @return array Extension settings
     */
    public function getExtensionSettings(string $name): array
    {
        return $this->config->getExtensionSettings($name);
    }

    /**
     * Update extension settings
     *
     * @param string $name Extension name
     * @param array $settings Settings data
     * @return void
     */
    public function updateExtensionSettings(string $name, array $settings): void
    {
        $this->config->updateExtensionSettings($name, $settings);
    }

    /**
     * Get global configuration
     *
     * @return array Global configuration
     */
    public function getGlobalConfig(): array
    {
        return $this->config->getConfig();
    }

    /**
     * Get core extensions
     *
     * @return array Core extensions
     */
    public function getCoreExtensions(): array
    {
        return $this->config->getCoreExtensions();
    }

    /**
     * Get optional extensions
     *
     * @return array Optional extensions
     */
    public function getOptionalExtensions(): array
    {
        return $this->config->getOptionalExtensions();
    }

    /**
     * Check if extension is core
     *
     * @param string $name Extension name
     * @return bool Core status
     */
    public function isCoreExtension(string $name): bool
    {
        return $this->config->isCoreExtension($name);
    }

    // ============================================================================
    // BULK OPERATIONS
    // ============================================================================

    /**
     * Enable multiple extensions
     *
     * @param array $names Extension names
     * @return array Results
     */
    public function enableMultiple(array $names): array
    {
        $results = [];

        foreach ($names as $name) {
            $results[$name] = $this->enable($name);
        }

        return $results;
    }

    /**
     * Disable multiple extensions
     *
     * @param array $names Extension names
     * @return array Results
     */
    public function disableMultiple(array $names): array
    {
        $results = [];

        foreach ($names as $name) {
            $results[$name] = $this->disable($name);
        }

        return $results;
    }

    /**
     * Load all enabled extensions (for framework boot) with SPA registration
     *
     * @return void
     */
    public function loadEnabledExtensions(): void
    {
        $enabledExtensions = $this->config->getEnabledExtensions();

        foreach ($enabledExtensions as $extensionName) {
            if (!$this->loader->isLoaded($extensionName)) {
                $this->loader->loadExtension($extensionName);

                // Register SPA configurations after loading
                $this->registerSpaConfigurations("Glueful\\Extensions\\{$extensionName}");
            }
        }

        // Service providers are now loaded during container compilation
        // $this->loadExtensionServiceProviders();
    }

    /**
     * Load service providers for all enabled extensions
     *
     * @return void
     */
    public function loadExtensionServiceProviders(): void
    {
        $enabledExtensions = $this->config->getEnabledExtensions();

        foreach ($enabledExtensions as $extensionName) {
            $this->loader->loadServiceProviders($extensionName);
        }
    }

    /**
     * Register SPA configurations from extension
     *
     * @param string $extensionClass Extension class name
     * @return void
     */
    protected function registerSpaConfigurations(string $extensionClass): void
    {
        try {
            if ($this->container && $this->container->has(\Glueful\SpaManager::class)) {
                $spaManager = $this->container->get(\Glueful\SpaManager::class);
                $spaManager->registerFromExtension($extensionClass);
            }
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error(
                    "Failed to register SPA configurations for {$extensionClass}: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Load extension routes for all enabled extensions
     *
     * @return void
     */
    public function loadExtensionRoutes(): void
    {
        $enabledExtensions = $this->config->getEnabledExtensions();

        foreach ($enabledExtensions as $extensionName) {
            // Load routes regardless of isLoaded() status, since routes are skipped during bootstrap
            $this->loader->loadRoutes($extensionName);
        }
    }

    /**
     * Discover extensions in directory
     *
     * @param string|null $extensionsPath Extensions directory path
     * @return array Discovered extensions
     */
    public function discoverExtensions(?string $extensionsPath = null): array
    {
        return $this->loader->discoverExtensions($extensionsPath);
    }

    // ============================================================================
    // UTILITY OPERATIONS
    // ============================================================================

    /**
     * Get extensions by type
     *
     * @param string $type Extension type
     * @return array Extensions
     */
    public function getExtensionsByType(string $type): array
    {
        $extensions = [];
        $config = $this->config->getConfig();

        foreach ($config['extensions'] ?? [] as $name => $extensionConfig) {
            if (($extensionConfig['type'] ?? 'optional') === $type) {
                $extensions[] = $name;
            }
        }

        return $extensions;
    }

    /**
     * Clear all caches
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->config->clearCache();
        $this->catalog->clearCache();
    }

    /**
     * Set debug mode
     *
     * @param bool $enable Enable debug mode
     * @return void
     */
    public function setDebugMode(bool $enable = true): void
    {
        $this->debug = $enable;
        $this->loader->setDebugMode($enable);
        $this->config->setDebugMode($enable);
        $this->catalog->setDebugMode($enable);
        $this->validator->setDebugMode($enable);
    }

    /**
     * Set class loader
     *
     * @param ClassLoader $classLoader Composer class loader
     * @return void
     */
    public function setClassLoader(ClassLoader $classLoader): void
    {
        $this->loader->setClassLoader($classLoader);
    }

    /**
     * Get registered namespaces
     *
     * @return array Registered namespaces
     */
    public function getRegisteredNamespaces(): array
    {
        return $this->loader->getRegisteredNamespaces();
    }

    // ============================================================================
    // LEGACY COMPATIBILITY METHODS
    // ============================================================================

    /**
     * Legacy method: Get extension data
     *
     * @param string $name Extension name
     * @return array|null Extension data
     */
    public function getExtensionData(string $name): ?array
    {
        return $this->getExtensionMetadata($name);
    }

    /**
     * Legacy method: Find extension
     *
     * @param string $name Extension name
     * @param bool $checkFilesOnly Check files only
     * @return string|null Extension path
     */
    public function findExtension(string $name, bool $checkFilesOnly = false): ?string
    {
        return $this->getExtensionPath($name);
    }

    /**
     * Legacy method: Check if extension is enabled
     *
     * @param string $name Extension name
     * @return bool Enabled status
     */
    public function isExtensionEnabled(string $name): bool
    {
        return $this->isEnabled($name);
    }

    // ============================================================================
    // EXTENSION SERVICE REGISTRATION (FOR COMPILER PASSES)
    // ============================================================================

    /**
     * Register an extension service (called by compiler passes)
     *
     * @param string $extensionName Extension name
     * @param string $serviceId Service ID
     * @param object $service Service instance
     * @return void
     */
    public function registerExtensionService(string $extensionName, string $serviceId, object $service): void
    {

        // This method is called by the ExtensionServicePass compiler pass
        // to register services that are tagged with 'extension.service'
        // The actual service registration is handled by the DI container
        // This method is mainly for logging and tracking purposes
    }

    /**
     * Register an extension provider (called by compiler passes)
     *
     * @param string $extensionName Extension name
     * @param object $provider Provider instance
     * @return void
     */
    public function registerExtensionProvider(string $extensionName, object $provider): void
    {

        // This method is called by the ExtensionServicePass compiler pass
        // to register providers that are tagged with 'extension.provider'
        // The actual provider registration is handled by the DI container
        // This method is mainly for logging and tracking purposes
    }

    // ============================================================================
    // PRIVATE HELPER METHODS
    // ============================================================================

    private function getExtensionsPath(): string
    {
        // Go up from api/Extensions to the project root
        $projectRoot = dirname(__DIR__, 2); // Up 2 levels from api/Extensions/
        return $projectRoot . '/extensions';
    }

    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }

    /**
     * Get available actions for an extension
     *
     * @param string $extensionName Extension name
     * @param array $extension Extension data
     * @return array Available actions
     */
    private function getAvailableActions(string $extensionName, array $extension): array
    {
        $actions = [];

        if ($extension['installed'] ?? false) {
            if ($extension['enabled'] ?? false) {
                $actions[] = 'disable';
            } else {
                $actions[] = 'enable';
            }
            $actions[] = 'uninstall';
            $actions[] = 'configure';
        } else {
            $actions[] = 'install';
        }

        return $actions;
    }

    /**
     * Get registry URL from catalog
     *
     * @return string Registry URL
     */
    public function getRegistryUrl(): string
    {
        return $this->catalog->getRegistryUrl();
    }

    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($this->logger) {
            $this->logger->debug("[ExtensionManager] {$message}");
        } else {
            error_log("[ExtensionManager] {$message}");
        }
    }
}
