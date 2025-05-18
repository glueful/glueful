<?php

declare(strict_types=1);

namespace Glueful\Cache\Nodes;

use Glueful\Cache\Nodes\RedisNode;
use Glueful\Cache\Nodes\MemcachedNode;
use Glueful\Cache\Nodes\FileNode;

/**
 * Cache Node
 *
 * Abstract representation of a cache node in a distributed system.
 * Provides common interface for all node implementations.
 */
abstract class CacheNode
{
    /** @var string Unique node identifier */
    protected $id;

    /** @var array Node configuration */
    protected $config;

    /**
     * Initialize cache node
     *
     * @param string $id Node identifier
     * @param array $config Node configuration
     */
    public function __construct(string $id, array $config)
    {
        $this->id = $id;
        $this->config = $config;
    }

    /**
     * Get node identifier
     *
     * @return string Node ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get node configuration
     *
     * @return array Configuration array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool True if set successfully
     */
    abstract public function set(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    abstract public function get(string $key);

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    abstract public function delete(string $key): bool;

    /**
     * Clear all cached values
     *
     * @return bool True if cleared successfully
     */
    abstract public function clear(): bool;

    /**
     * Check if key exists
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    abstract public function exists(string $key): bool;

    /**
     * Get node status
     *
     * @return array Status information
     */
    abstract public function getStatus(): array;

    /**
     * Add a key to a tag set
     *
     * @param string $tag Tag name
     * @param string $key Key to add
     * @param int $score Score for sorted set (typically timestamp)
     * @return bool True if added successfully
     */
    abstract public function addTaggedKey(string $tag, string $key, int $score): bool;

    /**
     * Get keys from a tag set
     *
     * @param string $tag Tag name
     * @return array Keys in the tag set
     */
    abstract public function getTaggedKeys(string $tag): array;

    /**
     * Create appropriate node instance based on driver type
     *
     * @param string $driver Driver type
     * @param array $config Configuration array
     * @return self Node instance
     * @throws \InvalidArgumentException If driver is not supported
     */
    public static function factory(string $driver, array $config): self
    {
        return match ($driver) {
            'redis' => new RedisNode($config['id'] ?? uniqid('redis_'), $config),
            'memcached' => new MemcachedNode($config['id'] ?? uniqid('memcached_'), $config),
            'file' => new FileNode($config['id'] ?? uniqid('file_'), $config),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
        };
    }
}
