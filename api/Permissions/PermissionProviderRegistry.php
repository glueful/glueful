<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Interfaces\Permission\PermissionProviderInterface;
use Glueful\Permissions\Exceptions\ProviderNotFoundException;
use Glueful\Permissions\Exceptions\PermissionException;

/**
 * Permission Provider Registry
 *
 * Manages registration and discovery of permission providers.
 * This allows the system to support multiple permission providers
 * and switch between them as needed.
 *
 * The registry handles:
 * - Provider registration and validation
 * - Provider discovery and metadata
 * - Provider configuration management
 * - Provider lifecycle management
 *
 * @package Glueful\Permissions
 */
class PermissionProviderRegistry
{
    /** @var array<string, PermissionProviderInterface> Registered providers */
    private static array $providers = [];

    /** @var array<string, array> Provider configurations */
    private static array $configurations = [];

    /** @var array<string, array> Provider metadata cache */
    private static array $metadataCache = [];

    /** @var string|null Currently active provider name */
    private static ?string $activeProviderName = null;

    /**
     * Register a permission provider
     *
     * @param string $name Unique name for the provider
     * @param PermissionProviderInterface $provider Provider instance
     * @param array $config Provider configuration
     * @return void
     * @throws PermissionException If provider name already exists
     */
    public static function register(string $name, PermissionProviderInterface $provider, array $config = []): void
    {
        if (isset(self::$providers[$name])) {
            throw new PermissionException("Provider with name '{$name}' is already registered");
        }

        // Validate provider by getting its info
        try {
            $metadata = $provider->getProviderInfo();
            self::$metadataCache[$name] = $metadata;
        } catch (\Exception $e) {
            throw new PermissionException("Invalid provider: " . $e->getMessage(), 0, $e);
        }

        self::$providers[$name] = $provider;
        self::$configurations[$name] = $config;
    }

    /**
     * Unregister a permission provider
     *
     * @param string $name Provider name to unregister
     * @return bool True if provider was unregistered, false if not found
     */
    public static function unregister(string $name): bool
    {
        if (!isset(self::$providers[$name])) {
            return false;
        }

        // If this is the active provider, clear it
        if (self::$activeProviderName === $name) {
            self::$activeProviderName = null;
        }

        unset(self::$providers[$name]);
        unset(self::$configurations[$name]);
        unset(self::$metadataCache[$name]);

        return true;
    }

    /**
     * Get a registered provider
     *
     * @param string $name Provider name
     * @return PermissionProviderInterface Provider instance
     * @throws ProviderNotFoundException If provider is not registered
     */
    public static function get(string $name): PermissionProviderInterface
    {
        if (!isset(self::$providers[$name])) {
            throw new ProviderNotFoundException("Provider '{$name}' is not registered");
        }

        return self::$providers[$name];
    }

    /**
     * Check if a provider is registered
     *
     * @param string $name Provider name
     * @return bool True if provider is registered
     */
    public static function has(string $name): bool
    {
        return isset(self::$providers[$name]);
    }

