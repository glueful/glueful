<?php

namespace Tests\Mocks;

/**
 * Mock CacheEngine class for testing
 *
 * This class mocks the CacheEngine behavior for testing purposes
 * without requiring an actual cache engine or modifying the original class.
 */
class MockCacheEngine
{
    /** @var array Cache data storage */
    private static array $cache = [];

    /** @var array Sorted set data storage */
    private static array $sets = [];

    /** @var bool Whether the engine is enabled */
    private static bool $enabled = true;

    /** @var string Cache prefix */
    private static string $prefix = 'test:';

    /**
     * Reset all mock data
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$sets = [];
        self::$enabled = true;
        self::$prefix = 'test:';
    }

    /**
     * Initialize the cache engine
     *
     * @param string $prefix Key prefix
     * @param string $driver Driver type
     * @return bool True if initialization successful
     */
    public static function initialize(string $prefix = '', string $driver = ''): bool
    {
        self::$prefix = $prefix ?: 'test:';
        return true;
    }

    /**
     * Get a value from cache
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public static function get(string $key): mixed
    {
        return self::$cache[$key] ?? null;
    }

    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl TTL in seconds (ignored in mock)
     * @return bool True if stored successfully
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        self::$cache[$key] = $value;
        return true;
    }

    /**
     * Delete a value from cache
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public static function delete(string $key): bool
    {
        if (isset(self::$cache[$key])) {
            unset(self::$cache[$key]);
            return true;
        }
        return false;
    }

    /**
     * Increment a value
     *
     * @param string $key Cache key
     * @return bool True if incremented successfully
     */
    public static function increment(string $key): bool
    {
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = 1;
            return true;
        }

        if (is_numeric(self::$cache[$key])) {
            self::$cache[$key]++;
            return true;
        }

        return false;
    }

    /**
     * Get TTL for a key
     *
     * @param string $key Cache key
     * @return int TTL in seconds or 0 if not found
     */
    public static function ttl(string $key): int
    {
        return isset(self::$cache[$key]) ? 3600 : 0;
    }

    /**
     * Flush the cache
     *
     * @return bool True if flushed successfully
     */
    public static function flush(): bool
    {
        self::$cache = [];
        self::$sets = [];
        return true;
    }

    /**
     * Check if cache is enabled
     *
     * @return bool True if cache is enabled
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Add to sorted set
     *
     * @param string $key Set key
     * @param array $members Members with scores
     * @return bool True if added successfully
     */
    public static function zadd(string $key, array $members): bool
    {
        if (!isset(self::$sets[$key])) {
            self::$sets[$key] = [];
        }

        foreach ($members as $member => $score) {
            self::$sets[$key][$member] = $score;
        }

        return true;
    }

    /**
     * Get sorted set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public static function zcard(string $key): int
    {
        return count(self::$sets[$key] ?? []);
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
        if (!isset(self::$sets[$key])) {
            return [];
        }

        $members = array_keys(self::$sets[$key]);
        return array_slice($members, $start, $stop - $start + 1);
    }

    /**
     * Remove range by score
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return bool True if members removed
     */
    public static function zremrangebyscore(string $key, string $min, string $max): bool
    {
        if (!isset(self::$sets[$key])) {
            return false;
        }

        $minScore = (float) $min;
        $maxScore = (float) $max;
        $removed = false;

        foreach (self::$sets[$key] as $member => $score) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset(self::$sets[$key][$member]);
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Set expiration for a key
     *
     * @param string $key Cache key
     * @param int $seconds Seconds until expiration
     * @return bool True if expiration set
     */
    public static function expire(string $key, int $seconds): bool
    {
        return isset(self::$cache[$key]);
    }
}
