<?php

namespace Tests\Mocks;

use Glueful\Security\RateLimiter as OriginalRateLimiter;
use Glueful\Security\AdaptiveRateLimiter as OriginalAdaptiveRateLimiter;

/**
 * Type Adapter for RateLimiter class
 *
 * This class provides an adapter between our mock classes and
 * the real Rate Limiter classes used in the application.
 * We'll use this in tests to cast our mock objects to the correct type.
 */
class RateLimiterAdapter
{
    /**
     * Cast a mock rate limiter to the original RateLimiter type
     * that the middleware expects
     *
     * @param mixed $limiter The limiter to cast
     * @return OriginalRateLimiter The cast limiter
     */
    public static function castToRateLimiter($limiter): OriginalRateLimiter
    {
        // This cast is only for type compatibility
        // The actual instance remains a mock
        return $limiter;
    }

    /**
     * Cast a mock adaptive rate limiter to the original AdaptiveRateLimiter type
     *
     * @param mixed $limiter The limiter to cast
     * @return OriginalAdaptiveRateLimiter The cast limiter
     */
    public static function castToAdaptiveRateLimiter($limiter): OriginalAdaptiveRateLimiter
    {
        // This cast is only for type compatibility
        // The actual instance remains a mock
        return $limiter;
    }
}
