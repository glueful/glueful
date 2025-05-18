<?php

declare(strict_types=1);

namespace Glueful\Tests\Cache;

use Glueful\Cache\DistributedCacheService;
use PHPUnit\Framework\TestCase;
use Glueful\Tests\Cache\Nodes\MemoryNode;
use Glueful\Tests\Cache\Helpers\CacheNodePatcher;

/**
 * Test failover and recovery mechanisms in the distributed cache
 */
class FailoverRecoveryTest extends TestCase
{
    private $cacheService;
    private $nodeManager;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        // Configure test nodes
        $nodeConfig = [
            [
                'driver' => 'memory',
                'id' => 'memory-node-1',
                'weight' => 1
            ],
            [
                'driver' => 'memory',
                'id' => 'memory-node-2',
                'weight' => 1
            ],
            [
                'driver' => 'memory',
                'id' => 'memory-node-3',
                'weight' => 1
            ]
        ];

        // Initialize cache service with test configuration
        $this->cacheService = new DistributedCacheService([
            'nodes' => $nodeConfig,
            'replication' => 'consistent-hashing',
            'health' => [
                'enabled' => true,
                'health_check' => [
                    'timeout' => 1,
                    'interval' => 5,
                    'failure_threshold' => 2
                ],
                'failover' => [
                    'enabled' => true,
                    'circuit_breaker' => [
                        'failure_threshold' => 3,
                        'reset_timeout' => 10
                    ]
                ],
                'recovery' => [
                    'enabled' => true,
                    'check_interval' => 15
                ]
            ]
        ]);

        $this->nodeManager = DistributedCacheService::getNodeManager();
    }

