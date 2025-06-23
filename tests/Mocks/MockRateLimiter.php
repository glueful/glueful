<?php

namespace Tests\Mocks;

use Glueful\Security\RateLimiter;

/**
 * Mock RateLimiter class for testing
 *
 * This class mocks the RateLimiter behavior for testing purposes
 * without requiring an actual rate limiter or modifying the original class.
 */
class MockRateLimiter extends RateLimiter implements RateLimiterInterface
{
    /** @var string Rate limiter key */
    protected string $key;

    /** @var int Maximum attempts allowed */
    protected int $maxAttempts;

    /** @var int Time window in seconds */
    protected int $windowSeconds;

    /** @var int Current attempt count */
    protected int $attemptCount = 0;

    /** @var array Custom behaviors for testing */
    protected static array $testBehaviors = [];

    /** @var MockCacheStore|null Shared cache instance */
    protected static ?MockCacheStore $mockCache = null;

    /**
     * Get or create cache instance
     */
    protected static function getCache(): MockCacheStore
    {
        if (self::$mockCache === null) {
            self::$mockCache = new MockCacheStore();
        }
        return self::$mockCache;
    }

    /**
     * Constructor
     *
     * @param string $key Unique identifier for this rate limiter
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(string $key, int $maxAttempts, int $windowSeconds)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;

        // Initialize attempt count from cache if exists
        $cacheKey = $this->getCacheKey();
        $cache = new MockCacheStore();
        $this->attemptCount = $cache->zcard($cacheKey);
    }

    /**
     * Record an attempt and check if it's allowed
     *
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool
    {
        // Allow overriding for tests
        if (isset(self::$testBehaviors[$this->key]['attemptResult'])) {
            return self::$testBehaviors[$this->key]['attemptResult'];
        }

        $cacheKey = $this->getCacheKey();

        // Clean up old attempts
        $now = microtime(true);
        $cutoff = $now - $this->windowSeconds;
        self::getCache()->zremrangebyscore($cacheKey, '0', (string) $cutoff);

        // Count current attempts
        $currentCount = self::getCache()->zcard($cacheKey);

        // Check if we're over the limit
        if ($currentCount >= $this->maxAttempts) {
            return false;
        }

        // Record this attempt - cast to string to avoid deprecation warning
        self::getCache()->zadd($cacheKey, [(string)$now => (string)$now]);

        // Update attempt count
        $this->attemptCount = $currentCount + 1;

        return true;
    }

    /**
     * Check if the rate limit is exceeded
     *
     * @return bool True if the rate limit is exceeded
     */
    public function isExceeded(): bool
    {
        // Allow overriding for tests
        if (isset(self::$testBehaviors[$this->key]['isExceeded'])) {
            return self::$testBehaviors[$this->key]['isExceeded'];
        }

        $cacheKey = $this->getCacheKey();

        // Clean up old attempts
        $now = microtime(true);
        $cutoff = $now - $this->windowSeconds;
        self::getCache()->zremrangebyscore($cacheKey, '0', (string) $cutoff);

        // Count current attempts
        $currentCount = self::getCache()->zcard($cacheKey);

        return $currentCount >= $this->maxAttempts;
    }

    /**
     * Get remaining attempts
     *
     * @return int Number of attempts remaining
     */
    public function remaining(): int
    {
        // Allow overriding for tests
        if (isset(self::$testBehaviors[$this->key]['remaining'])) {
            return self::$testBehaviors[$this->key]['remaining'];
        }

        $cacheKey = $this->getCacheKey();

        // Clean up old attempts
        $now = microtime(true);
        $cutoff = $now - $this->windowSeconds;
        self::getCache()->zremrangebyscore($cacheKey, '0', (string) $cutoff);

        // Count current attempts
        $currentCount = self::getCache()->zcard($cacheKey);

        return max(0, $this->maxAttempts - $currentCount);
    }

    /**
     * Get retry after time
     *
     * @return int Seconds to wait before retry
     */
    public function getRetryAfter(): int
    {
        // Allow overriding for tests
        if (isset(self::$testBehaviors[$this->key]['retryAfter'])) {
            return self::$testBehaviors[$this->key]['retryAfter'];
        }

        return $this->getSecondsUntilReset();
    }

