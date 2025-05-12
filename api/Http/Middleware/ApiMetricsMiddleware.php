<?php

namespace Glueful\Http\Middleware;

use Glueful\Services\ApiMetricsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for collecting API metrics
 *
 * Tracks API request metrics including response time, endpoints used,
 * error rates, and stores them asynchronously for reporting
 */
class ApiMetricsMiddleware implements MiddlewareInterface
{
    private ?ApiMetricsService $metricsService = null;
    private static array $metricData = [];
    private static float $startTime = 0;

    public function __construct()
    {
        try {
            $this->metricsService = new ApiMetricsService();

            // Register shutdown function to ensure metrics are recorded
            register_shutdown_function([$this, 'recordMetricsOnShutdown']);
        } catch (\Exception $e) {
            // Continue without a metrics service, so API requests still work
            $this->metricsService = null;
        }
    }

    /**
     * Process an incoming request and collect metrics
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Record start time for performance measurement
        self::$startTime = microtime(true);

        try {
            // Store request information for later use in the shutdown function
            self::$metricData = [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'ip' => $request->getClientIp() ?: '0.0.0.0',
                'timestamp' => time(),
                // Other fields will be filled in by the shutdown function
                'response_time' => 0,
                'status_code' => 200, // Default value
                'is_error' => false
            ];

            // Process the request through the next handler
            $response = $handler->handle($request);

            // If we get here, update the status code from the actual response
            self::$metricData['status_code'] = $response->getStatusCode();
            self::$metricData['is_error'] = $response->getStatusCode() >= 400;

            return $response;
        } catch (\Exception $e) {
            // Mark as error in the metrics
            self::$metricData['status_code'] = 500;
            self::$metricData['is_error'] = true;

            // Rethrow the exception to be handled upstream
            throw $e;
        }
    }

    /**
     * Record metrics during PHP shutdown
     * This ensures metrics are recorded even if the normal execution flow is interrupted
     */
    public function recordMetricsOnShutdown(): void
    {
        // Skip if no metrics data was collected or service is not available
        if (empty(self::$metricData) || $this->metricsService === null) {
            return;
        }

        try {
            // Calculate response time
            $endTime = microtime(true);
            $responseTime = (self::$startTime > 0) ? ($endTime - self::$startTime) * 1000 : 0;
            self::$metricData['response_time'] = $responseTime;

            // Call the metrics service
            $this->metricsService->recordMetricAsync(self::$metricData);
        } catch (\Exception $e) {
            // Silently fail - we don't want to break functionality due to metrics issues
        }
    }
}
