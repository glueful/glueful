<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Cache;

use Memcached;

class MemcachedCacheDriver implements CacheDriverInterface
{
    private Memcached $memcached;

    public function __construct(Memcached $memcached)
    {
        $this->memcached = $memcached;
    }

    public function zadd(string $key, array $scoreValues): bool
    {
        $timestamps = $this->memcached->get($key) ?? [];
        foreach ($scoreValues as $score => $value) {
            $timestamps[$value] = $score;
        }
        return $this->memcached->set($key, $timestamps);
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $timestamps = $this->memcached->get($key) ?? [];
        $filtered = array_filter($timestamps, fn($score) => $score > (int) $max);
        $this->memcached->set($key, $filtered);
        return count($timestamps) - count($filtered);
    }

    public function zcard(string $key): int
    {
        return count($this->memcached->get($key) ?? []);
    }

    public function zrange(string $key, int $start, int $stop): array
    {
        $timestamps = $this->memcached->get($key) ?? [];
        ksort($timestamps);
        return array_slice(array_keys($timestamps), $start, $stop - $start + 1);
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->memcached->touch($key, time() + $seconds);
    }

    public function del(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    public function get(string $key): mixed
    {
        $value = $this->memcached->get($key);
        return $this->memcached->getResultCode() === Memcached::RES_NOTFOUND ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->memcached->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    public function increment(string $key): bool
    {
        return $this->memcached->increment($key, 1, 1) !== false;
    }

    public function ttl(string $key): int
    {
        // Memcached doesn't provide direct TTL lookup
        return $this->get($key) !== null ? 3600 : 0;
    }

    public function flush(): bool
    {
        return $this->memcached->flush();
    }
}