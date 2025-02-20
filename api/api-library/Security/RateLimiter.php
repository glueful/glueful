<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

use Glueful\Api\Library\Cache\CacheEngine;

/**
 * Rate Limiter
 * 
 * Implements sliding window rate limiting for API requests.
 * Uses cache backend to track and limit request frequencies.
 */
class RateLimiter
{
    /** @var string Cache key prefix for rate limit entries */
    private const PREFIX = 'rate_limit:';

    /**
     * Constructor
     * 
     * @param string $key Unique identifier for the rate limit
     * @param int $maxAttempts Maximum allowed attempts in window
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(
        private readonly string $key,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds
    ) {
        CacheEngine::initialize('Glueful:', config('cache.default'));
    }

    /**
     * Record and validate attempt
     * 
     * Tracks new attempt and checks if limit is exceeded.
     * 
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool
    {
        $key = $this->getCacheKey();
        $now = time();

        // Remove expired entries
        CacheEngine::zremrangebyscore($key, '-inf', (string) ($now - $this->windowSeconds));

        // Get current attempt count
        if (CacheEngine::zcard($key) >= $this->maxAttempts) {
            return false;
        }

        // Add new request timestamp
        CacheEngine::zadd($key, [$now => $now]);

        // Set expiration time
        CacheEngine::expire($key, $this->windowSeconds);

        return true;
    }

    /**
     * Get remaining attempts
     * 
     * Returns number of attempts left in current window.
     * 
     * @return int Remaining attempts
     */
    public function remaining(): int
    {
        return max(0, $this->maxAttempts - CacheEngine::zcard($this->getCacheKey()));
    }

    /**
     * Get retry delay
     * 
     * Returns seconds until rate limit resets.
     * 
     * @return int Seconds until next attempt allowed
     */
    public function getRetryAfter(): int
    {
        $timestamps = CacheEngine::zrange($this->getCacheKey(), 0, 0);
        return empty($timestamps) ? 0 : max(0, (int) $timestamps[0] + $this->windowSeconds - time());
    }

    /**
     * Reset rate limiter
     * 
     * Clears all tracking data for this rate limit.
     */
    public function reset(): void
    {
        CacheEngine::delete($this->getCacheKey());
    }

    /**
     * Check if limit exceeded
     * 
     * @return bool True if rate limit is exceeded
     */
    public function isExceeded(): bool
    {
        return !$this->attempt();
    }

    /**
     * Get cache key
     * 
     * @return string Prefixed cache key
     */
    private function getCacheKey(): string
    {
        return self::PREFIX . $this->key;
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
        return new self("ip:$ip", $maxAttempts, $windowSeconds);
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
        return new self("user:$userId", $maxAttempts, $windowSeconds);
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
        return new self("endpoint:$endpoint:$identifier", $maxAttempts, $windowSeconds);
    }
}