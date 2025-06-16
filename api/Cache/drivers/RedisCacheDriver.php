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
                $result[] = unserialize($values[$i]);
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
            $pipe->setex($key, $ttl, serialize($value));
        }

        $results = $pipe->exec();

        // Check if all operations succeeded
        return !in_array(false, $results);
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
}
