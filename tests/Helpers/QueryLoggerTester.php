<?php

namespace Glueful\Tests\Helpers;

use Glueful\Database\QueryLogger;
use Glueful\Logging\LogManager;
use Glueful\Logging\AuditLogger;

/**
 * Helper class for testing QueryLogger functionality
 *
 * This class provides convenient methods to test and validate the
 * performance optimizations and features of the QueryLogger class
 */
class QueryLoggerTester
{
    /** @var QueryLogger The QueryLogger instance being tested */
    protected QueryLogger $queryLogger;

    /** @var array Simulated database queries for testing */
    protected array $testQueries = [];

    /**
     * Create a new QueryLoggerTester instance
     *
     * @param QueryLogger|null $queryLogger An existing QueryLogger instance or null to create a new one
     */
    public function __construct(?QueryLogger $queryLogger = null)
    {
        $this->queryLogger = $queryLogger ?? new QueryLogger();
        $this->setupTestQueries();
    }

    /**
     * Get the QueryLogger instance
     *
     * @return QueryLogger
     */
    public function getQueryLogger(): QueryLogger
    {
        return $this->queryLogger;
    }

    /**
     * Test the audit logging performance with different sampling rates
     *
     * @param int $iterations Number of operations to simulate
     * @param float[] $samplingRates Array of sampling rates to test
     * @return array Performance metrics for each sampling rate
     */
    public function testAuditLoggingPerformance(int $iterations = 1000, array $samplingRates = [1.0, 0.5, 0.1, 0.01]): array
    {
        $results = [];

        foreach ($samplingRates as $rate) {
            // Configure audit logging with the current sampling rate
            $this->queryLogger->configureAuditLogging(true, $rate, false, 10);

            // Reset performance metrics
            $this->resetPerformanceMetrics();

            $startTime = microtime(true);

            // Simulate database operations
            for ($i = 0; $i < $iterations; $i++) {
                $queryIndex = $i % count($this->testQueries);
                $query = $this->testQueries[$queryIndex];

                $this->queryLogger->logQuery(
                    $query['sql'],
                    $query['params'],
                    microtime(true),
                    null,
                    true,
                    $query['purpose']
                );
            }

            $totalTime = (microtime(true) - $startTime) * 1000; // ms
            $metrics = $this->queryLogger->getAuditPerformanceMetrics();

            $results[$rate] = [
                'sampling_rate' => $rate,
                'total_operations' => $metrics['total_operations'],
                'logged_operations' => $metrics['logged_operations'],
                'skipped_operations' => $metrics['skipped_operations'],
                'total_audit_time' => $metrics['total_audit_time'],
                'avg_audit_time' => $metrics['avg_audit_time'],
                'total_execution_time' => $totalTime,
                'operations_per_second' => $iterations / ($totalTime / 1000)
            ];
        }

        return $results;
    }

    /**
     * Test batch processing performance for audit logging
     *
     * @param int $iterations Number of operations to simulate
     * @param array $batchSizes Array of batch sizes to test
     * @return array Performance metrics for each batch size
     */
    public function testBatchProcessingPerformance(int $iterations = 1000, array $batchSizes = [1, 10, 50, 100]): array
    {
        $results = [];

        foreach ($batchSizes as $batchSize) {
            // Configure audit logging with the current batch size
            $this->queryLogger->configureAuditLogging(true, 1.0, true, $batchSize);

            // Reset performance metrics
            $this->resetPerformanceMetrics();

            $startTime = microtime(true);

            // Simulate database operations
            for ($i = 0; $i < $iterations; $i++) {
                $queryIndex = $i % count($this->testQueries);
                $query = $this->testQueries[$queryIndex];

                $this->queryLogger->logQuery(
                    $query['sql'],
                    $query['params'],
                    microtime(true),
                    null,
                    true,
                    $query['purpose']
                );
            }

            // Make sure to flush any remaining batch
            $this->queryLogger->flushAuditLogBatch();

            $totalTime = (microtime(true) - $startTime) * 1000; // ms
            $metrics = $this->queryLogger->getAuditPerformanceMetrics();

            $results[$batchSize] = [
                'batch_size' => $batchSize,
                'total_operations' => $metrics['total_operations'],
                'logged_operations' => $metrics['logged_operations'],
                'total_audit_time' => $metrics['total_audit_time'],
                'avg_audit_time' => $metrics['avg_audit_time'],
                'total_execution_time' => $totalTime,
                'operations_per_second' => $iterations / ($totalTime / 1000)
            ];
        }

        return $results;
    }

