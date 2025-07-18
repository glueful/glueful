<?php

declare(strict_types=1);

namespace Glueful\DI;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

/**
 * Glueful Container - Clean interface over pure Symfony DI
 */
class Container implements ContainerInterface
{
    public function __construct(
        private SymfonyContainerInterface $container
    ) {
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function getSymfonyContainer(): SymfonyContainerInterface
    {
        return $this->container;
    }

    public function isCompiled(): bool
    {
        return !$this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder;
    }

    public function getParameter(string $name): mixed
    {
        return $this->container->getParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->container->hasParameter($name);
    }

    public function getServiceIds(): array
    {
        // Handle both ContainerBuilder and compiled containers
        if ($this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder) {
            return $this->container->getServiceIds();
        }

        // For compiled containers, we need to use reflection to get service IDs
        if (method_exists($this->container, 'getServiceIds')) {
            return $this->container->getServiceIds();
        }

        // Fallback for basic containers - return empty array
        return [];
    }

    /**
     * Register a service provider
     *
     * @param object $serviceProvider Service provider instance
     * @return void
     */
    public function register(object $serviceProvider): void
    {
        // Check if the service provider has a register method
        if (method_exists($serviceProvider, 'register')) {
            // Pass the underlying Symfony container, not the wrapper
            $serviceProvider->register($this->container);
        }
    }
}
