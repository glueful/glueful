<?php

declare(strict_types=1);

namespace Glueful\DI\Interfaces;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Container Interface
 *
 * Defines the contract for dependency injection containers.
 * Extends PSR-11 ContainerInterface for standards compliance.
 */
interface ContainerInterface extends PsrContainerInterface
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
     *
     * @param string $id Identifier of the entry to look for
     * @return mixed Entry
     * @throws \Psr\Container\NotFoundExceptionInterface No entry was found for this identifier
     * @throws \Psr\Container\ContainerExceptionInterface Error while retrieving the entry
     */
    public function get(string $id): mixed;

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

    /**
     * Create an alias for a service
     */
    public function alias(string $alias, string $abstract): void;
}
