<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Cache;

interface CacheDriverInterface
{
    // Basic cache operations
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 3600): bool;
    public function delete(string $key): bool;
    public function increment(string $key): bool;
    public function ttl(string $key): int;
    public function flush(): bool;
    
    // Redis sorted set operations
    public function zadd(string $key, array $scoreValues): bool;
    public function zremrangebyscore(string $key, string $min, string $max): int;
    public function zcard(string $key): int;
    public function zrange(string $key, int $start, int $stop): array;
    
    // Key operations
    public function expire(string $key, int $seconds): bool;
    public function del(string $key): bool;
}