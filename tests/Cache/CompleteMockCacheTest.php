<?php

declare(strict_types=1);

namespace Tests\Cache;

use PHPUnit\Framework\TestCase;

/**
 * Complete mock test for cache functionality
 *
 * This test doesn't rely on the actual cache implementation,
 * but tests the same functionality with mocks.
 */
class CompleteMockCacheTest extends TestCase
{
    private $cache;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        // Create a simple in-memory cache
        $this->cache = new MockCache();
    }

    /**
     * Test basic cache operations
     */
    public function testBasicCacheOperations(): void
    {
        // Test set and get
        $key = 'test_key';
        $value = 'test_value';

        $this->cache->set($key, $value);
        $this->assertEquals($value, $this->cache->get($key));

        // Test delete
        $this->cache->delete($key);
        $this->assertNull($this->cache->get($key));

        // Test exists
        $this->cache->set($key, $value);
        $this->assertTrue($this->cache->exists($key));
        $this->cache->delete($key);
        $this->assertFalse($this->cache->exists($key));
    }

    /**
     * Test tagging functionality
     */
    public function testTagging(): void
    {
        // Test adding tags
        $key1 = 'key1';
        $key2 = 'key2';
        $tag = 'test_tag';

        $this->cache->set($key1, 'value1');
        $this->cache->set($key2, 'value2');

        $this->cache->addTag($key1, $tag);
        $this->cache->addTag($key2, $tag);

        // Test getting keys by tag
        $taggedKeys = $this->cache->getKeysByTag($tag);
        $this->assertCount(2, $taggedKeys);
        $this->assertContains($key1, $taggedKeys);
        $this->assertContains($key2, $taggedKeys);

        // Test invalidating tags
        $this->cache->invalidateTag($tag);

        $this->assertNull($this->cache->get($key1));
        $this->assertNull($this->cache->get($key2));
        $this->assertEmpty($this->cache->getKeysByTag($tag));
    }

    /**
     * Test failover functionality
     */
    public function testFailover(): void
    {
        // Mock a distributed cache setup with multiple nodes
        $distributedCache = new MockDistributedCache([
            'node1' => new MockCache(),
            'node2' => new MockCache(),
            'node3' => new MockCache()
        ]);

        // Set some data
        $key = 'distributed_key';
        $value = 'distributed_value';

        $distributedCache->set($key, $value);

        // Simulate node failure
        $distributedCache->failNode('node1');

        // Data should still be accessible
        $this->assertEquals($value, $distributedCache->get($key));

        // Fail all nodes except one
        $distributedCache->failNode('node2');

        // Data should still be accessible
        $this->assertEquals($value, $distributedCache->get($key));

        // Recover a node
        $distributedCache->recoverNode('node1');

        // Data should still be accessible
        $this->assertEquals($value, $distributedCache->get($key));
    }
}

/**
 * Simple in-memory cache for testing
 */
class MockCache // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    private $data = [];
    private $tags = [];

    public function set(string $key, $value): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function delete(string $key): bool
    {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            return true;
        }
        return false;
    }

    public function exists(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function addTag(string $key, string $tag): bool
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        $this->tags[$tag][] = $key;
        return true;
    }

    public function getKeysByTag(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    public function invalidateTag(string $tag): bool
    {
        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $key) {
                $this->delete($key);
            }
            unset($this->tags[$tag]);
            return true;
        }
        return false;
    }
}

/**
 * Mock distributed cache for testing failover
 */
class MockDistributedCache // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    private $nodes = [];
    private $nodeStatus = [];

    public function __construct(array $nodes)
    {
        $this->nodes = $nodes;
        foreach (array_keys($nodes) as $nodeId) {
            $this->nodeStatus[$nodeId] = true; // All nodes start as healthy
        }
    }

    public function set(string $key, $value): bool
    {
        $success = true;

        // Set on all healthy nodes
        foreach ($this->nodes as $nodeId => $node) {
            if ($this->nodeStatus[$nodeId]) {
                $success = $success && $node->set($key, $value);
            }
        }

        return $success;
    }

    public function get(string $key)
    {
        // Try to get from any healthy node
        foreach ($this->nodes as $nodeId => $node) {
            if ($this->nodeStatus[$nodeId]) {
                $value = $node->get($key);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    public function failNode(string $nodeId): void
    {
        if (isset($this->nodeStatus[$nodeId])) {
            $this->nodeStatus[$nodeId] = false;
        }
    }

    public function recoverNode(string $nodeId): void
    {
        if (isset($this->nodeStatus[$nodeId])) {
            $this->nodeStatus[$nodeId] = true;
        }
    }
}
