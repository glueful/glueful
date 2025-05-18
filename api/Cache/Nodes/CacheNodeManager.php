<?php

declare(strict_types=1);

namespace Glueful\Cache\Nodes;

use Glueful\Cache\Nodes\CacheNode;
use Glueful\Cache\Replication\ReplicationStrategyFactory;
use Glueful\Cache\Health\HealthMonitoringService;

/**
 * Cache Node Manager
 *
 * Manages a collection of cache nodes and provides node selection strategies
 * based on keys and replication configurations.
 */
class CacheNodeManager
{
    /** @var array Cache node registry with nodes and their weights */
    private $nodes = [];

    /** @var array Hash ring for consistent hashing */
    private $hashRing = [];

    /** @var int Number of virtual nodes per physical node for consistent hashing */
    private $virtualNodes = 64;

    /** @var int Default number of replicas for each key */
    private $defaultReplicas = 2;

    /** @var array Replication strategy configuration */
    private $strategyConfig = [];

    /** @var HealthMonitoringService|null Health monitoring service */
    private $healthMonitor = null;

    /** @var array Failover configuration */
    private $failoverConfig = [];

    /**
     * Initialize node manager with configuration
     *
     * @param array $nodeConfigs Node configuration array
     */
    public function __construct(array $nodeConfigs = [])
    {
        foreach ($nodeConfigs as $config) {
            $this->addNode($config);
        }

        $this->buildHashRing();
    }

    /**
     * Add a cache node
     *
     * @param array $config Node configuration
     * @return void
     */
    public function addNode(array $config): void
    {
        $driver = $config['driver'] ?? 'redis';
        $weight = $config['weight'] ?? 1;

        $node = CacheNode::factory($driver, $config);
        $this->nodes[$node->getId()] = [
            'node' => $node,
            'weight' => $weight
        ];

        $this->buildHashRing();
    }

    /**
     * Remove a cache node
     *
     * @param string $nodeId Node ID to remove
     * @return bool True if node was removed
     */
    public function removeNode(string $nodeId): bool
    {
        if (!isset($this->nodes[$nodeId])) {
            return false;
        }

        unset($this->nodes[$nodeId]);
        $this->buildHashRing();

        return true;
    }

    /**
     * Get all available cache nodes
     *
     * @return array All node instances
     */
    public function getAllNodes(): array
    {
        $nodes = [];
        foreach ($this->nodes as $nodeData) {
            $nodes[] = $nodeData['node'];
        }

        return $nodes;
    }

    /**
     * Get appropriate nodes for a key based on strategy
     *
     * @param string $key Cache key
     * @param string $strategy Replication strategy
     * @return array Array of node instances
     * @throws \InvalidArgumentException If strategy is unknown
     */
    public function getNodesForKey(string $key, string $strategy = 'consistent-hashing'): array
    {
        try {
            // Use our new replication strategy factory
            $config = $this->strategyConfig[$strategy] ?? [];
            $strategyInstance = ReplicationStrategyFactory::getStrategy($strategy, $config);
            return $strategyInstance->getNodesForKey($key, $this->getAllNodes());
        } catch (\InvalidArgumentException $e) {
            // Fallback to legacy implementation for backward compatibility
            return match ($strategy) {
                'consistent-hashing' => $this->getNodesConsistentHashing($key),
                'full-replication', 'replicated' => $this->getAllNodes(),
                'primary-replica' => $this->getPrimaryReplicaNodes($key),
                default => throw new \InvalidArgumentException("Unknown replication strategy: {$strategy}")
            };
        }
    }

    /**
     * Get nodes using consistent hashing algorithm
     *
     * @param string $key Cache key
     * @param int $replicas Number of replicas to return
     * @return array Selected node instances
     */
    public function getNodesConsistentHashing(string $key, int $replicas = 0): array
    {
        if (empty($this->hashRing)) {
            return [];
        }

        $replicas = $replicas ?: $this->defaultReplicas;
        $keyHash = $this->hashKey($key);
        $selectedNodes = [];
        $nodeIds = [];

        // Find position in the ring
        $position = $this->findPositionInRing($keyHash);

        // Start from this position and go around the ring to find required nodes
        $ringKeys = array_keys($this->hashRing);
        $ringSize = count($ringKeys);

        if ($ringSize === 0) {
            return [];
        }

        // Find the initial position >= keyHash
        $start = 0;
        foreach ($ringKeys as $i => $hash) {
            if ($hash >= $keyHash) {
                $start = $i;
                break;
            }
        }

        // Collect nodes, avoiding duplicates
        $i = $start;
        $count = 0;
        while ($count < $replicas && $count < count($this->nodes)) {
            $hash = $ringKeys[$i];
            $nodeId = $this->hashRing[$hash];

            // Only take unique nodes
            if (!in_array($nodeId, $nodeIds)) {
                $nodeIds[] = $nodeId;
                $selectedNodes[] = $this->nodes[$nodeId]['node'];
                $count++;
            }

            $i = ($i + 1) % $ringSize;

            // Avoid infinite loop if we've gone all the way around
            if ($i === $start) {
                break;
            }
        }

        return $selectedNodes;
    }

