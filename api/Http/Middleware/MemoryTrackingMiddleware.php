<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Performance\MemoryManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware for tracking memory usage during HTTP requests
 */
class MemoryTrackingMiddleware implements MiddlewareInterface
{
    /**
     * @var MemoryManager
     */
    private $memoryManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var float
     */
    private $sampleRate;

    /**
     * Create a new memory tracking middleware instance
     *
     * @param MemoryManager $memoryManager
     * @param LoggerInterface|null $logger Optional logger instance, uses NullLogger if not provided
     */
    public function __construct(MemoryManager $memoryManager, ?LoggerInterface $logger = null)
    {
        $this->memoryManager = $memoryManager;
        $this->logger = $logger ?? new NullLogger();
        $this->enabled = config('app.performance.memory.monitoring.enabled', true);
        $this->sampleRate = config('app.performance.memory.monitoring.sample_rate', 0.01);
    }

    /**
     * Process a request and return a response
     *
     * @param Request $request
     * @param RequestHandlerInterface $handler
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$this->enabled || mt_rand(1, 100) / 100 > $this->sampleRate) {
            return $handler->handle($request);
        }

        // Record initial memory usage
        $startMemory = memory_get_usage(true);
        $startPeakMemory = memory_get_peak_usage(true);
        $startTime = microtime(true);

        try {
            // Process the request
            $response = $handler->handle($request);

            // Record memory usage after processing
            $endMemory = memory_get_usage(true);
            $endPeakMemory = memory_get_peak_usage(true);
            $endTime = microtime(true);

            // Calculate usage statistics
            $route = $request->getPathInfo();
            $method = $request->getMethod();
            $memoryUsed = $endMemory - $startMemory;
            $peakIncrease = $endPeakMemory - $startPeakMemory;
            $executionTime = ($endTime - $startTime) * 1000; // milliseconds

            // Log memory usage information
            $this->logMemoryUsage($route, $method, $memoryUsed, $endPeakMemory, $executionTime);

            // Check if usage is above thresholds
            $usage = $this->memoryManager->monitor();

            // Add memory usage headers to response if significant
            if ($memoryUsed > 1048576) { // More than 1MB used
                $response->headers->set('X-Memory-Used', $this->formatBytes($memoryUsed));
                $response->headers->set('X-Memory-Peak', $this->formatBytes($endPeakMemory));
            }

            return $response;
        } catch (\Throwable $e) {
            // Even on error, log memory usage
            $endMemory = memory_get_usage(true);
            $endPeakMemory = memory_get_peak_usage(true);
            $endTime = microtime(true);

            $route = $request->getPathInfo();
            $method = $request->getMethod();
            $memoryUsed = $endMemory - $startMemory;
            $executionTime = ($endTime - $startTime) * 1000; // milliseconds

            $this->logMemoryUsage($route, $method, $memoryUsed, $endPeakMemory, $executionTime, true);

            // Re-throw the exception
            throw $e;
        }
    }

    /**
     * Log memory usage information
     *
     * @param string $route
     * @param string $method
     * @param int $memoryUsed
     * @param int $peakMemory
     * @param float $executionTime
     * @param bool $isError
     * @return void
     */
    private function logMemoryUsage(
        string $route,
        string $method,
        int $memoryUsed,
        int $peakMemory,
        float $executionTime,
        bool $isError = false
    ): void {
        $logLevel = config('app.performance.memory.monitoring.log_level', 'info');

        // Only log if memory usage is high or there was an error
        if ($memoryUsed > 5242880 || $isError) { // 5MB threshold for logging
            $message = sprintf(
                'Memory usage for %s %s: %s used, %s peak, %.2fms execution time',
                $method,
                $route,
                $this->formatBytes($memoryUsed),
                $this->formatBytes($peakMemory),
                $executionTime
            );

            $context = [
                'route' => $route,
                'method' => $method,
                'memory_used' => $memoryUsed,
                'peak_memory' => $peakMemory,
                'execution_time_ms' => $executionTime,
                'is_error' => $isError
            ];

            // Log at the appropriate level
            $this->logger->log($logLevel, $message, $context);

            // Force garbage collection if memory usage is high
            if ($memoryUsed > 20971520) { // 20MB threshold for GC
                $this->memoryManager->forceGarbageCollection();
            }
        }
    }

    /**
     * Format bytes into a human-readable string
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
