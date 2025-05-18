<?php

declare(strict_types=1);

namespace Glueful\Tests\Cache\Helpers;

use Glueful\Cache\Nodes\CacheNode;
use Glueful\Tests\Cache\Nodes\MemoryNode;

/**
 * Helper class to patch CacheNode for testing
 */
class CacheNodePatcher
{
    /**
     * Register the MemoryNode type for testing
     *
     * @return bool True if successfully patched
     */
    public static function registerMemoryNode(): bool
    {
        // Check if runkit7 extension is loaded
        if (extension_loaded('runkit7') || extension_loaded('runkit')) {
            // Use runkit to modify the factory method
            $extensionName = extension_loaded('runkit7') ? 'runkit7' : 'runkit';

            if (function_exists($extensionName . '_method_redefine')) {
                // Redefine the factory method to include memory nodes
                $success = $extensionName . '_method_redefine'(
                    CacheNode::class,
                    'factory',
                    '$driver, $config',
                    'if ($driver === "memory") {
                        return new \Glueful\Tests\Cache\Nodes\MemoryNode($config["id"] ?? uniqid("memory_"), $config);
                    }
                    
                    return match ($driver) {
                        "redis" => new \Glueful\Cache\Nodes\RedisNode(
                            $config["id"] ?? uniqid("redis_"), 
                            $config
                        ),
                        "memcached" => new \Glueful\Cache\Nodes\MemcachedNode($config["id"] ?? uniqid("memcached_"), 
                        $config),
                        "file" => new \Glueful\Cache\Nodes\FileNode($config["id"] ?? uniqid("file_"), $config),
                        default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}")
                    };'
                );

                return $success !== false;
            }
        }

        // If runkit is not available, use a different approach
        // We'll monkey patch the factory method using inheritance and autoloader magic

        // Create an autoloader function that maps CacheNode to our test version
        spl_autoload_register(function ($class) {
            if ($class === CacheNode::class) {
                require_once __DIR__ . '/../Nodes/TestCacheNode.php';
                class_alias('\Glueful\Tests\Cache\Nodes\TestCacheNode', $class);
                return true;
            }
            return false;
        }, true, true);

        return true;
    }
}
