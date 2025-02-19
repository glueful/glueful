<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Cache;

use Redis;

class RedisCacheDriver implements CacheDriverInterface
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function zadd(string $key, array $scoreValues): bool
    {
        return $this->redis->zAdd($key, ...array_merge(...array_map(null, array_values($scoreValues), array_keys($scoreValues))));
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return $this->redis->zRemRangeByScore($key, $min, $max);
    }

    public function zcard(string $key): int
    {
        return (int) $this->redis->zCard($key);
    }

    public function zrange(string $key, int $start, int $stop): array
    {
        return $this->redis->zRange($key, $start, $stop);
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->redis->expire($key, $seconds);
    }

    public function del(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);
        return $value === false ? null : unserialize($value);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->redis->setex($key, $ttl, serialize($value));
    }

    public function delete(string $key): bool
    {
        return $this->del($key);
    }

    public function increment(string $key): bool
    {
        return $this->redis->incr($key) !== false;
    }

    public function ttl(string $key): int
    {
        return max(0, (int)$this->redis->ttl($key));
    }

    public function flush(): bool
    {
        return $this->redis->flushDB();
    }
}