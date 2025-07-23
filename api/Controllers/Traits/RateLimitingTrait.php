<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Security\RateLimiter;
use Glueful\Security\AdaptiveRateLimiter;
use Glueful\Exceptions\RateLimitExceededException;
use Glueful\Exceptions\SecurityException;
use Glueful\Permissions\Exceptions\UnauthorizedException;
use Glueful\Http\Response;

/**
 * Rate Limiting Trait
 *
 * Provides comprehensive rate limiting functionality for controllers.
 * Supports method-specific, resource-based, multi-level, and adaptive rate limiting.
 *
 * @package Glueful\Controllers\Traits
 */
trait RateLimitingTrait
{
    /**
     * Apply rate limiting to actions
     *
     * @param string $action Action identifier
     * @param int|null $maxAttempts Maximum attempts allowed (null for config default)
     * @param int|null $windowSeconds Time window in seconds (null for config default)
     * @param bool $useAdaptive Use adaptive rate limiting with behavior analysis
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function rateLimit(
        string $action,
        ?int $maxAttempts = null,
        ?int $windowSeconds = null,
        bool $useAdaptive = true
    ): void {
        // Use config defaults if not specified
        $maxAttempts = $maxAttempts ?? config('security.rate_limiter.default_max_attempts', 60);
        $windowSeconds = $windowSeconds ?? config('security.rate_limiter.default_window_seconds', 60);

        // Create unique key for this controller action
        $key = sprintf(
            '%s:%s:%s',
            static::class,
            $action,
            $this->getCachedUserUuid() ?? $this->request->getClientIp()
        );

        if ($useAdaptive && config('security.rate_limiter.enable_adaptive', true)) {
            // Use adaptive rate limiter with behavior analysis
            $limiter = new AdaptiveRateLimiter(
                $key,
                $maxAttempts,
                $windowSeconds,
                [
                    'controller' => static::class,
                    'action' => $action,
                    'user_uuid' => $this->getCachedUserUuid(),
                    'ip' => $this->request->getClientIp(),
                    'user_agent' => $this->request->headers->get('User-Agent'),
                ],
                config('security.rate_limiter.enable_distributed', false)
            );

            // Check behavior score for additional security
            if ($limiter->getBehaviorScore() > 0.8) {
                throw new RateLimitExceededException(
                    'Suspicious behavior detected. Please try again later.',
                    $limiter->getRetryAfter()
                );
            }
        } else {
            // Use standard rate limiter
            $limiter = new RateLimiter($key, $maxAttempts, $windowSeconds);
        }

        if (!$limiter->attempt()) {
            throw new RateLimitExceededException(
                sprintf(
                    'Rate limit exceeded for %s. Please try again in %d seconds.',
                    $action,
                    $limiter->getRetryAfter()
                ),
                $limiter->getRetryAfter()
            );
        }
    }

    /**
     * Apply method-specific rate limiting
     *
     * @param string|null $method Method name (auto-detected if null)
     * @param array|null $customLimits Custom rate limits
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function rateLimitMethod(?string $method = null, ?array $customLimits = null): void
    {
        $method = $method ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        // Check for controller-specific rate limit configuration
        $configKey = sprintf('security.rate_limits.controllers.%s.%s', static::class, $method);
        $methodLimits = config($configKey, $customLimits);

        if (!$methodLimits) {
            // Fall back to HTTP method-based limits
            $httpMethod = $this->request->getMethod();
            $methodLimits = config("security.rate_limits.methods.{$httpMethod}", [
                'attempts' => 60,
                'window' => 60,
                'adaptive' => true
            ]);
        }

        $this->rateLimit(
            $method,
            $methodLimits['attempts'] ?? 60,
            $methodLimits['window'] ?? 60,
            $methodLimits['adaptive'] ?? true
        );
    }

    /**
     * Apply resource-based rate limiting
     *
     * @param string $resource Resource identifier
     * @param string $operation Operation type (read, write, delete, export, bulk)
     * @param int|null $maxAttempts Override max attempts
     * @param int|null $windowSeconds Override window seconds
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function rateLimitResource(
        string $resource,
        string $operation,
        ?int $maxAttempts = null,
        ?int $windowSeconds = null
    ): void {
        // Get operation-specific limits from config
        $operationLimits = config("security.rate_limits.operations.{$operation}", [
            'attempts' => 30,
            'window' => 60
        ]);

        $maxAttempts = $maxAttempts ?? $operationLimits['attempts'];
        $windowSeconds = $windowSeconds ?? $operationLimits['window'];

        $this->rateLimit(
            "{$resource}:{$operation}",
            $maxAttempts,
            $windowSeconds,
            config("security.rate_limits.operations.{$operation}.adaptive", true)
        );
    }

    /**
     * Apply multi-level rate limiting
     *
     * Checks multiple rate limit levels (e.g., per minute, hour, day)
     *
     * @param string $action Action identifier
     * @param array $levels Array of level configurations
     * @throws RateLimitExceededException If any level is exceeded
     */
    protected function multiLevelRateLimit(string $action, array $levels = []): void
    {
        $defaultLevels = config('security.rate_limits.multi_level', [
            'minute' => ['attempts' => 10, 'window' => 60],
            'hour' => ['attempts' => 100, 'window' => 3600],
            'day' => ['attempts' => 1000, 'window' => 86400]
        ]);

        $levels = $levels ?: $defaultLevels;

        foreach ($levels as $level => $config) {
            $this->rateLimit(
                "{$action}:level_{$level}",
                $config['attempts'],
                $config['window'],
                false // Disable adaptive for multi-level
            );
        }
    }