    /**
     * Get nodes using primary-replica strategy
     *
     * @param string $key Cache key
     * @return array Primary and replica nodes
     */
    public function getPrimaryReplicaNodes(string $key): array
    {
        if (empty($this->nodes)) {
            return [];
        }

        // Sort nodes by weight, highest first
        $sortedNodes = $this->nodes;
        uasort($sortedNodes, function ($a, $b) {
            return $b['weight'] <=> $a['weight'];
        });

        $result = [];
        foreach ($sortedNodes as $nodeData) {
            $result[] = $nodeData['node'];
        }

        return $result;
    }

    /**
     * Build the hash ring for consistent hashing
     *
     * @return void
     */
    public function buildHashRing(): void
    {
        $this->hashRing = [];

        foreach ($this->nodes as $nodeId => $nodeData) {
            $weight = $nodeData['weight'];

            // Number of virtual nodes is proportional to weight
            $vnodesCount = $this->virtualNodes * $weight;

            for ($i = 0; $i < $vnodesCount; $i++) {
                $vnode = $nodeId . ':' . $i;
                $hash = $this->hashKey($vnode);
                $this->hashRing[$hash] = $nodeId;
            }
        }

        // Sort the hash ring by hash value
        ksort($this->hashRing, SORT_STRING);
    }

    /**
     * Hash a key for consistent hashing
     *
     * @param string $key Key to hash
     * @return string Hash value
     */
    private function hashKey(string $key): string
    {
        // Using MD5 for consistent hashing
        return md5($key);
    }

    /**
     * Find the position in the hash ring
     *
     * @param string $hash Hash to locate
     * @return int Position in the ring
     */
    private function findPositionInRing(string $hash): int
    {
        $keys = array_keys($this->hashRing);

        // Binary search for the position
        $low = 0;
        $high = count($keys) - 1;

        while ($low <= $high) {
            $mid = (int)(($low + $high) / 2);

            if ($keys[$mid] < $hash) {
                $low = $mid + 1;
            } elseif ($keys[$mid] > $hash) {
                $high = $mid - 1;
            } else {
                return $mid;
            }
        }

        // If we didn't find an exact match, return the next position
        // or wrap around to the beginning
        return $low < count($keys) ? $low : 0;
    }

    /**
     * Get node by ID
     *
     * @param string $nodeId Node ID
     * @return CacheNode|null Node instance or null if not found
     */
    public function getNode(string $nodeId): ?CacheNode
    {
        return isset($this->nodes[$nodeId]) ? $this->nodes[$nodeId]['node'] : null;
    }

    /**
     * Set the number of virtual nodes for consistent hashing
     *
     * @param int $count Virtual node count
     * @return self
     */
    public function setVirtualNodeCount(int $count): self
    {
        $this->virtualNodes = max(1, $count);
        $this->buildHashRing();
        return $this;
    }

    /**
     * Set the default number of replicas
     *
     * @param int $count Replica count
     * @return self
     */
    public function setDefaultReplicas(int $count): self
    {
        $this->defaultReplicas = max(1, $count);
        return $this;
    }

    /**
     * Configure a replication strategy
     *
     * @param string $strategy Strategy name
     * @param array $config Configuration options
     * @return self
     */
    public function configureStrategy(string $strategy, array $config): self
    {
        $this->strategyConfig[$strategy] = $config;
        return $this;
    }

    /**
     * Initialize health monitoring
     *
     * @param array $config Health monitoring configuration
     * @return HealthMonitoringService Health monitoring service instance
     */
    public function initializeHealthMonitoring(array $config = []): HealthMonitoringService
    {
        if ($this->healthMonitor === null) {
            $this->healthMonitor = new HealthMonitoringService($this, $config);
        }

        return $this->healthMonitor;
    }

    /**
     * Get healthy nodes for a key based on strategy
     *
     * This method uses the health monitoring service to filter out unhealthy nodes
     *
     * @param string $key Cache key
     * @param string $strategy Replication strategy
     * @return array Array of healthy node instances
     */
    public function getHealthyNodesForKey(string $key, string $strategy = 'consistent-hashing'): array
    {
        // If health monitoring is not enabled, use regular node selection
        if ($this->healthMonitor === null) {
            return $this->getNodesForKey($key, $strategy);
        }

        // Use health monitoring to get healthy nodes
        return $this->healthMonitor->getAvailableNodesForKey($key, $strategy);
    }

    /**
     * Check health of all nodes
     *
     * @return array Health status for all nodes
     */
    public function checkNodesHealth(): array
    {
        if ($this->healthMonitor === null) {
            // Initialize health monitoring with default config
            $this->initializeHealthMonitoring();
        }

        return $this->healthMonitor->monitorNodes();
    }

    /**
     * Set failover configuration
     *
     * @param array $config Failover configuration
     * @return self
     */
    public function setFailoverConfig(array $config): self
    {
        $this->failoverConfig = $config;

        // Update health monitoring if it's already initialized
        if ($this->healthMonitor !== null) {
            // Re-initialize with new configuration
            $this->healthMonitor = new HealthMonitoringService($this, [
                'failover' => $this->failoverConfig
            ]);
        }

        return $this;
    }

    /**
     * Get health monitoring service instance
     *
     * @return HealthMonitoringService|null Health monitoring service instance or null
     */
    public function getHealthMonitor(): ?HealthMonitoringService
    {
        return $this->healthMonitor;
    }
}
