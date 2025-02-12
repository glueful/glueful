<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

require_once dirname(__DIR__, 2) . '/api/bootstrap.php';

class CacheEngine {
    private static ?\Memcached $memcached = null;
    private static ?\Redis $redis = null;
    private static bool $enabled = false;
    private static string $prefix = '';
    private static string $driver = 'file';  // Default value

    public static function initialize(string $prefix = '', string $driver = ''): void 
    {
        if (!defined('CACHE_ENGINE')) {
            return;
        }

        $config = require_once dirname(__DIR__, 3) . '/config/cache.php';
        
        self::$prefix = $prefix ?: config('cache.prefix');
        self::$enabled = true;
        self::$driver = $driver ?: config('cache.default');
        
        match (self::$driver) {
            'memcached' => self::initializeMemcached(config('stores.memcached')),
            'redis' => self::initializeRedis(config('stores.redis')),
            default => throw new \InvalidArgumentException('Unsupported cache driver')
        };
    }

    private static function initializeMemcached(array $config): void
    {
        if (!extension_loaded('memcached')) {
            self::$enabled = false;
            return;
        }

        self::$memcached = new \Memcached(config('cache.stores.memcached.persistent_id') ?? '');
        
        // Add servers from configuration
        foreach (config('cache.stores.memcached.servers') as $server) {
            self::$memcached->addServer(
                $server['host'],
                $server['port'],
                $server['weight']
            );
        }
        
        // Set options
        if (!empty(config('cache.stores.memcached.options'))) {
            self::$memcached->setOptions(config('cache.stores.memcached.options'));
        }

        // Set SASL authentication if configured
        if (!empty($config('cache.stores.memcached.sasl')[0]) && !empty($config('cache.stores.memcached.sasl')[1])) {
            self::$memcached->setSaslAuthData($config('cache.stores.memcached.sasl')[0], $config('cache.stores.memcached.sasl')[1]);
        }
    }

    private static function initializeRedis(array $config): void
    {
        if (!extension_loaded('redis')) {
            self::$enabled = false;
            return;
        }

        self::$redis = new \Redis();
        try {
            self::$redis->connect(
                config('cache.stores.redis.host'),
                (int)config('cache.stores.redis.port')
            );
            
            if (!empty(config('cache.stores.redis.password'))) {
                self::$redis->auth(config('cache.stores.redis.password'));
            }

            if (null !== config('cache.stores.redis.database')) {
                self::$redis->select((int)config('cache.stores.redis.database'));
            }

            if (null !== config('cache.stores.redis.read_write_timeout')) {
                self::$redis->setOption(\Redis::OPT_READ_TIMEOUT, config('cache.stores.redis.read_write_timeout'));
            }
        } catch (\RedisException $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            self::$enabled = false;
        }
    }

    public static function get(string $key): mixed 
    {
        if (!self::$enabled) {
            return null;
        }

        $prefixedKey = self::$prefix . $key;
        return match (self::$driver) {
            'memcached' => self::getMemcached($prefixedKey),
            'redis' => self::getRedis($prefixedKey),
            default => null
        };
    }

    private static function getMemcached(string $key): mixed
    {
        if (!self::$memcached) return null;
        $value = self::$memcached->get($key);
        return self::$memcached->getResultCode() === \Memcached::RES_NOTFOUND ? null : $value;
    }

    private static function getRedis(string $key): mixed
    {
        if (!self::$redis) return null;
        $value = self::$redis->get($key);
        return $value === false ? null : unserialize($value);
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool 
    {
        if (!self::$enabled) {
            return false;
        }

        $prefixedKey = self::$prefix . $key;
        return match (self::$driver) {
            'memcached' => self::setMemcached($prefixedKey, $value, $ttl),
            'redis' => self::setRedis($prefixedKey, $value, $ttl),
            default => false
        };
    }

    private static function setMemcached(string $key, mixed $value, int $ttl): bool
    {
        return self::$memcached ? self::$memcached->set($key, $value, $ttl) : false;
    }

    private static function setRedis(string $key, mixed $value, int $ttl): bool
    {
        if (!self::$redis) return false;
        return self::$redis->setex($key, $ttl, serialize($value));
    }

    public static function delete(string $key): bool 
    {
        if (!self::$enabled) {
            return false;
        }

        $prefixedKey = self::$prefix . $key;
        return match (self::$driver) {
            'memcached' => self::$memcached?->delete($prefixedKey) ?? false,
            'redis' => self::$redis?->del($prefixedKey) > 0,
            default => false
        };
    }

    public static function flush(): bool 
    {
        if (!self::$enabled) {
            return false;
        }

        return match (self::$driver) {
            'memcached' => self::$memcached?->flush() ?? false,
            'redis' => self::$redis?->flushDB(),
            default => false
        };
    }

    public static function isEnabled(): bool 
    {
        return self::$enabled;
    }

    public static function getDriver(): string
    {
        return self::$driver;
    }

    public static function getInstance(): \Memcached|\Redis|null
    {
        return match (self::$driver) {
            'memcached' => self::$memcached,
            'redis' => self::$redis,
            default => null
        };
    }
}