    /**
     * Test N+1 query detection with different patterns
     *
     * @return array Detection results
     */
    public function testN1QueryDetection(): array
    {
        $results = [];

        // Configure N+1 detection
        $this->queryLogger->configureN1Detection(5, 5);

        // Test case 1: Typical N+1 pattern (repeated queries with different IDs)
        $baseQuery = "SELECT * FROM users WHERE id = ?";
        $this->simulateN1Pattern($baseQuery, 10);
        $results['typical_n1'] = $this->collectN1Results();

        // Test case 2: JOIN-based query that should not trigger N+1
        $joinQuery = "SELECT users.*, roles.name FROM users JOIN roles ON users.role_id = roles.id WHERE users.id = ?";
        $this->simulateN1Pattern($joinQuery, 10);
        $results['join_query'] = $this->collectN1Results();

        // Test case 3: N+1 with LIMIT 1
        $limitQuery = "SELECT * FROM posts WHERE author_id = ? LIMIT 1";
        $this->simulateN1Pattern($limitQuery, 10);
        $results['limit_query'] = $this->collectN1Results();

        return $results;
    }

    /**
     * Test that the memory management in addToRecentQueries prevents excessive memory usage
     *
     * @param int $iterations Number of queries to simulate
     * @return array Memory usage statistics
     */
    public function testMemoryManagement(int $iterations = 2000): array
    {
        $memoryBefore = memory_get_usage(true);

        // Generate many queries
        for ($i = 0; $i < $iterations; $i++) {
            $queryIndex = $i % count($this->testQueries);
            $query = $this->testQueries[$queryIndex];

            $this->queryLogger->logQuery(
                $query['sql'] . " -- iteration $i", // Make each query unique
                $query['params'],
                microtime(true),
                null,
                true,
                $query['purpose']
            );
        }

        $memoryAfter = memory_get_usage(true);

        return [
            'memory_before' => $memoryBefore,
            'memory_after' => $memoryAfter,
            'memory_increase' => $memoryAfter - $memoryBefore,
            'memory_increase_per_query' => ($memoryAfter - $memoryBefore) / $iterations,
            'iterations' => $iterations
        ];
    }

    /**
     * Reset performance metrics in the QueryLogger
     */
    protected function resetPerformanceMetrics(): void
    {
        $reflection = new \ReflectionClass($this->queryLogger);
        $property = $reflection->getProperty('auditPerformanceMetrics');
        $property->setAccessible(true);
        $property->setValue($this->queryLogger, [
            'total_operations' => 0,
            'logged_operations' => 0,
            'skipped_operations' => 0,
            'total_audit_time' => 0,
            'avg_audit_time' => 0
        ]);
    }

    /**
     * Simulate an N+1 query pattern
     *
     * @param string $query Base query to simulate
     * @param int $repetitions Number of times to repeat the query
     */
    protected function simulateN1Pattern(string $query, int $repetitions): void
    {
        for ($i = 1; $i <= $repetitions; $i++) {
            $this->queryLogger->logQuery(
                $query,
                [$i], // Different parameter each time
                microtime(true),
                null,
                true,
                "N+1 test query $i"
            );

            // Small delay to simulate real-world scenario
            usleep(10000); // 10ms
        }
    }

    /**
     * Collect N+1 detection results
     *
     * @return array Detection results
     */
    protected function collectN1Results(): array
    {
        // This would normally check logs or notifications
        // For this test helper, we'll just return the internal state
        $reflection = new \ReflectionClass($this->queryLogger);
        $property = $reflection->getProperty('recentQueries');
        $property->setAccessible(true);

        return [
            'recent_queries_count' => count($property->getValue($this->queryLogger)),
            'recent_queries_sample' => array_slice($property->getValue($this->queryLogger), 0, 3)
        ];
    }

    /**
     * Setup test queries for simulating database operations
     */
    protected function setupTestQueries(): void
    {
        $this->testQueries = [
            [
                'sql' => "SELECT * FROM users WHERE id = ?",
                'params' => [1],
                'purpose' => "Fetch user details"
            ],
            [
                'sql' => "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
                'params' => ['Test User', 'test@example.com', 'hashed_password'],
                'purpose' => "Create new user"
            ],
            [
                'sql' => "UPDATE users SET last_login = ? WHERE id = ?",
                'params' => ['2023-01-01 12:00:00', 1],
                'purpose' => "Update user login time"
            ],
            [
                'sql' => "DELETE FROM sessions WHERE expires_at < ?",
                'params' => ['2023-01-01 00:00:00'],
                'purpose' => "Clean expired sessions"
            ],
            [
                'sql' => "SELECT p.*, u.name FROM posts p JOIN users u ON p.author_id = u.id WHERE p.is_published = ?",
                'params' => [1],
                'purpose' => "Fetch published posts with author data"
            ]
        ];
    }
}
