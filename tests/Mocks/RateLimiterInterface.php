<?php

namespace Tests\Mocks;

/**
 * Rate Limiter Interface
 *
 * This interface defines the required methods for rate limiters.
 * Both the mock classes will implement this interface.
 */
interface RateLimiterInterface
{
    /**
     * Check if rate limit is exceeded
     *
     * @return bool True if rate limit is exceeded
     */
    public function isExceeded(): bool;

    /**
     * Get remaining attempts
     *
     * @return int Number of attempts remaining
     */
    public function remaining(): int;

    /**
     * Get retry after time
     *
     * @return int Seconds to wait before retry
     */
    public function getRetryAfter(): int;

    /**
     * Record an attempt and check if it's allowed
     *
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool;
}