    /**
     * Apply conditional rate limiting based on user type
     *
     * @param string $action Action identifier
     * @param array|null $customLimits Custom limits by user type
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function conditionalRateLimit(string $action, ?array $customLimits = null): void
    {
        $isAuthenticated = $this->isCachedAuthenticated();
        $isAdmin = $this->isCachedAdmin();

        $defaultLimits = [
            'admin' => ['attempts' => 1000, 'window' => 60],
            'authenticated' => ['attempts' => 100, 'window' => 60],
            'anonymous' => ['attempts' => 20, 'window' => 60]
        ];

        $limits = $customLimits ?? config('security.rate_limits.user_types', $defaultLimits);

        if ($isAdmin && isset($limits['admin'])) {
            $userLimit = $limits['admin'];
        } elseif ($isAuthenticated && isset($limits['authenticated'])) {
            $userLimit = $limits['authenticated'];
        } else {
            $userLimit = $limits['anonymous'];
        }

        $this->rateLimit(
            $action,
            $userLimit['attempts'],
            $userLimit['window'],
            $userLimit['adaptive'] ?? true
        );
    }

    /**
     * Get rate limit headers for response
     *
     * @return array Headers array
     */
    protected function getRateLimitHeaders(): array
    {
        $key = sprintf(
            '%s:%s:%s',
            static::class,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'],
            $this->currentUser?->uuid ?? $this->request->getClientIp()
        );

        $limiter = new RateLimiter(
            $key,
            config('security.rate_limiter.default_max_attempts', 60),
            config('security.rate_limiter.default_window_seconds', 60)
        );

        return [
            'X-RateLimit-Limit' => config('security.rate_limiter.default_max_attempts', 60),
            'X-RateLimit-Remaining' => $limiter->remaining(),
            'X-RateLimit-Reset' => time() + $limiter->getRetryAfter(),
            'X-RateLimit-Policy' => sprintf(
                '%d;w=%d',
                config('security.rate_limiter.default_max_attempts', 60),
                config('security.rate_limiter.default_window_seconds', 60)
            )
        ];
    }

    /**
     * Require low risk behavior score for sensitive operations
     *
     * @param float $maxScore Maximum allowed behavior score
     * @param string|null $operation Operation description
     * @throws UnauthorizedException If not authenticated
     * @throws SecurityException If behavior score is too high
     */
    protected function requireLowRiskBehavior(float $maxScore = 0.6, ?string $operation = null): void
    {
        if (!$this->currentUser) {
            throw new UnauthorizedException(
                'anonymous',
                'authentication',
                'system',
                'Authentication required for this operation'
            );
        }

        $limiter = new AdaptiveRateLimiter(
            "user:{$this->currentUser->uuid}",
            1, // Dummy values
            1,
            [
                'operation' => $operation ?? 'sensitive_action',
                'controller' => static::class,
                'ip' => $this->request->getClientIp()
            ],
            false
        );

        $behaviorScore = $limiter->getBehaviorScore();

        if ($behaviorScore > $maxScore) {
            throw new SecurityException(
                'This operation requires additional verification due to unusual account activity',
                Response::HTTP_FORBIDDEN
            );
        }
    }

    /**
     * Reset rate limits for an identifier
     *
     * @param string|null $identifier Rate limit identifier to reset
     * @throws UnauthorizedException If insufficient permissions
     */
    protected function resetRateLimits(?string $identifier = null): void
    {
        $this->requirePermission('system.rate_limits.reset');

        $key = $identifier ?? sprintf(
            '%s:%s',
            static::class,
            $this->currentUser?->uuid ?? $this->request->getClientIp()
        );

        $limiter = new RateLimiter($key, 1, 1);
        $limiter->reset();
    }

    /**
     * Apply burst-aware rate limiting
     *
     * @param string $action Action identifier
     * @param int $burstSize Maximum burst size
     * @param int $sustainedRate Sustained rate limit
     * @param int $windowSeconds Time window in seconds
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function burstRateLimit(
        string $action,
        int $burstSize = 10,
        int $sustainedRate = 60,
        int $windowSeconds = 60
    ): void {
        // Allow initial burst
        $burstKey = sprintf('%s:burst', $action);
        $sustainedKey = sprintf('%s:sustained', $action);

        try {
            // Check burst limit first (shorter window)
            $this->rateLimit($burstKey, $burstSize, 10, false);
        } catch (RateLimitExceededException $e) {
            // Burst exceeded, check sustained rate
            $this->rateLimit($sustainedKey, $sustainedRate, $windowSeconds, true);
        }
    }

    /**
     * Add rate limit headers to response
     *
     * @param Response $response Response object
     * @return Response Response with rate limit headers
     */
    protected function withRateLimitHeaders(Response $response): Response
    {
        $headers = $this->getRateLimitHeaders();

        foreach ($headers as $header => $value) {
            header($header . ': ' . $value);
        }

        return $response;
    }
}
