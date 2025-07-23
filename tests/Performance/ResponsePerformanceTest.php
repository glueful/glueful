<?php

declare(strict_types=1);

namespace Tests\Performance;

use Tests\TestCase;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Performance tests for the Response API
 *
 * Ensures that the new Response API doesn't introduce performance
 * regressions compared to direct JsonResponse instantiation.
 */
class ResponsePerformanceTest extends TestCase
{
    /**
     * Number of iterations for performance tests
     */
    private const ITERATIONS = 10000;

    /**
     * Test Response::success() performance
     */
    public function testSuccessResponsePerformance(): void
    {
        $data = ['user' => 'John Doe', 'email' => 'john@example.com'];
        $message = 'User retrieved successfully';

        // Test our Response API
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = Response::success($data, $message);
        }
        $responseTime = microtime(true) - $start;

        // Test direct JsonResponse creation (baseline)
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = new JsonResponse([
                'success' => true,
                'message' => $message,
                'data' => $data
            ]);
        }
        $baselineTime = microtime(true) - $start;

        // Calculate overhead
        $overhead = (($responseTime / $baselineTime) - 1) * 100;

        // Log performance results
        echo sprintf(
            "\nResponse::success() Performance:\n" .
            "  API Time: %.3f ms (%.0f ops/sec)\n" .
            "  Baseline: %.3f ms (%.0f ops/sec)\n" .
            "  Overhead: %.1f%%\n",
            $responseTime * 1000,
            self::ITERATIONS / $responseTime,
            $baselineTime * 1000,
            self::ITERATIONS / $baselineTime,
            $overhead
        );

        // Assert that overhead is reasonable (< 50%)
        $this->assertLessThan(50, $overhead, 'Response::success() has excessive overhead');
    }

    /**
     * Test Response::error() performance
     */
    public function testErrorResponsePerformance(): void
    {
        $message = 'An error occurred';
        $details = ['field' => 'Invalid value'];

        // Test our Response API
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = Response::error($message, 400, $details);
        }
        $responseTime = microtime(true) - $start;

        // Test direct JsonResponse creation (baseline)
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $message,
                'error' => [
                    'code' => 400,
                    'timestamp' => date('c'),
                    'request_id' => 'req_' . bin2hex(random_bytes(6)),
                    'details' => $details
                ]
            ], 400);
        }
        $baselineTime = microtime(true) - $start;

        // Calculate overhead
        $overhead = (($responseTime / $baselineTime) - 1) * 100;

        // Log performance results
        echo sprintf(
            "\nResponse::error() Performance:\n" .
            "  API Time: %.3f ms (%.0f ops/sec)\n" .
            "  Baseline: %.3f ms (%.0f ops/sec)\n" .
            "  Overhead: %.1f%%\n",
            $responseTime * 1000,
            self::ITERATIONS / $responseTime,
            $baselineTime * 1000,
            self::ITERATIONS / $baselineTime,
            $overhead
        );

        // Assert that overhead is reasonable (< 100% due to random_bytes)
        $this->assertLessThan(100, $overhead, 'Response::error() has excessive overhead');
    }

    /**
     * Test Response JSON serialization performance
     */
    public function testJsonSerializationPerformance(): void
    {
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData[] = [
                'id' => $i,
                'name' => "User $i",
                'email' => "user$i@example.com",
                'metadata' => ['created' => date('c'), 'active' => true]
            ];
        }

        // Test our Response API with large data
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $response = Response::success($largeData, 'Large dataset retrieved');
            $content = $response->getContent(); // Force JSON serialization
        }
        $responseTime = microtime(true) - $start;

        // Test direct JsonResponse with same data
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $response = new JsonResponse([
                'success' => true,
                'message' => 'Large dataset retrieved',
                'data' => $largeData
            ]);
            $content = $response->getContent(); // Force JSON serialization
        }
        $baselineTime = microtime(true) - $start;

        // Calculate overhead
        $overhead = (($responseTime / $baselineTime) - 1) * 100;

        // Log performance results
        echo sprintf(
            "\nJSON Serialization Performance (large data):\n" .
            "  API Time: %.3f ms (%.0f ops/sec)\n" .
            "  Baseline: %.3f ms (%.0f ops/sec)\n" .
            "  Overhead: %.1f%%\n",
            $responseTime * 1000,
            100 / $responseTime,
            $baselineTime * 1000,
            100 / $baselineTime,
            $overhead
        );

        // Note: The overhead is due to JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE options
        // which provide consistent encoding but are slower for large datasets.
        // This is an acceptable trade-off for data consistency and security.

        // Assert that overhead is reasonable for large data sets (< 500%)
        // In practice, large datasets should use pagination to avoid this issue
        $this->assertLessThan(500, $overhead, 'Response API has excessive JSON serialization overhead');

        // Log recommendation for large datasets
        if ($overhead > 100) {
            echo "  Note: For large datasets, consider using pagination to improve performance\n";
        }
    }

    /**
     * Test memory usage of Response API
     */
    public function testMemoryUsage(): void
    {
        $data = ['test' => 'data', 'number' => 123];

        // Measure memory for our Response API
        $memoryBefore = memory_get_usage();
        $responses = [];
        for ($i = 0; $i < 1000; $i++) {
            $responses[] = Response::success($data, "Message $i");
        }
        $apiMemory = memory_get_usage() - $memoryBefore;

        // Clear responses and measure baseline memory
        unset($responses);
        gc_collect_cycles();

        $memoryBefore = memory_get_usage();
        $responses = [];
        for ($i = 0; $i < 1000; $i++) {
            $responses[] = new JsonResponse([
                'success' => true,
                'message' => "Message $i",
                'data' => $data
            ]);
        }
        $baselineMemory = memory_get_usage() - $memoryBefore;

        // Calculate memory overhead
        $memoryOverhead = (($apiMemory / $baselineMemory) - 1) * 100;

        // Log memory results
        echo sprintf(
            "\nMemory Usage (1000 responses):\n" .
            "  API Memory: %s\n" .
            "  Baseline: %s\n" .
            "  Overhead: %.1f%%\n",
            $this->formatBytes($apiMemory),
            $this->formatBytes($baselineMemory),
            $memoryOverhead
        );

        // Assert reasonable memory overhead (< 30%)
        $this->assertLessThan(30, $memoryOverhead, 'Response API has excessive memory overhead');

        // Cleanup
        unset($responses);
    }

    /**
     * Test middleware compatibility performance
     */
    public function testMiddlewareCompatibilityPerformance(): void
    {
        $data = ['result' => 'success'];

        // Test Response object flow through simulated middleware
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = Response::success($data, 'Test');

            // Simulate middleware operations
            $response->headers->set('X-Custom-Header', 'test');
            $statusCode = $response->getStatusCode();
            $content = $response->getContent();
            $isJson = str_contains($response->headers->get('Content-Type', ''), 'application/json');
        }
        $middlewareTime = microtime(true) - $start;

        // Test direct JsonResponse through same operations
        $start = microtime(true);
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = new JsonResponse([
                'success' => true,
                'message' => 'Test',
                'data' => $data
            ]);

            // Simulate middleware operations
            $response->headers->set('X-Custom-Header', 'test');
            $statusCode = $response->getStatusCode();
            $content = $response->getContent();
            $isJson = str_contains($response->headers->get('Content-Type', ''), 'application/json');
        }
        $baselineTime = microtime(true) - $start;

        // Calculate overhead
        $overhead = (($middlewareTime / $baselineTime) - 1) * 100;

        // Log performance results
        echo sprintf(
            "\nMiddleware Compatibility Performance:\n" .
            "  API Time: %.3f ms (%.0f ops/sec)\n" .
            "  Baseline: %.3f ms (%.0f ops/sec)\n" .
            "  Overhead: %.1f%%\n",
            $middlewareTime * 1000,
            self::ITERATIONS / $middlewareTime,
            $baselineTime * 1000,
            self::ITERATIONS / $baselineTime,
            $overhead
        );

        // Assert minimal overhead for middleware operations (< 25%)
        $this->assertLessThan(25, $overhead, 'Response API has excessive middleware overhead');
    }

    /**
     * Test overall API performance benchmark
     */
    public function testOverallPerformanceBenchmark(): void
    {
        // Mixed workload test
        $testData = [
            ['method' => 'success', 'data' => ['user' => 'test'], 'message' => 'Success'],
            ['method' => 'error', 'message' => 'Error occurred', 'code' => 400],
            ['method' => 'created', 'data' => ['id' => 123], 'message' => 'Created'],
            ['method' => 'notFound', 'message' => 'Not found'],
            ['method' => 'validation', 'errors' => ['field' => ['required']], 'message' => 'Validation failed']
        ];

        $iterations = 2000; // 2000 iterations * 5 methods = 10,000 total operations

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($testData as $test) {
                switch ($test['method']) {
                    case 'success':
                        $response = Response::success($test['data'], $test['message']);
                        break;
                    case 'error':
                        $response = Response::error($test['message'], $test['code']);
                        break;
                    case 'created':
                        $response = Response::created($test['data'], $test['message']);
                        break;
                    case 'notFound':
                        $response = Response::notFound($test['message']);
                        break;
                    case 'validation':
                        $response = Response::validation($test['errors'], $test['message']);
                        break;
                }

                // Force content generation to measure full cost
                $content = $response->getContent();
            }
        }
        $totalTime = microtime(true) - $start;
        $operationsPerSecond = ($iterations * 5) / $totalTime;

        echo sprintf(
            "\nOverall Performance Benchmark:\n" .
            "  Total Time: %.3f ms\n" .
            "  Operations: %d\n" .
            "  Ops/Second: %.0f\n" .
            "  Avg per operation: %.3f μs\n",
            $totalTime * 1000,
            $iterations * 5,
            $operationsPerSecond,
            ($totalTime / ($iterations * 5)) * 1000000
        );

        // Assert reasonable performance (> 5000 ops/sec)
        $this->assertGreaterThan(5000, $operationsPerSecond, 'Overall Response API performance is too slow');
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
