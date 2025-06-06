<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use Memcached;

/**
 * Memcached Cache Driver
 *
 * Implements cache operations using Memcached backend.
 * Provides sorted set emulation using stored arrays.
 */
class MemcachedCacheDriver implements CacheDriverInterface
{
    /** @var Memcached Memcached connection instance */
    private Memcached $memcached;

    /**
     * Constructor
     *
     * @param Memcached $memcached Configured Memcached connection
     */
    public function __construct(Memcached $memcached)
    {
        $this->memcached = $memcached;
    }

    /**
     * Add to sorted set
     *
     * Emulates Redis ZADD using array storage.
     *
     * @param string $key Set key
     * @param array $scoreValues Score-value pairs
     * @return bool True if added successfully
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        $timestamps = $this->memcached->get($key) ?? [];
        foreach ($scoreValues as $score => $value) {
            $timestamps[$value] = $score;
        }
        return $this->memcached->set($key, $timestamps);
    }

    /**
     * Remove set members by score
     *
     * Emulates Redis ZREMRANGEBYSCORE.
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $timestamps = $this->memcached->get($key) ?? [];
        $filtered = array_filter($timestamps, fn($score) => $score > (int) $max);
        $this->memcached->set($key, $filtered);
        return count($timestamps) - count($filtered);
    }

    /**
     * Get set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int
    {
        return count($this->memcached->get($key) ?? []);
    }

    /**
     * Get set range
     *
     * Emulates Redis ZRANGE with sorting.
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return array Range of members
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        $timestamps = $this->memcached->get($key) ?? [];
        ksort($timestamps);
        return array_slice(array_keys($timestamps), $start, $stop - $start + 1);
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
        return $this->memcached->touch($key, time() + $seconds);
    }

    /**
     * Delete key
     *
     * @param string $key Cache key
     * @return bool True if deleted
     */
    public function del(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Value or null if not found
     */
    public function get(string $key): mixed
    {
        $value = $this->memcached->get($key);
        return $this->memcached->getResultCode() === Memcached::RES_NOTFOUND ? null : $value;
    }

    /**
     * Store value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if stored successfully
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->memcached->set($key, $value, $ttl);
    }

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True if deleted
     */
    public function delete(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @return bool True if incremented
     */
    public function increment(string $key): bool
    {
        return $this->memcached->increment($key, 1, 1) !== false;
    }

    /**
     * Get remaining TTL
     *
     * Note: Memcached doesn't support direct TTL lookup
     *
     * @param string $key Cache key
     * @return int Approximate TTL or 0 if expired
     */
    public function ttl(string $key): int
    {
        // Memcached doesn't provide direct TTL lookup
        return $this->get($key) !== null ? 3600 : 0;
    }

    /**
     * Clear all cached values
     *
     * @return bool True if cache cleared
     */
    public function flush(): bool
    {
        return $this->memcached->flush();
    }

    /**
     * Delete keys matching a pattern
     *
     * Note: Memcached doesn't support pattern-based deletion natively.
     * This is a limited implementation that cannot efficiently handle patterns.
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return bool True if deletion successful
     */
    public function deletePattern(string $pattern): bool
    {
        // Memcached doesn't support pattern-based operations
        // This operation is not feasible without key enumeration
        return false;
    }

    /**
     * Get all cache keys
     *
     * Note: Memcached doesn't support key enumeration natively.
     * This method returns an empty array as keys cannot be retrieved.
     *
     * @param string $pattern Optional pattern to filter keys
     * @return array List of cache keys (always empty for Memcached)
     */
    public function getKeys(string $pattern = '*'): array
    {
        // Memcached doesn't support key enumeration
        return [];
    }

    /**
     * Get cache statistics and information
     *
     * Returns Memcached server statistics.
     *
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        try {
            $stats = $this->memcached->getStats();

            if (empty($stats)) {
                return [
                    'driver' => 'memcached',
                    'error' => 'No server statistics available'
                ];
            }

            // Get stats from first server
            $serverStats = reset($stats);

            return [
                'driver' => 'memcached',
                'version' => $serverStats['version'] ?? 'unknown',
                'uptime' => $serverStats['uptime'] ?? 0,
                'memory' => [
                    'limit' => $serverStats['limit_maxbytes'] ?? 0,
                    'used' => $serverStats['bytes'] ?? 0,
                    'available' => ($serverStats['limit_maxbytes'] ?? 0) - ($serverStats['bytes'] ?? 0),
                ],
                'performance' => [
                    'total_connections' => $serverStats['total_connections'] ?? 0,
                    'current_connections' => $serverStats['curr_connections'] ?? 0,
                    'get_hits' => $serverStats['get_hits'] ?? 0,
                    'get_misses' => $serverStats['get_misses'] ?? 0,
                    'hit_rate' => $this->calculateHitRate($serverStats),
                ],
                'operations' => [
                    'cmd_get' => $serverStats['cmd_get'] ?? 0,
                    'cmd_set' => $serverStats['cmd_set'] ?? 0,
                    'get_hits' => $serverStats['get_hits'] ?? 0,
                    'get_misses' => $serverStats['get_misses'] ?? 0,
                ],
                'items' => [
                    'current_items' => $serverStats['curr_items'] ?? 0,
                    'total_items' => $serverStats['total_items'] ?? 0,
                ],
                'limitations' => [
                    'pattern_deletion' => false,
                    'key_enumeration' => false,
                    'note' => 'Memcached does not support pattern operations or key enumeration'
                ]
            ];
        } catch (\Exception $e) {
            return [
                'driver' => 'memcached',
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all cache keys
     *
     * Note: Memcached doesn't support key enumeration natively.
     * This method returns an empty array as keys cannot be retrieved.
     *
     * @return array List of all cache keys (always empty for Memcached)
     */
    public function getAllKeys(): array
    {
        return [];
    }

    /**
     * Calculate cache hit rate from Memcached stats
     *
     * @param array $stats Server statistics
     * @return float Hit rate as percentage
     */
    private function calculateHitRate(array $stats): float
    {
        $hits = $stats['get_hits'] ?? 0;
        $misses = $stats['get_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }
}
