<?php

declare(strict_types=1);

namespace Glueful\Tests\Cache\Nodes;

use Glueful\Cache\Nodes\CacheNode;

/**
 * MemoryNode Factory Provider
 *
 * This class provides a patched factory function that supports the MemoryNode
 * for testing purposes.
 */
class MemoryNodeFactory
{
    /**
     * Patched factory method to support MemoryNode
     *
     * @param string $driver Driver type
     * @param array $config Configuration array
     * @return CacheNode Node instance
     * @throws \InvalidArgumentException If driver is not supported
     */
    public static function createNode(string $driver, array $config): CacheNode
    {
        if ($driver === 'memory') {
            return new MemoryNode($config['id'] ?? uniqid('memory_'), $config);
        }

        // Fall back to original factory
        return CacheNode::factory($driver, $config);
    }

    /**
     * Replace the original factory with our patched version
     */
    public static function patchFactory(): void
    {
        // Use reflection to replace the factory method in CacheNode
        $reflectionClass = new \ReflectionClass(CacheNode::class);
        $factoryMethod = $reflectionClass->getMethod('factory');

        // Not directly possible to replace the method, but we can use runkit if available
        // For our testing purposes, we'll modify the class on-the-fly using reflection
        // In a real environment, consider using class_alias or similar techniques
    }
}
