<?php

namespace Glueful\Performance;

use function config;

class MemoryPool
{
    /**
     * In-memory object storage
     *
     * @var array
     */
    private $pool = [];

    /**
     * Maximum number of objects in the pool
     *
     * @var int
     */
    private $maxSize;

    /**
     * Current number of objects in the pool
     *
     * @var int
     */
    private $currentSize = 0;

    /**
     * Tracking when items were last accessed (for LRU)
     *
     * @var array
     */
    private $lastAccessed = [];

    /**
     * Initialize the memory pool
     *
     * @param int|null $maxSize Maximum number of objects in the pool
     */
    public function __construct(?int $maxSize = null)
    {
        $this->maxSize = $maxSize ?? config('performance.memory.pool_size', 100);
    }

    /**
     * Add an object to the pool
     *
     * @param string $key Unique identifier for the object
     * @param mixed $object The object to store
     * @return bool Whether the operation was successful
     */
    public function add(string $key, $object): bool
    {
        if ($this->currentSize >= $this->maxSize && !isset($this->pool[$key])) {
            $this->evict();
        }

        $isNewItem = !isset($this->pool[$key]);
        $this->pool[$key] = $object;
        $this->lastAccessed[$key] = microtime(true);

        if ($isNewItem) {
            $this->currentSize++;
        }

        return true;
    }

    /**
     * Retrieve an object from the pool
     *
     * @param string $key Identifier of the object to retrieve
     * @return mixed The object or null if not found
     */
    public function get(string $key)
    {
        if (isset($this->pool[$key])) {
            // Update access time for LRU algorithm
            $this->lastAccessed[$key] = microtime(true);
            return $this->pool[$key];
        }

        return null;
    }

    /**
     * Check if an object exists in the pool
     *
     * @param string $key Identifier to check
     * @return bool Whether the object exists in the pool
     */
    public function has(string $key): bool
    {
        return isset($this->pool[$key]);
    }

    /**
     * Remove an object from the pool
     *
     * @param string $key Identifier of the object to remove
     * @return bool Whether the object was removed
     */
    public function remove(string $key): bool
    {
        if (isset($this->pool[$key])) {
            unset($this->pool[$key]);
            unset($this->lastAccessed[$key]);
            $this->currentSize--;
            return true;
        }

        return false;
    }

    /**
     * Clear all objects from the pool
     *
     * @return void
     */
    public function clear(): void
    {
        $this->pool = [];
        $this->lastAccessed = [];
        $this->currentSize = 0;
    }

    /**
     * Get the number of objects in the pool
     *
     * @return int Current pool size
     */
    public function size(): int
    {
        return $this->currentSize;
    }

    /**
     * Get the maximum capacity of the pool
     *
     * @return int Maximum pool size
     */
    public function capacity(): int
    {
        return $this->maxSize;
    }

    /**
     * Evict items from the pool using LRU (Least Recently Used) algorithm
     *
     * @return void
     */
    private function evict(): void
    {
        if (empty($this->lastAccessed)) {
            return;
        }

        // Find the least recently accessed key
        $leastRecentKey = null;
        $leastRecentTime = PHP_FLOAT_MAX;

        foreach ($this->lastAccessed as $key => $accessTime) {
            if ($accessTime < $leastRecentTime) {
                $leastRecentTime = $accessTime;
                $leastRecentKey = $key;
            }
        }

        if ($leastRecentKey !== null) {
            $this->remove($leastRecentKey);
        }
    }

    /**
     * Get a list of all keys in the pool
     *
     * @return array Array of key strings
     */
    public function getKeys(): array
    {
        return array_keys($this->pool);
    }

    /**
     * Get pool statistics
     *
     * @return array Statistics including size, capacity, and usage
     */
    public function getStats(): array
    {
        return [
            'current_size' => $this->currentSize,
            'max_size' => $this->maxSize,
            'usage_percentage' => $this->maxSize > 0 ? ($this->currentSize / $this->maxSize) * 100 : 0,
            'keys' => count($this->pool),
        ];
    }
}
