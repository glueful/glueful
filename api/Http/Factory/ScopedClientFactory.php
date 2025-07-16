<?php

declare(strict_types=1);

namespace Glueful\Http\Factory;

use Glueful\Http\Client;
use Glueful\Http\Configuration\HttpClientConfig;

/**
 * Scoped Client Factory
 *
 * Factory for creating pre-configured HTTP clients for specific use cases.
 * Provides convenient methods for common client configurations.
 */
class ScopedClientFactory
{
    public function __construct(private Client $baseClient)
    {
    }

    /**
     * Create an OAuth client
     */
    public function createOAuthClient(?string $baseUri = null): Client
    {
        $config = HttpClientConfig::forOAuth($baseUri);
        return $this->baseClient->createScopedClient($config->toSymfonyOptions());
    }

    /**
     * Create a webhook delivery client
     */
    public function createWebhookClient(): Client
    {
        $config = HttpClientConfig::forWebhooks();
        return $this->baseClient->createScopedClient($config->toSymfonyOptions());
    }


    /**
     * Create an API client with authentication
     */
    public function createApiClient(string $baseUri, ?string $apiKey = null): Client
    {
        $config = HttpClientConfig::forApi($baseUri, $apiKey);
        return $this->baseClient->createScopedClient($config->toSymfonyOptions());
    }

    /**
     * Create a client from configuration
     */
    public function createFromConfig(HttpClientConfig $config): Client
    {
        return $this->baseClient->createScopedClient($config->toSymfonyOptions());
    }

    /**
     * Create a client from array configuration
     */
    public function createFromArray(array $config): Client
    {
        $httpConfig = HttpClientConfig::fromArray($config);
        return $this->createFromConfig($httpConfig);
    }

    /**
     * Create a client with bearer token authentication
     */
    public function createBearerClient(string $baseUri, string $token): Client
    {
        return $this->baseClient->createScopedClient([
            'base_uri' => $baseUri,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Create a client with basic authentication
     */
    public function createBasicAuthClient(string $baseUri, string $username, string $password): Client
    {
        return $this->baseClient->createScopedClient([
            'base_uri' => $baseUri,
            'auth_basic' => [$username, $password],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Create a client with custom headers
     */
    public function createWithHeaders(string $baseUri, array $headers): Client
    {
        return $this->baseClient->createScopedClient([
            'base_uri' => $baseUri,
            'headers' => $headers
        ]);
    }

    /**
     * Create a client optimized for file downloads
     */
    public function createDownloadClient(int $timeout = 300): Client
    {
        return $this->baseClient->createScopedClient([
            'timeout' => $timeout,
            'max_duration' => $timeout + 60,
            'buffer' => false, // Don't buffer large responses
            'headers' => [
                'Accept' => '*/*',
            ]
        ]);
    }

    /**
     * Create a client for social login providers
     */
    public function createSocialLoginClient(string $provider, string $baseUri): Client
    {
        $userAgent = "Glueful-SocialLogin-{$provider}/1.0";
        return $this->baseClient->createScopedClient([
            'base_uri' => $baseUri,
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => $userAgent,
            ]
        ]);
    }

    /**
     * Create a client for payment gateways
     */
    public function createPaymentClient(string $baseUri, string $apiKey): Client
    {
        return $this->baseClient->createScopedClient([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'verify_peer' => true,
            'verify_host' => true,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Glueful-Payment/1.0',
            ]
        ]);
    }

    /**
     * Create a client for monitoring and health checks
     */
    public function createMonitoringClient(): Client
    {
        return $this->baseClient->createScopedClient([
            'timeout' => 5,
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => 'Glueful-Monitor/1.0',
                'Accept' => '*/*',
            ]
        ]);
    }
}
