<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ExtensionInterface;

/**
 * Extension Service Provider
 *
 * Base service provider for extensions. Provides common functionality
 * for registering extension services with the DI container.
 */
abstract class ExtensionServiceProvider implements ServiceProviderInterface, ExtensionInterface
{
    protected bool $enabled = true;
    protected array $dependencies = [];

    /**
     * Get the extension name
     */
    abstract public function getName(): string;

    /**
     * Get the extension version
     */
    abstract public function getVersion(): string;

    /**
     * Get the extension description
     */
    abstract public function getDescription(): string;

    /**
     * Register services with the container
     */
    abstract public function register(ContainerInterface $container): void;

    /**
     * Boot the extension (optional override)
     */
    public function boot(ContainerInterface $container): void
    {
        // Override in child classes if needed
    }

    /**
     * Register extension routes (optional override)
     */
    public function routes(): void
    {
        // Override in child classes if needed
    }

    /**
     * Get extension dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Check if the extension is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable the extension
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable the extension
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Helper method to bind extension controllers
     */
    protected function bindController(ContainerInterface $container, string $controller): void
    {
        $container->bind($controller);
    }

    /**
     * Helper method to bind extension services
     */
    protected function bindService(
        ContainerInterface $container,
        string $abstract,
        callable $concrete,
        bool $singleton = false
    ): void {
        if ($singleton) {
            $container->singleton($abstract, $concrete);
        } else {
            $container->bind($abstract, $concrete);
        }
    }
}
