<?php

/**
 * Deprecation Middleware for API Versioning Warnings
 *
 * Framework middleware that logs deprecation warnings when deprecated endpoints are accessed.
 * Follows framework logging best practices by only logging framework concerns (API deprecations).
 */

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Psr\Log\LoggerInterface;
use Glueful\Http\Middleware\MiddlewareInterface;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DeprecationMiddleware implements MiddlewareInterface
{
    private LoggerInterface $logger;
    private array $deprecatedRoutes;
    private bool $enabled;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->deprecatedRoutes = config('api.deprecated_routes', []);
        $this->enabled = config('logging.framework.log_deprecations', true);
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $routePath = $request->getPathInfo();
        $method = $request->getMethod();
        $routeKey = $method . ' ' . $routePath;

        // Check for exact route match first, then pattern matches
        $deprecation = $this->findDeprecatedRoute($routeKey, $routePath, $method);

        if ($deprecation) {
            $this->logDeprecation($request, $routePath, $method, $deprecation);
            $response = $handler->handle($request);
            return $this->addDeprecationHeaders($response, $deprecation);
        }

        return $handler->handle($request);
    }

    /**
     * Find deprecated route configuration
     */
    private function findDeprecatedRoute(string $routeKey, string $routePath, string $method): ?array
    {
        // Check exact route match
        if (isset($this->deprecatedRoutes[$routeKey])) {
            return $this->deprecatedRoutes[$routeKey];
        }

        // Check method-specific route
        if (
            isset($this->deprecatedRoutes[$routePath]) &&
            (!isset($this->deprecatedRoutes[$routePath]['methods']) ||
             in_array($method, $this->deprecatedRoutes[$routePath]['methods']))
        ) {
            return $this->deprecatedRoutes[$routePath];
        }

        // Check pattern matches
        foreach ($this->deprecatedRoutes as $pattern => $config) {
            if ($this->matchesPattern($pattern, $routePath, $method)) {
                return $config;
            }
        }

        return null;
    }

    /**
     * Check if route matches a pattern
     */
    private function matchesPattern(string $pattern, string $routePath, string $method): bool
    {
        // Handle method-specific patterns like "GET /api/v1/*"
        if (strpos($pattern, ' ') !== false) {
            [$patternMethod, $patternPath] = explode(' ', $pattern, 2);
            if ($patternMethod !== $method) {
                return false;
            }
            $pattern = $patternPath;
        }

        // Convert wildcards to regex
        $regexPattern = str_replace(
            ['*', '?'],
            ['[^/]*', '.'],
            preg_quote($pattern, '/')
        );

        return preg_match('/^' . $regexPattern . '$/', $routePath) === 1;
    }

    /**
     * Log deprecation warning (framework concern)
     */
    private function logDeprecation(Request $request, string $routePath, string $method, array $deprecation): void
    {
        $this->logger->notice('Deprecated endpoint accessed', [
            'type' => 'deprecation',
            'message' => 'Deprecated API endpoint called',
            'path' => $routePath,
            'method' => $method,
            'deprecated_since' => $deprecation['since'] ?? 'unknown',
            'removal_version' => $deprecation['removal_version'] ?? 'unknown',
            'replacement' => $deprecation['replacement'] ?? null,
            'reason' => $deprecation['reason'] ?? null,
            'request_id' => $request->attributes->get('request_id'),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => date('c')
        ]);
    }

    /**
     * Add deprecation headers to response
     */
    private function addDeprecationHeaders(Response $response, array $deprecation): Response
    {
        $response->headers->set('X-API-Deprecated', 'true');

        if (isset($deprecation['since'])) {
            $response->headers->set('X-API-Deprecated-Since', $deprecation['since']);
        }

        if (isset($deprecation['removal_version'])) {
            $response->headers->set('X-API-Removal-Version', $deprecation['removal_version']);
        }

        if (isset($deprecation['replacement'])) {
            $response->headers->set('X-API-Replacement', $deprecation['replacement']);
        }

        // Add deprecation warning in response headers
        $warning = 'The requested endpoint is deprecated';
        if (isset($deprecation['removal_version'])) {
            $warning .= ' and will be removed in version ' . $deprecation['removal_version'];
        }
        if (isset($deprecation['replacement'])) {
            $warning .= '. Use ' . $deprecation['replacement'] . ' instead';
        }

        $response->headers->set('Warning', '299 - "' . $warning . '"');

        return $response;
    }
}
