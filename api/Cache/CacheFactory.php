<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Cache\Drivers\{CacheDriverInterface, RedisCacheDriver, MemcachedCacheDriver};
use Redis;
use Memcached;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\DatabaseException;

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
     * @throws \Glueful\Exceptions\DatabaseException If connection fails
     * @throws \Glueful\Exceptions\BusinessLogicException If cache type is not supported
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
                    throw DatabaseException::connectionFailed(
                        "Failed to connect to Redis at {$host}:{$port}"
                    );
                }

                // Authenticate if password is set
                if (!empty($password)) {
                    $authenticated = $redis->auth($password);
                    if (!$authenticated) {
                        throw DatabaseException::connectionFailed(
                            'Redis authentication failed'
                        );
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
                    throw DatabaseException::connectionFailed(
                        "Redis ping failed: " . var_export($ping, true)
                    );
                }

                return new RedisCacheDriver($redis);
            } catch (\RedisException $e) {
                throw DatabaseException::connectionFailed(
                    "Redis connection error: " . $e->getMessage(),
                    $e
                );
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
                    throw DatabaseException::connectionFailed(
                        "Failed to add Memcached server at {$host}:{$port}"
                    );
                }

                // Check connection with a simple get operation
                $testKey = 'connection_test_' . uniqid();
                $memcached->set($testKey, 'test', 10);
                $testGet = $memcached->get($testKey);
                if ($testGet !== 'test') {
                    throw DatabaseException::connectionFailed(
                        "Memcached connection test failed: " . $memcached->getResultMessage()
                    );
                }

                return new MemcachedCacheDriver($memcached);
            } catch (\Exception $e) {
                throw DatabaseException::connectionFailed(
                    "Memcached connection error: " . $e->getMessage(),
                    $e
                );
            }
        }

        // Fall back to file-based caching if enabled
        if ($cacheType === 'file' || ($driverOverride === '' && config('cache.fallback_to_file', false))) {
            return self::createFileDriver();
        }

        throw BusinessLogicException::operationNotAllowed(
            'cache_creation',
            "Unsupported cache type: {$cacheType}"
        );
    }

    /**
     * Create a file-based cache driver as fallback
     *
     * @return CacheDriverInterface File-based cache driver
     */
    private static function createFileDriver(): CacheDriverInterface
    {
        if (!class_exists('\\Glueful\\Cache\\Drivers\\FileCacheDriver')) {
            throw BusinessLogicException::operationNotAllowed(
                'cache_creation',
                'FileCacheDriver class not found'
            );
        }

        $path = config('app.paths.storage_path', __DIR__ . '/../../storage') . '/cache/';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return new \Glueful\Cache\Drivers\FileCacheDriver($path);
    }
}
