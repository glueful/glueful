<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Cache\Drivers\CacheDriverInterface;
use Glueful\Cache\CacheFactory;

/**
 * Cache Engine
 *
 * Provides unified caching interface across the application.
 * Supports multiple cache drivers with prefix management.
 */
class CacheEngine
{
    /** @var CacheDriverInterface|null Active cache driver instance */
    private static ?CacheDriverInterface $driver = null;

    /** @var bool Cache system enabled state */
    private static bool $enabled = false;

    /** @var string Cache key prefix */
    private static string $prefix = '';

    /**
     * Initialize cache engine
     *
     * Sets up cache driver and configuration.
     *
     * @param string $prefix Optional key prefix
     * @param string $driver Optional driver type
     * @return bool True if initialization successful
     */
    public static function initialize(string $prefix = '', string $driver = ''): bool
    {
        // Define CACHE_ENGINE constant if not already defined
        if (!defined('CACHE_ENGINE')) {
            define('CACHE_ENGINE', true);
        }

        self::$prefix = $prefix ?: config('cache.prefix');

        try {
            self::$driver = CacheFactory::create($driver);
            self::$enabled = true;
            return true;
        } catch (\Exception $e) {
            error_log("Cache initialization failed: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            self::$enabled = false;
            return false;
        }
    }

    /**
     * Check if cache is enabled
     *
     * @return bool True if cache system is ready
     */
    private static function ensureEnabled(): bool
    {
        return self::$enabled && self::$driver !== null;
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public static function get(string $key): mixed
    {
        return self::ensureEnabled() ? self::$driver->get(self::$prefix . $key) : null;
    }

    /**
     * Store value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool True if stored successfully
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return self::ensureEnabled() ? self::$driver->set(self::$prefix . $key, $value, $ttl) : false;
    }

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public static function delete(string $key): bool
    {
        return self::ensureEnabled() ? self::$driver->delete(self::$prefix . $key) : false;
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @return bool True if incremented successfully
     */
    public static function increment(string $key): bool
    {
        return self::ensureEnabled() ? self::$driver->increment(self::$prefix . $key) : false;
    }

    /**
     * Get remaining TTL
     *
     * @param string $key Cache key
     * @return int Remaining time in seconds
     */
    public static function ttl(string $key): int
    {
        return self::ensureEnabled() ? self::$driver->ttl(self::$prefix . $key) : 0;
    }

    /**
     * Clear all cached values
     *
     * @return bool True if cache cleared successfully
     */
    public static function flush(): bool
    {
        return self::ensureEnabled() ? self::$driver->flush() : false;
    }

    /**
     * Remove sorted set members by score
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return bool True if members removed
     */
    public static function zremrangebyscore(string $key, string $min, string $max): bool
    {
        return self::ensureEnabled() ? self::$driver->zremrangebyscore(self::$prefix . $key, $min, $max) > 0 : false;
    }

    /**
     * Get sorted set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public static function zcard(string $key): int
    {
        return self::ensureEnabled() ? self::$driver->zcard(self::$prefix . $key) : 0;
    }

    /**
     * Add members to sorted set
     *
     * @param string $key Set key
     * @param array $members Members with scores
     * @return bool True if added successfully
     */
    public static function zadd(string $key, array $members): bool
    {
        return self::ensureEnabled() ? self::$driver->zadd(self::$prefix . $key, $members) : false;
    }

    /**
     * Set key expiration
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool True if expiration set
     */
    public static function expire(string $key, int $seconds): bool
    {
        return self::ensureEnabled() ? self::$driver->expire(self::$prefix . $key, $seconds) : false;
    }

    /**
     * Get sorted set range
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return array Range of members
     */
    public static function zrange(string $key, int $start, int $stop): array
    {
        return self::ensureEnabled() ? self::$driver->zrange(self::$prefix . $key, $start, $stop) : [];
    }

    /**
     * Check cache availability
     *
     * @return bool True if cache system is enabled
     */
    public static function isEnabled(): bool
    {
        return self::ensureEnabled();
    }

    /**
     * Check if cache driver is initialized
     *
     * @return bool True if cache driver is initialized
     */
    public static function isInitialized(): bool
    {
        return self::$driver !== null;
    }

    /**
     * Remember a value in cache, or execute a callback to generate it
     *
     * @param string $key Cache key
     * @param \Closure $callback Function to generate value if not cached
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or generated value
     */
    public static function remember(string $key, \Closure $callback, int $ttl = 3600): mixed
    {
        if (!self::ensureEnabled()) {
            return $callback();
        }

        $fullKey = self::$prefix . $key;
        $value = self::$driver->get($fullKey);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::$driver->set($fullKey, $value, $ttl);

        return $value;
    }

    /**
     * Invalidate cache entries by tags
     *
     * @param array|string $tags Tags to invalidate
     * @return bool True if invalidation succeeded
     */
    public static function invalidateTags(array|string $tags): bool
    {
        if (!self::ensureEnabled()) {
            return false;
        }

        if (is_string($tags)) {
            $tags = [$tags];
        }

        $success = true;

        foreach ($tags as $tag) {
            // Get all keys associated with this tag
            $tagKey = self::$prefix . "tag:{$tag}";
            $keys = self::$driver->zrange($tagKey, 0, -1);

            // Delete each key
            foreach ($keys as $key) {
                $success = $success && self::$driver->delete($key);
            }

            // Delete the tag set itself
            $success = $success && self::$driver->delete($tagKey);
        }

        return $success;
    }

    /**
     * Add tags to a cache key
     *
     * @param string $key Cache key
     * @param array|string $tags Tags to associate
     * @return bool True if tags added successfully
     */
    public static function addTags(string $key, array|string $tags): bool
    {
        if (!self::ensureEnabled()) {
            return false;
        }

        if (is_string($tags)) {
            $tags = [$tags];
        }

        $fullKey = self::$prefix . $key;
        $success = true;
        $now = time();

        foreach ($tags as $tag) {
            $tagKey = self::$prefix . "tag:{$tag}";
            $success = $success && self::$driver->zadd($tagKey, [$fullKey => $now]);
        }

        return $success;
    }

    /**
     * Delete cache keys matching a pattern
     *
     * Deletes all cache keys that match the given pattern.
     * Uses wildcard (*) matching for flexibility.
     *
     * @param string $pattern Pattern to match (e.g., 'user:*', '*:permissions')
     * @return bool True if deletion successful
     */
    public static function deletePattern(string $pattern): bool
    {
        if (!self::ensureEnabled()) {
            return false;
        }

        try {
            $fullPattern = self::$prefix . $pattern;

            // For Redis driver, use SCAN and DEL
            if (method_exists(self::$driver, 'deletePattern')) {
                return self::$driver->deletePattern($fullPattern);
            }

            // Fallback: Get all keys and filter (less efficient but works)
            if (method_exists(self::$driver, 'getAllKeys')) {
                $allKeys = self::$driver->getAllKeys();
                $matchingKeys = [];

                // Convert pattern to regex
                $regex = '/^' . str_replace('*', '.*', preg_quote($fullPattern, '/')) . '$/';

                foreach ($allKeys as $key) {
                    if (preg_match($regex, $key)) {
                        $matchingKeys[] = str_replace(self::$prefix, '', $key);
                    }
                }

                $success = true;
                foreach ($matchingKeys as $key) {
                    $success = $success && self::delete($key);
                }

                return $success;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get cache statistics
     *
     * Returns statistics about cache usage and performance.
     *
     * @return array Cache statistics
     */
    public static function getStats(): array
    {
        if (!self::ensureEnabled()) {
            return [
                'enabled' => false,
                'error' => 'Cache not enabled or initialized'
            ];
        }

        try {
            $stats = [
                'enabled' => true,
                'driver' => get_class(self::$driver),
                'prefix' => self::$prefix,
            ];

            // Get driver-specific stats if available
            if (method_exists(self::$driver, 'getStats')) {
                $stats['driver_stats'] = self::$driver->getStats();
            }

            // Add basic health check
            $testKey = 'health_check_' . time();
            $testValue = 'test';

            $writeSuccess = self::set($testKey, $testValue, 60);
            $readSuccess = self::get($testKey) === $testValue;
            self::delete($testKey);

            $stats['health'] = [
                'write' => $writeSuccess,
                'read' => $readSuccess,
                'overall' => $writeSuccess && $readSuccess
            ];

            return $stats;
        } catch (\Exception $e) {
            return [
                'enabled' => true,
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all cache keys with optional pattern filtering
     *
     * Returns all cache keys, optionally filtered by pattern.
     *
     * @param string $pattern Optional pattern to filter keys
     * @return array List of cache keys
     */
    public static function getKeys(string $pattern = '*'): array
    {
        if (!self::ensureEnabled()) {
            return [];
        }

        try {
            $fullPattern = self::$prefix . $pattern;

            // If driver supports pattern-based key retrieval
            if (method_exists(self::$driver, 'getKeys')) {
                $keys = self::$driver->getKeys($fullPattern);

                // Remove prefix from keys
                return array_map(function ($key) {
                    return str_replace(self::$prefix, '', $key);
                }, $keys);
            }

            // Fallback: Get all keys and filter
            if (method_exists(self::$driver, 'getAllKeys')) {
                $allKeys = self::$driver->getAllKeys();
                $matchingKeys = [];

                // Convert pattern to regex
                $regex = '/^' . str_replace('*', '.*', preg_quote($fullPattern, '/')) . '$/';

                foreach ($allKeys as $key) {
                    if (preg_match($regex, $key)) {
                        $matchingKeys[] = str_replace(self::$prefix, '', $key);
                    }
                }

                return $matchingKeys;
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get cache key count
     *
     * Returns the total number of cache keys.
     *
     * @param string $pattern Optional pattern to count specific keys
     * @return int Number of cache keys
     */
    public static function getKeyCount(string $pattern = '*'): int
    {
        return count(self::getKeys($pattern));
    }

    /**
     * Check if cache driver supports advanced operations
     *
     * Returns information about which advanced operations
     * the current cache driver supports.
     *
     * @return array Supported operations
     */
    public static function getCapabilities(): array
    {
        if (!self::ensureEnabled()) {
            return [];
        }

        $capabilities = [
            'basic_operations' => true,
            'pattern_deletion' => method_exists(self::$driver, 'deletePattern') ||
                                  method_exists(self::$driver, 'getAllKeys'),
            'key_enumeration' => method_exists(self::$driver, 'getKeys') || method_exists(self::$driver, 'getAllKeys'),
            'statistics' => method_exists(self::$driver, 'getStats'),
            'tagging' => true, // Basic tagging is always supported via our implementation
            'atomic_operations' => method_exists(self::$driver, 'multi') || method_exists(self::$driver, 'pipeline'),
            'expiration' => true, // TTL is always supported
            'increment' => method_exists(self::$driver, 'increment'),
            'sorted_sets' => method_exists(self::$driver, 'zadd'),
        ];

        return $capabilities;
    }
}
