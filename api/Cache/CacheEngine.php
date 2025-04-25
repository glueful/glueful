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
}
