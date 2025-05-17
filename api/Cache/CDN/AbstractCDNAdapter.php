<?php

namespace Glueful\Cache\CDN;

/**
 * Abstract base class for CDN Adapters.
 *
 * This class provides common functionality for all CDN adapters and serves
 * as a base for implementing CDN-specific extensions.
 */
abstract class AbstractCDNAdapter implements CDNAdapterInterface
{
    /**
     * Configuration for the CDN adapter
     *
     * @var array
     */
    protected array $config;

    /**
     * Default time-to-live for cached content in seconds
     *
     * @var int
     */
    protected int $defaultTtl;

    /**
     * Constructor for CDN adapters
     *
     * @param array $config Configuration options for the adapter
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultTtl = $config['default_ttl'] ?? 3600; // Default: 1 hour
    }

    /**
     * {@inheritdoc}
     */
    public function purgeUrl(string $url): bool
    {
        // This method must be implemented by concrete adapters
        throw new \LogicException(sprintf(
            'The %s adapter does not implement the purgeUrl method.',
            static::class
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function purgeByTag(string $tag): bool
    {
        // This method must be implemented by concrete adapters
        throw new \LogicException(sprintf(
            'The %s adapter does not implement the purgeByTag method.',
            static::class
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function purgeAll(): bool
    {
        // This method must be implemented by concrete adapters
        throw new \LogicException(sprintf(
            'The %s adapter does not implement the purgeAll method.',
            static::class
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        // Default implementation returns empty stats
        return [
            'provider' => $this->getProviderName(),
            'status' => 'unknown',
            'cache_hit_ratio' => null,
            'edge_requests' => null,
            'origin_requests' => null
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        // Get route-specific config if available
        $routeConfig = $this->getRouteConfig($route);

        // Determine TTL
        $ttl = $routeConfig['ttl'] ?? $this->defaultTtl;

        // Default cache headers
        $headers = [
            'Cache-Control' => 'public, max-age=' . $ttl,
            'X-Cache-Provider' => $this->getProviderName()
        ];

        // Add vary headers if specified
        if (!empty($routeConfig['vary_by'])) {
            $headers['Vary'] = implode(', ', $routeConfig['vary_by']);
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function isCacheable(object $request, object $response): bool
    {
        // Do not cache if request method is not GET
        if ($request->method() !== 'GET') {
            return false;
        }

        // Do not cache error responses
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        // Do not cache responses with specific cache-control directives
        $cacheControl = $response->headers->get('Cache-Control', '');
        if (
            str_contains($cacheControl, 'no-store') ||
            str_contains($cacheControl, 'no-cache') ||
            str_contains($cacheControl, 'private')
        ) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'configured' => !empty($this->config),
            'enabled' => $this->config['enabled'] ?? false
        ];
    }

    /**
     * Get configuration for a specific route
     *
     * @param string $route The route name
     * @return array The route-specific configuration
     */
    protected function getRouteConfig(string $route): array
    {
        $rules = $this->config['rules'] ?? [];

        // Check for exact route match
        if (isset($rules[$route])) {
            return $rules[$route];
        }

        // Check for wildcard matches
        foreach ($rules as $pattern => $config) {
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('*', '.*', $pattern) . '$/';
                if (preg_match($regex, $route)) {
                    return $config;
                }
            }
        }

        return [];
    }

    /**
     * Get the configured API key or token
     *
     * @return string|null The API key or token
     */
    protected function getApiKey(): ?string
    {
        return $this->config['api_key'] ??
               $this->config['api_token'] ??
               $this->config['token'] ??
               $this->config['key'] ??
               null;
    }
}
