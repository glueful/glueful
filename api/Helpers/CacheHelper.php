<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Cache\CacheStore;
use Glueful\Cache\CacheFactory;
use Glueful\Helpers\Utils;

/**
 * Cache Helper Utilities
 *
 * Provides common cache-related helper methods for services that need
 * graceful cache integration with proper fallback handling.
 * Also includes cache key management utilities for consistent key prefixing.
 */
class CacheHelper
{
    /**
     * Create cache instance with proper fallback handling
     *
     * This method attempts to create a cache instance using the configured
     * cache driver. If cache initialization fails (e.g., Redis unavailable,
     * configuration issues), it returns null to allow services to continue
     * operating without caching.
     *
     * @return CacheStore|null Cache instance or null if cache unavailable
     */
    public static function createCacheInstance(): ?CacheStore
    {
        try {
            return CacheFactory::create();
        } catch (\Exception $e) {
            // Log the cache initialization failure but don't throw
            // This allows services to degrade gracefully without caching
            error_log("Cache initialization failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if cache is available and healthy
     *
     * @param CacheStore|null $cache Cache instance to test
     * @return bool True if cache is available and responding
     */
    public static function isCacheHealthy(?CacheStore $cache): bool
    {
        if ($cache === null) {
            return false;
        }

        try {
            // Simple health check - set and get a test value
            $testKey = 'health_check_' . uniqid();
            $cache->set($testKey, 'ok', 5);
            $result = $cache->get($testKey);
            $cache->delete($testKey);

            return $result === 'ok';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Safely execute a cache operation with fallback
     *
     * @param CacheStore|null $cache Cache instance
     * @param callable $operation Cache operation to execute
     * @param mixed $fallback Fallback value if cache operation fails
     * @return mixed Result of cache operation or fallback value
     */
    public static function safeExecute(?CacheStore $cache, callable $operation, mixed $fallback = null): mixed
    {
        if ($cache === null) {
            return $fallback;
        }

        try {
            return $operation($cache);
        } catch (\Exception $e) {
            error_log("Cache operation failed: " . $e->getMessage());
            return $fallback;
        }
    }

    // === Cache Key Management Methods ===

    /** @var string Default cache prefix from config */
    private static ?string $defaultPrefix = null;

    /**
     * Get cache key with automatic prefix
     *
     * @param string $key Base cache key
     * @param string|null $prefix Optional prefix override
     * @return string Prefixed cache key
     */
    public static function key(string $key, ?string $prefix = null): string
    {
        $prefix = $prefix ?? self::getDefaultPrefix();
        return $prefix . $key;
    }

    /**
     * Get multiple cache keys with automatic prefix
     *
     * @param array $keys Array of base cache keys
     * @param string|null $prefix Optional prefix override
     * @return array Array of prefixed cache keys
     */
    public static function keys(array $keys, ?string $prefix = null): array
    {
        $prefix = $prefix ?? self::getDefaultPrefix();
        return array_map(fn($key) => $prefix . $key, $keys);
    }

    /**
     * Create associative array with prefixed keys
     *
     * @param array $values Associative array of key => value pairs
     * @param string|null $prefix Optional prefix override
     * @return array Array with prefixed keys
     */
    public static function prefixValues(array $values, ?string $prefix = null): array
    {
        $prefix = $prefix ?? self::getDefaultPrefix();
        $prefixed = [];

        foreach ($values as $key => $value) {
            $prefixed[$prefix . $key] = $value;
        }

        return $prefixed;
    }

    /**
     * Remove prefix from cache key
     *
     * @param string $prefixedKey Prefixed cache key
     * @param string|null $prefix Optional prefix override
     * @return string Base cache key without prefix
     */
    public static function unprefix(string $prefixedKey, ?string $prefix = null): string
    {
        $prefix = $prefix ?? self::getDefaultPrefix();

        if (str_starts_with($prefixedKey, $prefix)) {
            return substr($prefixedKey, strlen($prefix));
        }

        return $prefixedKey;
    }

    /**
     * Get default cache prefix from configuration
     *
     * @return string Cache prefix
     */
    public static function getDefaultPrefix(): string
    {
        if (self::$defaultPrefix === null) {
            self::$defaultPrefix = config('cache.prefix', '');
        }

        return self::$defaultPrefix;
    }

    /**
     * Set default cache prefix (useful for testing)
     *
     * @param string $prefix New default prefix
     * @return void
     */
    public static function setDefaultPrefix(string $prefix): void
    {
        self::$defaultPrefix = $prefix;
    }

    /**
     * Generate cache key for user-specific data
     *
     * @param string $userId User identifier
     * @param string $key Base key
     * @return string User-scoped cache key
     */
    public static function userKey(string $userId, string $key): string
    {
        return self::key("user:{$userId}:{$key}");
    }

    /**
     * Generate cache key for session data
     *
     * @param string $sessionId Session identifier
     * @param string $type Session data type
     * @return string Session-scoped cache key
     */
    public static function sessionKey(string $sessionId, string $type = 'data'): string
    {
        return self::key("session:{$type}:{$sessionId}");
    }

    /**
     * Generate cache key for rate limiting
     *
     * @param string $identifier Rate limit identifier (IP, user, etc.)
     * @param string $type Rate limit type
     * @return string Rate limit cache key
     */
    public static function rateLimitKey(string $identifier, string $type = 'default'): string
    {
        $sanitized = Utils::sanitizeCacheKey($identifier);
        return self::key("rate_limit:{$type}:{$sanitized}");
    }

    /**
     * Generate cache key for permissions
     *
     * @param string $userId User identifier
     * @param string $context Permission context
     * @return string Permission cache key
     */
    public static function permissionKey(string $userId, string $context = 'all'): string
    {
        return self::key("permissions:{$userId}:{$context}");
    }

    /**
     * Generate cache key for API metrics
     *
     * @param string $endpoint API endpoint
     * @param string $metric Metric type
     * @return string Metrics cache key
     */
    public static function metricsKey(string $endpoint, string $metric = 'requests'): string
    {
        $sanitized = Utils::sanitizeCacheKey($endpoint);
        return self::key("metrics:{$metric}:{$sanitized}");
    }

    /**
     * Generate cache key for configuration
     *
     * @param string $configKey Configuration key
     * @return string Config cache key
     */
    public static function configKey(string $configKey): string
    {
        return self::key("config:{$configKey}");
    }

    // === Cache Stampede Protection ===

    /**
     * Remember pattern with cache stampede protection
     *
     * This method prevents cache stampedes by using distributed locks.
     * When multiple processes try to regenerate the same cache key simultaneously,
     * only one process will execute the callback while others wait for the result.
     *
     * @param CacheStore $cache Cache instance
     * @param string $key Cache key
     * @param callable $callback Function to execute on cache miss
     * @param int $ttl Cache TTL in seconds (default: 3600)
     * @param int $lockTtl Lock TTL in seconds (default: 60)
     * @param int $maxWaitTime Maximum time to wait for lock in seconds (default: 30)
     * @param int $retryInterval Retry interval in microseconds (default: 100000 = 0.1s)
     * @return mixed Cached or computed value
     * @throws \RuntimeException If lock cannot be acquired within maxWaitTime
     */
    public static function rememberWithStampedeProtection(
        CacheStore $cache,
        string $key,
        callable $callback,
        int $ttl = 3600,
        int $lockTtl = 60,
        int $maxWaitTime = 30,
        int $retryInterval = 100000
    ): mixed {
        // First, try to get from cache
        $value = $cache->get($key);
        if ($value !== null) {
            return $value;
        }

        $lockKey = self::lockKey($key);
        $lockValue = uniqid('lock_', true);
        $startTime = time();

        // Try to acquire lock
        while (!$cache->setNx($lockKey, $lockValue, $lockTtl)) {
            // Check if we've exceeded max wait time
            if (time() - $startTime >= $maxWaitTime) {
                // Fallback: execute callback without lock protection
                error_log("Cache stampede protection timeout for key: {$key}. Executing without lock.");
                return $callback();
            }

            // Wait before retrying
            usleep($retryInterval);

            // Check if value appeared in cache while waiting
            $value = $cache->get($key);
            if ($value !== null) {
                return $value;
            }
        }

        try {
            // We have the lock, check cache one more time
            $value = $cache->get($key);
            if ($value !== null) {
                return $value;
            }

            // Execute callback and store result
            $value = $callback();
            $cache->set($key, $value, $ttl);

            return $value;
        } finally {
            // Always release the lock
            self::releaseLock($cache, $lockKey, $lockValue);
        }
    }

    /**
     * Advanced remember pattern with early expiration and background refresh
     *
     * This method extends stampede protection with early expiration detection.
     * If cache is close to expiring, it triggers background refresh while
     * returning the current cached value.
     *
     * @param CacheStore $cache Cache instance
     * @param string $key Cache key
     * @param callable $callback Function to execute on cache miss
     * @param int $ttl Cache TTL in seconds
     * @param float $refreshThreshold Threshold for early refresh (0.0-1.0, e.g., 0.8 = 80% of TTL)
     * @param int $lockTtl Lock TTL in seconds
     * @return mixed Cached or computed value
     */
    public static function rememberWithEarlyExpiration(
        CacheStore $cache,
        string $key,
        callable $callback,
        int $ttl = 3600,
        float $refreshThreshold = 0.8,
        int $lockTtl = 60
    ): mixed {
        $value = $cache->get($key);

        if ($value !== null) {
            // Check if we need early refresh
            $remainingTtl = $cache->ttl($key);
            $refreshPoint = $ttl * $refreshThreshold;

            if ($remainingTtl > 0 && $remainingTtl <= ($ttl - $refreshPoint)) {
                // Try to acquire refresh lock non-blocking
                $refreshLockKey = self::lockKey($key, 'refresh');
                if ($cache->setNx($refreshLockKey, uniqid(), $lockTtl)) {
                    try {
                        // Background refresh
                        $newValue = $callback();
                        $cache->set($key, $newValue, $ttl);
                    } catch (\Exception $e) {
                        error_log("Background cache refresh failed for key {$key}: " . $e->getMessage());
                    } finally {
                        $cache->delete($refreshLockKey);
                    }
                }
            }

            return $value;
        }

        // Cache miss - use standard stampede protection
        return self::rememberWithStampedeProtection($cache, $key, $callback, $ttl, $lockTtl);
    }

    /**
     * Simple wrapper around cache remember with optional stampede protection
     *
     * @param CacheStore|null $cache Cache instance
     * @param string $key Cache key
     * @param callable $callback Function to execute on cache miss
     * @param int $ttl Cache TTL in seconds
     * @param bool|null $useStampedeProtection Whether to use stampede protection (null = use config)
     * @return mixed Cached or computed value
     */
    public static function remember(
        ?CacheStore $cache,
        string $key,
        callable $callback,
        int $ttl = 3600,
        ?bool $useStampedeProtection = null
    ): mixed {
        if ($cache === null) {
            return $callback();
        }

        // Determine if stampede protection should be used
        $shouldUseStampedeProtection = $useStampedeProtection ?? config('cache.stampede_protection.enabled', false);

        if ($shouldUseStampedeProtection) {
            // Check if early expiration is enabled
            $earlyExpirationEnabled = config('cache.stampede_protection.early_expiration.enabled', false);

            if ($earlyExpirationEnabled) {
                $threshold = config('cache.stampede_protection.early_expiration.threshold', 0.8);
                $lockTtl = config('cache.stampede_protection.lock_ttl', 60);
                return self::rememberWithEarlyExpiration($cache, $key, $callback, $ttl, $threshold, $lockTtl);
            } else {
                $lockTtl = config('cache.stampede_protection.lock_ttl', 60);
                $maxWait = config('cache.stampede_protection.max_wait_time', 30);
                $retryInterval = config('cache.stampede_protection.retry_interval', 100000);
                return self::rememberWithStampedeProtection(
                    $cache,
                    $key,
                    $callback,
                    $ttl,
                    $lockTtl,
                    $maxWait,
                    $retryInterval
                );
            }
        }

        // Use cache's built-in remember if available, otherwise implement manually
        if (method_exists($cache, 'remember')) {
            return $cache->remember($key, $callback, $ttl);
        }

        $value = $cache->get($key);
        if ($value === null) {
            $value = $callback();
            $cache->set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Generate lock key for cache stampede protection
     *
     * @param string $cacheKey Original cache key
     * @param string $type Lock type (default: 'lock')
     * @return string Lock key
     */
    public static function lockKey(string $cacheKey, string $type = 'lock'): string
    {
        return self::key("stampede_{$type}:{$cacheKey}");
    }

    /**
     * Release a distributed lock safely
     *
     * @param CacheStore $cache Cache instance
     * @param string $lockKey Lock key
     * @param string $lockValue Lock value for verification
     * @return bool True if lock was released
     */
    private static function releaseLock(CacheStore $cache, string $lockKey, string $lockValue): bool
    {
        try {
            // Only delete the lock if we own it (atomic check-and-delete would be better)
            $currentValue = $cache->get($lockKey);
            if ($currentValue === $lockValue) {
                return $cache->delete($lockKey);
            }
            return false;
        } catch (\Exception $e) {
            error_log("Failed to release cache lock {$lockKey}: " . $e->getMessage());
            return false;
        }
    }
}
