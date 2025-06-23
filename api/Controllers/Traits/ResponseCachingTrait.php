<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Http\Response;
use Glueful\Cache\CacheStore;
use Glueful\Database\QueryCacheService;
use Glueful\Cache\EdgeCacheService;
use Glueful\Logging\AuditEvent;

/**
 * Response Caching Trait
 *
 * Provides comprehensive caching functionality for controllers.
 * Handles response caching, query caching, cache invalidation, and CDN integration.
 *
 * @package Glueful\Controllers\Traits
 */
trait ResponseCachingTrait
{
    /**
     * Get cache store instance
     *
     * @return CacheStore
     */
    protected function getCacheStore(): CacheStore
    {
        try {
            return container()->get(CacheStore::class);
        } catch (\Exception $e) {
            throw new \RuntimeException('CacheStore is required for response caching: ' . $e->getMessage());
        }
    }

     /**
     * Cache response with automatic cache key generation
     *
     * @param string $key Cache key identifier
     * @param callable $callback Callback to generate data if not cached
     * @param int $ttl Time to live in seconds
     * @param array $tags Cache tags for invalidation
     * @return mixed Cached or generated data
     */
    protected function cacheResponse(
        string $key,
        callable $callback,
        int $ttl = 3600,
        array $tags = []
    ): mixed {
        // Generate cache key with controller context (sanitize class name for cache compatibility)
        $controllerName = str_replace('\\', '.', static::class);
        $cacheKey = sprintf(
            'controller:%s:%s:%s',
            $controllerName,
            $key,
            md5(serialize([
                $this->request->query->all(),
                $this->currentUser?->uuid ?? null,
                $this->request->headers->get('Accept'),
                $this->request->headers->get('Accept-Language')
            ]))
        );

        // Add user-specific tags if authenticated
        if ($this->currentUser) {
            $tags[] = 'user:' . $this->currentUser->uuid;
        }

        return $this->getCacheStore()->remember($cacheKey, $callback, $ttl);
    }

    /**
     * Cache query results with repository integration
     *
     * @param string $repository Repository name
     * @param string $method Repository method name
     * @param array $args Method arguments
     * @param int $ttl Time to live in seconds
     * @param array $tags Additional cache tags
     * @return mixed Cached query results
     */
    protected function cacheQuery(
        string $repository,
        string $method,
        array $args = [],
        int $ttl = 3600,
        array $tags = []
    ): mixed {
        $repo = $this->repositoryFactory->getRepository($repository);

        // Use QueryCacheService for intelligent query caching
        $cacheService = new QueryCacheService();

        // Add repository-specific tags
        $tags[] = 'repository:' . $repository;
        $tags[] = 'method:' . $method;

        // Note: $ttl and $tags parameters are prepared for future use
        // when QueryCacheService supports them
        return $cacheService->cacheRepositoryMethod($repo, $method, $args);
    }

    /**
     * Cache paginated results
     *
     * @param string $repository Repository name
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param array $conditions Query conditions
     * @param array $orderBy Order by criteria
     * @param int $ttl Cache time to live
     * @return array Paginated results
     */
    protected function cachePaginatedResponse(
        string $repository,
        int $page = 1,
        int $perPage = 25,
        array $conditions = [],
        array $orderBy = ['created_at' => 'DESC'],
        int $ttl = 600
    ): array {
        $cacheKey = sprintf(
            'paginated:%s:page_%d:per_%d',
            $repository,
            $page,
            $perPage
        );

        return $this->cacheResponse($cacheKey, function () use ($repository, $page, $perPage, $conditions, $orderBy) {
            $repo = $this->repositoryFactory->getRepository($repository);
            return $repo->paginate($page, $perPage, $conditions, $orderBy);
        }, $ttl, ['pagination', 'repository:' . $repository]);
    }

    /**
     * Add cache headers to response
     *
     * @param Response $response Response object
     * @param array $options Cache options
     * @return Response Response with cache headers
     */
    protected function withCacheHeaders(
        Response $response,
        array $options = []
    ): Response {
        $defaults = [
            'public' => true,
            'max_age' => 3600,
            's_maxage' => 3600,
            'must_revalidate' => true,
            'etag' => true,
            'vary' => ['Accept', 'Authorization']
        ];

        $settings = array_merge($defaults, $options);

        // Set cache control headers
        $cacheControl = [];

        if ($settings['public']) {
            $cacheControl[] = 'public';
        } else {
            $cacheControl[] = 'private';
        }

        $cacheControl[] = 'max-age=' . $settings['max_age'];

        if ($settings['s_maxage'] !== null) {
            $cacheControl[] = 's-maxage=' . $settings['s_maxage'];
        }

        if ($settings['must_revalidate']) {
            $cacheControl[] = 'must-revalidate';
        }

        header('Cache-Control: ' . implode(', ', $cacheControl));

        // Set Vary header
        if (!empty($settings['vary'])) {
            header('Vary: ' . implode(', ', $settings['vary']));
        }

        return $response;
    }

