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
}
