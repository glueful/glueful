<?php

namespace Glueful\Cache\CDN;

/**
 * Interface for CDN Adapter implementations.
 *
 * This interface defines the contract for all CDN adapters in the Glueful framework.
 * CDN adapters can be implemented as extensions to provide integration with various
 * Content Delivery Network providers.
 */
interface CDNAdapterInterface
{
    /**
     * Purge a specific URL from the CDN cache
     *
     * @param string $url The URL to purge from cache
     * @return bool True on successful purge request, false otherwise
     */
    public function purgeUrl(string $url): bool;

    /**
     * Purge all content with the given tag from the CDN cache
     *
     * @param string $tag The cache tag to purge
     * @return bool True on successful purge request, false otherwise
     */
    public function purgeByTag(string $tag): bool;

    /**
     * Purge all content from the CDN cache
     *
     * @return bool True on successful purge request, false otherwise
     */
    public function purgeAll(): bool;

    /**
     * Get cache statistics from the CDN
     *
     * @return array The cache statistics
     */
    public function getStats(): array;

    /**
     * Generate CDN-specific cache control headers
     *
     * @param string $route The route name for which to generate headers
     * @param string|null $contentType The content type of the response
     * @return array Key-value pairs of headers to add to the response
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array;

    /**
     * Check if content is cacheable based on request and response
     *
     * @param object $request The request object
     * @param object $response The response object
     * @return bool True if the content is cacheable, false otherwise
     */
    public function isCacheable(object $request, object $response): bool;

    /**
     * Get the provider name
     *
     * @return string The name of the CDN provider
     */
    public function getProviderName(): string;

    /**
     * Get adapter status
     *
     * @return array Status information including connectivity, configuration state, etc.
     */
    public function getStatus(): array;
}
