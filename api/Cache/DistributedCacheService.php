<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Health\HealthMonitoringService;
use Glueful\Helpers\CacheHelper;

/**
 * Distributed Cache Service
 *
 * Provides a distributed caching system that coordinates multiple cache nodes
 * with support for different replication strategies.
 */
class DistributedCacheService implements CacheStore
{
    /** @var CacheStore Primary cache store */
    private CacheStore $primaryCache;

    /** @var Nodes\CacheNodeManager Node management service */
    private $nodeManager;

    /** @var string Replication strategy to use */
    private string $replicationStrategy;

    /** @var bool Whether failover is enabled */
    private bool $failoverEnabled = false;

    /** @var HealthMonitoringService|null Health monitoring service */
    private ?HealthMonitoringService $healthMonitor = null;




    /**
     * Initialize the distributed cache service
     *
     * @param CacheStore|null $primaryCache Primary cache store
     * @param array $config Configuration parameters
     */
    public function __construct(?CacheStore $primaryCache = null, array $config = [])
    {
        // Set up cache - try provided instance or get from container
        $this->primaryCache = $primaryCache ?? $this->createCacheInstance();

        if ($this->primaryCache === null) {
            throw new \RuntimeException(
                'CacheStore is required for distributed cache service. '
                . 'Please ensure cache is properly configured or provide a CacheStore instance.'
            );
        }

        $this->nodeManager = new Nodes\CacheNodeManager($config['nodes'] ?? []);
        $this->replicationStrategy = $config['replication'] ?? 'consistent-hashing';
        $this->failoverEnabled = $config['failover'] ?? false;

        // Configure replication strategies
        $this->configureStrategies($config['strategies'] ?? []);

        // Initialize health monitoring
        $this->initializeHealthMonitoring($config['health'] ?? []);
    }

    /**
     * Configure replication strategies
     *
     * @param array $strategies Strategies configuration
     * @return void
     */
    private function configureStrategies(array $strategies): void
    {
        foreach ($strategies as $name => $config) {
            $this->nodeManager->configureStrategy($name, $config);
        }
    }

    /**
     * Create cache instance with proper fallback handling
     *
     * @return CacheStore|null Cache instance or null if unavailable
     */
    private function createCacheInstance(): ?CacheStore
    {
        try {
            return container()->get(CacheStore::class);
        } catch (\Exception) {
            // Try using CacheHelper as fallback
            return CacheHelper::createCacheInstance();
        }
    }

    /**
     * Initialize health monitoring
     *
     * @param array $config Health monitoring configuration
     * @return void
     */
    private function initializeHealthMonitoring(array $config): void
    {
        $this->healthMonitor = new HealthMonitoringService($this->nodeManager, $config);
    }

    /**
     * Set value across the distributed cache system
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if value was set on all nodes
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);

        // Set in primary cache first
        $success = $this->primaryCache->set($key, $value, $ttlSeconds);

        // Use health monitoring if enabled to get healthy nodes
        $nodes = $this->failoverEnabled && $this->healthMonitor !== null
            ? $this->nodeManager->getHealthyNodesForKey($key, $this->replicationStrategy)
            : $this->nodeManager->getNodesForKey($key, $this->replicationStrategy);

        foreach ($nodes as $node) {
            $success = $success && $node->set($key, $value, $ttlSeconds);
        }

        return $success;
    }

    /**
     * Get value from the distributed cache system
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default if not found
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Try primary cache first
        $value = $this->primaryCache->get($key);
        if ($value !== null) {
            return $value;
        }

        // Use health monitoring if enabled to get healthy nodes
        $nodes = $this->failoverEnabled && $this->healthMonitor !== null
            ? $this->nodeManager->getHealthyNodesForKey($key, $this->replicationStrategy)
            : $this->nodeManager->getNodesForKey($key, $this->replicationStrategy);

        foreach ($nodes as $node) {
            $value = $node->get($key);
            if ($value !== null) {
                // Update primary cache with found value
                $this->primaryCache->set($key, $value);
                return $value;
            }
        }

        return $default;
    }

    /**
     * Delete value from the distributed cache system
     *
     * @param string $key Cache key
     * @return bool True if value was deleted from all nodes
     */
    public function delete(string $key): bool
    {
        // Delete from primary cache first
        $success = $this->primaryCache->delete($key);

        // Use health monitoring if enabled to get healthy nodes
        $nodes = $this->failoverEnabled && $this->healthMonitor !== null
            ? $this->nodeManager->getHealthyNodesForKey($key, $this->replicationStrategy)
            : $this->nodeManager->getNodesForKey($key, $this->replicationStrategy);

        foreach ($nodes as $node) {
            $success = $success && $node->delete($key);
        }

        return $success;
    }

