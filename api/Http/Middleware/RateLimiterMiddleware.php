<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Security\RateLimiter;

/**
 * Rate Limiter Middleware
 *
 * PSR-15 compatible middleware that implements rate limiting for API endpoints.
 * Uses the sliding window algorithm to accurately track and limit request rates.
 *
 * Features:
 * - IP-based rate limiting
 * - User-based rate limiting (when authenticated)
 * - Configurable limits and time windows
 * - Returns appropriate HTTP 429 responses when limits are exceeded
 * - Adds rate limit headers to responses
 */
class RateLimiterMiddleware implements MiddlewareInterface
{
    /** @var int Maximum number of requests allowed in the time window */
    private int $maxAttempts;

    /** @var int Time window in seconds */
    private int $windowSeconds;

    /** @var string Rate limiter type (ip or user) */
    private string $type;

    /**
     * Create a new rate limiter middleware
     *
     * @param int $maxAttempts Maximum number of requests allowed
     * @param int $windowSeconds Time window in seconds
     * @param string $type Rate limiter type (ip or user)
     */
    public function __construct(
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        string $type = 'ip'
    ) {
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->type = $type;
    }

    /**
     * Process the request through the rate limiter middleware
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Create the appropriate rate limiter based on type
        $limiter = $this->createLimiter($request);

        // Check if the rate limit has been exceeded
        if ($limiter->isExceeded()) {
            return $this->createRateLimitExceededResponse($limiter);
        }

        // Register this attempt
        $limiter->attempt();

        // Process the request through the middleware pipeline
        $response = $handler->handle($request);

        // Add rate limit headers to the response
        $response = $this->addRateLimitHeaders($response, $limiter);

        return $response;
    }

    /**
     * Create a rate limiter instance based on the configuration
     *
     * @param Request $request The incoming request
     * @return RateLimiter The rate limiter instance
     */
    private function createLimiter(Request $request): RateLimiter
    {
        if ($this->type === 'user') {
            // Get user ID from the authenticated session
            $userId = $this->getUserIdFromRequest($request);

            // If no user ID is available, fall back to IP-based limiting
            if (!$userId) {
                return RateLimiter::perIp(
                    $request->getClientIp() ?: '0.0.0.0',
                    $this->maxAttempts,
                    $this->windowSeconds
                );
            }

            return RateLimiter::perUser(
                $userId,
                $this->maxAttempts,
                $this->windowSeconds
            );
        }

        // Default to IP-based rate limiting
        return RateLimiter::perIp(
            $request->getClientIp() ?: '0.0.0.0',
            $this->maxAttempts,
            $this->windowSeconds
        );
    }

    /**
     * Get user ID from the authenticated request
     *
     * @param Request $request The incoming request
     * @return string|null The user ID or null if not authenticated
     */
    private function getUserIdFromRequest(Request $request): ?string
    {
        // Try to get the user ID from the request attributes
        $userId = $request->attributes->get('user_id');

        if ($userId) {
            return $userId;
        }

        // Try to get the user from token if available
        $token = $request->headers->get('Authorization');
        if ($token) {
            // Remove 'Bearer ' prefix if present
            $token = str_replace('Bearer ', '', $token);

            // Try to get user from session cache
            $session = \Glueful\Auth\SessionCacheManager::getSession($token);
            return $session['uuid'] ?? null;
        }

        return null;
    }

    /**
     * Create a response for when the rate limit is exceeded
     *
     * @param RateLimiter $limiter The rate limiter instance
     * @return Response The rate limit exceeded response
     */
    private function createRateLimitExceededResponse(RateLimiter $limiter): Response
    {
        $response = new JsonResponse([
            'success' => false,
            'message' => 'Too Many Requests',
            'retry_after' => $limiter->getRetryAfter(),
            'remaining' => 0,
            'code' => 429
        ], 429);

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', (string) $this->maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', '0');
        $response->headers->set('Retry-After', (string) $limiter->getRetryAfter());
        $response->headers->set('X-RateLimit-Reset', (string) (time() + $limiter->getRetryAfter()));

        return $response;
    }

    /**
     * Add rate limit headers to the response
     *
     * @param Response $response The response
     * @param RateLimiter $limiter The rate limiter instance
     * @return Response The response with rate limit headers
     */
    private function addRateLimitHeaders(Response $response, RateLimiter $limiter): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $this->maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $limiter->remaining());
        $response->headers->set('X-RateLimit-Reset', (string) (time() + $this->windowSeconds));

        return $response;
    }
}
