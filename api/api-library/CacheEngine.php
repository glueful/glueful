<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class CacheEngine {
    private static ?\Memcached $memcached = null;
    private static bool $enabled = false;
    private static string $prefix = '';

    public static function initialize(string $prefix = ''): void 
    {
        if (!defined('CACHE_ENGINE') || !extension_loaded('memcached')) {
            return;
        }

        self::$prefix = $prefix;
        self::$enabled = true;
        
        if (CACHE_ENGINE === 'memcached') {
            self::$memcached = new \Memcached();
            self::$memcached->addServer('127.0.0.1', 11211);
            
            // Set some common options
            self::$memcached->setOptions([
                \Memcached::OPT_COMPRESSION => true,
                \Memcached::OPT_BINARY_PROTOCOL => true,
                \Memcached::OPT_TCP_NODELAY => true
            ]);
        }
    }

    public static function get(string $key): mixed 
    {
        if (!self::$enabled || !self::$memcached) {
            return null;
        }

        $value = self::$memcached->get(self::$prefix . $key);
        return self::$memcached->getResultCode() === \Memcached::RES_NOTFOUND ? null : $value;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool 
    {
        if (!self::$enabled || !self::$memcached) {
            return false;
        }

        return self::$memcached->set(self::$prefix . $key, $value, $ttl);
    }

    public static function delete(string $key): bool 
    {
        if (!self::$enabled || !self::$memcached) {
            return false;
        }

        return self::$memcached->delete(self::$prefix . $key);
    }

    public static function flush(): bool 
    {
        if (!self::$enabled || !self::$memcached) {
            return false;
        }

        return self::$memcached->flush();
    }

    public static function isEnabled(): bool 
    {
        return self::$enabled;
    }

    public static function getMemcached(): ?\Memcached 
    {
        return self::$memcached;
    }
}
