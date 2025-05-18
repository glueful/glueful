<?php

declare(strict_types=1);

namespace Glueful\Cache\Health;

use Glueful\Cache\Nodes\CacheNode;
use Glueful\Cache\Nodes\CacheNodeManager;

/**
 * Failover Manager
 *
 * Handles automatic failover operations when nodes become unhealthy.
 * Works with the node health checker to detect and respond to node failures.
 */
class FailoverManager
{
    /** @var NodeHealthChecker Health checker instance */
    private $healthChecker;

    /** @var array Circuit breakers for nodes */
    private $circuitBreakers = [];

    /** @var array Configuration for failover */
    private $config;

    /** @var array Event subscribers for failover events */
    private $subscribers = [];

    /** @var bool Whether automatic failover is enabled */
    private $enabled;

    /** @var bool Whether to use circuit breakers */
    private $useCircuitBreakers;

    /**
     * Initialize failover manager
     *
     * @param NodeHealthChecker $healthChecker Health checker instance
     * @param array $config Configuration for failover
     */
    public function __construct(NodeHealthChecker $healthChecker, array $config = [])
    {
        $this->healthChecker = $healthChecker;
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? true;
        $this->useCircuitBreakers = $config['use_circuit_breakers'] ?? true;
    }

    /**
     * Check if a node is available (healthy and circuit closed)
     *
     * @param CacheNode $node Cache node to check
     * @return bool True if node is available
     */
    public function isNodeAvailable(CacheNode $node): bool
    {
        $nodeId = $node->getId();

        // If failover is disabled, always return true
        if (!$this->enabled) {
            return true;
        }

        // Check circuit breaker first (fast check)
        if ($this->useCircuitBreakers && isset($this->circuitBreakers[$nodeId])) {
            $circuitBreaker = $this->circuitBreakers[$nodeId];
            if (!$circuitBreaker->isCallAllowed()) {
                return false;
            }
        }

        // Check node health (may involve a network call)
        $isHealthy = $this->healthChecker->isHealthy($node);

        // Update circuit breaker based on health check
        if ($this->useCircuitBreakers) {
            $this->getCircuitBreaker($nodeId)->recordSuccess();
            if (!$isHealthy) {
                $this->getCircuitBreaker($nodeId)->recordFailure();
            }
        }

        return $isHealthy;
    }

    /**
     * Filter available nodes from a list
     *
     * @param array $nodes List of cache nodes
     * @return array Available nodes
     */
    public function filterAvailableNodes(array $nodes): array
    {
        if (!$this->enabled) {
            return $nodes;
        }

        return array_filter($nodes, function ($node) {
            return $this->isNodeAvailable($node);
        });
    }

    /**
     * Handle node failure
     *
     * @param CacheNode $node Failed node
     * @param string $reason Failure reason
     * @return void
     */
    public function handleNodeFailure(CacheNode $node, string $reason): void
    {
        $nodeId = $node->getId();

        // Update circuit breaker
        if ($this->useCircuitBreakers) {
            $this->getCircuitBreaker($nodeId)->recordFailure();
        }

        // Notify subscribers
        $this->notifySubscribers('node.failure', [
            'node' => $node,
            'node_id' => $nodeId,
            'reason' => $reason
        ]);
    }

    /**
     * Handle node recovery
     *
     * @param CacheNode $node Recovered node
     * @return void
     */
    public function handleNodeRecovery(CacheNode $node): void
    {
        $nodeId = $node->getId();

        // Reset health information
        $this->healthChecker->resetNodeHealth($nodeId);

        // Reset circuit breaker
        if ($this->useCircuitBreakers && isset($this->circuitBreakers[$nodeId])) {
            $this->circuitBreakers[$nodeId]->forceState(CircuitBreaker::STATE_CLOSED);
        }

        // Notify subscribers
        $this->notifySubscribers('node.recovery', [
            'node' => $node,
            'node_id' => $nodeId
        ]);
    }

    /**
     * Find backup nodes for a failed node
     *
     * @param CacheNode $failedNode Failed node
     * @param CacheNodeManager $nodeManager Node manager instance
     * @return array Backup nodes
     */
    public function findBackupNodes(CacheNode $failedNode, CacheNodeManager $nodeManager): array
    {
        // Get all available nodes
        $allNodes = $nodeManager->getAllNodes();

        // Filter out the failed node and any other unavailable nodes
        $availableNodes = array_filter($allNodes, function ($node) use ($failedNode) {
            return $node->getId() !== $failedNode->getId() && $this->isNodeAvailable($node);
        });

        return array_values($availableNodes);
    }

    /**
     * Get circuit breaker for a node
     *
     * @param string $nodeId Node identifier
     * @return CircuitBreaker Circuit breaker instance
     */
    private function getCircuitBreaker(string $nodeId): CircuitBreaker
    {
        if (!isset($this->circuitBreakers[$nodeId])) {
            $failureThreshold = $this->config['circuit_breaker']['failure_threshold'] ?? 5;
            $resetTimeout = $this->config['circuit_breaker']['reset_timeout'] ?? 60;

            $this->circuitBreakers[$nodeId] = new CircuitBreaker($failureThreshold, $resetTimeout);
        }

        return $this->circuitBreakers[$nodeId];
    }

    /**
     * Subscribe to failover events
     *
     * @param string $event Event name
     * @param callable $callback Event callback
     * @return void
     */
    public function subscribe(string $event, callable $callback): void
    {
        if (!isset($this->subscribers[$event])) {
            $this->subscribers[$event] = [];
        }

        $this->subscribers[$event][] = $callback;
    }

    /**
     * Notify subscribers of an event
     *
     * @param string $event Event name
     * @param array $data Event data
     * @return void
     */
    private function notifySubscribers(string $event, array $data): void
    {
        if (!isset($this->subscribers[$event])) {
            return;
        }

        foreach ($this->subscribers[$event] as $callback) {
            call_user_func($callback, $data);
        }
    }

    /**
     * Enable or disable failover
     *
     * @param bool $enabled Whether failover is enabled
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Enable or disable circuit breakers
     *
     * @param bool $enabled Whether circuit breakers are enabled
     * @return self
     */
    public function setUseCircuitBreakers(bool $enabled): self
    {
        $this->useCircuitBreakers = $enabled;
        return $this;
    }

    /**
     * Get health checker instance
     *
     * @return NodeHealthChecker Health checker instance
     */
    public function getHealthChecker(): NodeHealthChecker
    {
        return $this->healthChecker;
    }
}
