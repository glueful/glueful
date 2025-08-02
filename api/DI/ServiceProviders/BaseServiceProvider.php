<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;

/**
 * Base Service Provider
 *
 * Provides clean abstraction methods to register services without directly using Symfony DI components.
 * Can be used by core services, application services, or any part of the system.
 */
abstract class BaseServiceProvider implements ServiceProviderInterface
{
    private ContainerBuilder $containerBuilder;

    public function register(ContainerBuilder $container): void
    {
        $this->containerBuilder = $container;

        // Register services using abstraction methods
        $this->registerServices();
    }

    public function boot(Container $container): void
    {
        // Post-compilation initialization if needed
    }

    /**
     * Register services using abstraction methods
     * Override in service providers
     */
    protected function registerServices(): void
    {
        // Override in service providers
    }

    public function getCompilerPasses(): array
    {
        return [];
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