    /**
     * Get current attempt count
     *
     * @return int Current attempt count
     */
    public function getAttemptCount(): int
    {
        $cacheKey = $this->getCacheKey();

        // Clean up old attempts
        $now = microtime(true);
        $cutoff = $now - $this->windowSeconds;
        self::getCache()->zremrangebyscore($cacheKey, '0', (string) $cutoff);

        return self::getCache()->zcard($cacheKey);
    }

    /**
     * Get time until reset
     *
     * @return int Seconds until rate limit resets
     */
    public function getSecondsUntilReset(): int
    {
        $cacheKey = $this->getCacheKey();

        // Get oldest attempt timestamp
        $range = self::getCache()->zrange($cacheKey, 0, 0);
        if (empty($range)) {
            return 0;
        }

        $oldestTime = (float) $range[0];
        $now = microtime(true);

        return max(0, (int) ceil($oldestTime + $this->windowSeconds - $now));
    }

    /**
     * Get cache key
     *
     * @return string Cache key
     */
    protected function getCacheKey(): string
    {
        return 'rate_limit:' . $this->key;
    }

    /**
     * Create an IP-based rate limiter
     *
     * @param string $ip IP address to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param bool $distributed Whether to use distributed rate limiting
     * @return static Rate limiter instance
     */
    public static function perIp(string $ip, int $maxAttempts, int $windowSeconds, bool $distributed = false): static
    {
        return new static("ip:$ip", $maxAttempts, $windowSeconds);
    }

    /**
     * Create a user-based rate limiter
     *
     * @param string $userId User ID to track
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     * @param bool $distributed Whether to use distributed rate limiting
     * @return static Rate limiter instance
     */
    public static function perUser(
        string $userId,
        int $maxAttempts,
        int $windowSeconds,
        bool $distributed = false
    ): static {
        return new static("user:$userId", $maxAttempts, $windowSeconds);
    }

    /**
     * Create an endpoint-based rate limiter
     *
     * @param string $endpoint Endpoint to track
     * @param string $identifier Unique identifier for the endpoint
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param bool $distributed Whether to use distributed rate limiting
     * @return static Rate limiter instance
     */
    public static function perEndpoint(
        string $endpoint,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds,
        bool $distributed = false
    ): static {
        return new static("endpoint:{$endpoint}:{$identifier}", $maxAttempts, $windowSeconds);
    }

    /**
     * Set the isExceeded behavior for testing
     *
     * @param string $key Rate limiter key
     * @param bool $isExceeded Whether the rate limit is exceeded
     */
    public static function setIsExceeded(string $key, bool $isExceeded): void
    {
        if (!isset(self::$testBehaviors[$key])) {
            self::$testBehaviors[$key] = [];
        }
        self::$testBehaviors[$key]['isExceeded'] = $isExceeded;
    }

    /**
     * Set the attempt result for testing
     *
     * @param string $key Rate limiter key
     * @param bool $result Whether the attempt is successful
     */
    public static function setAttemptResult(string $key, bool $result): void
    {
        if (!isset(self::$testBehaviors[$key])) {
            self::$testBehaviors[$key] = [];
        }
        self::$testBehaviors[$key]['attemptResult'] = $result;
    }

    /**
     * Set the remaining attempts for testing
     *
     * @param string $key Rate limiter key
     * @param int $remaining Number of attempts remaining
     */
    public static function setRemaining(string $key, int $remaining): void
    {
        if (!isset(self::$testBehaviors[$key])) {
            self::$testBehaviors[$key] = [];
        }
        self::$testBehaviors[$key]['remaining'] = $remaining;
    }

    /**
     * Set the retry after time for testing
     *
     * @param string $key Rate limiter key
     * @param int $seconds Seconds to wait before retry
     */
    public static function setRetryAfter(string $key, int $seconds): void
    {
        if (!isset(self::$testBehaviors[$key])) {
            self::$testBehaviors[$key] = [];
        }
        self::$testBehaviors[$key]['retryAfter'] = $seconds;
    }

    /**
     * Reset all test behaviors
     */
    public static function resetMock(): void
    {
        self::$testBehaviors = [];
        if (self::$mockCache !== null) {
            self::$mockCache->reset();
        }
    }
}
