<?php

namespace Tests\Mocks;

use Glueful\Cache\CacheStore;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Mock CacheStore implementation for testing
 *
 * Provides an in-memory cache implementation that follows the CacheStore interface.
 * This replaces the old MockCacheEngine for test purposes.
 */
class MockCacheStore implements CacheStore
{
    /** @var array Cache data storage */
    private static array $cache = [];

    /** @var array Sorted set data storage */
    private static array $sets = [];

    /** @var array Tag associations */
    private static array $tags = [];

    /** @var bool Whether the cache is enabled */
    private static bool $enabled = true;

    /**
     * Reset all mock data
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$sets = [];
        self::$tags = [];
        self::$enabled = true;
    }

    /**
     * Get a value from cache
     *
     * @param string $key The unique key of this item in the cache
     * @param mixed $default Default value to return if the key does not exist
     * @return mixed The value of the item from the cache, or $default
     */
    public function get($key, $default = null): mixed
    {
        $this->validateKey($key);
        return self::$cache[$key] ?? $default;
    }

    /**
     * Persists data in the cache
     *
     * @param string $key The key of the item to store
     * @param mixed $value The value of the item to store
     * @param null|int|\DateInterval $ttl The TTL value (ignored in mock)
     * @return bool True on success and false on failure
     */
    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);
        self::$cache[$key] = $value;
        return true;
    }

    /**
     * Delete an item from the cache by its unique key
     *
     * @param string $key The unique cache key of the item to delete
     * @return bool True if the item was successfully removed
     */
    public function delete($key): bool
    {
        $this->validateKey($key);
        if (isset(self::$cache[$key])) {
            unset(self::$cache[$key]);
            // Remove from tags
            foreach (self::$tags as $tag => $keys) {
                self::$tags[$tag] = array_diff($keys, [$key]);
            }
            return true;
        }
        return false;
    }

    /**
     * Wipes clean the entire cache's keys
     *
     * @return bool True on success and false on failure
     */
    public function clear(): bool
    {
        self::$cache = [];
        self::$sets = [];
        self::$tags = [];
        return true;
    }

    /**
     * Obtains multiple cache items by their unique keys
     *
     * @param iterable $keys A list of keys
     * @param mixed $default Default value for missing keys
     * @return iterable
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Persists a set of key => value pairs in the cache
     *
     * @param iterable $values Key-value pairs
     * @param null|int|\DateInterval $ttl The TTL value
     * @return bool True on success and false on failure
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * Deletes multiple cache items
     *
     * @param iterable $keys Keys to delete
     * @return bool True on success and false on failure
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * Determines whether an item is present in the cache
     *
     * @param string $key The cache item key
     * @return bool
     */
    public function has($key): bool
    {
        $this->validateKey($key);
        return isset(self::$cache[$key]);
    }

    // Extended CacheStore methods

    /**
     * Set value only if key does not exist
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->has($key)) {
            return $this->set($key, $value, $ttl);
        }
        return false;
    }

    /**
     * Get multiple cached values
     */
    public function mget(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = $this->get($key);
        }
        return $result;
    }

    /**
     * Store multiple values in cache
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        return $this->setMultiple($values, $ttl);
    }

    /**
     * Delete keys matching pattern
     */
    public function deletePattern(string $pattern): bool
    {
        $pattern = str_replace('*', '.*', $pattern);
        $deleted = false;
        foreach (array_keys(self::$cache) as $key) {
            if (preg_match('/^' . $pattern . '$/', $key)) {
                $this->delete($key);
                $deleted = true;
            }
        }
        return $deleted;
    }

    /**
     * Increment numeric value
     */
    public function increment(string $key, int $value = 1): int
    {
        $current = $this->get($key, 0);
        if (!is_numeric($current)) {
            return 0; // Return 0 instead of false to match interface
        }
        $new = $current + $value;
        $this->set($key, $new);
        return $new;
    }

    /**
     * Decrement numeric value
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        return [
            'keys' => count(self::$cache),
            'sets' => count(self::$sets),
            'tags' => count(self::$tags),
            'enabled' => self::$enabled
        ];
    }

    /**
     * Get time-to-live for key
     */
    public function ttl(string $key): int
    {
        return $this->has($key) ? 3600 : -1;
    }

    /**
     * Set expiration time for key
     */
    public function expire(string $key, int $seconds): bool
    {
        return $this->has($key);
    }

    /**
     * Remember pattern - get or compute and store
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->set($key, $value, $ttl ?? 3600);
        return $value;
    }

    /**
     * Flush the cache (alias for clear)
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Delete a key (alias for delete)
     */
    public function del(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Get keys matching pattern
     */
    public function getKeys(string $pattern = '*'): array
    {
        $pattern = str_replace('*', '.*', $pattern);
        $keys = [];
        foreach (array_keys(self::$cache) as $key) {
            if (preg_match('/^' . $pattern . '$/', $key)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Get all cache keys
     */
    public function getAllKeys(): array
    {
        return array_keys(self::$cache);
    }

    /**
     * Get count of keys matching pattern
     */
    public function getKeyCount(string $pattern = '*'): int
    {
        return count($this->getKeys($pattern));
    }

    /**
     * Add tags to a cache key
     */
    public function addTags(string $key, array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!isset(self::$tags[$tag])) {
                self::$tags[$tag] = [];
            }
            if (!in_array($key, self::$tags[$tag])) {
                self::$tags[$tag][] = $key;
            }
        }
        return true;
    }

    /**
     * Invalidate cache entries by tags
     */
    public function invalidateTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (isset(self::$tags[$tag])) {
                foreach (self::$tags[$tag] as $key) {
                    $this->delete($key);
                }
                unset(self::$tags[$tag]);
            }
        }
        return true;
    }

    /**
     * Get driver capabilities
     */
    public function getCapabilities(): array
    {
        return [
            'atomic_operations',
            'pattern_deletion',
            'counters',
            'sorted_sets',
            'tagging',
            'batch_operations'
        ];
    }

    // Sorted set operations (for compatibility with tests that need them)

    /**
     * Add to sorted set
     */
    public function zadd(string $key, array $members): bool
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
     */
    public function zcard(string $key): int
    {
        return count(self::$sets[$key] ?? []);
    }

    /**
     * Get sorted set range
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        if (!isset(self::$sets[$key])) {
            return [];
        }
        $members = array_keys(self::$sets[$key]);
        return array_slice($members, $start, $stop - $start + 1);
    }

    /**
     * Remove range by score
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        if (!isset(self::$sets[$key])) {
            return 0;
        }
        $minScore = (float) $min;
        $maxScore = (float) $max;
        $removedCount = 0;
        foreach (self::$sets[$key] as $member => $score) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset(self::$sets[$key][$member]);
                $removedCount++;
            }
        }
        return $removedCount;
    }

    /**
     * Validate cache key
     */
    private function validateKey($key): void
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException('Cache key must be a string');
        }
    }
}
