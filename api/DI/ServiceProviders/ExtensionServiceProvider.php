<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\Services\ExtensionLoader;
use Glueful\Extensions\Services\ExtensionConfig;
use Glueful\Extensions\Services\ExtensionCatalog;
use Glueful\Extensions\Services\ExtensionValidator;
use Glueful\Extensions\Services\Interfaces\ExtensionLoaderInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionCatalogInterface;
use Glueful\Extensions\Services\Interfaces\ExtensionValidatorInterface;

/**
 * Extension Service Provider
 *
 * Registers all extension-related services in the DI container
 */
class ExtensionServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Register helper service implementations
        $container->singleton(ExtensionLoaderInterface::class, function (ContainerInterface $container) {
            $loader = new ExtensionLoader(
                $container->get(\Glueful\Services\FileFinder::class),
                $container->get(\Glueful\Services\FileManager::class),
                $container->get(\Psr\Log\LoggerInterface::class)
            );

            // Set the Composer ClassLoader like current system
            $classLoader = null;
            if (class_exists('\Composer\Autoload\ClassLoader')) {
                // Try to get from vendor/autoload.php global
                foreach (get_included_files() as $file) {
                    if (str_ends_with($file, 'vendor/autoload.php')) {
                        $classLoader = require $file;
                        break;
                    }
                }
            }

            if ($classLoader) {
                $loader->setClassLoader($classLoader);
            }

            return $loader;
        });
        $container->singleton(ExtensionConfigInterface::class, ExtensionConfig::class);
        $container->singleton(ExtensionCatalogInterface::class, ExtensionCatalog::class);
        $container->singleton(ExtensionValidatorInterface::class, ExtensionValidator::class);

        // Register concrete implementations as well for direct injection
        $container->singleton(ExtensionLoader::class);
        $container->singleton(ExtensionConfig::class);
        $container->singleton(ExtensionCatalog::class);
        $container->singleton(ExtensionValidator::class);

        // Register the main ExtensionManager facade
        $container->singleton(ExtensionManager::class, function (ContainerInterface $container) {
            return new ExtensionManager(
                $container->get(ExtensionLoaderInterface::class),
                $container->get(ExtensionConfigInterface::class),
                $container->get(ExtensionCatalogInterface::class),
                $container->get(ExtensionValidatorInterface::class),
                $container->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // Register global alias for easy access
        $container->alias('extensions', ExtensionManager::class);
    }

    public function boot(ContainerInterface $container): void
    {
        // Initialize the extension system after all services are registered
        $extensionManager = $container->get(ExtensionManager::class);

        // Set up composer class loader if available
        $classLoaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
        if (!empty($classLoaders)) {
            // Get the first registered class loader
            $classLoader = reset($classLoaders);
            $extensionManager->setClassLoader($classLoader);
        }

        // Load enabled extensions during framework boot
        try {
            $extensionManager->loadEnabledExtensions();
        } catch (\Exception $e) {
            // Log error but don't fail the boot process
            if ($container->has(\Psr\Log\LoggerInterface::class)) {
                $logger = $container->get(\Psr\Log\LoggerInterface::class);
                $logger->error('Failed to load enabled extensions: ' . $e->getMessage());
            }
        }
    }
}