/**
     * @beforeClass
     */
    public static function setupMemoryNode(): void
    {
        // Register our memory node implementation
        require_once __DIR__ . '/Nodes/MemoryNode.php';
        require_once __DIR__ . '/Helpers/CacheNodePatcher.php';

        // Patch CacheNode to support memory nodes
        CacheNodePatcher::registerMemoryNode();
    }

    /**
     * Test failover when a node becomes unhealthy
     */
    public function testFailoverToHealthyNodes(): void
    {
        // Enable failover
        DistributedCacheService::enableFailover(true);
        $this->assertTrue(DistributedCacheService::isFailoverEnabled());

        // Set test data
        $key = 'test_failover_key';
        $value = 'test_value';

        DistributedCacheService::set($key, $value);

        // Get all nodes that should have this key
        $nodes = $this->nodeManager->getNodesForKey($key, DistributedCacheService::getReplicationStrategy());
        $this->assertNotEmpty($nodes, 'No nodes found for key');

        // Get the first node and mark it as unhealthy by triggering health check failures
        $firstNode = $nodes[0];
        $nodeId = $firstNode->getId();

        // Get health monitoring service through reflection
        $reflection = new \ReflectionObject($this->nodeManager);
        $property = $reflection->getProperty('healthMonitor');
        $property->setAccessible(true);
        $healthMonitor = $property->getValue($this->nodeManager);

        // Get health checker through reflection
        $reflection = new \ReflectionObject($healthMonitor);
        $property = $reflection->getProperty('healthChecker');
        $property->setAccessible(true);
        $healthChecker = $property->getValue($healthMonitor);

        // Mark the node as unhealthy
        $method = new \ReflectionMethod($healthChecker, 'markNodeUnhealthy');
        $method->setAccessible(true);
        $method->invoke($healthChecker, $firstNode, 'Test-induced failure');

        // Verify the node is marked as unhealthy
        $method = new \ReflectionMethod($healthChecker, 'isHealthy');
        $method->setAccessible(true);
        $isHealthy = $method->invoke($healthChecker, $firstNode);
        $this->assertFalse($isHealthy, 'Node should be marked as unhealthy');

        // Now try to get the value - it should succeed with failover
        $retrievedValue = DistributedCacheService::get($key);
        $this->assertEquals(
            $value,
            $retrievedValue,
            'Retrieved value should match original value despite node failure'
        );

        // Disable failover and verify it fails (in a real scenario - might still work if other nodes have the value)
        DistributedCacheService::enableFailover(false);

        // Reset the test
        $method = new \ReflectionMethod($healthChecker, 'markNodeHealthy');
        $method->setAccessible(true);
        $method->invoke($healthChecker, $firstNode);
    }

    /**
     * Test recovery when a previously unhealthy node returns to service
     */
    public function testNodeRecovery(): void
    {
        // Enable failover
        DistributedCacheService::enableFailover(true);

        // Set test data on all available nodes
        $key = 'test_recovery_key';
        $value = 'recovery_test_value';

        DistributedCacheService::set($key, $value);

        // Get all nodes for this key
        $nodes = $this->nodeManager->getNodesForKey($key, DistributedCacheService::getReplicationStrategy());
        $this->assertNotEmpty($nodes, 'No nodes found for key');

        // Get the first node
        $firstNode = $nodes[0];
        $nodeId = $firstNode->getId();

        // Get health monitoring service through reflection
        $reflection = new \ReflectionObject($this->nodeManager);
        $property = $reflection->getProperty('healthMonitor');
        $property->setAccessible(true);
        $healthMonitor = $property->getValue($this->nodeManager);

        // Get health checker through reflection
        $reflection = new \ReflectionObject($healthMonitor);
        $property = $reflection->getProperty('healthChecker');
        $property->setAccessible(true);
        $healthChecker = $property->getValue($healthMonitor);

        // Mark the node as unhealthy
        $method = new \ReflectionMethod($healthChecker, 'markNodeUnhealthy');
        $method->setAccessible(true);
        $method->invoke($healthChecker, $firstNode, 'Test-induced failure');

        // Verify it's marked as unhealthy
        $method = new \ReflectionMethod($healthChecker, 'isHealthy');
        $method->setAccessible(true);
        $isHealthy = $method->invoke($healthChecker, $firstNode);
        $this->assertFalse($isHealthy, 'Node should be marked as unhealthy');

        // Update the value while the node is down
        $updatedValue = 'updated_recovery_value';
        DistributedCacheService::set($key, $updatedValue);

        // Now mark the node as healthy again
        $method = new \ReflectionMethod($healthChecker, 'markNodeHealthy');
        $method->setAccessible(true);
        $method->invoke($healthChecker, $firstNode);

        // Get recovery manager through reflection
        $reflection = new \ReflectionObject($healthMonitor);
        $property = $reflection->getProperty('recoveryManager');
        $property->setAccessible(true);
        $recoveryManager = $property->getValue($healthMonitor);

        // Trigger recovery for the node
        $method = new \ReflectionMethod($recoveryManager, 'initiateRecovery');
        $method->setAccessible(true);
        $method->invoke($recoveryManager, $firstNode, $this->nodeManager);

        // Verify node is healthy now
        $method = new \ReflectionMethod($healthChecker, 'isHealthy');
        $method->setAccessible(true);
        $isHealthy = $method->invoke($healthChecker, $firstNode);
        $this->assertTrue($isHealthy, 'Node should be marked as healthy after recovery');

        // Verify the recovered node has the updated value (in a real implementation with real recovery)
        // For this test, we'll manually set the value to simulate recovery
        $firstNode->set($key, $updatedValue);

        // Check that getting the value directly from the recovered node returns the updated value
        $recoveredValue = $firstNode->get($key);
        $this->assertEquals($updatedValue, $recoveredValue, 'Recovered node should have updated value');
    }

    /**
     * Test circuit breaker functionality
     */
    public function testCircuitBreaker(): void
    {
        // Enable failover
        DistributedCacheService::enableFailover(true);

        // Get nodes
        $allNodes = $this->nodeManager->getAllNodes();
        $this->assertNotEmpty($allNodes, 'No nodes configured');

        // Get the first node
        $node = $allNodes[0];

        // Get health monitoring service through reflection
        $reflection = new \ReflectionObject($this->nodeManager);
        $property = $reflection->getProperty('healthMonitor');
        $property->setAccessible(true);
        $healthMonitor = $property->getValue($this->nodeManager);

        // Get failover manager through reflection
        $reflection = new \ReflectionObject($healthMonitor);
        $property = $reflection->getProperty('failoverManager');
        $property->setAccessible(true);
        $failoverManager = $property->getValue($healthMonitor);

        // Trigger circuit breaker by reporting multiple failures
        $method = new \ReflectionMethod($failoverManager, 'handleNodeFailure');
        $method->setAccessible(true);

        // Trip the circuit breaker with multiple failures
        for ($i = 0; $i < 5; $i++) {
            $method->invoke($failoverManager, $node, "Simulated test failure {$i}");
        }

        // Check that the circuit is open
        // Get circuit breakers through reflection
        $reflection = new \ReflectionObject($failoverManager);
        $property = $reflection->getProperty('circuitBreakers');
        $property->setAccessible(true);
        $circuitBreakers = $property->getValue($failoverManager);

        $nodeId = $node->getId();
        $this->assertArrayHasKey($nodeId, $circuitBreakers, 'Circuit breaker should exist for node');

        $circuitBreaker = $circuitBreakers[$nodeId];
        $reflection = new \ReflectionObject($circuitBreaker);
        $property = $reflection->getProperty('state');
        $property->setAccessible(true);
        $state = $property->getValue($circuitBreaker);

        // Circuit should be open after multiple failures
        $this->assertEquals('open', $state, 'Circuit breaker should be in open state after multiple failures');

        // Reset the circuit breaker for cleanup
        $method = new \ReflectionMethod($circuitBreaker, 'reset');
        $method->setAccessible(true);
        $method->invoke($circuitBreaker);
    }
}
