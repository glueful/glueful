<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Cache;

use Redis;
use Memcached;
use Exception;

class CacheFactory
{
    public static function create(): CacheDriverInterface
    {
        $cacheType = config('cache.default', 'redis');

        if ($cacheType === 'redis') {
            $redis = new Redis();
            $redis->connect(config('cache.redis.host'), config('cache.redis.port'));
            return new RedisCacheDriver($redis);
        }

        if ($cacheType === 'memcached') {
            $memcached = new Memcached();
            $memcached->addServer(config('cache.memcached.host'), config('cache.memcached.port'));
            return new MemcachedCacheDriver($memcached);
        }

        throw new Exception("Unsupported cache type: {$cacheType}");
    }
}