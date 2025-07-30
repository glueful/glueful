<?php

namespace Glueful\Cache;

/**
 * Mock Cache Engine for testing
 *
 * This class replaces the CacheEngine for testing purposes.
 * It stores data in memory rather than using an actual cache system.
 */
class CacheEngine
{
    /** @var array In-memory store for cache data */
    protected static array $cacheData = [];

    /** @var string Key prefix */
    private static string $prefix = '';

    /** @var bool Cache enabled flag */
    private static bool $enabled = true;

    /**
     * Initialize the mock cache engine
     *
     * @param string $prefix The prefix for cache keys
     * @param string $driver The cache driver (ignored in mock)
     * @return bool Always returns true
     */
    public static function initialize(string $prefix = '', string $driver = ''): bool
    {
        self::$prefix = $prefix;
        self::$enabled = true;
        return true;
    }

    /**
     * Check if cache is enabled
     */
    private static function ensureEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Get a value from the mock cache
     *
     * @param string $key The cache key
     * @return mixed The stored value or null if not found
     */
    public static function get(string $key): mixed
    {
        return self::$cacheData[self::$prefix . $key] ?? null;
    }

    /**
     * Set a value in the mock cache
     *
     * @param string $key The cache key
     * @param mixed $value The value to store
     * @param int $ttl Time to live (ignored in mock)
     * @return bool Always returns true
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        self::$cacheData[self::$prefix . $key] = $value;
        return true;
    }

    /**
     * Delete a value from the mock cache
     *
     * @param string $key The cache key
     * @return bool Always returns true
     */
    public static function delete(string $key): bool
    {
        if (isset(self::$cacheData[self::$prefix . $key])) {
            unset(self::$cacheData[self::$prefix . $key]);
        }
        return true;
    }

    /**
     * Reset the mock cache (for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$cacheData = [];
    }

    /**
     * Set raw cache data for testing
     *
     * @param array $data The data to set
     * @return void
     */
    public static function setRawCacheData(array $data): void
    {
        self::$cacheData = $data;
    }
}
