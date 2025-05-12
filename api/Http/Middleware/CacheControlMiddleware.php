<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache Control Middleware
 *
 * PSR-15 compatible middleware that adds appropriate cache control headers to responses.
 * Configurable for different caching strategies based on route or content type.
 *
 * Features:
 * - Configurable cache control directives
 * - Route-based caching strategies
 * - Content type specific caching rules
 * - ETag generation for resource validation
 */
class CacheControlMiddleware implements MiddlewareInterface
{
    /** @var array Cache configuration for this middleware */
    private array $config;

    /**
     * Create a new cache control middleware
     *
     * @param array $config Cache configuration settings
     */
    public function __construct(array $config = [])
    {
        // Default cache configuration
        $this->config = array_merge([
            'public' => true,                     // Public or private cache
            'max_age' => 3600,                    // Default max age in seconds
            's_maxage' => null,                   // Shared max age (for CDNs)
            'must_revalidate' => true,            // Client must revalidate stale resources
            'routes' => [                         // Route-specific overrides
                'GET /users' => ['max_age' => 300],
                'GET /blobs/*' => ['max_age' => 86400],
            ],
            'content_types' => [                  // Content-type specific settings
                'image/*' => ['max_age' => 86400, 'immutable' => true],
                'application/pdf' => ['max_age' => 86400],
            ],
            'methods' => [                        // HTTP method defaults
                'GET' => true,                    // Cache GET requests
                'HEAD' => true,                   // Cache HEAD requests
                'POST' => false,                  // Don't cache POST requests
                'PUT' => false,                   // Don't cache PUT requests
                'DELETE' => false,                // Don't cache DELETE requests
            ],
            'etag' => true,                       // Generate ETags for responses
        ], $config);
    }

    /**
     * Process the request through the cache control middleware
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Process the request through the middleware pipeline
        $response = $handler->handle($request);

        // Check if this request method should be cached
        if (!$this->isCacheableMethod($request->getMethod())) {
            $this->addNoCacheHeaders($response);
            return $response;
        }

        // Apply cache headers based on configuration
        $this->applyCacheHeaders($request, $response);

        // Generate and add ETag if configured
        if ($this->config['etag']) {
            $this->addETag($response);
        }

        return $response;
    }

    /**
     * Check if the HTTP method is cacheable
     *
     * @param string $method The HTTP method
     * @return bool Whether the method is cacheable
     */
    private function isCacheableMethod(string $method): bool
    {
        return $this->config['methods'][strtoupper($method)] ?? false;
    }

    /**
     * Add Cache-Control: no-cache headers to the response
     *
     * @param Response $response The response
     */
    private function addNoCacheHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }

    /**
     * Apply cache headers based on configuration
     *
     * @param Request $request The request
     * @param Response $response The response
     */
    private function applyCacheHeaders(Request $request, Response $response): void
    {
        // Get cache settings for this request
        $settings = $this->getCacheSettings($request);

        // Apply content type specific settings
        $settings = $this->applyContentTypeSettings($response, $settings);

        // Build Cache-Control header value
        $cacheControl = [];

        // Public or private cache
        $cacheControl[] = $settings['public'] ? 'public' : 'private';

        // Max age directive
        if (isset($settings['max_age'])) {
            $cacheControl[] = 'max-age=' . $settings['max_age'];
        }

        // Shared max age directive (for CDNs)
        if (isset($settings['s_maxage'])) {
            $cacheControl[] = 's-maxage=' . $settings['s_maxage'];
        }

        // Must revalidate directive
        if ($settings['must_revalidate'] ?? false) {
            $cacheControl[] = 'must-revalidate';
        }

        // Immutable directive for unchanging resources
        if ($settings['immutable'] ?? false) {
            $cacheControl[] = 'immutable';
        }

        // Set Cache-Control header
        $response->headers->set('Cache-Control', implode(', ', $cacheControl));

        // Set Expires header
        if (isset($settings['max_age'])) {
            $expires = new \DateTime();
            $expires->modify('+' . $settings['max_age'] . ' seconds');
            $response->headers->set('Expires', $expires->format('D, d M Y H:i:s') . ' GMT');
        }
    }

    /**
     * Get cache settings for the current request
     *
     * @param Request $request The request
     * @return array Cache settings
     */
    private function getCacheSettings(Request $request): array
    {
        $settings = $this->config;

        // Check for route-specific settings
        $routePattern = $request->getMethod() . ' ' . $request->getPathInfo();
        foreach ($this->config['routes'] as $pattern => $routeSettings) {
            if ($this->matchRoute($routePattern, $pattern)) {
                $settings = array_merge($settings, $routeSettings);
                break;
            }
        }

        // Note: Content-type specific settings are applied later
        // when we have access to the response

        return $settings;
    }

    /**
     * Apply content type specific cache settings
     *
     * @param Response $response The response
     * @param array $settings The current cache settings
     * @return array Updated cache settings
     */
    private function applyContentTypeSettings(Response $response, array $settings): array
    {
        $contentType = $response->headers->get('Content-Type', '');

        foreach ($this->config['content_types'] as $pattern => $typeSettings) {
            if ($this->matchContentType($contentType, $pattern)) {
                $settings = array_merge($settings, $typeSettings);
                break;
            }
        }

        return $settings;
    }

    /**
     * Match a route pattern against a route
     *
     * @param string $route The actual route
     * @param string $pattern The pattern to match
     * @return bool Whether the route matches the pattern
     */
    private function matchRoute(string $route, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $pattern = str_replace('*', '.*', $pattern);
        return (bool) preg_match('#^' . $pattern . '$#', $route);
    }

    /**
     * Match a content type against a pattern
     *
     * @param string $contentType The actual content type
     * @param string $pattern The pattern to match
     * @return bool Whether the content type matches the pattern
     */
    private function matchContentType(string $contentType, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $pattern = str_replace('*', '.*', $pattern);
        return (bool) preg_match('#^' . $pattern . '$#', $contentType);
    }

    /**
     * Add ETag header to the response
     *
     * @param Response $response The response
     */
    private function addETag(Response $response): void
    {
        // Skip if response already has an ETag
        if ($response->headers->has('ETag')) {
            return;
        }

        // Generate ETag from response content
        $etag = md5($response->getContent());
        $response->headers->set('ETag', '"' . $etag . '"');
    }
}
