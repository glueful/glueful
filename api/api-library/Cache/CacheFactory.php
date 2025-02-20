<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Cache;

use Glueful\Api\Library\Cache\Drivers\{CacheDriverInterface, RedisCacheDriver, MemcachedCacheDriver};
use Redis;
use Memcached;
use Exception;

/**
 * Cache Factory
 * 
 * Creates and configures cache driver instances based on configuration.
 * Supports Redis and Memcached implementations.
 */
class CacheFactory
{
    /**
     * Create cache driver instance
     * 
     * Initializes and configures appropriate cache driver based on config.
     * Handles connection setup and error handling.
     * 
     * @return CacheDriverInterface Configured cache driver
     * @throws Exception If cache type is not supported
     */
    public static function create(): CacheDriverInterface
    {
        $cacheType = config('cache.default', 'redis');

        if ($cacheType === 'redis') {
            $redis = new Redis();
            $host = config('cache.stores.redis.host') ?: env('REDIS_HOST', '127.0.0.1');
            $port = (int)(config('cache.stores.redis.port') ?: env('REDIS_PORT', 6379));
            
            $redis->connect($host, $port);
            return new RedisCacheDriver($redis);
        }

        if ($cacheType === 'memcached') {
            $memcached = new Memcached();
            $memcached->addServer(config('cache.stores.memcached.host'), config('cache.stores.memcached.port'));
            return new MemcachedCacheDriver($memcached);
        }

        throw new Exception("Unsupported cache type: {$cacheType}");
    }
}