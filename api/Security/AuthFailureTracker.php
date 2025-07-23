<?php

declare(strict_types=1);

namespace Glueful\Security;

use Glueful\Cache\CacheStore;
use Glueful\Helpers\Utils;
use Glueful\Helpers\CacheHelper;

/**
 * Authentication Failure Tracker
 *
 * Tracks and manages failed authentication attempts.
 * Implements temporary blocking after excessive failures.
 */
class AuthFailureTracker
{
    /** @var string Cache key prefix for failed attempts */
    private const PREFIX = 'auth_fail:';

    /** @var CacheStore Cache driver instance */
    private CacheStore $cache;

    /**
     * Constructor
     *
     * @param string $key Identifier (user ID or IP)
     * @param int $maxAttempts Maximum failed attempts before blocking
     * @param int $decaySeconds Block duration in seconds
     * @param CacheStore|null $cache Cache driver instance
     */
    public function __construct(
        private readonly string $key, // Either user ID or IP
        private readonly int $maxAttempts = 5,
        private readonly int $decaySeconds = 900, // 15 minutes
        ?CacheStore $cache = null
    ) {
        $this->cache = $cache ?? $this->createCacheInstance();
    }

    /**
     * Record authentication failure
     *
     * Increments failure count and sets decay timer.
     */
    public function recordFailure(): void
    {
        $key = $this->getCacheKey();
        $attempts = (int)($this->cache->get($key) ?? 0);

        if ($attempts === 0) {
            $this->cache->set($key, 1, $this->decaySeconds);
        } else {
            $this->cache->increment($key);
        }
    }

    /**
     * Get current failure count
     *
     * @return int Number of failed attempts
     */
    public function getFailures(): int
    {
        return (int)($this->cache->get($this->getCacheKey()) ?? 0);
    }

    /**
     * Reset failure counter
     *
     * Clears all tracked failures for this identifier.
     */
    public function resetFailures(): void
    {
        $this->cache->delete($this->getCacheKey());
    }

    /**
     * Check if authentication is blocked
     *
     * @return bool True if max attempts exceeded
     */
    public function isBlocked(): bool
    {
        return $this->getFailures() >= $this->maxAttempts;
    }

    /**
     * Get remaining block time
     *
     * @return int Seconds until block expires
     */
    public function getRetryAfter(): int
    {
        return $this->cache->ttl($this->getCacheKey());
    }

    /**
     * Get cache key for identifier
     *
     * @return string Prefixed cache key
     */
    private function getCacheKey(): string
    {
        return self::PREFIX . Utils::sanitizeCacheKey($this->key);
    }

    /**
     * Create cache instance with proper fallback handling
     *
     * @return CacheStore Cache instance
     * @throws \RuntimeException If cache cannot be created
     */
    private function createCacheInstance(): CacheStore
    {
        try {
            return container()->get(CacheStore::class);
        } catch (\Exception) {
            // Try using CacheHelper as fallback
            $cache = CacheHelper::createCacheInstance();
            if ($cache === null) {
                throw new \RuntimeException(
                    'Cache is required for AuthFailureTracker. '
                    . 'Please ensure cache is properly configured.'
                );
            }
            return $cache;
        }
    }

    /**
     * Create user-specific tracker
     *
     * @param string $userId User identifier
     * @param int $maxAttempts Maximum allowed failures
     * @param int $decaySeconds Block duration
     * @return self Tracker instance
     */
    public static function forUser(string $userId, int $maxAttempts = 5, int $decaySeconds = 900): self
    {
        return new self("user:$userId", $maxAttempts, $decaySeconds, self::createStaticCacheInstance());
    }

    /**
     * Create IP-specific tracker
     *
     * @param string $ip IP address to track
     * @param int $maxAttempts Maximum allowed failures
     * @param int $decaySeconds Block duration
     * @return self Tracker instance
     */
    public static function forIp(string $ip, int $maxAttempts = 5, int $decaySeconds = 900): self
    {
        return new self("ip:$ip", $maxAttempts, $decaySeconds, self::createStaticCacheInstance());
    }

    /**
     * Create cache instance for static factory methods
     *
     * @return CacheStore|null Cache instance or null for graceful degradation
     */
    private static function createStaticCacheInstance(): ?CacheStore
    {
        try {
            return container()->get(CacheStore::class);
        } catch (\Exception) {
            // Try using CacheHelper as fallback
            return CacheHelper::createCacheInstance();
        }
    }
}
