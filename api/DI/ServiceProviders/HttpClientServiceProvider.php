<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Http\Client\ClientInterface;
use Glueful\Http\Client;
use Glueful\Http\Factory\ScopedClientFactory;
use Glueful\Http\Services\WebhookDeliveryService;
use Glueful\Http\Services\ExternalApiService;
use Glueful\Http\Services\HealthCheckService;
use Glueful\Http\Builders\OAuthClientBuilder;
use Glueful\Http\Builders\PaymentClientBuilder;
use Glueful\Http\Builders\NotificationClientBuilder;

/**
 * HTTP Client Service Provider
 *
 * Registers Symfony HttpClient components with the dependency injection container.
 * Provides both Symfony's HttpClientInterface and PSR-18 compliant ClientInterface.
 *
 * @package Glueful\DI\ServiceProviders
 */
class HttpClientServiceProvider implements ServiceProviderInterface
{
    /**
     * Register HTTP client services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Symfony HTTP client
        $container->register(HttpClientInterface::class)
            ->setFactory([$this, 'createHttpClient'])
            ->setPublic(true);

        // Register PSR-18 compliant client
        $container->register(ClientInterface::class, Psr18Client::class)
            ->setArguments([new Reference(HttpClientInterface::class)])
            ->setPublic(true);

        // Register Glueful HTTP client facade
        $container->register(Client::class)
            ->setArguments([
                new Reference(HttpClientInterface::class),
                new Reference('logger')
            ])
            ->setPublic(true);

        // Register scoped client factory
        $container->register(ScopedClientFactory::class)
            ->setArguments([new Reference(Client::class)])
            ->setPublic(true);

        // Register webhook delivery service
        $container->register(WebhookDeliveryService::class)
            ->setArguments([
                new Reference(Client::class),
                new Reference('logger')
            ])
            ->setPublic(true);

        // Register specialized client builders
        $container->register(OAuthClientBuilder::class)
            ->setArguments([new Reference(Client::class)])
            ->setPublic(true);

        $container->register(PaymentClientBuilder::class)
            ->setArguments([new Reference(Client::class)])
            ->setPublic(true);

        $container->register(NotificationClientBuilder::class)
            ->setArguments([new Reference(Client::class)])
            ->setPublic(true);

        // Register helper services
        $container->register(ExternalApiService::class)
            ->setArguments([
                new Reference(Client::class),
                new Reference('logger')
            ])
            ->setPublic(true);

        $container->register(HealthCheckService::class)
            ->setArguments([
                new Reference(Client::class),
                new Reference('logger')
            ])
            ->setPublic(true);
    }

    /**
     * Boot HTTP client services after container is built
     */
    public function boot(Container $container): void
    {
        // No additional boot logic required for HTTP client
    }

    /**
     * Get compiler passes for HTTP client services
     */
    public function getCompilerPasses(): array
    {
        return [
            // HTTP client services don't need custom compiler passes
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'http_client';
    }

    /**
     * Factory method for creating HTTP client
     */
    public static function createHttpClient(): HttpClientInterface
    {
        return HttpClient::create([
            'timeout' => config('http.default.timeout', 30),
            'max_duration' => config('http.default.max_duration', 60),
            'max_redirects' => config('http.default.max_redirects', 3),
            'http_version' => config('http.default.http_version', '2.0'),
            'verify_peer' => config('http.default.verify_ssl', true),
            'verify_host' => config('http.default.verify_ssl', true),
            'headers' => config('http.default.default_headers', []),
        ]);
    }
}
