<?php

declare(strict_types=1);

namespace Glueful\Cache\Replication;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Consistent Hashing Replication Strategy
 *
 * Implements a consistent hashing algorithm for distributing keys
 * across cache nodes, minimizing redistribution on node changes.
 * Ideal for distributed data management with dynamic node changes.
 */
class ConsistentHashingStrategy implements ReplicationStrategyInterface
{
    /** @var int Number of virtual nodes per physical node */
    private $virtualNodes;

    /** @var int Number of replicas for each key */
    private $replicas;

    /**
     * Initialize strategy with configuration
     *
     * @param int $virtualNodes Number of virtual nodes per physical node
     * @param int $replicas Number of replicas for each key
     */
    public function __construct(int $virtualNodes = 64, int $replicas = 2)
    {
        $this->virtualNodes = max(1, $virtualNodes);
        $this->replicas = max(1, $replicas);
    }

    /**
     * {@inheritdoc}
     */
    public function getNodesForKey(string $key, array $allNodes): array
    {
        if (empty($allNodes)) {
            return [];
        }

        // Build the hash ring
        $hashRing = $this->buildHashRing($allNodes);
        if (empty($hashRing)) {
            return [];
        }

        $keyHash = $this->hashKey($key);
        $selectedNodes = [];
        $nodeIds = [];

        // Sort the hash ring by hash value
        ksort($hashRing, SORT_STRING);

        // Get the ring keys
        $ringKeys = array_keys($hashRing);
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
        $nodeMap = [];

        // Build a map of node IDs to node objects
        foreach ($allNodes as $node) {
            $nodeMap[$node->getId()] = $node;
        }

        // Go around the ring to find required nodes
        while ($count < $this->replicas && $count < count($nodeMap)) {
            $hash = $ringKeys[$i];
            $nodeId = $hashRing[$hash];

            // Only take unique nodes
            if (!in_array($nodeId, $nodeIds, true)) {
                $nodeIds[] = $nodeId;
                if (isset($nodeMap[$nodeId])) {
                    $selectedNodes[] = $nodeMap[$nodeId];
                    $count++;
                }
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
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'consistent-hashing';
    }

    /**
     * Build the hash ring from available nodes
     *
     * @param array $nodes Available nodes
     * @return array Hash ring mapping hashes to node IDs
     */
    private function buildHashRing(array $nodes): array
    {
        $hashRing = [];

        foreach ($nodes as $node) {
            // Get node weight (default to 1)
            $nodeId = $node->getId();
            $weight = 1;

            // Number of virtual nodes is proportional to weight
            $vnodesCount = $this->virtualNodes * $weight;

            for ($i = 0; $i < $vnodesCount; $i++) {
                $vnode = $nodeId . ':' . $i;
                $hash = $this->hashKey($vnode);
                $hashRing[$hash] = $nodeId;
            }
        }

        return $hashRing;
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
     * Set the number of replicas
     *
     * @param int $replicas Number of replicas
     * @return self
     */
    public function setReplicas(int $replicas): self
    {
        $this->replicas = max(1, $replicas);
        return $this;
    }

    /**
     * Set the number of virtual nodes
     *
     * @param int $virtualNodes Number of virtual nodes
     * @return self
     */
    public function setVirtualNodes(int $virtualNodes): self
    {
        $this->virtualNodes = max(1, $virtualNodes);
        return $this;
    }
}
