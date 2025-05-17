<?php

namespace Glueful\Http\Middleware;

use Glueful\Cache\EdgeCacheService;

/**
 * Middleware for edge caching
 *
 * This middleware adds cache headers to responses based on configuration
 * and route settings, facilitating integration with CDNs and edge caches.
 */
class EdgeCacheMiddleware
{
    /**
     * The edge cache service
     *
     * @var EdgeCacheService
     */
    private $edgeCacheService;

    /**
     * Constructor for the Edge Cache Middleware
     *
     * @param EdgeCacheService $edgeCacheService The edge cache service to use
     */
    public function __construct(EdgeCacheService $edgeCacheService)
    {
        $this->edgeCacheService = $edgeCacheService;
    }

    /**
     * Handle the incoming request and add cache headers to the response
     *
     * @param object $request The request object
     * @param callable $next The next middleware handler
     * @return object The response object
     */
    public function handle($request, $next)
    {
        // Handle request
        $response = $next($request);

        // Add cache headers based on route and content type
        if ($this->isCacheable($request, $response)) {
            $headers = $this->edgeCacheService->generateCacheHeaders(
                $request->route()->getName(),
                $response->headers->get('Content-Type')
            );

            foreach ($headers as $key => $value) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }

    /**
     * Determine if the request and response are cacheable
     *
     * @param object $request The request object
     * @param object $response The response object
     * @return bool True if the request and response are cacheable, false otherwise
     */
    private function isCacheable($request, $response): bool
    {
        // Delegate to the edge cache service to determine cacheability
        return $this->edgeCacheService->isCacheable($request, $response);
    }
}
