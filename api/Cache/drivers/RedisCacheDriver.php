<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use Redis;

/**
 * Redis Cache Driver
 *
 * Implements cache operations using Redis backend.
 * Provides sorted set support and automatic serialization.
 */
class RedisCacheDriver implements CacheDriverInterface
{
    /** @var Redis Redis connection instance */
    private Redis $redis;

    /**
     * Constructor
     *
     * @param Redis $redis Configured Redis connection
     */
    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Add to sorted set
     *
     * @param string $key Set key
     * @param array $scoreValues Score-value pairs
     * @return bool True if added successfully
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        $mergedArray = array_merge(
            ...array_map(null, array_values($scoreValues), array_keys($scoreValues))
        );
        return $this->redis->zAdd($key, ...$mergedArray);
    }

    /**
     * Remove set members by score
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return $this->redis->zRemRangeByScore($key, $min, $max);
    }

    /**
     * Get set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int
    {
        return (int) $this->redis->zCard($key);
    }

    /**
     * Get set range
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return array Range of members
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        return $this->redis->zRange($key, $start, $stop);
    }

    /**
     * Set key expiration
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool True if expiration set
     */
    public function expire(string $key, int $seconds): bool
    {
        return $this->redis->expire($key, $seconds);
    }

    /**
     * Delete key
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function del(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    /**
     * Get cached value
     *
     * Retrieves and unserializes stored value.
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);
        return $value === false ? null : unserialize($value);
    }

    /**
     * Store value in cache
     *
     * Serializes and stores value with expiration.
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if stored successfully
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->redis->setex($key, $ttl, serialize($value));
    }

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function delete(string $key): bool
    {
        return $this->del($key);
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @return bool True if incremented successfully
     */
    public function increment(string $key): bool
    {
        return $this->redis->incr($key) !== false;
    }

    /**
     * Get remaining TTL
     *
     * @param string $key Cache key
     * @return int Remaining time in seconds
     */
    public function ttl(string $key): int
    {
        return max(0, (int)$this->redis->ttl($key));
    }

    /**
     * Clear all cached values
     *
     * @return bool True if cache cleared successfully
     */
    public function flush(): bool
    {
        return $this->redis->flushDB();
    }
}