    /**
     * Get all registered provider names
     *
     * @return array List of registered provider names
     */
    public static function getProviderNames(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Get all registered providers
     *
     * @return array<string, PermissionProviderInterface> Provider name => instance mapping
     */
    public static function getAllProviders(): array
    {
        return self::$providers;
    }

    /**
     * Get provider metadata
     *
     * @param string $name Provider name
     * @return array Provider metadata
     * @throws ProviderNotFoundException If provider is not registered
     */
    public static function getProviderMetadata(string $name): array
    {
        if (!isset(self::$providers[$name])) {
            throw new ProviderNotFoundException("Provider '{$name}' is not registered");
        }

        // Return cached metadata if available
        if (isset(self::$metadataCache[$name])) {
            return self::$metadataCache[$name];
        }

        // Fetch and cache metadata
        $metadata = self::$providers[$name]->getProviderInfo();
        self::$metadataCache[$name] = $metadata;

        return $metadata;
    }

    /**
     * Get metadata for all registered providers
     *
     * @return array Provider name => metadata mapping
     */
    public static function getAllProviderMetadata(): array
    {
        $metadata = [];
        foreach (array_keys(self::$providers) as $name) {
            try {
                $metadata[$name] = self::getProviderMetadata($name);
            } catch (\Exception $e) {
                $metadata[$name] = [
                    'error' => 'Failed to get metadata: ' . $e->getMessage()
                ];
            }
        }
        return $metadata;
    }

    /**
     * Get provider configuration
     *
     * @param string $name Provider name
     * @return array Provider configuration
     * @throws ProviderNotFoundException If provider is not registered
     */
    public static function getProviderConfiguration(string $name): array
    {
        if (!isset(self::$providers[$name])) {
            throw new ProviderNotFoundException("Provider '{$name}' is not registered");
        }

        return self::$configurations[$name] ?? [];
    }

    /**
     * Update provider configuration
     *
     * @param string $name Provider name
     * @param array $config New configuration
     * @return void
     * @throws ProviderNotFoundException If provider is not registered
     */
    public static function updateProviderConfiguration(string $name, array $config): void
    {
        if (!isset(self::$providers[$name])) {
            throw new ProviderNotFoundException("Provider '{$name}' is not registered");
        }

        self::$configurations[$name] = $config;

        // Re-initialize provider with new configuration if it's active
        if (self::$activeProviderName === $name) {
            self::$providers[$name]->initialize($config);
        }
    }

    /**
     * Set the active provider
     *
     * @param string $name Provider name to activate
     * @return void
     * @throws ProviderNotFoundException If provider is not registered
     * @throws PermissionException If provider initialization fails
     */
    public static function setActiveProvider(string $name): void
    {
        if (!isset(self::$providers[$name])) {
            throw new ProviderNotFoundException("Provider '{$name}' is not registered");
        }

        try {
            // Initialize provider with its configuration
            $config = self::$configurations[$name] ?? [];
            self::$providers[$name]->initialize($config);
            self::$activeProviderName = $name;
        } catch (\Exception $e) {
            throw new PermissionException("Failed to activate provider '{$name}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the active provider
     *
     * @return PermissionProviderInterface|null Active provider or null if none set
     */
    public static function getActiveProvider(): ?PermissionProviderInterface
    {
        if (self::$activeProviderName && isset(self::$providers[self::$activeProviderName])) {
            return self::$providers[self::$activeProviderName];
        }

        return null;
    }

    /**
     * Get the active provider name
     *
     * @return string|null Active provider name or null if none set
     */
    public static function getActiveProviderName(): ?string
    {
        return self::$activeProviderName;
    }

    /**
     * Clear the active provider
     *
     * @return void
     */
    public static function clearActiveProvider(): void
    {
        self::$activeProviderName = null;
    }

    /**
     * Find providers by capability
     *
     * Search for providers that support specific capabilities.
     *
     * @param array $capabilities Required capabilities
     * @return array Provider names that support all required capabilities
     */
    public static function findProvidersByCapability(array $capabilities): array
    {
        $matching = [];

        foreach (self::$providers as $name => $provider) {
            try {
                $metadata = self::getProviderMetadata($name);
                $providerCapabilities = $metadata['capabilities'] ?? [];

                // Check if provider supports all required capabilities
                if (empty(array_diff($capabilities, $providerCapabilities))) {
                    $matching[] = $name;
                }
            } catch (\Exception $e) {
                // Skip providers that fail metadata retrieval
                continue;
            }
        }

        return $matching;
    }

    /**
     * Find providers by version requirement
     *
     * @param string $minVersion Minimum required version
     * @return array Provider names that meet version requirement
     */
    public static function findProvidersByVersion(string $minVersion): array
    {
        $matching = [];

        foreach (self::$providers as $name => $provider) {
            try {
                $metadata = self::getProviderMetadata($name);
                $providerVersion = $metadata['version'] ?? '0.0.0';

                if (version_compare($providerVersion, $minVersion, '>=')) {
                    $matching[] = $name;
                }
            } catch (\Exception $e) {
                // Skip providers that fail metadata retrieval
                continue;
            }
        }

        return $matching;
    }

    /**
     * Perform health check on all providers
     *
     * @return array Provider name => health status mapping
     */
    public static function healthCheckAll(): array
    {
        $results = [];

        foreach (self::$providers as $name => $provider) {
            try {
                $results[$name] = $provider->healthCheck();
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 'error',
                    'message' => 'Health check failed: ' . $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Clear all registered providers
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$providers = [];
        self::$configurations = [];
        self::$metadataCache = [];
        self::$activeProviderName = null;
    }

    /**
     * Get registry statistics
     *
     * @return array Registry statistics
     */
    public static function getStats(): array
    {
        return [
            'total_providers' => count(self::$providers),
            'active_provider' => self::$activeProviderName,
            'provider_names' => array_keys(self::$providers),
            'metadata_cached' => count(self::$metadataCache)
        ];
    }
}