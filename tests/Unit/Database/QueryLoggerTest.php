<?php

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\QueryLogger;
use Glueful\Logging\LogManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the QueryLogger class with focus on performance optimizations
 * and N+1 query detection
 */
class QueryLoggerTest extends TestCase
{
    protected QueryLogger $queryLogger;
    protected LogManager|null|\PHPUnit\Framework\MockObject\MockObject $mockLogger;

    protected function setUp(): void
    {
        $this->mockLogger = $this->getMockBuilder(LogManager::class)
            ->getMock();
        /** @var LogManager $mockLogger */
        $mockLogger = $this->mockLogger;
        $this->queryLogger = new QueryLogger($mockLogger);

        // Configure for testing
        $this->queryLogger->configure(true, true, 100);
    }

    public function testAuditLoggingSampling()
    {
        // Configure with 50% sampling rate
        $this->queryLogger->configureAuditLogging(true, 0.5, false, 10);

        // Get initial metrics
        $initialMetrics = $this->queryLogger->getAuditPerformanceMetrics();

        // Simulate 100 queries to sensitive tables
        for ($i = 0; $i < 100; $i++) {
            $this->queryLogger->logQuery(
                "UPDATE users SET last_login = NOW() WHERE id = $i",
                [],
                microtime(true)
            );
        }

        // Get updated metrics
        $metrics = $this->queryLogger->getAuditPerformanceMetrics();

        // With 50% sampling, we expect roughly 50 operations logged and 50 skipped
        // Allow for some variance due to randomness
        $totalOps = $metrics['total_operations'] - $initialMetrics['total_operations'];
        $loggedOps = $metrics['logged_operations'] - $initialMetrics['logged_operations'];
        $skippedOps = $metrics['skipped_operations'] - $initialMetrics['skipped_operations'];

        $this->assertEquals(100, $totalOps, "Should have counted 100 total operations");
        $this->assertLessThan(80, $loggedOps, "Should have logged significantly less than 100% with 50% sampling");
        $this->assertGreaterThan(20, $loggedOps, "Should have logged a reasonable number with 50% sampling");
        $this->assertEquals($totalOps, $loggedOps + $skippedOps, "Total operations should equal logged + skipped");
    }

    public function testAuditLoggingBatching()
    {
        // Setup a mock to track how many times the audit logging is called
        $auditLogger = $this->getMockBuilder('Glueful\Logging\AuditLogger')
            ->disableOriginalConstructor()
            ->addMethods(['dataEvent'])
            ->getMock();

        // Replace the singleton instance with our mock using reflection
        $reflectionClass = new \ReflectionClass('Glueful\Logging\AuditLogger');
        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, $auditLogger);

        // Expect the dataEvent method to be called exactly once (for the batch)
        $auditLogger->expects($this->once())
            ->method('dataEvent');

        // Configure with batching enabled, batch size of 5
        $this->queryLogger->configureAuditLogging(true, 1.0, true, 5);

        // Run 5 queries (exactly one batch)
        for ($i = 0; $i < 5; $i++) {
            $this->queryLogger->logQuery(
                "UPDATE users SET last_login = NOW() WHERE id = $i",
                [],
                microtime(true)
            );
        }
    }

    public function testN1QueryDetection()
    {
        // Configure N+1 detection
        $this->queryLogger->configureN1Detection(5, 5);

        // Setup logger mock to verify warning is logged
        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Potential N+1 query pattern detected')
            );

        // Simulate an N+1 query pattern
        for ($i = 1; $i <= 10; $i++) {
            $this->queryLogger->logQuery(
                "SELECT * FROM posts WHERE author_id = ?",
                [$i],
                microtime(true)
            );
            usleep(10000); // Small delay to avoid timing issues
        }
    }

    public function testN1FixRecommendation()
    {
        // Use reflection to access the protected method
        $reflectionClass = new \ReflectionClass('Glueful\Database\QueryLogger');
        $method = $reflectionClass->getMethod('generateN1FixRecommendation');
        $method->setAccessible(true);

        // Test for ID-based queries
        $idQuery = "SELECT * FROM orders WHERE user_id = 123";
        $recommendation = $method->invoke($this->queryLogger, $idQuery, ['orders']);
        $this->assertStringContainsString('eager loading', $recommendation);
        $this->assertStringContainsString('WHERE IN clause', $recommendation);

        // Test for single table without JOIN
        $singleTableQuery = "SELECT * FROM products WHERE category = 'electronics'";
        $recommendation = $method->invoke($this->queryLogger, $singleTableQuery, ['products']);
        $this->assertStringContainsString('JOIN', $recommendation);

        // Test for LIMIT 1 queries
        $limitQuery = "SELECT * FROM orders WHERE user_id = 5 LIMIT 1";
        $recommendation = $method->invoke($this->queryLogger, $limitQuery, ['orders']);
        $this->assertStringContainsString('batch query', $recommendation);
    }

    public function testMemoryManagement()
    {
        // Access the recentQueries property via reflection
        $reflectionClass = new \ReflectionClass('Glueful\Database\QueryLogger');
        $property = $reflectionClass->getProperty('recentQueries');
        $property->setAccessible(true);

        // Generate 2000 queries
        for ($i = 0; $i < 2000; $i++) {
            $this->queryLogger->logQuery(
                "SELECT * FROM test_table WHERE id = $i",
                [$i],
                microtime(true)
            );
        }

        // Verify that the size is limited (should be max 500 as implemented)
        $recentQueries = $property->getValue($this->queryLogger);
        $this->assertLessThanOrEqual(
            500,
            count($recentQueries),
            "Recent queries array should be limited in size"
        );
    }

    protected function tearDown(): void
    {
        // If we modified the AuditLogger singleton, reset it
        try {
            $reflectionClass = new \ReflectionClass('Glueful\Logging\AuditLogger');
            $instanceProperty = $reflectionClass->getProperty('instance');
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue(null, null);
        } catch (\Exception $e) {
            // Ignore any errors
        }
    }
}
