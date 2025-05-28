<?php

declare(strict_types=1);

namespace Glueful\DI\Interfaces;

/**
 * Service Provider Interface
 *
 * Defines the contract for service providers that register services with the container
 */
interface ServiceProviderInterface
{
    /**
     * Register services with the container
     */
    public function register(ContainerInterface $container): void;

    /**
     * Boot services after all providers have been registered (optional)
     */
    public function boot(ContainerInterface $container): void;
}