<?php
namespace Tests\Unit\Mocks;

/**
 * Mock Cache Engine for testing
 * 
 * This class mocks the behavior of the CacheEngine class for testing purposes.
 * It stores data in memory rather than using an actual cache system.
 */
class MockCacheEngine 
{
    /** @var array In-memory store for cache data */
    protected static array $cacheData = [];
    
    /** @var string Key prefix */
    private static string $prefix = '';

    /**
     * Initialize the mock cache engine
     *
     * @param string $prefix The prefix for cache keys
     * @return bool Always returns true
     */
    public static function initialize(string $prefix = '', string $driver = ''): bool 
    {
        self::$prefix = $prefix;
        return true;
    }

    /**
     * Get a value from the mock cache
     *
     * @param string $key The cache key
     * @return mixed The stored value or null if not found
     */
    public static function get(string $key): mixed 
    {
        return self::$cacheData[$key] ?? null;
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
        self::$cacheData[$key] = $value;
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
        if (isset(self::$cacheData[$key])) {
            unset(self::$cacheData[$key]);
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
