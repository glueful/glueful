<?php

namespace Glueful\Cache;

use Glueful\Cache\CDN\CDNAdapterInterface;
use Glueful\Helpers\ExtensionsManager;

/**
 * Service for edge caching functionality.
 *
 * This service provides integration with Content Delivery Networks (CDNs)
 * through a pluggable adapter system. CDN adapters can be implemented
 * as extensions.
 */
class EdgeCacheService
{
    /**
     * The cache engine instance
     *
     * @var CacheEngine
     */
    private $cacheEngine;

    /**
     * The CDN adapter instance
     *
     * @var CDNAdapterInterface|null
     */
    private $cdnAdapter;

    /**
     * The edge cache configuration
     *
     * @var array
     */
    private $config;

    /**
     * Constructor for the Edge Cache Service
     *
     * @param CacheEngine|null $cacheEngine The cache engine to use
     * @param CDNAdapterInterface|null $cdnAdapter A specific CDN adapter to use
     */
    public function __construct(?CacheEngine $cacheEngine = null, ?CDNAdapterInterface $cdnAdapter = null)
    {
        $this->cacheEngine = $cacheEngine ?? new CacheEngine();
        $this->config = config('cache.edge', []);

        // If no adapter is provided, attempt to resolve one
        if ($cdnAdapter === null) {
            $this->cdnAdapter = $this->resolveCDNAdapter();
        } else {
            $this->cdnAdapter = $cdnAdapter;
        }
    }

    /**
     * Generate cache control headers for edge caching
     *
     * @param string $route The route name
     * @param string|null $contentType The content type of the response
     * @return array The cache headers
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return [];
        }

        return $this->cdnAdapter->generateCacheHeaders($route, $contentType);
    }

    /**
     * Purge a specific URL from the CDN cache
     *
     * @param string $url The URL to purge
     * @return bool True if the purge was successful, false otherwise
     */
    public function purgeUrl(string $url): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->purgeUrl($url);
    }

    /**
     * Purge content by cache tag
     *
     * @param string $tag The cache tag to purge
     * @return bool True if the purge was successful, false otherwise
     */
    public function purgeByTag(string $tag): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->purgeByTag($tag);
    }

    /**
     * Purge all content from the CDN cache
     *
     * @return bool True if the purge was successful, false otherwise
     */
    public function purgeAll(): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->purgeAll();
    }

    /**
     * Get cache statistics from the CDN
     *
     * @return array The cache statistics
     */
    public function getStats(): array
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return [
                'enabled' => false,
                'provider' => null
            ];
        }

        return $this->cdnAdapter->getStats();
    }

    /**
     * Check if content is cacheable based on request and response
     *
     * @param object $request The request object
     * @param object $response The response object
     * @return bool True if the content is cacheable, false otherwise
     */
    public function isCacheable(object $request, object $response): bool
    {
        if (!$this->isEnabled() || $this->cdnAdapter === null) {
            return false;
        }

        return $this->cdnAdapter->isCacheable($request, $response);
    }

    /**
     * Get the configured CDN provider
     *
     * @return string|null The name of the CDN provider, or null if none is configured
     */
    public function getProvider(): ?string
    {
        if ($this->cdnAdapter === null) {
            return null;
        }

        return $this->cdnAdapter->getProviderName();
    }

    /**
     * Check if edge caching is enabled
     *
     * @return bool True if edge caching is enabled, false otherwise
     */
    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? false) && $this->cdnAdapter !== null;
    }

    /**
     * Get the current CDN adapter
     *
     * @return CDNAdapterInterface|null The current CDN adapter, or null if none is configured
     */
    public function getCDNAdapter(): ?CDNAdapterInterface
    {
        return $this->cdnAdapter;
    }

    /**
     * Set a CDN adapter
     *
     * @param CDNAdapterInterface $adapter The CDN adapter to use
     * @return self
     */
    public function setCDNAdapter(CDNAdapterInterface $adapter): self
    {
        $this->cdnAdapter = $adapter;
        return $this;
    }

    /**
     * Resolve a CDN adapter from the extension system
     *
     * @return CDNAdapterInterface|null The resolved CDN adapter, or null if none could be resolved
     */
    private function resolveCDNAdapter(): ?CDNAdapterInterface
    {
        // If no provider is configured, return null
        if (empty($this->config['provider'])) {
            return null;
        }

        try {
            // Get the extension manager
            $extensionManager = new ExtensionsManager();

            // Try to resolve an adapter for the configured provider
            return $extensionManager->resolveCDNAdapter($this->config['provider'], $this->config);
        } catch (\Throwable $e) {
            // Log the error
            error_log('Failed to resolve CDN adapter: ' . $e->getMessage());

            return null;
        }
    }
}
