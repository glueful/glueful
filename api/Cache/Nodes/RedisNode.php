<?php

declare(strict_types=1);

namespace Glueful\Cache\Nodes;

use Redis;

/**
 * Redis Node
 *
 * Redis implementation of a cache node.
 */
class RedisNode extends CacheNode
{
    /** @var Redis Redis client instance */
    private $redis;

    /** @var bool Connection status */
    private $connected = false;

    /**
     * Initialize Redis node
     *
     * @param string $id Node identifier
     * @param array $config Node configuration
     */
    public function __construct(string $id, array $config)
    {
        parent::__construct($id, $config);
        $this->connect();
    }

    /**
     * Connect to Redis server
     *
     * @return bool True if connected successfully
     */
    private function connect(): bool
    {
        $this->redis = new Redis();

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int)($this->config['port'] ?? 6379);
        $timeout = (float)($this->config['timeout'] ?? 2.5);
        $password = $this->config['password'] ?? null;
        $database = (int)($this->config['database'] ?? 0);

        try {
            $connected = $this->redis->connect($host, $port, $timeout);

            if (!$connected) {
                error_log("Failed to connect to Redis node {$this->id} at {$host}:{$port}");
                return false;
            }

            // Authenticate if password is set
            if (!empty($password)) {
                $authenticated = $this->redis->auth($password);
                if (!$authenticated) {
                    error_log("Redis authentication failed for node {$this->id}");
                    return false;
                }
            }

            // Select database if specified
            if ($database > 0) {
                $this->redis->select($database);
            }

            // Test connection with a ping
            $ping = $this->redis->ping();
            if ($ping !== "+PONG" && $ping !== true) {
                error_log("Redis ping failed for node {$this->id}: " . var_export($ping, true));
                return false;
            }

            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            error_log("Redis connection error for node {$this->id}: " . $e->getMessage());
            $this->connected = false;
            return false;
        }
    }

    /**
     * Ensure connection is active
     *
     * @return bool True if connected
     */
    private function ensureConnected(): bool
    {
        if (!$this->connected) {
            return $this->connect();
        }

        return true;
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool True if set successfully
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $value);
            } else {
                return $this->redis->set($key, $value);
            }
        } catch (\Exception $e) {
            error_log("Redis set error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public function get(string $key)
    {
        if (!$this->ensureConnected()) {
            return null;
        }

        try {
            $value = $this->redis->get($key);
            return $value !== false ? $value : null;
        } catch (\Exception $e) {
            error_log("Redis get error for node {$this->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function delete(string $key): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $result = $this->redis->del($key);
            return is_numeric($result) && (int)$result > 0;
        } catch (\Exception $e) {
            error_log("Redis delete error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cached values
     *
     * @return bool True if cleared successfully
     */
    public function clear(): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            return $this->redis->flushDb();
        } catch (\Exception $e) {
            error_log("Redis clear error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if key exists
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function exists(string $key): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            return (bool)$this->redis->exists($key);
        } catch (\Exception $e) {
            error_log("Redis exists error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get node status
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        $status = [
            'id' => $this->id,
            'driver' => 'redis',
            'connected' => $this->connected,
            'host' => $this->config['host'] ?? '127.0.0.1',
            'port' => $this->config['port'] ?? 6379,
            'database' => $this->config['database'] ?? 0,
        ];

        if ($this->connected) {
            try {
                $info = $this->redis->info();
                $status['version'] = $info['redis_version'] ?? 'unknown';
                $status['memory_used'] = $info['used_memory_human'] ?? 'unknown';
                $status['uptime'] = $info['uptime_in_seconds'] ?? 0;
                $status['clients'] = $info['connected_clients'] ?? 0;
                $status['keys'] = $info['db' . ($this->config['database'] ?? 0)] ?? 'unknown';
            } catch (\Exception $e) {
                $status['error'] = $e->getMessage();
            }
        }

        return $status;
    }

    /**
     * Add a key to a tag set
     *
     * @param string $tag Tag name
     * @param string $key Key to add
     * @param int $score Score for sorted set (typically timestamp)
     * @return bool True if added successfully
     */
    public function addTaggedKey(string $tag, string $key, int $score): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            return $this->redis->zAdd($tag, $score, $key) !== false;
        } catch (\Exception $e) {
            error_log("Redis zAdd error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get keys from a tag set
     *
     * @param string $tag Tag name
     * @return array Keys in the tag set
     */
    public function getTaggedKeys(string $tag): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        try {
            return $this->redis->zRange($tag, 0, -1);
        } catch (\Exception $e) {
            error_log("Redis zRange error for node {$this->id}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the Redis client instance
     *
     * @return Redis Redis client
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }
}
