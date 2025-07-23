<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;
use Glueful\DI\Container;

/**
 * Base Extension Service Provider
 *
 * Provides abstraction methods for extensions to register services
 * without directly using Symfony DI components
 */
abstract class BaseExtensionServiceProvider implements ServiceProviderInterface
{
    protected string $extensionName;
    protected string $extensionPath;
    private ContainerBuilder $containerBuilder;

    public function register(ContainerBuilder $container): void
    {
        $this->containerBuilder = $container;

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

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return $this->extensionName;
    }

    /**
     * Register a singleton service (shared instance)
     */
    protected function singleton(string $abstract, $concrete = null): void
    {
        $definition = new Definition($concrete ?? $abstract);
        $definition->setShared(true);
        $definition->setPublic(true);

        if (is_callable($concrete)) {
            $definition->setFactory($concrete);
        }

        $this->containerBuilder->setDefinition($abstract, $definition);
    }

    /**
     * Bind a service to the container
     */
    protected function bind(string $abstract, $concrete = null): void
    {
        $definition = new Definition($concrete ?? $abstract);
        $definition->setShared(false);
        $definition->setPublic(true);

        if (is_callable($concrete)) {
            $definition->setFactory($concrete);
        }

        $this->containerBuilder->setDefinition($abstract, $definition);
    }

    /**
     * Register a service with a factory method
     */
    protected function factory(string $abstract, callable $factory): void
    {
        $definition = new Definition();
        $definition->setFactory($factory);
        $definition->setPublic(true);

        $this->containerBuilder->setDefinition($abstract, $definition);
    }

    /**
     * Create a service reference
     */
    protected function ref(string $serviceId): Reference
    {
        return new Reference($serviceId);
    }

    /**
     * Tag a service
     */
    protected function tagService(string $serviceId, string $tag, array $attributes = []): void
    {
        if ($this->containerBuilder->hasDefinition($serviceId)) {
            $this->containerBuilder->getDefinition($serviceId)->addTag($tag, $attributes);
        }
    }

    /**
     * Set an alias for a service
     */
    protected function alias(string $alias, string $service): void
    {
        $this->containerBuilder->setAlias($alias, $service);
    }

    /**
     * Register a service with explicit arguments
     */
    protected function service(string $id, string $class, array $arguments = []): void
    {
        $definition = new Definition($class);
        $definition->setArguments($arguments);
        $definition->setPublic(true);

        $this->containerBuilder->setDefinition($id, $definition);
    }
}
