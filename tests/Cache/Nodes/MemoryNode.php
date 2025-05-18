<?php

declare(strict_types=1);

namespace Glueful\Tests\Cache\Nodes;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Memory Cache Node for Testing
 *
 * A simple in-memory cache node implementation for testing purposes.
 */
class MemoryNode extends CacheNode
{
    /** @var array In-memory storage */
    private $storage = [];

    /** @var array Expiration times */
    private $expires = [];

    /** @var array Tag sets */
    private $tags = [];

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $this->storage[$key] = $value;
        $this->expires[$key] = time() + $ttl;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key)
    {
        if (!$this->exists($key)) {
            return null;
        }
        return $this->storage[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (isset($this->storage[$key])) {
            unset($this->storage[$key]);
            unset($this->expires[$key]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->storage = [];
        $this->expires = [];
        $this->tags = [];
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        // Check expiration
        if (isset($this->expires[$key]) && time() > $this->expires[$key]) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(): array
    {
        return [
            'type' => 'memory',
            'items' => count($this->storage),
            'uptime' => 0,
            'memory_usage' => 0,
            'hit_ratio' => 0
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function addTaggedKey(string $tag, string $key, int $score): bool
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        $this->tags[$tag][$key] = $score;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getTaggedKeys(string $tag): array
    {
        return array_keys($this->tags[$tag] ?? []);
    }
}
