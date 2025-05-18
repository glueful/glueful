<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Cache\Replication\ReplicationStrategyFactory;
use Glueful\Cache\Health\HealthMonitoringService;

/**
 * Distributed Cache Service
 *
 * Provides a distributed caching system that coordinates multiple cache nodes
 * with support for different replication strategies.
 */
class DistributedCacheService extends CacheEngine
{
    /** @var Nodes\CacheNodeManager Node management service */
    private $nodeManager;

    /** @var string Replication strategy to use */
    private $replicationStrategy;

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var bool Whether failover is enabled */
    private $failoverEnabled = false;

    /** @var HealthMonitoringService|null Health monitoring service */
    private $healthMonitor = null;


    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }


    /**
     * Initialize the distributed cache service
     *
     * @param array $config Configuration parameters
     */
    public function __construct(array $config = [])
    {
        // Initialize parent but avoid using static methods directly
        if (!self::isInitialized()) {
            self::initialize();
        }

        $this->nodeManager = new Nodes\CacheNodeManager($config['nodes'] ?? []);
        $this->replicationStrategy = $config['replication'] ?? 'consistent-hashing';

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
     * @param int $ttl Time to live in seconds
     * @return bool True if value was set on all nodes
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        // Get an instance from the static context
        $instance = self::getInstance();

        // Use health monitoring if enabled to get healthy nodes
        $nodes = $instance->failoverEnabled && $instance->healthMonitor !== null
            ? $instance->nodeManager->getHealthyNodesForKey($key, $instance->replicationStrategy)
            : $instance->nodeManager->getNodesForKey($key, $instance->replicationStrategy);

        $success = true;
        foreach ($nodes as $node) {
            $success = $success && $node->set($key, $value, $ttl);
        }

        return $success;
    }

    /**
     * Get value from the distributed cache system
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public static function get(string $key): mixed
    {
        $instance = self::getInstance();
        // Use health monitoring if enabled to get healthy nodes
        $nodes = $instance->failoverEnabled && $instance->healthMonitor !== null
            ? $instance->nodeManager->getHealthyNodesForKey($key, $instance->replicationStrategy)
            : $instance->nodeManager->getNodesForKey($key, $instance->replicationStrategy);

        foreach ($nodes as $node) {
            $value = $node->get($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Delete value from the distributed cache system
     *
     * @param string $key Cache key
     * @return bool True if value was deleted from all nodes
     */
    public static function delete(string $key): bool
    {
        $instance = self::getInstance();
        // Use health monitoring if enabled to get healthy nodes
        $nodes = $instance->failoverEnabled && $instance->healthMonitor !== null
            ? $instance->nodeManager->getHealthyNodesForKey($key, $instance->replicationStrategy)
            : $instance->nodeManager->getNodesForKey($key, $instance->replicationStrategy);

        $success = true;
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
    public static function exists(string $key): bool
    {
        $instance = self::getInstance();
        // Use health monitoring if enabled to get healthy nodes
        $nodes = $instance->failoverEnabled && $instance->healthMonitor !== null
            ? $instance->nodeManager->getHealthyNodesForKey($key, $instance->replicationStrategy)
            : $instance->nodeManager->getNodesForKey($key, $instance->replicationStrategy);

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
    public static function flush(): bool
    {
        $instance = self::getInstance();
        // When failover is enabled, only flush healthy nodes
        $allNodes = $instance->failoverEnabled && $instance->healthMonitor !== null
            ? $instance->healthMonitor->getAvailableNodesForKey('*', $instance->replicationStrategy)
            : $instance->nodeManager->getAllNodes();

        $success = true;
        foreach ($allNodes as $node) {
            $success = $success && $node->clear();
        }

        return $success;
    }

    /**
     * Remember a value in cache, or execute a callback to generate it
     *
     * @param string $key Cache key
     * @param \Closure $callback Function to generate value if not cached
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or generated value
     */
    public static function remember(string $key, \Closure $callback, int $ttl = 3600): mixed
    {
        $value = self::get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    /**
     * Add tags to a cache key
     *
     * @param string $key Cache key
     * @param array|string $tags Tags to associate
     * @return bool True if tags added successfully
     */
    public static function addTags(string $key, array|string $tags): bool
    {
        $instance = self::getInstance();

        if (is_string($tags)) {
            $tags = [$tags];
        }

        $success = true;
        $now = time();

        foreach ($tags as $tag) {
            $tagKey = "tag:{$tag}";
            // Use health monitoring if enabled to get healthy nodes
            $nodes = $instance->failoverEnabled && $instance->healthMonitor !== null
                ? $instance->nodeManager->getHealthyNodesForKey($tagKey, $instance->replicationStrategy)
                : $instance->nodeManager->getNodesForKey($tagKey, $instance->replicationStrategy);

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
     * @param array|string $tags Tags to invalidate
     * @return bool True if invalidation succeeded
     */
    public static function invalidateTags(array|string $tags): bool
    {
        $instance = self::getInstance();

        if (is_string($tags)) {
            $tags = [$tags];
        }

        $success = true;

        foreach ($tags as $tag) {
            $tagKey = "tag:{$tag}";
            // Use health monitoring if enabled to get healthy nodes
            $nodes = $instance->failoverEnabled && $instance->healthMonitor !== null
                ? $instance->nodeManager->getHealthyNodesForKey($tagKey, $instance->replicationStrategy)
                : $instance->nodeManager->getNodesForKey($tagKey, $instance->replicationStrategy);

            foreach ($nodes as $node) {
                // Get all keys associated with this tag
                $keys = $node->getTaggedKeys($tagKey);

                // Delete each key from all nodes that should have it
                foreach ($keys as $key) {
                    $keyNodes = $instance->failoverEnabled && $instance->healthMonitor !== null
                        ? $instance->nodeManager->getHealthyNodesForKey($key, $instance->replicationStrategy)
                        : $instance->nodeManager->getNodesForKey($key, $instance->replicationStrategy);

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
    public static function getNodeManager(): Nodes\CacheNodeManager
    {
        return self::getInstance()->nodeManager;
    }

    /**
     * Get the current replication strategy
     *
     * @return string Replication strategy
     */
    public static function getReplicationStrategy(): string
    {
        return self::getInstance()->replicationStrategy;
    }

    /**
     * Set the replication strategy
     *
     * @param string $strategy Replication strategy
     * @return self
     */
    public static function setReplicationStrategy(string $strategy): self
    {
        $instance = self::getInstance();
        $instance->replicationStrategy = $strategy;
        return $instance;
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
     * Enable or disable failover functionality
     *
     * @param bool $enabled Whether failover should be enabled
     * @return bool Current failover status
     */
    public static function enableFailover(bool $enabled = true): bool
    {
        $instance = self::getInstance();
        $instance->setFailoverEnabled($enabled);
        return $instance->failoverEnabled;
    }

    /**
     * Get the current failover status
     *
     * @return bool Whether failover is enabled
     */
    public static function isFailoverEnabled(): bool
    {
        return self::getInstance()->failoverEnabled;
    }
}
