<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

/**
 * Cache Driver Interface
 * 
 * Defines required methods for cache driver implementations.
 * Supports basic caching, sorted sets, and key operations.
 */
interface CacheDriverInterface
{
    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Store value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if stored successfully
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function delete(string $key): bool;

    /**
     * Increment numeric value
     * 
     * @param string $key Cache key
     * @return bool True if incremented successfully
     */
    public function increment(string $key): bool;

    /**
     * Get remaining TTL
     * 
     * @param string $key Cache key
     * @return int Remaining time in seconds
     */
    public function ttl(string $key): int;

    /**
     * Clear all cached values
     * 
     * @return bool True if cache cleared successfully
     */
    public function flush(): bool;
    
    /**
     * Add to sorted set
     * 
     * @param string $key Set key
     * @param array $scoreValues Score-value pairs
     * @return bool True if added successfully
     */
    public function zadd(string $key, array $scoreValues): bool;

    /**
     * Remove set members by score
     * 
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int;

    /**
     * Get set cardinality
     * 
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int;

    /**
     * Get set range
     * 
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return array Range of members
     */
    public function zrange(string $key, int $start, int $stop): array;
    
    /**
     * Set key expiration
     * 
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool True if expiration set
     */
    public function expire(string $key, int $seconds): bool;

    /**
     * Delete key
     * 
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function del(string $key): bool;
}