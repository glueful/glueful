<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
    /**
     * Register security services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register SecureSerializer instances for different use cases
        $container->register('serializer.cache', SecureSerializer::class)
            ->setFactory([SecureSerializer::class, 'forCache'])
            ->setPublic(true);

        $container->register('serializer.queue', SecureSerializer::class)
            ->setFactory([SecureSerializer::class, 'forQueue'])
            ->setPublic(true);

        $container->register(SecureSerializer::class)
            ->setPublic(true);
    }

    /**
     * Boot security services after container is built
     */
    public function boot(Container $container): void
    {
        // Nothing to boot
    }

    /**
     * Get compiler passes for security services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Security services don't need custom compiler passes
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'security';
    }
}
