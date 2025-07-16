<?php

declare(strict_types=1);

namespace Glueful\DI;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Pure Symfony DI Service Provider Interface
 */
interface ServiceProviderInterface
{
    /**
     * Register services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void;

    /**
     * Boot services after container is built (optional)
     */
    public function boot(Container $container): void;

    /**
     * Get compiler passes for advanced service processing
     */
    public function getCompilerPasses(): array;

    /**
     * Get the provider name for debugging
     */
    public function getName(): string;
}
