<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use Redis;
use Glueful\Security\SecureSerializer;
use Psr\SimpleCache\InvalidArgumentException;
use Glueful\Exceptions\CacheException;
use Glueful\Cache\CacheStore;

/**
 * Redis Cache Driver
 *
 * Implements cache operations using Redis backend.
 * Provides sorted set support and automatic serialization.
 */
class RedisCacheDriver implements CacheStore
{
    /** @var Redis Redis connection instance */
    private Redis $redis;

    /** @var SecureSerializer Secure serialization service */
    private SecureSerializer $serializer;

    /**
     * Constructor
     *
     * @param Redis $redis Configured Redis connection
     */
    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
        $this->serializer = SecureSerializer::forCache();
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
        $result = $this->redis->zAdd($key, ...$mergedArray);
        return $result !== false;
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
        $result = $this->redis->del($key);
        return is_numeric($result) && (int)$result > 0;
    }

    /**
     * Get cached value (PSR-16 compatible)
     *
     * Retrieves and unserializes stored value.
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or default if not found
     * @throws InvalidArgumentException If key is invalid
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $value = $this->redis->get($key);
        return $value === false ? $default : $this->serializer->unserialize($value);
    }

    /**
     * Store value in cache (PSR-16 compatible)
     *
     * Serializes and stores value with expiration.
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if stored successfully
     * @throws InvalidArgumentException If key is invalid
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $seconds = $this->normalizeTtl($ttl);

        if ($seconds === null) {
            return $this->redis->set($key, $this->serializer->serialize($value));
        }

        return $this->redis->setex($key, $seconds, $this->serializer->serialize($value));
    }

    /**
     * Set value only if key does not exist (atomic operation)
     *
     * Uses Redis SET command with NX and EX options for atomic operation.
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if key was set (didn't exist), false if key already exists
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        // Use Redis SET command with NX (only set if not exists) and EX (set expiry)
        $result = $this->redis->set($key, $this->serializer->serialize($value), ['nx', 'ex' => $ttl]);
        return $result === true;
    }

    /**
     * Get multiple cached values
     *
     * @param array $keys Array of cache keys
     * @return array Indexed array of values (same order as keys, null for missing keys)
     */
    public function mget(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $values = $this->redis->mget($keys);
        $result = [];

        for ($i = 0; $i < count($keys); $i++) {
            if ($values[$i] !== false) {
                $result[] = $this->serializer->unserialize($values[$i]);
            } else {
                $result[] = null;
            }
        }

        return $result;
    }

    /**
     * Store multiple values in cache
     *
     * @param array $values Associative array of key => value pairs
     * @param int $ttl Time to live in seconds
     * @return bool True if all values stored successfully
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        if (empty($values)) {
            return true;
        }

        // Redis MSET doesn't support TTL, so we use a pipeline for efficiency
        $pipe = $this->redis->multi(\Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $pipe->setex($key, $ttl, $this->serializer->serialize($value));
        }

        $results = $pipe->exec();

        // Check if all operations succeeded
        return !in_array(false, $results);
    }

    /**
     * Delete cached value (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     * @throws InvalidArgumentException If key is invalid
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return $this->del($key);
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment by (default: 1)
     * @return int New value after increment
     */
    public function increment(string $key, int $value = 1): int
    {
        if ($value === 1) {
            return (int) $this->redis->incr($key);
        }
        return (int) $this->redis->incrBy($key, $value);
    }

    /**
     * Decrement numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement by (default: 1)
     * @return int New value after decrement
     */
    public function decrement(string $key, int $value = 1): int
    {
        if ($value === 1) {
            return (int) $this->redis->decr($key);
        }
        return (int) $this->redis->decrBy($key, $value);
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
     * Clear all cached values (PSR-16 compatible)
     *
     * @return bool True if cache cleared successfully
     */
    public function clear(): bool
    {
        return $this->redis->flushDB();
    }

    /**
     * Clear all cached values (alias for PSR-16 clear() for backward compatibility)
     *
     * @return bool True if cache cleared successfully
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Delete keys matching a pattern
     *
     * Uses Redis SCAN and DEL commands for efficient pattern-based deletion.
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return bool True if deletion successful
     */
    public function deletePattern(string $pattern): bool
    {
        try {
            $iterator = null;
            $deletedCount = 0;

            // Use SCAN to iterate through keys matching the pattern
            do {
                $keys = $this->redis->scan($iterator, $pattern, 100);
                if ($keys !== false && !empty($keys)) {
                    $result = $this->redis->del($keys);
                    $deletedCount += $result;
                }
            } while ($iterator > 0);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all cache keys
     *
     * Uses Redis SCAN command to efficiently retrieve keys.
     *
     * @param string $pattern Optional pattern to filter keys
     * @return array List of cache keys
     */
    public function getKeys(string $pattern = '*'): array
    {
        try {
            $keys = [];
            $iterator = null;

            // Use SCAN to iterate through all keys
            do {
                $scanResult = $this->redis->scan($iterator, $pattern, 1000);
                if ($scanResult !== false) {
                    $keys = array_merge($keys, $scanResult);
                }
            } while ($iterator > 0);

            return $keys;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get cache statistics and information
     *
     * Returns Redis server information and statistics.
     *
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        try {
            $info = $this->redis->info();
            $stats = [
                'driver' => 'redis',
                'connection' => [
                    'host' => $this->redis->getHost(),
                    'port' => $this->redis->getPort(),
                    'database' => $this->redis->getDBNum(),
                ],
                'memory' => [
                    'used' => $info['used_memory_human'] ?? 'unknown',
                    'peak' => $info['used_memory_peak_human'] ?? 'unknown',
                    'fragmentation_ratio' => $info['mem_fragmentation_ratio'] ?? 'unknown',
                ],
                'performance' => [
                    'total_connections' => $info['total_connections_received'] ?? 0,
                    'total_commands' => $info['total_commands_processed'] ?? 0,
                    'ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? 0,
                    'hit_rate' => $this->calculateHitRate($info),
                ],
                'keyspace' => [],
            ];

            // Add keyspace information
            foreach ($info as $key => $value) {
                if (strpos($key, 'db') === 0) {
                    $stats['keyspace'][$key] = $value;
                }
            }

            return $stats;
        } catch (\Exception $e) {
            return [
                'driver' => 'redis',
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all cache keys
     *
     * Uses Redis SCAN command to efficiently retrieve all keys.
     *
     * @return array List of all cache keys
     */
    public function getAllKeys(): array
    {
        return $this->getKeys('*');
    }

    /**
     * Check if a cache key exists (PSR-16)
     *
     * @param string $key Cache key
     * @return bool True if key exists
     * @throws InvalidArgumentException If key is invalid
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        $result = $this->redis->exists($key);
        return is_int($result) ? $result > 0 : (bool) $result;
    }

    /**
     * Get multiple cached values (PSR-16)
     *
     * @param iterable $keys Cache keys
     * @param mixed $default Default value for missing keys
     * @return iterable Values in same order as keys
     * @throws InvalidArgumentException If any key is invalid
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($keyArray as $key) {
            $this->validateKey($key);
        }

        if (empty($keyArray)) {
            return [];
        }

        $values = $this->redis->mget($keyArray);
        $result = [];

        for ($i = 0; $i < count($keyArray); $i++) {
            if ($values[$i] !== false) {
                $result[$keyArray[$i]] = $this->serializer->unserialize($values[$i]);
            } else {
                $result[$keyArray[$i]] = $default;
            }
        }

        return $result;
    }

    /**
     * Store multiple values in cache (PSR-16)
     *
     * @param iterable $values Key-value pairs
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if all values stored successfully
     * @throws InvalidArgumentException If any key is invalid
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $valueArray = is_array($values) ? $values : iterator_to_array($values);

        foreach (array_keys($valueArray) as $key) {
            $this->validateKey($key);
        }

        return $this->mset($valueArray, $this->normalizeTtl($ttl) ?? 3600);
    }

    /**
     * Delete multiple cache keys (PSR-16)
     *
     * @param iterable $keys Cache keys
     * @return bool True if all keys deleted successfully
     * @throws InvalidArgumentException If any key is invalid
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($keyArray as $key) {
            $this->validateKey($key);
        }

        if (empty($keyArray)) {
            return true;
        }

        $result = $this->redis->del($keyArray);
        return is_int($result) ? $result > 0 : (bool) $result;
    }

    /**
     * Get count of keys matching pattern
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return int Number of matching keys
     */
    public function getKeyCount(string $pattern = '*'): int
    {
        return count($this->getKeys($pattern));
    }

    /**
     * Get cache driver capabilities
     *
     * @return array Driver capabilities and features
     */
    public function getCapabilities(): array
    {
        return [
            'driver' => 'redis',
            'features' => [
                'persistent' => true,
                'distributed' => true,
                'atomic_operations' => true,
                'pattern_deletion' => true,
                'sorted_sets' => true,
                'counters' => true,
                'expiration' => true,
                'bulk_operations' => true,
                'tags' => false, // Not implemented yet
            ],
            'data_types' => ['string', 'integer', 'float', 'boolean', 'array', 'object'],
            'max_key_length' => 512 * 1024 * 1024, // 512MB
            'max_value_size' => 512 * 1024 * 1024, // 512MB
        ];
    }

    /**
     * Add tags to a cache key for grouped invalidation
     *
     * @param string $key Cache key
     * @param array $tags Array of tags to associate with the key
     * @return bool True if tags added successfully
     */
    public function addTags(string $key, array $tags): bool
    {
        // TODO: Implement tagging system using Redis sets
        // For now, return false to indicate not implemented
        return false;
    }

    /**
     * Invalidate all cache entries with specified tags
     *
     * @param array $tags Array of tags to invalidate
     * @return bool True if invalidation successful
     */
    public function invalidateTags(array $tags): bool
    {
        // TODO: Implement tag-based invalidation
        // For now, return false to indicate not implemented
        return false;
    }

    /**
     * Remember pattern - get from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int|null $ttl Time to live in seconds (null = default)
     * @return mixed Cached or computed value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl ?? 3600);

        return $value;
    }

    /**
     * Calculate cache hit rate from Redis info
     *
     * @param array $info Redis info array
     * @return float Hit rate as percentage
     */
    private function calculateHitRate(array $info): float
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Validate cache key according to PSR-16 requirements
     *
     * @param string $key Cache key to validate
     * @throws InvalidArgumentException If key is invalid
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw CacheException::emptyKey();
        }

        // Redis supports colons in keys, so only check for truly problematic characters
        // PSR-16 reserves these characters but Redis can handle most of them
        if (strpbrk($key, '{}()/\\@') !== false) {
            throw CacheException::invalidCharacters($key);
        }
    }

    /**
     * Normalize TTL value to seconds
     *
     * @param null|int|\DateInterval $ttl TTL value
     * @return int|null TTL in seconds or null for no expiration
     */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $future = $now->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }

        return max(1, (int) $ttl);
    }
}
