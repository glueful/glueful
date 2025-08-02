<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;
use Glueful\DI\Container;

/**
 * Base Extension Service Provider
 *
 * Extends BaseServiceProvider with extension-specific functionality
 * for loading services from extension directories and configuration.
 */
abstract class BaseExtensionServiceProvider extends BaseServiceProvider
{
    protected string $extensionName;
    protected string $extensionPath;

    public function register(ContainerBuilder $container): void
    {
        // Call parent to set up container builder
        parent::register($container);

        // Load extension services configuration
        $servicesFile = $this->extensionPath . '/config/services.php';
        if (file_exists($servicesFile)) {
            $loader = new PhpFileLoader($container, new FileLocator($this->extensionPath . '/config'));
            $loader->load('services.php');
        }

        // Register extension-specific services using abstraction methods
        $this->registerExtensionServices();
    }

    public function boot(Container $container): void
    {
        // Post-compilation initialization for extensions
    }

    /**
     * Register extension services using abstraction methods
     * Override in extension service providers
     */
    protected function registerExtensionServices(): void
    {
        // Override in extension service providers
    }

    /**
     * Override parent method to call registerExtensionServices instead
     */
    protected function registerServices(): void
    {
        $this->registerExtensionServices();
    }

    public function getName(): string
    {
        return $this->extensionName;
    }
}
