<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Security\RateLimiter;
use Glueful\Security\AdaptiveRateLimiter;
use Glueful\DI\Container;
use Glueful\Exceptions\RateLimitExceededException;
use Glueful\Events\Auth\RateLimitExceededEvent;
use Glueful\Events\Event;

/**
 * Rate Limiter Middleware
 *
 * PSR-15 compatible middleware that implements rate limiting for API endpoints.
 * Uses the sliding window algorithm to accurately track and limit request rates.
 *
 * Features:
 * - IP-based rate limiting
 * - User-based rate limiting (when authenticated)
 * - Endpoint-based rate limiting
 * - Adaptive rate limiting with behavior profiling
 * - Distributed rate limiting across multiple nodes
 * - ML-powered anomaly detection
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

    /** @var string Rate limiter type (ip, user, endpoint) */
    private string $type;

    /** @var bool Whether to use adaptive rate limiting */
    private bool $useAdaptiveLimiter;

    /** @var bool Whether to enable distributed rate limiting */
    private bool $enableDistributed;

    /** @var Container|null DI Container */
    private ?Container $container;


    /**
     * Create a new rate limiter middleware
     *
     * @param int $maxAttempts Maximum number of requests allowed
     * @param int $windowSeconds Time window in seconds
     * @param string $type Rate limiter type (ip, user, endpoint)
     * @param bool $useAdaptiveLimiter Whether to use adaptive rate limiting
     * @param bool $enableDistributed Whether to enable distributed rate limiting
     * @param Container|null $container DI Container instance
     */
    public function __construct(
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        string $type = 'ip',
        ?bool $useAdaptiveLimiter = null,
        ?bool $enableDistributed = null,
        ?Container $container = null
    ) {
        $this->container = $container ?? $this->getDefaultContainer();
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->type = $type;


        // Default to config values if not specified
        $this->useAdaptiveLimiter = $useAdaptiveLimiter ??
            (bool) config('security.rate_limiter.enable_adaptive', false);
        $this->enableDistributed = $enableDistributed ??
            (bool) config('security.rate_limiter.enable_distributed', false);
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
            // Emit event for application business logic
            $currentAttempts = $this->maxAttempts - $limiter->remaining();
            Event::dispatch(new RateLimitExceededEvent(
                $request->getClientIp() ?: '0.0.0.0',
                $this->type,
                $currentAttempts,
                $this->maxAttempts,
                $this->windowSeconds,
                [
                    'endpoint' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'user_agent' => $request->headers->get('User-Agent'),
                    'request_id' => $request->attributes->get('request_id')
                ]
            ));

            // Let exceptions bubble up instead of returning response directly
            throw new RateLimitExceededException('Too Many Requests', $limiter->getRetryAfter());
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
    private function createLimiter(Request $request): RateLimiter|AdaptiveRateLimiter
    {
        // Create context data for adaptive rate limiting
        $context = [
            'ip' => $request->getClientIp() ?: '0.0.0.0',
            'user_agent' => $request->headers->get('User-Agent', ''),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query_count' => count($request->query->all()),
            'timestamp' => time(),
        ];

        // Standard vs. Adaptive rate limiting decision
        if ($this->useAdaptiveLimiter) {
            return $this->createAdaptiveLimiter($request, $context);
        } else {
            return $this->createStandardLimiter($request);
        }
    }

    /**
     * Create a standard rate limiter
     *
     * @param Request $request The incoming request
     * @return RateLimiter Standard rate limiter instance
     */
    private function createStandardLimiter(Request $request): RateLimiter|AdaptiveRateLimiter
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
        } elseif ($this->type === 'endpoint') {
            // Create an endpoint-specific limiter
            $endpoint = $request->getPathInfo();
            $identifier = $request->getClientIp() ?: '0.0.0.0';

            return RateLimiter::perEndpoint(
                $endpoint,
                $identifier,
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
     * Create an adaptive rate limiter
     *
     * @param Request $request The incoming request
     * @param array $context Request context for behavior analysis
     * @return AdaptiveRateLimiter Adaptive rate limiter instance
     */
    private function createAdaptiveLimiter(Request $request, array $context): RateLimiter|AdaptiveRateLimiter
    {
        $key = '';

        if ($this->type === 'user') {
            // Get user ID from the authenticated session
            $userId = $this->getUserIdFromRequest($request);

            // If no user ID is available, fall back to IP-based limiting
            if (!$userId) {
                $key = "ip:" . ($request->getClientIp() ?: '0.0.0.0');
            } else {
                $key = "user:$userId";
            }
        } elseif ($this->type === 'endpoint') {
            // Create an endpoint-specific limiter
            $endpoint = $request->getPathInfo();
            $identifier = $request->getClientIp() ?: '0.0.0.0';
            $key = "endpoint:$endpoint:$identifier";
        } else {
            // Default to IP-based rate limiting
            $key = "ip:" . ($request->getClientIp() ?: '0.0.0.0');
        }

        // Create new AdaptiveRateLimiter instance with specific parameters
        // Note: AdaptiveRateLimiter requires specific constructor parameters per request,
        // so we create new instances rather than using DI container
        return new AdaptiveRateLimiter(
            $key,
            $this->maxAttempts,
            $this->windowSeconds,
            $context,
            $this->enableDistributed
        );
    }

    /**
     * Get default container safely
     *
     * @return Container|null
     */
    private function getDefaultContainer(): ?Container
    {
        // Check if app() function exists (available when bootstrap is loaded)
        if (function_exists('container')) {
            try {
                return container();
            } catch (\Exception) {
                // In test environment or when container isn't initialized, return null
                // This allows the middleware to work without DI container
                return null;
            }
        }

        return null;
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

            // Try to get user from session using TokenStorageService
            $tokenStorage = new \Glueful\Auth\TokenStorageService();
            $session = $tokenStorage->getSessionByAccessToken($token);
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
        $responseData = [
            'success' => false,
            'message' => 'Too Many Requests',
            'retry_after' => $limiter->getRetryAfter(),
            'remaining' => 0,
            'code' => 429
        ];

        // Add adaptive information if available
        if ($limiter instanceof AdaptiveRateLimiter) {
            $responseData['adaptive'] = true;

            // Add active rules if any were applied
            $activeRules = $limiter->getActiveApplicableRules();
            if (!empty($activeRules)) {
                $responseData['rules_applied'] = array_keys($activeRules);
            }
        }

        $response = new JsonResponse($responseData, 429);

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

        // Add adaptive headers if applicable
        if ($limiter instanceof AdaptiveRateLimiter) {
            $response->headers->set('X-Adaptive-RateLimit', 'true');

            // Add distributed header if enabled
            if ($this->enableDistributed) {
                $response->headers->set('X-Distributed-RateLimit', 'true');
            }
        }

        return $response;
    }
}
