<?php

declare(strict_types=1);

namespace Glueful\Cache\Nodes;

use Memcached;

/**
 * Memcached Node
 *
 * Memcached implementation of a cache node.
 */
class MemcachedNode extends CacheNode
{
    /** @var Memcached Memcached client instance */
    private $memcached;

    /** @var bool Connection status */
    private $connected = false;

    /**
     * Initialize Memcached node
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
     * Connect to Memcached server
     *
     * @return bool True if connected successfully
     */
    private function connect(): bool
    {
        try {
            $this->memcached = new Memcached();

            $host = $this->config['host'] ?? '127.0.0.1';
            $port = (int)($this->config['port'] ?? 11211);

            $this->memcached->addServer($host, $port);

            // Check if server is added successfully
            $serverList = $this->memcached->getServerList();
            if (empty($serverList)) {
                error_log("Failed to add Memcached server for node {$this->id} at {$host}:{$port}");
                return false;
            }

            // Check connection with a simple get operation
            $testKey = 'connection_test_' . uniqid();
            $this->memcached->set($testKey, 'test', 10);
            $testGet = $this->memcached->get($testKey);

            if ($testGet !== 'test') {
                error_log("Memcached connection test failed for node {$this->id}: " .
                    $this->memcached->getResultMessage());
                return false;
            }

            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            error_log("Memcached connection error for node {$this->id}: " . $e->getMessage());
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
            return $this->memcached->set($key, $value, $ttl);
        } catch (\Exception $e) {
            error_log("Memcached set error for node {$this->id}: " . $e->getMessage());
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
            $result = $this->memcached->get($key);

            if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                return null;
            }

            return $result;
        } catch (\Exception $e) {
            error_log("Memcached get error for node {$this->id}: " . $e->getMessage());
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
            return $this->memcached->delete($key);
        } catch (\Exception $e) {
            error_log("Memcached delete error for node {$this->id}: " . $e->getMessage());
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
            return $this->memcached->flush();
        } catch (\Exception $e) {
            error_log("Memcached clear error for node {$this->id}: " . $e->getMessage());
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
            $this->memcached->get($key);
            return $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND;
        } catch (\Exception $e) {
            error_log("Memcached exists error for node {$this->id}: " . $e->getMessage());
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
            'driver' => 'memcached',
            'connected' => $this->connected,
            'host' => $this->config['host'] ?? '127.0.0.1',
            'port' => $this->config['port'] ?? 11211,
        ];

        if ($this->connected) {
            try {
                $serverStats = $this->memcached->getStats();

                if (!empty($serverStats)) {
                    $host = key($serverStats);
                    $stats = $serverStats[$host];

                    $status['version'] = $stats['version'] ?? 'unknown';
                    $status['uptime'] = $stats['uptime'] ?? 0;
                    $status['curr_connections'] = $stats['curr_connections'] ?? 0;
                    $status['curr_items'] = $stats['curr_items'] ?? 0;
                    $status['bytes'] = $stats['bytes'] ?? 0;
                    $status['limit_maxbytes'] = $stats['limit_maxbytes'] ?? 0;
                }
            } catch (\Exception $e) {
                $status['error'] = $e->getMessage();
            }
        }

        return $status;
    }

    /**
     * Add a key to a tag set
     *
     * Memcached doesn't support sorted sets natively,
     * so we implement this using regular key-value pairs.
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
            // Get existing tag set
            $tagSet = $this->memcached->get($tag);

            if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                $tagSet = [];
            }

            // Add the key with score
            $tagSet[$key] = $score;

            // Store updated tag set
            return $this->memcached->set($tag, $tagSet, 0);
        } catch (\Exception $e) {
            error_log("Memcached addTaggedKey error for node {$this->id}: " . $e->getMessage());
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
            $tagSet = $this->memcached->get($tag);

            if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                return [];
            }

            return array_keys($tagSet);
        } catch (\Exception $e) {
            error_log("Memcached getTaggedKeys error for node {$this->id}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the Memcached client instance
     *
     * @return Memcached Memcached client
     */
    public function getMemcached(): Memcached
    {
        return $this->memcached;
    }
}
