<?php

declare(strict_types=1);

namespace Glueful\Cache\Health;

use Glueful\Cache\Nodes\CacheNodeManager;

/**
 * Health Monitoring Service
 *
 * Integrates health checking, failover, and recovery components
 * to provide comprehensive health monitoring for distributed cache.
 */
class HealthMonitoringService
{
    /** @var NodeHealthChecker Health checker instance */
    private $healthChecker;

    /** @var FailoverManager Failover manager instance */
    private $failoverManager;

    /** @var RecoveryManager Recovery manager instance */
    private $recoveryManager;

    /** @var CacheNodeManager Node manager instance */
    private $nodeManager;

    /** @var array Monitoring configuration */
    private $config;

    /** @var bool Whether monitoring is enabled */
    private $enabled;

    /**
     * Initialize health monitoring service
     *
     * @param CacheNodeManager $nodeManager Node manager instance
     * @param array $config Monitoring configuration
     */
    public function __construct(CacheNodeManager $nodeManager, array $config = [])
    {
        $this->nodeManager = $nodeManager;
        $this->config = $config;
        $this->enabled = $config['enabled'] ?? true;

        // Initialize health checker
        $timeout = $config['health_check']['timeout'] ?? 5;
        $interval = $config['health_check']['interval'] ?? 60;
        $failureThreshold = $config['health_check']['failure_threshold'] ?? 3;
        $this->healthChecker = new NodeHealthChecker($timeout, $interval, $failureThreshold);

        // Initialize failover manager
        $failoverConfig = $config['failover'] ?? [];
        $this->failoverManager = new FailoverManager($this->healthChecker, $failoverConfig);

        // Initialize recovery manager
        $recoveryConfig = $config['recovery'] ?? [];
        $this->recoveryManager = new RecoveryManager($this->healthChecker, $this->failoverManager, $recoveryConfig);

        // Set up event listeners
        $this->setupEventListeners();
    }

    /**
     * Check all nodes health and manage failover/recovery
     *
     * @return array Health status for all nodes
     */
    public function monitorNodes(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $healthStatus = [];
        $allNodes = $this->nodeManager->getAllNodes();

        foreach ($allNodes as $node) {
            $nodeId = $node->getId();

            // Check if node is healthy
            $isHealthy = $this->healthChecker->isHealthy($node);

            // Get node details
            $healthStatus[$nodeId] = [
                'id' => $nodeId,
                'healthy' => $isHealthy,
                'details' => $this->healthChecker->getNodeHealth($nodeId),
                'circuit_state' => $this->getCircuitState($nodeId),
                'recovery_status' => $this->recoveryManager->getRecoveryStatus($nodeId)
            ];

            // Handle unhealthy nodes
            if (!$isHealthy) {
                $this->failoverManager->handleNodeFailure($node, "Health check failed during monitoring");
            }

            // Check if node needs recovery
            if ($this->recoveryManager->needsRecovery($node)) {
                $this->recoveryManager->initiateRecovery($node, $this->nodeManager);
            }
        }

        return $healthStatus;
    }

    /**
     * Filter available nodes for a key based on health and circuit state
     *
     * @param string $key Cache key
     * @param string $strategy Replication strategy
     * @return array Available nodes
     */
    public function getAvailableNodesForKey(string $key, string $strategy): array
    {
        if (!$this->enabled) {
            return $this->nodeManager->getNodesForKey($key, $strategy);
        }

        // Get nodes based on strategy
        $nodes = $this->nodeManager->getNodesForKey($key, $strategy);

        // Filter available nodes
        $availableNodes = $this->failoverManager->filterAvailableNodes($nodes);

        // If no available nodes, fall back to any available node
        if (empty($availableNodes)) {
            $allNodes = $this->nodeManager->getAllNodes();
            $availableNodes = $this->failoverManager->filterAvailableNodes($allNodes);
        }

        return $availableNodes;
    }

    /**
     * Get circuit state for a node
     *
     * @param string $nodeId Node identifier
     * @return string Circuit state or 'unknown'
     */
    private function getCircuitState(string $nodeId): string
    {
        $circuitBreaker = $this->getCircuitBreaker($nodeId);
        return $circuitBreaker ? $circuitBreaker->getState() : 'unknown';
    }

    /**
     * Get circuit breaker for a node if available
     *
     * @param string $nodeId Node identifier
     * @return CircuitBreaker|null Circuit breaker or null
     */
    private function getCircuitBreaker(string $nodeId): ?CircuitBreaker
    {
        $reflection = new \ReflectionObject($this->failoverManager);

        if (!$reflection->hasProperty('circuitBreakers')) {
            return null;
        }

        $property = $reflection->getProperty('circuitBreakers');
        $property->setAccessible(true);

        $circuitBreakers = $property->getValue($this->failoverManager);
        return $circuitBreakers[$nodeId] ?? null;
    }

    /**
     * Set up event listeners
     *
     * @return void
     */
    private function setupEventListeners(): void
    {
        // Listen for node failure events
        $this->failoverManager->subscribe('node.failure', function ($data) {
            // Log failure
            if (isset($this->config['logging']) && $this->config['logging']['enabled']) {
                // In a real implementation, use a proper logger
                error_log("Cache node failure: Node {$data['node_id']} - {$data['reason']}");
            }
        });

        // Listen for node recovery events
        $this->failoverManager->subscribe('node.recovery', function ($data) {
            // Log recovery
            if (isset($this->config['logging']) && $this->config['logging']['enabled']) {
                // In a real implementation, use a proper logger
                error_log("Cache node recovery: Node {$data['node_id']} has recovered");
            }
        });

        // Listen for recovery completion events
        $this->recoveryManager->subscribe('recovery.completed', function ($data) {
            // Log recovery completion
            if (isset($this->config['logging']) && $this->config['logging']['enabled']) {
                // In a real implementation, use a proper logger
                error_log("Cache node recovery completed: Node {$data['node_id']} synchronized 
                {$data['synchronized_keys']} keys");
            }
        });
    }

    /**
     * Enable or disable monitoring
     *
     * @param bool $enabled Whether monitoring is enabled
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->failoverManager->setEnabled($enabled);
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

    /**
     * Get failover manager instance
     *
     * @return FailoverManager Failover manager instance
     */
    public function getFailoverManager(): FailoverManager
    {
        return $this->failoverManager;
    }

    /**
     * Get recovery manager instance
     *
     * @return RecoveryManager Recovery manager instance
     */
    public function getRecoveryManager(): RecoveryManager
    {
        return $this->recoveryManager;
    }
}
