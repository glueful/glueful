<?php

declare(strict_types=1);

namespace Glueful\Cache\Replication;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Full Replication Strategy
 *
 * Replicates data to all available nodes in the cluster.
 * Provides highest availability and read performance at the cost
 * of write overhead. Ideal for read-heavy workloads requiring high availability.
 */
class FullReplicationStrategy implements ReplicationStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function getNodesForKey(string $key, array $allNodes): array
    {
        // In full replication, we simply return all available nodes
        return $allNodes;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'full-replication';
    }
}