    /**
     * Check if key exists in the distributed cache
     *
     * @param string $key Cache key
     * @return bool True if key exists in at least one node
     */
    public function has(string $key): bool
    {
        // Check primary cache first
        if ($this->primaryCache->has($key)) {
            return true;
        }

        // Use health monitoring if enabled to get healthy nodes
        $nodes = $this->failoverEnabled && $this->healthMonitor !== null
            ? $this->nodeManager->getHealthyNodesForKey($key, $this->replicationStrategy)
            : $this->nodeManager->getNodesForKey($key, $this->replicationStrategy);

        foreach ($nodes as $node) {
            if ($node->exists($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear all cache values across all nodes
     *
     * @return bool True if all nodes were cleared successfully
     */
    public function clear(): bool
    {
        // Clear primary cache first
        $success = $this->primaryCache->clear();

        // When failover is enabled, only flush healthy nodes
        $allNodes = $this->failoverEnabled && $this->healthMonitor !== null
            ? $this->healthMonitor->getAvailableNodesForKey('*', $this->replicationStrategy)
            : $this->nodeManager->getAllNodes();

        foreach ($allNodes as $node) {
            $success = $success && $node->clear();
        }

        return $success;
    }

    /**
     * Alias for clear() method
     *
     * @return bool True if all nodes were cleared successfully
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Remember a value in cache, or execute a callback to generate it
     *
     * @param string $key Cache key
     * @param callable $callback Function to generate value if not cached
     * @param int|null $ttl Time to live in seconds
     * @return mixed Cached or generated value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl ?? 3600);

        return $value;
    }

    /**
     * Add tags to a cache key
     *
     * @param string $key Cache key
     * @param array $tags Tags to associate
     * @return bool True if tags added successfully
     */
    public function addTags(string $key, array $tags): bool
    {
        // Add tags to primary cache first
        $success = $this->primaryCache->addTags($key, $tags);

        $now = time();

        foreach ($tags as $tag) {
            $tagKey = "tag:{$tag}";
            // Use health monitoring if enabled to get healthy nodes
            $nodes = $this->failoverEnabled && $this->healthMonitor !== null
                ? $this->nodeManager->getHealthyNodesForKey($tagKey, $this->replicationStrategy)
                : $this->nodeManager->getNodesForKey($tagKey, $this->replicationStrategy);

            foreach ($nodes as $node) {
                // Store key in tag set with current timestamp
                $success = $success && $node->addTaggedKey($tagKey, $key, $now);
            }
        }

        return $success;
    }

    /**
     * Invalidate cache entries by tags
     *
     * @param array $tags Tags to invalidate
     * @return bool True if invalidation succeeded
     */
    public function invalidateTags(array $tags): bool
    {
        // Invalidate tags in primary cache first
        $success = $this->primaryCache->invalidateTags($tags);

        foreach ($tags as $tag) {
            $tagKey = "tag:{$tag}";
            // Use health monitoring if enabled to get healthy nodes
            $nodes = $this->failoverEnabled && $this->healthMonitor !== null
                ? $this->nodeManager->getHealthyNodesForKey($tagKey, $this->replicationStrategy)
                : $this->nodeManager->getNodesForKey($tagKey, $this->replicationStrategy);

            foreach ($nodes as $node) {
                // Get all keys associated with this tag
                $keys = $node->getTaggedKeys($tagKey);

                // Delete each key from all nodes that should have it
                foreach ($keys as $key) {
                    $keyNodes = $this->failoverEnabled && $this->healthMonitor !== null
                        ? $this->nodeManager->getHealthyNodesForKey($key, $this->replicationStrategy)
                        : $this->nodeManager->getNodesForKey($key, $this->replicationStrategy);

                    foreach ($keyNodes as $keyNode) {
                        $success = $success && $keyNode->delete($key);
                    }
                }

                // Delete the tag set itself
                $success = $success && $node->delete($tagKey);
            }
        }

        return $success;
    }

    /**
     * Get the node manager instance
     *
     * @return Nodes\CacheNodeManager Node manager
     */
    public function getNodeManager(): Nodes\CacheNodeManager
    {
        return $this->nodeManager;
    }

    /**
     * Get the current replication strategy
     *
     * @return string Replication strategy
     */
    public function getReplicationStrategy(): string
    {
        return $this->replicationStrategy;
    }

    /**
     * Set the replication strategy
     *
     * @param string $strategy Replication strategy
     * @return self
     */
    public function setReplicationStrategy(string $strategy): self
    {
        $this->replicationStrategy = $strategy;
        return $this;
    }

    /**
     * Set whether failover is enabled
     *
     * @param bool $enabled Whether failover is enabled
     * @return self
     */
    public function setFailoverEnabled(bool $enabled): self
    {
        $this->failoverEnabled = $enabled;
        if ($this->healthMonitor !== null) {
            $this->healthMonitor->setEnabled($enabled);
        }
        return $this;
    }

    /**
     * Get the current failover status
     *
     * @return bool Whether failover is enabled
     */
    public function isFailoverEnabled(): bool
    {
        return $this->failoverEnabled;
    }

    // PSR-16 CacheInterface methods

    /**
     * Get multiple values from cache
     *
     * @param iterable $keys Cache keys
     * @param mixed $default Default value
     * @return iterable Values
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Set multiple values in cache
     *
     * @param iterable $values Key-value pairs
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool Success status
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $success && $this->set($key, $value, $ttl);
        }
        return $success;
    }

    /**
     * Delete multiple values from cache
     *
     * @param iterable $keys Cache keys
     * @return bool Success status
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $success && $this->delete($key);
        }
        return $success;
    }

    // CacheStore specific methods

    /**
     * Set value only if key does not exist
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live
     * @return bool Success status
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->primaryCache->setNx($key, $value, $ttl);
    }

    /**
     * Get multiple values (alias for getMultiple)
     *
     * @param array $keys Cache keys
     * @return array Values
     */
    public function mget(array $keys): array
    {
        return iterator_to_array($this->getMultiple($keys));
    }

    /**
     * Set multiple values (alias for setMultiple)
     *
     * @param array $values Key-value pairs
     * @param int $ttl Time to live
     * @return bool Success status
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        return $this->setMultiple($values, $ttl);
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment
     * @return int New value
     */
    public function increment(string $key, int $value = 1): int
    {
        return $this->primaryCache->increment($key, $value);
    }

    /**
     * Decrement numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement
     * @return int New value
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->primaryCache->decrement($key, $value);
    }

    /**
     * Get remaining TTL
     *
     * @param string $key Cache key
     * @return int Remaining time in seconds
     */
    public function ttl(string $key): int
    {
        return $this->primaryCache->ttl($key);
    }

    /**
     * Add to sorted set
     *
     * @param string $key Set key
     * @param array $scoreValues Score-value pairs
     * @return bool Success status
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        return $this->primaryCache->zadd($key, $scoreValues);
    }

    /**
     * Remove set members by score
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return $this->primaryCache->zremrangebyscore($key, $min, $max);
    }

    /**
     * Get set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int
    {
        return $this->primaryCache->zcard($key);
    }

    /**
     * Get set range
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return array Range of members
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        return $this->primaryCache->zrange($key, $start, $stop);
    }

    /**
     * Set key expiration
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool Success status
     */
    public function expire(string $key, int $seconds): bool
    {
        return $this->primaryCache->expire($key, $seconds);
    }

    /**
     * Delete key (alias for delete)
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function del(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Delete keys matching pattern
     *
     * @param string $pattern Pattern to match
     * @return bool Success status
     */
    public function deletePattern(string $pattern): bool
    {
        return $this->primaryCache->deletePattern($pattern);
    }

    /**
     * Get cache keys
     *
     * @param string $pattern Pattern to filter
     * @return array List of keys
     */
    public function getKeys(string $pattern = '*'): array
    {
        return $this->primaryCache->getKeys($pattern);
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        return $this->primaryCache->getStats();
    }

    /**
     * Get all cache keys
     *
     * @return array List of all keys
     */
    public function getAllKeys(): array
    {
        return $this->primaryCache->getAllKeys();
    }

    /**
     * Get count of keys matching pattern
     *
     * @param string $pattern Pattern to match
     * @return int Number of matching keys
     */
    public function getKeyCount(string $pattern = '*'): int
    {
        return $this->primaryCache->getKeyCount($pattern);
    }

    /**
     * Get cache driver capabilities
     *
     * @return array Driver capabilities
     */
    public function getCapabilities(): array
    {
        return $this->primaryCache->getCapabilities();
    }

    /**
     * Helper method to normalize TTL values
     *
     * @param null|int|\DateInterval $ttl TTL value
     * @return int|null Normalized TTL in seconds
     */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTime())->add($ttl)->getTimestamp() - time();
        }

        return $ttl;
    }
}
