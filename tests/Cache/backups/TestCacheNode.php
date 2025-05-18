<?php

declare(strict_types=1);

namespace Glueful\Tests\Cache\Nodes;

use Glueful\Cache\Nodes\CacheNode;
use Glueful\Cache\Nodes\RedisNode;
use Glueful\Cache\Nodes\MemcachedNode;
use Glueful\Cache\Nodes\FileNode;

/**
 * Extended CacheNode for testing
 *
 * This class extends the standard CacheNode to add support for memory nodes in tests
 */
class TestCacheNode extends CacheNode
{
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return true;
    }

    public function get(string $key)
    {
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function exists(string $key): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return [];
    }

    public function addTaggedKey(string $tag, string $key, int $score): bool
    {
        return true;
    }

    public function getTaggedKeys(string $tag): array
    {
        return [];
    }
    /**
     * Override the factory method to add memory support
     *
     * @param string $driver Driver type
     * @param array $config Configuration array
     * @return CacheNode Node instance
     * @throws \InvalidArgumentException If driver is not supported
     */
    public static function factory(string $driver, array $config): CacheNode
    {
        if ($driver === 'memory') {
            return new MemoryNode($config['id'] ?? uniqid('memory_'), $config);
        }

        // Fall back to parent implementation via match
        return match ($driver) {
            'redis' => new RedisNode($config['id'] ?? uniqid('redis_'), $config),
            'memcached' => new MemcachedNode($config['id'] ?? uniqid('memcached_'), $config),
            'file' => new FileNode($config['id'] ?? uniqid('file_'), $config),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
        };
    }
}
