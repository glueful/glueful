<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Cache\Drivers\{CacheDriverInterface, RedisCacheDriver, MemcachedCacheDriver};
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
     * @param string $driverOverride Optional driver override
     * @return CacheDriverInterface Configured cache driver
     * @throws Exception If cache type is not supported or connection fails
     */
    public static function create(string $driverOverride = ''): CacheDriverInterface
    {
        $cacheType = $driverOverride ?: config('cache.default', 'redis');

        if ($cacheType === 'redis') {
            $redis = new Redis();
            $host = config('cache.stores.redis.host') ?: env('REDIS_HOST', '127.0.0.1');
            $port = (int)(config('cache.stores.redis.port') ?: env('REDIS_PORT', 6379));
            $timeout = (float)(config('cache.stores.redis.timeout') ?: env('REDIS_TIMEOUT', 2.5));
            $password = config('cache.stores.redis.password') ?: env('REDIS_PASSWORD', null);

            try {
                // Set connection timeout to prevent long hangs
                $connected = $redis->connect($host, $port, $timeout);

                if (!$connected) {
                    throw new Exception("Failed to connect to Redis at {$host}:{$port}");
                }

                // Authenticate if password is set
                if (!empty($password)) {
                    $authenticated = $redis->auth($password);
                    if (!$authenticated) {
                        throw new Exception("Redis authentication failed");
                    }
                }

                // Select database if specified
                $database = (int)(config('cache.stores.redis.database') ?: env('REDIS_DB', 0));
                if ($database > 0) {
                    $redis->select($database);
                }

                // Test connection with a ping
                $ping = $redis->ping();
                if ($ping !== "+PONG" && $ping !== true) {
                    throw new Exception("Redis ping failed: " . var_export($ping, true));
                }

                return new RedisCacheDriver($redis);
            } catch (\RedisException $e) {
                throw new Exception("Redis connection error: " . $e->getMessage(), 0, $e);
            }
        }

        if ($cacheType === 'memcached') {
            try {
                $memcached = new Memcached();
                $host = config('cache.stores.memcached.host') ?: env('MEMCACHED_HOST', '127.0.0.1');
                $port = (int)(config('cache.stores.memcached.port') ?: env('MEMCACHED_PORT', 11211));

                $memcached->addServer($host, $port);

                // Check if server is added successfully
                $serverList = $memcached->getServerList();
                if (empty($serverList)) {
                    throw new Exception("Failed to add Memcached server at {$host}:{$port}");
                }

                // Check connection with a simple get operation
                $testKey = 'connection_test_' . uniqid();
                $memcached->set($testKey, 'test', 10);
                $testGet = $memcached->get($testKey);
                if ($testGet !== 'test') {
                    throw new Exception("Memcached connection test failed: " . $memcached->getResultMessage());
                }

                return new MemcachedCacheDriver($memcached);
            } catch (\Exception $e) {
                throw new Exception("Memcached connection error: " . $e->getMessage(), 0, $e);
            }
        }

        // Fall back to file-based caching if enabled
        if ($cacheType === 'file' || ($driverOverride === '' && config('cache.fallback_to_file', false))) {
            return self::createFileDriver();
        }

        throw new Exception("Unsupported cache type: {$cacheType}");
    }

    /**
     * Create a file-based cache driver as fallback
     *
     * @return CacheDriverInterface File-based cache driver
     */
    private static function createFileDriver(): CacheDriverInterface
    {
        if (!class_exists('\\Glueful\\Cache\\Drivers\\FileCacheDriver')) {
            throw new Exception("FileCacheDriver class not found");
        }

        $path = config('paths.storage_path', __DIR__ . '/../../storage') . '/cache/';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return new \Glueful\Cache\Drivers\FileCacheDriver($path);
    }
}
