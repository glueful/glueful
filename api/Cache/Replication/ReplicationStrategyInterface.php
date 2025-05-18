<?php

declare(strict_types=1);

namespace Glueful\Cache\Replication;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Replication Strategy Interface
 *
 * Defines the contract for cache replication strategies.
 * Each strategy determines how to distribute data across cache nodes.
 */
interface ReplicationStrategyInterface
{
    /**
     * Get nodes for a specific key
     *
     * @param string $key Cache key
     * @param array $allNodes All available cache nodes
     * @return array Selected nodes for the key
     */
    public function getNodesForKey(string $key, array $allNodes): array;

    /**
     * Get the strategy name
     *
     * @return string Strategy name identifier
     */
    public function getName(): string;
}
