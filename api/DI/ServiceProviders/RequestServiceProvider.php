<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
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
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register request services
     */
    public function register(ContainerInterface $container): void
    {
        // Register PSR-7 ServerRequest
        $container->singleton(ServerRequestInterface::class, function () {
            return ServerRequestFactory::fromGlobals();
        });

        // Register RequestContext
        $container->singleton(RequestContext::class, function ($container) {
            $request = $container->get(ServerRequestInterface::class);
            return new RequestContext($request);
        });

        // Register SessionContext
        $container->singleton(SessionContext::class, function () {
            return new SessionContext();
        });

        // Register EnvironmentContext
        $container->singleton(EnvironmentContext::class, function () {
            return new EnvironmentContext();
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
