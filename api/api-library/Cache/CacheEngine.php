<?php

declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\Cache\CacheDriverInterface;
use Glueful\Api\Library\Cache\CacheFactory;

class CacheEngine
{
    private static ?CacheDriverInterface $driver = null;
    private static bool $enabled = false;
    private static string $prefix = '';

    public static function initialize(string $prefix = '', string $driver = ''): void 
    {
        if (!defined('CACHE_ENGINE')) {
            return;
        }

        self::$prefix = $prefix ?: config('cache.prefix');
        
        try {
            self::$driver = CacheFactory::create();
            self::$enabled = true;
        } catch (\Exception $e) {
            error_log("Cache initialization failed: " . $e->getMessage());
            self::$enabled = false;
        }
    }

    private static function ensureEnabled(): bool
    {
        return self::$enabled && self::$driver !== null;
    }

    public static function get(string $key): mixed 
    {
        return self::ensureEnabled() ? self::$driver->get(self::$prefix . $key) : null;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool 
    {
        return self::ensureEnabled() ? self::$driver->set(self::$prefix . $key, $value, $ttl) : false;
    }

    public static function delete(string $key): bool 
    {
        return self::ensureEnabled() ? self::$driver->delete(self::$prefix . $key) : false;
    }

    public static function increment(string $key): bool
    {
        return self::ensureEnabled() ? self::$driver->increment(self::$prefix . $key) : false;
    }

    public static function ttl(string $key): int
    {
        return self::ensureEnabled() ? self::$driver->ttl(self::$prefix . $key) : 0;
    }

    public static function flush(): bool 
    {
        return self::ensureEnabled() ? self::$driver->flush() : false;
    }

    public static function zremrangebyscore(string $key, string $min, string $max): bool
    {
        return self::ensureEnabled() ? self::$driver->zremrangebyscore(self::$prefix . $key, $min, $max) > 0 : false;
    }

    public static function zcard(string $key): int
    {
        return self::ensureEnabled() ? self::$driver->zcard(self::$prefix . $key) : 0;
    }

    public static function zadd(string $key, array $members): bool
    {
        return self::ensureEnabled() ? self::$driver->zadd(self::$prefix . $key, $members) : false;
    }

    public static function expire(string $key, int $seconds): bool
    {
        return self::ensureEnabled() ? self::$driver->expire(self::$prefix . $key, $seconds) : false;
    }

    public static function zrange(string $key, int $start, int $stop): array
    {
        return self::ensureEnabled() ? self::$driver->zrange(self::$prefix . $key, $start, $stop) : [];
    }

    public static function isEnabled(): bool 
    {
        return self::ensureEnabled();
    }
}