    /**
     * Create cached response with ETag validation
     *
     * @param mixed $data Response data
     * @param string $cacheKey Cache key
     * @param int $ttl Time to live
     * @param array $tags Cache tags
     * @return Response Cached response
     */
    protected function cachedResponse(
        mixed $data,
        string $cacheKey,
        int $ttl = 3600,
        array $tags = []
    ): Response {
        // Generate ETag from data
        $etag = '"' . md5(serialize($data)) . '"';

        // Check If-None-Match header
        $clientEtag = $this->request->headers->get('If-None-Match');
        if ($clientEtag === $etag) {
            // Return 304 Not Modified
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }

        // Cache the data
        $this->cacheResponse($cacheKey, fn() => $data, $ttl, $tags);

        // Create response
        $response = Response::ok($data);

        // Add cache headers
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=' . $ttl . ', must-revalidate');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        return $response;
    }

    /**
     * Cache with permission awareness
     *
     * @param string $key Cache key
     * @param callable $callback Data generator callback
     * @param int $defaultTtl Default time to live
     * @return mixed Cached data
     */
    protected function cacheByPermission(
        string $key,
        callable $callback,
        int $defaultTtl = 3600
    ): mixed {
        $ttl = $defaultTtl;
        $tags = ['user:' . ($this->currentUser?->uuid ?? 'anonymous')];

        // Adjust cache duration based on user type
        if ($this->isAdmin()) {
            $ttl = 300; // 5 minutes for admins (fresher data)
            $tags[] = 'admin_cache';
        } elseif ($this->currentUser && $this->can('premium_features')) {
            $ttl = 7200; // 2 hours for premium users
            $tags[] = 'premium_cache';
        } elseif ($this->currentUser) {
            $ttl = 3600; // 1 hour for regular users
            $tags[] = 'user_cache';
        } else {
            $ttl = 1800; // 30 minutes for anonymous users
            $tags[] = 'anonymous_cache';
        }

        return $this->cacheResponse($key, $callback, $ttl, $tags);
    }

    /**
     * Invalidate cache by tags
     *
     * @param array $tags Cache tags to invalidate
     */
    protected function invalidateCache(array $tags = []): void
    {
        if (empty($tags)) {
            // Invalidate user-specific cache by default
            if ($this->currentUser) {
                $tags = ['user:' . $this->currentUser->uuid];
            }
        }

        $this->getCacheStore()->invalidateTags($tags);

        // Log cache invalidation
        $this->asyncAudit(
            AuditEvent::CATEGORY_SYSTEM,
            'cache_invalidated',
            AuditEvent::SEVERITY_INFO,
            [
                'tags' => $tags,
                'controller' => static::class,
                'user_uuid' => $this->currentUser?->uuid
            ]
        );
    }

    /**
     * Invalidate resource-specific cache
     *
     * @param string $resource Resource name
     * @param string|null $id Resource ID
     */
    protected function invalidateResourceCache(string $resource, ?string $id = null): void
    {
        $tags = ['repository:' . $resource];

        if ($id) {
            $tags[] = $resource . ':' . $id;
        }

        $this->invalidateCache($tags);
    }

    /**
     * Warm cache with predefined keys
     *
     * @param array $keys Cache keys with TTL values
     * @param callable $dataProvider Data provider callback
     */
    protected function warmCache(array $keys, callable $dataProvider): void
    {
        foreach ($keys as $key => $ttl) {
            $this->cacheResponse($key, function () use ($key, $dataProvider) {
                return $dataProvider($key);
            }, $ttl);
        }
    }

