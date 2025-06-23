<?php

declare(strict_types=1);

namespace Glueful\Tests\Cache\Drivers;

use Glueful\Cache\CacheStore;

/**
 * Memory Cache Driver for Testing
 *
 * A simple in-memory cache driver implementation for testing purposes.
 */
class MemoryCacheDriver implements CacheStore
{
    /** @var array In-memory storage */
    private $storage = [];

    /** @var array Expiration times */
    private $expires = [];

    /** @var array Sorted sets */
    private $sortedSets = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }
        return $this->storage[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $this->storage[$key] = $value;
        $this->expires[$key] = time() + $ttl;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (isset($this->storage[$key])) {
            unset($this->storage[$key]);
            unset($this->expires[$key]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            $this->storage[$key] = 1;
            return true;
        }

        if (!is_numeric($this->storage[$key])) {
            return false;
        }

        $this->storage[$key]++;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function ttl(string $key): int
    {
        if (!isset($this->expires[$key])) {
            return -2; // Key doesn't exist
        }

        $remaining = $this->expires[$key] - time();
        return $remaining > 0 ? $remaining : -1;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        $this->storage = [];
        $this->expires = [];
        $this->sortedSets = [];
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        if (!isset($this->sortedSets[$key])) {
            $this->sortedSets[$key] = [];
        }

        foreach ($scoreValues as $score => $value) {
            $this->sortedSets[$key][$value] = $score;
        }

        // Sort by score
        asort($this->sortedSets[$key]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        if (!isset($this->sortedSets[$key])) {
            return 0;
        }

        $count = 0;
        foreach ($this->sortedSets[$key] as $value => $score) {
            if ($score >= $min && $score <= $max) {
                unset($this->sortedSets[$key][$value]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function zcard(string $key): int
    {
        return isset($this->sortedSets[$key]) ? count($this->sortedSets[$key]) : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        if (!isset($this->sortedSets[$key])) {
            return [];
        }

        $values = array_keys($this->sortedSets[$key]);
        $length = count($values);

        // Adjust stop for inclusive indexing
        if ($stop >= 0) {
            $stop++;
        }

        // Handle negative indices
        if ($start < 0) {
            $start = $length + $start;
        }
        if ($stop < 0) {
            $stop = $length + $stop;
        }

        return array_slice($values, $start, $stop - $start);
    }

    /**
     * {@inheritdoc}
     */
    public function expire(string $key, int $seconds): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        $this->expires[$key] = time() + $seconds;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function del(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Check if key exists and is not expired
     *
     * @param string $key Cache key
     * @return bool True if key exists and is not expired
     */
    private function has(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        // Check expiration
        if (isset($this->expires[$key]) && time() > $this->expires[$key]) {
            $this->delete($key);
            return false;
        }

        return true;
    }
}
