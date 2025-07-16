<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
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
     * Register HTTP client services with the container
     */
    public function register(ContainerInterface $container): void
    {
        // Register Symfony HTTP client
        $container->singleton(HttpClientInterface::class, function () {
            return HttpClient::create([
                'timeout' => config('http.default.timeout', 30),
                'max_duration' => config('http.default.max_duration', 60),
                'max_redirects' => config('http.default.max_redirects', 3),
                'http_version' => config('http.default.http_version', '2.0'),
                'verify_peer' => config('http.default.verify_ssl', true),
                'verify_host' => config('http.default.verify_ssl', true),
                'headers' => config('http.default.default_headers', []),
            ]);
        });

        // Register PSR-18 compliant client
        $container->singleton(ClientInterface::class, function (ContainerInterface $container) {
            return new Psr18Client(
                $container->get(HttpClientInterface::class)
            );
        });

        // Register Glueful HTTP client facade
        $container->singleton(Client::class, function (ContainerInterface $container) {
            return new Client(
                $container->get(HttpClientInterface::class),
                $container->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // Register scoped client factory
        $container->singleton(ScopedClientFactory::class, function (ContainerInterface $container) {
            return new ScopedClientFactory(
                $container->get(Client::class)
            );
        });

        // Register webhook delivery service
        $container->singleton(WebhookDeliveryService::class, function (ContainerInterface $container) {
            return new WebhookDeliveryService(
                $container->get(Client::class),
                $container->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // Register specialized client builders
        $container->singleton(OAuthClientBuilder::class, function (ContainerInterface $container) {
            return new OAuthClientBuilder(
                $container->get(Client::class)
            );
        });

        $container->singleton(PaymentClientBuilder::class, function (ContainerInterface $container) {
            return new PaymentClientBuilder(
                $container->get(Client::class)
            );
        });

        $container->singleton(NotificationClientBuilder::class, function (ContainerInterface $container) {
            return new NotificationClientBuilder(
                $container->get(Client::class)
            );
        });

        // Register helper services
        $container->singleton(ExternalApiService::class, function (ContainerInterface $container) {
            return new ExternalApiService(
                $container->get(Client::class),
                $container->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $container->singleton(HealthCheckService::class, function (ContainerInterface $container) {
            return new HealthCheckService(
                $container->get(Client::class),
                $container->get(\Psr\Log\LoggerInterface::class)
            );
        });
    }

    /**
     * Boot HTTP client services
     */
    public function boot(ContainerInterface $container): void
    {
        // No additional boot logic required for HTTP client
    }
}
