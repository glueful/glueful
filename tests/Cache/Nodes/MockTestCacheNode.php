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
class MockTestCacheNode extends CacheNode
{
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return true;
    }

    public function get(string $key)
    {
        return null;
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
        // For tests, redirect all drivers to memory nodes to avoid actual Redis connections
        if ($driver === 'memory') {
            return new MemoryNode($config['id'] ?? uniqid('memory_'), $config);
        }

        // For other drivers in test, use memory nodes instead of actual implementations
        return new MemoryNode($config['id'] ?? uniqid($driver . '_'), $config);
    }
}