    /**
     * Conditional caching based on request context
     *
     * @param string $key Cache key
     * @param callable $callback Data generator
     * @param array $conditions Cache conditions
     * @return mixed Data
     */
    protected function conditionalCache(
        string $key,
        callable $callback,
        array $conditions = []
    ): mixed {
        $shouldCache = true;
        $ttl = 3600;

        // Check conditions
        foreach ($conditions as $condition => $value) {
            switch ($condition) {
                case 'method':
                    if (!in_array($this->request->getMethod(), (array)$value)) {
                        $shouldCache = false;
                    }
                    break;

                case 'authenticated':
                    if ($value && !$this->currentUser) {
                        $shouldCache = false;
                    }
                    break;

                case 'min_permission':
                    if (!$this->can($value)) {
                        $shouldCache = false;
                    }
                    break;

                case 'ttl':
                    $ttl = $value;
                    break;
            }
        }

        if (!$shouldCache) {
            return $callback();
        }

        return $this->cacheResponse($key, $callback, $ttl);
    }

    /**
     * Add edge cache headers for CDN
     *
     * @param Response $response Response object
     * @param string $pattern Route pattern
     * @param int $ttl Time to live
     * @return Response Response with edge cache headers
     */
    protected function edgeCacheResponse(
        Response $response,
        string $pattern,
        int $ttl = 3600
    ): Response {
        // Add edge cache headers based on route pattern
        $edgeService = new EdgeCacheService();
        $contentType = $this->request->headers->get('Accept', 'application/json');
        $headers = $edgeService->generateCacheHeaders($pattern, $contentType);

        foreach ($headers as $header => $value) {
            header($header . ': ' . $value);
        }

        // Add surrogate keys for targeted purging
        $surrogateKeys = [
            'controller:' . basename(str_replace('\\', '/', static::class)),
            'user:' . ($this->currentUser?->uuid ?? 'anonymous'),
            'pattern:' . $pattern
        ];

        header('Surrogate-Key: ' . implode(' ', $surrogateKeys));

        // Note: $ttl parameter is prepared for future use
        return $response;
    }

    /**
     * Track cache performance metrics
     *
     * @param string $key Cache key
     * @param bool $hit Whether it was a cache hit
     * @param float $duration Operation duration
     */
    protected function trackCachePerformance(string $key, bool $hit, float $duration): void
    {
        // Track cache hit/miss metrics
        $metrics = [
            'key' => $key,
            'hit' => $hit,
            'duration_ms' => $duration * 1000,
            'controller' => static::class,
            'user_uuid' => $this->currentUser?->uuid,
            'timestamp' => time()
        ];

        // Store metrics (could be sent to monitoring service)
        $this->getCacheStore()->zadd('cache_metrics', [json_encode($metrics) => time()]);

        // Cleanup old metrics (keep last 24 hours)
        $this->getCacheStore()->zremrangebyscore('cache_metrics', '-inf', (string)(time() - 86400));
    }

    /**
     * Cache fragment of response
     *
     * @param string $fragment Fragment identifier
     * @param callable $callback Data generator
     * @param int $ttl Time to live
     * @param array $dependencies Cache dependencies
     * @return mixed Cached fragment
     */
    protected function cacheFragment(
        string $fragment,
        callable $callback,
        int $ttl = 1800,
        array $dependencies = []
    ): mixed {
        $controllerName = str_replace('\\', '.', static::class);
        $cacheKey = sprintf(
            'fragment:%s:%s:%s',
            $controllerName,
            $fragment,
            md5(serialize($dependencies))
        );

        $startTime = microtime(true);
        // Check if key exists by attempting to get it
        $existingValue = $this->getCacheStore()->get($cacheKey);
        $cached = $existingValue !== null;

        $result = $this->cacheResponse($cacheKey, $callback, $ttl, ['fragment', 'fragment:' . $fragment]);

        $this->trackCachePerformance($cacheKey, $cached, microtime(true) - $startTime);

        return $result;
    }

    /**
     * Cache multiple operations in batch
     *
     * @param array $operations Array of cache operations
     * @return array Results array
     */
    protected function cacheMultiple(array $operations): array
    {
        $results = [];

        // Process each operation
        foreach ($operations as $key => $operation) {
            // Try to get from cache first
            $controllerName = str_replace('\\', '.', static::class);
            $cacheKey = sprintf('controller:%s:%s', $controllerName, $key);
            $cachedValue = $this->getCacheStore()->get($cacheKey);

            if ($cachedValue !== null) {
                $results[$key] = $cachedValue;
            } else {
                // Execute callback and cache result
                $results[$key] = $this->cacheResponse(
                    $key,
                    $operation['callback'],
                    $operation['ttl'] ?? 3600,
                    $operation['tags'] ?? []
                );
            }
        }

        return $results;
    }
}
