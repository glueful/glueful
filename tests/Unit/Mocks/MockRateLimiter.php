<?php

namespace Tests\Unit\Mocks;

/**
 * Mock Rate Limiter for testing
 *
 * This class mimics the behavior of the RateLimiter class but without actual cache dependencies
 */
class MockRateLimiter
{
    /** @var bool Whether attempt will be allowed */
    private bool $allowAttempt;

    /** @var int Number of remaining attempts */
    private int $remaining;

    /** @var int Seconds until retry allowed */
    private int $retryAfter;

    /** @var bool Whether limit is exceeded */
    private bool $isExceeded;

    /**
     * Create a new mock rate limiter
     *
     * @param bool $allowAttempt Whether attempt will be allowed
     * @param int $remaining Number of remaining attempts
     * @param int $retryAfter Seconds until retry allowed
     * @param bool $isExceeded Whether limit is exceeded
     */
    public function __construct(
        bool $allowAttempt = true,
        int $remaining = 59,
        int $retryAfter = 0,
        bool $isExceeded = false
    ) {
        $this->allowAttempt = $allowAttempt;
        $this->remaining = $remaining;
        $this->retryAfter = $retryAfter;
        $this->isExceeded = $isExceeded;
    }

    /**
     * Record and validate attempt
     *
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool
    {
        return $this->allowAttempt;
    }

    /**
     * Get remaining attempts
     *
     * @return int Remaining attempts
     */
    public function remaining(): int
    {
        return $this->remaining;
    }

    /**
     * Get retry delay
     *
     * @return int Seconds until next attempt allowed
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Reset rate limiter
     */
    public function reset(): void
    {
        $this->allowAttempt = true;
        $this->remaining = 59;
        $this->retryAfter = 0;
        $this->isExceeded = false;
    }

    /**
     * Check if limit exceeded
     *
     * @return bool True if rate limit is exceeded
     */
    public function isExceeded(): bool
    {
        return $this->isExceeded;
    }

    /**
     * Create IP-based rate limiter
     *
     * @param string $ip IP address to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return self Rate limiter instance
     */
    public static function perIp(string $ip, int $maxAttempts, int $windowSeconds): self
    {
        return new self();
    }

    /**
     * Create user-based rate limiter
     *
     * @param string $userId User identifier to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return self Rate limiter instance
     */
    public static function perUser(string $userId, int $maxAttempts, int $windowSeconds): self
    {
        return new self();
    }

    /**
     * Create endpoint-specific rate limiter
     *
     * @param string $endpoint API endpoint to track
     * @param string $identifier Unique request identifier
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return self Rate limiter instance
     */
    public static function perEndpoint(string $endpoint, string $identifier, int $maxAttempts, int $windowSeconds): self
    {
        return new self();
    }
}
