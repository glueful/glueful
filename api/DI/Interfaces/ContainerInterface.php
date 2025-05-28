<?php

declare(strict_types=1);

namespace Glueful\DI\Interfaces;

/**
 * Container Interface
 *
 * Defines the contract for dependency injection containers
 */
interface ContainerInterface
{
    /**
     * Bind a service to the container
     */
    public function bind(string $abstract, mixed $concrete = null, bool $singleton = false): void;

    /**
     * Bind a singleton service
     */
    public function singleton(string $abstract, mixed $concrete = null): void;

    /**
     * Bind an existing instance
     */
    public function instance(string $abstract, object $instance): void;

    /**
     * Get a service from the container
     */
    public function get(string $id): object;

    /**
     * Check if a service exists
     */
    public function has(string $id): bool;

    /**
     * Resolve a service with auto-wiring
     */
    public function resolve(string $abstract): object;

    /**
     * Register a service provider
     */
    public function register(ServiceProviderInterface $provider): void;

    /**
     * Boot all registered service providers
     */
    public function boot(): void;
}