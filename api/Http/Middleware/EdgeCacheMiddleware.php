<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Cache\EdgeCacheService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for edge caching
 *
 * This middleware adds cache headers to responses based on configuration
 * and route settings, facilitating integration with CDNs and edge caches.
 */
class EdgeCacheMiddleware implements MiddlewareInterface
{
    /**
     * The edge cache service
     */
    private EdgeCacheService $edgeCacheService;

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
     * Process the request and add cache headers to the response
     *
     * @param Request $request The request object
     * @param RequestHandlerInterface $handler The next request handler
     * @return Response The response object
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Process request
        $response = $handler->handle($request);

        // Add cache headers based on route and content type
        if ($this->isCacheable($request, $response)) {
            // Get route name from request attributes (Symfony style)
            $routeName = $request->attributes->get('_route') ?? 'unknown';

            $headers = $this->edgeCacheService->generateCacheHeaders(
                $routeName,
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
     * @param Request $request The request object
     * @param Response $response The response object
     * @return bool True if the request and response are cacheable, false otherwise
     */
    private function isCacheable(Request $request, Response $response): bool
    {
        // Delegate to the edge cache service to determine cacheability
        return $this->edgeCacheService->isCacheable($request, $response);
    }
}
