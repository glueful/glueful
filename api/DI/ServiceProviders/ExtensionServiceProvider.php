<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;

/**
 * Extension Service Provider
 * Uses Symfony DI and tagged services
 */
class ExtensionServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register ExtensionLoader
        $container->register(\Glueful\Extensions\Services\ExtensionLoader::class)
            ->setArguments([
                new Reference(\Glueful\Services\FileFinder::class),
                new Reference(\Glueful\Services\FileManager::class),
                new Reference('logger')
            ])
            ->addMethodCall('setDebugMode', [true])
            ->setPublic(true)
            ->addTag(ServiceTags::EXTENSION_SERVICE, ['extension' => 'core']);

        // Register ExtensionConfig
        $container->register(\Glueful\Extensions\Services\ExtensionConfig::class)
            ->setPublic(true)
            ->addTag(ServiceTags::EXTENSION_SERVICE, ['extension' => 'core']);

        // Register ExtensionCatalog
        $container->register(\Glueful\Extensions\Services\ExtensionCatalog::class)
            ->setPublic(true)
            ->addTag(ServiceTags::EXTENSION_SERVICE, ['extension' => 'core']);

        // Register ExtensionValidator
        $container->register(\Glueful\Extensions\Services\ExtensionValidator::class)
            ->setPublic(true)
            ->addTag(ServiceTags::EXTENSION_SERVICE, ['extension' => 'core']);

        // Register interface bindings
        $container->setAlias(
            \Glueful\Extensions\Services\Interfaces\ExtensionLoaderInterface::class,
            \Glueful\Extensions\Services\ExtensionLoader::class
        );
        $container->setAlias(
            \Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface::class,
            \Glueful\Extensions\Services\ExtensionConfig::class
        );
        $container->setAlias(
            \Glueful\Extensions\Services\Interfaces\ExtensionCatalogInterface::class,
            \Glueful\Extensions\Services\ExtensionCatalog::class
        );
        $container->setAlias(
            \Glueful\Extensions\Services\Interfaces\ExtensionValidatorInterface::class,
            \Glueful\Extensions\Services\ExtensionValidator::class
        );

        // Register the main ExtensionManager facade
        $container->register('extension.manager', \Glueful\Extensions\ExtensionManager::class)
            ->setArguments([
                new Reference(\Glueful\Extensions\Services\Interfaces\ExtensionLoaderInterface::class),
                new Reference(\Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface::class),
                new Reference(\Glueful\Extensions\Services\Interfaces\ExtensionCatalogInterface::class),
                new Reference(\Glueful\Extensions\Services\Interfaces\ExtensionValidatorInterface::class),
                new Reference('logger'),
                new Reference('service_container')
            ])
            ->setPublic(true);

        // Register class alias
        $container->setAlias(\Glueful\Extensions\ExtensionManager::class, 'extension.manager')
            ->setPublic(true);

        // Register alias for easy access
        $container->setAlias('extensions', 'extension.manager')
            ->setPublic(true);
    }

    public function boot(Container $container): void
    {
        // Initialize the extension system after all services are registered
        $extensionManager = $container->get('extension.manager');

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
            if ($container->has('logger')) {
                $logger = $container->get('logger');
                $logger->error('Failed to load enabled extensions: ' . $e->getMessage());
            }
        }
    }

    public function getCompilerPasses(): array
    {
        return [
            // Extension services will be processed by ExtensionServicePass
        ];
    }

    public function getName(): string
    {
        return 'extensions';
    }
}
