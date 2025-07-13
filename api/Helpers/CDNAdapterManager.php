<?php

namespace Glueful\Helpers;

use Glueful\Cache\CDN\CDNAdapterInterface;
use Glueful\Extensions\ExtensionManager;

/**
 * CDN Adapter Manager
 *
 * This trait extends the ExtensionsManager with methods specifically
 * for handling CDN adapter extensions.
 */
trait CDNAdapterManager
{
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
    public static function resolveCDNAdapter(string $provider, array $config = []): ?CDNAdapterInterface
    {
        // Normalize provider name for consistent lookup
        $normalizedProvider = strtolower($provider);

        // Look for extensions with CDN adapters
        foreach (self::getLoadedExtensions() as $extension) {
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
     * Get all available CDN adapters from extensions
     *
     * @return array Associative array of provider names to adapter class names
     */
    public static function getAvailableCDNAdapters(): array
    {
        $adapters = [];

        // Get adapters from all extensions
        foreach (self::getLoadedExtensions() as $extension) {
            if (!method_exists($extension, 'registerCDNAdapters')) {
                continue;
            }

            // Merge adapters from this extension
            if (is_object($extension)) {
                // For object instances
                if (method_exists($extension, 'registerCDNAdapters')) {
                    $extensionAdapters = $extension->registerCDNAdapters();
                    $adapters = array_merge($adapters, $extensionAdapters);
                }
            } else {
                // For class names (static call)
                $extensionAdapters = $extension::registerCDNAdapters();
                $adapters = array_merge($adapters, $extensionAdapters);
            }
        }

        return $adapters;
    }

    /**
     * Get the loaded extensions
     *
     * This method provides access to the loaded extensions
     * from the ExtensionManager class.
     *
     * @return array The loaded extensions
     */
    private static function getLoadedExtensions(): array
    {
        $extensionManager = container()->get(ExtensionManager::class);
        return $extensionManager->getLoadedExtensions();
    }
}
