<?php

declare(strict_types=1);

namespace Glueful\Console;

/**
 * Simple Service Container for Console Commands
 *
 * Provides dependency injection and lazy loading of services
 * to reduce memory usage and prevent circular dependencies.
 *
 * @package Glueful\Console
 */
class ServiceContainer
{
    /** @var array<string, callable> Service factories */
    private array $factories = [];

    /** @var array<string, object> Singleton instances */
    private array $instances = [];

    /** @var array<string, bool> Singleton flags */
    private array $singletons = [];

    /**
     * Register a service factory
     *
     * @param string $name Service name
     * @param callable $factory Service factory function
     * @param bool $singleton Whether to treat as singleton
     * @return void
     */
    public function register(string $name, callable $factory, bool $singleton = true): void
    {
        $this->factories[$name] = $factory;
        $this->singletons[$name] = $singleton;
    }

    /**
     * Get a service instance
     *
     * @param string $name Service name
     * @return object Service instance
     * @throws \InvalidArgumentException If service not found
     */
    public function get(string $name): object
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Service '{$name}' not registered");
        }

        // Return existing singleton instance if available
        if ($this->singletons[$name] && isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        // Create new instance
        $instance = $this->factories[$name]();

        // Store singleton instances
        if ($this->singletons[$name]) {
            $this->instances[$name] = $instance;
        }

        return $instance;
    }

    /**
     * Check if service is registered
     *
     * @param string $name Service name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * Clear all singleton instances (for memory cleanup)
     *
     * @return void
     */
    public function clearInstances(): void
    {
        foreach ($this->instances as $instance) {
            if (method_exists($instance, 'cleanup')) {
                $instance->cleanup();
            }
        }
        $this->instances = [];
    }

    /**
     * Register default console services
     *
     * @return void
     */
    public function registerDefaults(): void
    {
        // Register SecurityManager
        $this->register('security_manager', function () {
            return new \Glueful\Security\SecurityManager();
        });

        // Register VulnerabilityScanner
        $this->register('vulnerability_scanner', function () {
            return new \Glueful\Security\VulnerabilityScanner();
        });

        // Register SystemCheckCommand
        $this->register('system_checker', function () {
            return new \Glueful\Console\Commands\SystemCheckCommand();
        });
    }
}
