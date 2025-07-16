<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Http\RequestContext;
use Glueful\Http\SessionContext;
use Glueful\Http\EnvironmentContext;
use Glueful\Http\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request Service Provider
 *
 * Registers request-related services in the DI container.
 * Provides abstracted access to request data.
 *
 * @package Glueful\DI\ServiceProviders
 */
class RequestServiceProvider implements ServiceProviderInterface
{
    /**
     * Register request services
     */
    public function register(ContainerBuilder $container): void
    {
        // Register PSR-7 ServerRequest
        $container->register(ServerRequestInterface::class)
            ->setFactory([ServerRequestFactory::class, 'fromGlobals'])
            ->setPublic(true);

        // Register RequestContext
        $container->register(RequestContext::class)
            ->setArguments([new Reference(ServerRequestInterface::class)])
            ->setPublic(true);

        // Register SessionContext
        $container->register(SessionContext::class)
            ->setPublic(true);

        // Register EnvironmentContext
        $container->register(EnvironmentContext::class)
            ->setPublic(true);
    }

    /**
     * Boot the service provider
     */
    public function boot(Container $container): void
    {
        // Nothing to boot
    }

    /**
     * Get compiler passes for request services
     */
    public function getCompilerPasses(): array
    {
        return [];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'request';
    }
}
