<?php

declare(strict_types=1);

namespace Glueful\Cache\Replication;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Primary-Replica Replication Strategy
 *
 * Implements a strategy where one node is designated as primary
 * and others as replicas. All writes go to primary then propagate to replicas.
 * Optimized for write-heavy workloads with eventual consistency.
 */
class PrimaryReplicaStrategy implements ReplicationStrategyInterface
{
    /** @var int Maximum number of replicas to use */
    private $maxReplicas;

    /**
     * Initialize strategy
     *
     * @param int $maxReplicas Maximum number of replicas (not including primary)
     */
    public function __construct(int $maxReplicas = 2)
    {
        $this->maxReplicas = max(0, $maxReplicas);
    }

    /**
     * {@inheritdoc}
     */
    public function getNodesForKey(string $key, array $allNodes): array
    {
        if (empty($allNodes)) {
            return [];
        }

        // Sort nodes by their weight, assuming higher weights should be primary
        // We'll use node ID as a stable sort key if weights are equal
        usort($allNodes, function ($a, $b) {
            $aWeight = $this->getNodeWeight($a);
            $bWeight = $this->getNodeWeight($b);

            if ($aWeight === $bWeight) {
                return strcmp($a->getId(), $b->getId());
            }

            return $bWeight <=> $aWeight;
        });

        // Take the primary node and the specified number of replicas
        $result = array_slice($allNodes, 0, 1 + $this->maxReplicas);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'primary-replica';
    }

    /**
     * Get node weight, defaulting to 1 if not available
     *
     * @param CacheNode $node Cache node
     * @return int Node weight
     */
    private function getNodeWeight(CacheNode $node): int
    {
        // Ideally, nodes would expose their weights directly
        // For now, we'll use a simple heuristic based on node ID
        // A more sophisticated implementation would get this from node properties
        $id = $node->getId();

        // Assume nodes with "primary" in their ID have higher weight
        if (stripos($id, 'primary') !== false) {
            return 10;
        }

        // Assume nodes with "replica" in their ID have lower weight
        if (stripos($id, 'replica') !== false) {
            return 5;
        }

        // Default weight
        return 1;
    }

    /**
     * Set maximum number of replicas
     *
     * @param int $maxReplicas Maximum replicas
     * @return self
     */
    public function setMaxReplicas(int $maxReplicas): self
    {
        $this->maxReplicas = max(0, $maxReplicas);
        return $this;
    }
}
