<?php

declare(strict_types=1);

namespace Glueful\Cache\Health;

use Glueful\Cache\Nodes\CacheNode;

/**
 * Node Health Checker
 *
 * Monitors the health status of cache nodes in a distributed cache system.
 * Performs periodic health checks and maintains status information.
 */
class NodeHealthChecker
{
    /** @var array Health status registry for nodes */
    private $nodeHealth = [];

    /** @var int Default timeout for health check operations in seconds */
    private $timeout;

    /** @var int Health check interval in seconds */
    private $checkInterval;

    /** @var int Number of failures before marking a node as unhealthy */
    private $failureThreshold;

    /** @var array Timestamps of last health checks */
    private $lastChecks = [];

    /**
     * Initialize health checker
     *
     * @param int $timeout Operation timeout in seconds
     * @param int $checkInterval Interval between health checks in seconds
     * @param int $failureThreshold Number of failures before marking node as unhealthy
     */
    public function __construct(int $timeout = 5, int $checkInterval = 60, int $failureThreshold = 3)
    {
        $this->timeout = $timeout;
        $this->checkInterval = $checkInterval;
        $this->failureThreshold = $failureThreshold;
    }

    /**
     * Check if a node is healthy
     *
     * @param CacheNode $node Cache node to check
     * @param bool $force Force check regardless of interval
     * @return bool True if node is healthy
     */
    public function isHealthy(CacheNode $node, bool $force = false): bool
    {
        $nodeId = $node->getId();

        // If we have recent check result and not forcing a new check
        if (
            !$force && isset($this->lastChecks[$nodeId]) &&
            (time() - $this->lastChecks[$nodeId]) < $this->checkInterval
        ) {
            return $this->nodeHealth[$nodeId]['healthy'] ?? false;
        }

        // Perform health check
        return $this->checkHealth($node);
    }

    /**
     * Perform a health check on a node
     *
     * @param CacheNode $node Cache node to check
     * @return bool True if node is healthy
     */
    public function checkHealth(CacheNode $node): bool
    {
        $nodeId = $node->getId();
        $this->lastChecks[$nodeId] = time();

        try {
            // Set a timeout for operations
            set_time_limit($this->timeout);

            // Try to get the node status
            $status = $node->getStatus();

            // Try a basic operation - ping-like test
            $testKey = 'health_check_' . uniqid();
            $testValue = microtime(true);

            $setSuccess = $node->set($testKey, $testValue, 60);
            $getValue = $node->get($testKey);
            $deleteSuccess = $node->delete($testKey);

            $isOperational = $setSuccess && $getValue === $testValue && $deleteSuccess;

            // Reset any failure count if successful
            if ($isOperational) {
                $this->nodeHealth[$nodeId] = [
                    'healthy' => true,
                    'failures' => 0,
                    'last_success' => time(),
                    'status' => $status
                ];
                return true;
            }

            // Increment failure count
            $this->recordFailure($nodeId, 'Node operations failed');
            return false;
        } catch (\Throwable $e) {
            // Record exception as failure
            $this->recordFailure($nodeId, $e->getMessage());
            return false;
        } finally {
            // Reset time limit
            set_time_limit(0);
        }
    }

    /**
     * Record a node failure
     *
     * @param string $nodeId Node identifier
     * @param string $reason Failure reason
     * @return void
     */
    private function recordFailure(string $nodeId, string $reason): void
    {
        if (!isset($this->nodeHealth[$nodeId])) {
            $this->nodeHealth[$nodeId] = [
                'healthy' => true,
                'failures' => 0,
                'last_success' => 0,
                'status' => []
            ];
        }

        $failures = ++$this->nodeHealth[$nodeId]['failures'];

        // Mark as unhealthy if failures exceed threshold
        $this->nodeHealth[$nodeId]['healthy'] = $failures < $this->failureThreshold;
        $this->nodeHealth[$nodeId]['last_failure'] = time();
        $this->nodeHealth[$nodeId]['last_failure_reason'] = $reason;
    }

    /**
     * Get health information for a node
     *
     * @param string $nodeId Node identifier
     * @return array Health information
     */
    public function getNodeHealth(string $nodeId): array
    {
        return $this->nodeHealth[$nodeId] ?? [
            'healthy' => false,
            'failures' => 0,
            'last_success' => 0,
            'status' => []
        ];
    }

    /**
     * Get all nodes health status
     *
     * @return array All nodes health information
     */
    public function getAllNodesHealth(): array
    {
        return $this->nodeHealth;
    }

    /**
     * Reset health information for a node
     *
     * @param string $nodeId Node identifier
     * @return void
     */
    public function resetNodeHealth(string $nodeId): void
    {
        if (isset($this->nodeHealth[$nodeId])) {
            $this->nodeHealth[$nodeId]['healthy'] = true;
            $this->nodeHealth[$nodeId]['failures'] = 0;
            $this->nodeHealth[$nodeId]['last_success'] = time();
        }
    }

    /**
     * Set failure threshold
     *
     * @param int $threshold Number of failures before marking node as unhealthy
     * @return self
     */
    public function setFailureThreshold(int $threshold): self
    {
        $this->failureThreshold = max(1, $threshold);
        return $this;
    }

    /**
     * Set health check interval
     *
     * @param int $interval Interval between health checks in seconds
     * @return self
     */
    public function setCheckInterval(int $interval): self
    {
        $this->checkInterval = max(1, $interval);
        return $this;
    }

    /**
     * Set operation timeout
     *
     * @param int $timeout Operation timeout in seconds
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);
        return $this;
    }
}
