<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Container;
use Glueful\Security\SecureSerializer;

/**
 * Security Service Provider
 *
 * Registers security-related services in the DI container.
 * Provides secure serialization and other security utilities.
 *
 * @package Glueful\DI\ServiceProviders
 */
class SecurityServiceProvider implements ServiceProviderInterface
{
    public function __construct(Container $container)
    {
        unset($container); // Container reference not needed for this provider
    }

    /**
     * Register security services
     */
    public function register(ContainerInterface $container): void
    {
        // Register SecureSerializer instances for different use cases
        $container->singleton('serializer.cache', function () {
            return SecureSerializer::forCache();
        });

        $container->singleton('serializer.queue', function () {
            return SecureSerializer::forQueue();
        });

        $container->singleton(SecureSerializer::class, function () {
            return new SecureSerializer();
        });
    }

    /**
     * Boot the service provider
     */
    public function boot(ContainerInterface $container): void
    {
        // Nothing to boot
    }
}
