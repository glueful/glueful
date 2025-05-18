<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

/** // phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder
 * Bootstrap file for Cache testing
 *
 * This file registers the MemoryNode implementation with the CacheNode factory
 */

namespace Glueful\Cache\Nodes;

// Define a custom extension to the factory method
if (!function_exists('Glueful\Cache\Nodes\CacheNode::factoryWithMemory')) {
    /**
     * Extended factory with memory support for testing
     *
     * @param string $driver Driver type
     * @param array $config Configuration array
     * @return CacheNode Node instance
     */
    function factoryWithMemory(string $driver, array $config): CacheNode
    {
        if ($driver === 'memory') {
            return new \Glueful\Tests\Cache\Nodes\MemoryNode($config['id'] ?? uniqid('memory_'), $config);
        }

        // Fall back to built-in drivers
        return match ($driver) {
            'redis' => new RedisNode($config['id'] ?? uniqid('redis_'), $config),
            'memcached' => new MemcachedNode($config['id'] ?? uniqid('memcached_'), $config),
            'file' => new FileNode($config['id'] ?? uniqid('file_'), $config),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
        };
    }
}

// Patch the CacheNode factory method using a monkey patch approach
// Since we can't directly override the static method, we'll use class_alias as a workaround
class_exists(\Glueful\Tests\Cache\Nodes\MemoryNode::class);

// Backup the original factory method if needed
if (!function_exists('Glueful\Cache\Nodes\CacheNode::originalFactory')) {
    function originalFactory(string $driver, array $config): CacheNode
    {
        return match ($driver) {
            'redis' => new RedisNode($config['id'] ?? uniqid('redis_'), $config),
            'memcached' => new MemcachedNode($config['id'] ?? uniqid('memcached_'), $config),
            'file' => new FileNode($config['id'] ?? uniqid('file_'), $config),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
        };
    }
}

// Monkey patch the factory method
CacheNode::factory('memory', ['id' => 'memory_test']);

// Override the factory method in CacheNode - this is a testing-only approach
// In a real application, proper extension mechanisms should be used
eval('namespace Glueful\Cache\Nodes {
    class CacheNode {
        public static function factory(string $driver, array $config): self
        {
            if ($driver === "memory") {
                return new \Glueful\Tests\Cache\Nodes\MemoryNode($config["id"] ?? uniqid("memory_"), $config);
            }
            
            // Fall back to original implementation
            return match ($driver) {
                "redis" => new RedisNode($config["id"] ?? uniqid("redis_"), $config),
                "memcached" => new MemcachedNode($config["id"] ?? uniqid("memcached_"), $config),
                "file" => new FileNode($config["id"] ?? uniqid("file_"), $config),
                default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
            };
        }
    }
}');
