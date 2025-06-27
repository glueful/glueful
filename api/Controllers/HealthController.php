<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Services\HealthService;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Glueful\Constants\ErrorCodes;
use Glueful\Http\Router;
use Glueful\Services\ApiMetricsService;
use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;

class HealthController extends BaseController
{
    /**
     * Constructor
     *
     * @param RepositoryFactory|null $repositoryFactory
     * @param AuthenticationManager|null $authManager
     * @param AuditLogger|null $auditLogger
     * @param Request|null $request
     */
    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?Request $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);
    }
    /**
     * Get overall system health status
     *
     * Public endpoint with rate limiting and caching for DDoS protection
     *
     * @return mixed HTTP response with health check results
     */
    public function index()
    {
        // Apply conditional rate limiting based on authentication
        // Anonymous users: 30 requests/minute per IP
        // Authenticated users: 100 requests/minute per user
        $this->conditionalRateLimit('health_check');

        // Cache response with short TTL for monitoring tools
        $response = $this->cacheResponse('health_overall', function () {
            // Optional audit logging for security monitoring
            if ($this->getCurrentUser()) {
                $this->auditLogger->audit(
                    'health',
                    'health_check_authenticated',
                    'info',
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'endpoint' => 'overall_health',
                        'ip' => $this->request->getClientIp()
                    ]
                );
            }

            $health = HealthService::getOverallHealth();

            if ($health['status'] === 'error') {
                return [
                    'error' => true,
                    'status' => ErrorCodes::SERVICE_UNAVAILABLE,
                    'message' => 'System health check failed',
                    'data' => $health
                ];
            }

            return [
                'error' => false,
                'status' => ErrorCodes::SUCCESS,
                'message' => 'System health check completed',
                'data' => $health
            ];
        }, 30); // 30-second cache for fresh monitoring data

        // Convert cached response to actual Response object
        if ($response['error']) {
            return Response::error(
                $response['message'],
                $response['status'],
                $response['data']
            );
        }

        // Use private caching for health checks (short TTL for monitoring tools)
        return $this->privateCached(
            Response::success($response['data'], $response['message']),
            30  // 30 seconds for monitoring tools
        );
    }

    /**
     * Get database health status only
     *
     * @return mixed HTTP response with database health
     */
    public function database()
    {
        $health = HealthService::checkDatabase();

        if ($health['status'] === 'error') {
            return Response::error(
                'Database health check failed',
                ErrorCodes::SERVICE_UNAVAILABLE,
                $health
            );
        }

        // Use private caching for database health (monitoring tools)
        return $this->privateCached(
            Response::success($health, 'Database health check completed'),
            30  // 30 seconds
        );
    }

    /**
     * Get cache health status only
     *
     * @return mixed HTTP response with cache health
     */
    public function cache()
    {
        $health = HealthService::checkCache();

        if ($health['status'] === 'error') {
            return Response::error(
                'Cache health check failed',
                ErrorCodes::SERVICE_UNAVAILABLE,
                $health
            );
        }

        // Use private caching for cache health (monitoring tools)
        return $this->privateCached(
            Response::success($health, 'Cache health check completed'),
            30  // 30 seconds
        );
    }

    /**
     * Get detailed production monitoring metrics
     *
     * Comprehensive health endpoint with Response API metrics, middleware pipeline status,
     * performance indicators, and system monitoring data for production environments.
     *
     * @return Response HTTP response with detailed health metrics
     */
    public function detailed(): Response
    {
        // Require permission for detailed health monitoring
        $this->requirePermission('system.health.detailed', 'health:detailed');

        // Apply stricter rate limiting for detailed monitoring
        $this->rateLimit('health_detailed', 10, 60); // 10 requests per minute

        // Audit detailed health access
        $this->auditLogger->audit(
            'health',
            'detailed_health_check',
            'info',
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'endpoint' => 'detailed_health',
                'ip' => $this->request->getClientIp(),
                'is_admin' => $this->isAdmin()
            ]
        );

        // Cache detailed health metrics for shorter duration (more frequent updates)
        $health = $this->cacheResponse('health_detailed', function () {
            return $this->generateDetailedHealthMetrics();
        }, 60); // 1 minute cache for detailed monitoring

        // Use private caching with ETag for production monitoring tools
        return $this->privateCached(
            Response::success($health, 'Detailed health check completed')
                ->withCacheHeaders(60, false) // Private cache for 1 minute
                ->withETag(md5(serialize($health))),
            60
        );
    }

    /**
     * Get middleware pipeline status
     *
     * @return Response HTTP response with middleware health status
     */
    public function middleware(): Response
    {
        $this->requirePermission('system.middleware.health', 'health:middleware');
        $this->rateLimit('health_middleware', 20, 60);

        $middlewareHealth = $this->checkMiddlewarePipeline();

        return $this->privateCached(
            Response::success($middlewareHealth, 'Middleware health check completed'),
            120 // 2 minutes cache
        );
    }

    /**
     * Get Response API performance metrics
     *
     * @return Response HTTP response with Response API performance data
     */
    public function responseApi(): Response
    {
        $this->requirePermission('system.response_api.metrics', 'health:response_api');
        $this->rateLimit('health_response_api', 30, 60);

        $responseApiMetrics = [
            'performance' => $this->getResponseApiPerformance(),
            'response_times' => $this->getAverageResponseTime(),
            'error_rates' => $this->getErrorRate(),
            'throughput' => $this->getResponseApiThroughput(),
            'middleware_performance' => $this->getMiddlewarePerformanceMetrics()
        ];

        return $this->privateCached(
            Response::success($responseApiMetrics, 'Response API metrics retrieved'),
            30 // 30 seconds for real-time monitoring
        );
    }

    /**
     * Generate comprehensive detailed health metrics
     */
    private function generateDetailedHealthMetrics(): array
    {
        $startTime = microtime(true);

        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => $this->getApplicationVersion(),
            'environment' => env('APP_ENV', 'unknown'),
            'debug_mode' => env('APP_DEBUG', false),

            // System metrics
            'system' => [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'memory_limit' => $this->parseMemoryLimit(ini_get('memory_limit')),
                'memory_usage_percent' => $this->calculateMemoryUsagePercent(),
                'uptime' => $this->getUptime(),
                'load_average' => $this->getSystemLoad(),
                'disk_usage' => $this->getDiskUsage(),
                'php_version' => PHP_VERSION,
                'extensions_loaded' => count(get_loaded_extensions()),
            ],

            // Response API metrics
            'response_api' => [
                'ops_per_second' => $this->getResponseApiPerformance(),
                'avg_response_time' => $this->getAverageResponseTime(),
                'error_rate' => $this->getErrorRate(),
                'throughput' => $this->getResponseApiThroughput(),
                'active_requests' => $this->getActiveRequestCount(),
                'response_size_avg' => $this->getAverageResponseSize(),
            ],

            // Middleware pipeline
            'middleware' => [
                'pipeline_health' => $this->checkMiddlewarePipeline(),
                'active_middleware' => $this->getActiveMiddlewareCount(),
                'middleware_performance' => $this->getMiddlewarePerformanceMetrics(),
                'validation_metrics' => $this->getResponseValidationMetrics(),
                'compression_metrics' => $this->getCompressionMetrics(),
            ],

            // Cache performance
            'cache' => [
                'response_cache_hits' => $this->getResponseCacheHits(),
                'cache_hit_ratio' => $this->getCacheHitRatio(),
                'cache_size' => $this->getCacheSize(),
                'cache_memory_usage' => $this->getCacheMemoryUsage(),
                'edge_cache_performance' => $this->getEdgeCacheMetrics(),
            ],

            // Database health
            'database' => [
                'connection_status' => $this->getDatabaseConnectionStatus(),
                'connection_pool' => $this->getConnectionPoolMetrics(),
                'query_performance' => $this->getQueryPerformanceMetrics(),
                'slow_queries' => $this->getSlowQueryCount(),
            ],

            // Security metrics
            'security' => [
                'rate_limit_violations' => $this->getRateLimitViolations(),
                'authentication_failures' => $this->getAuthenticationFailures(),
                'blocked_ips' => $this->getBlockedIpCount(),
                'security_events' => $this->getSecurityEventCount(),
            ],

            // Application metrics
            'application' => [
                'route_cache_status' => $this->getRouteCacheStatus(),
                'extensions_health' => $this->getExtensionsHealth(),
                'queue_health' => $this->getQueueHealth(),
                'notification_health' => $this->getNotificationHealth(),
            ]
        ];

        // Calculate overall health status based on metrics
        $health['status'] = $this->calculateOverallHealthStatus($health);
        $health['generation_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        return $health;
    }

    /**
     * Get Response API performance metrics
     */
    private function getResponseApiPerformance(): array
    {
        try {
            $metricsService = container()->get(ApiMetricsService::class);
            $metrics = $metricsService->getApiMetrics();

            $currentHour = date('Y-m-d-H');
            $requestCount = $metrics['hourly'][$currentHour]['requests'] ?? 0;

            return [
                'requests_per_hour' => $requestCount,
                'ops_per_second' => round($requestCount / 3600, 2),
                'peak_ops_per_second' => $this->getPeakOpsPerSecond(),
            ];
        } catch (\Throwable $e) {
            return [
                'requests_per_hour' => 0,
                'ops_per_second' => 0,
                'error' => 'Unable to retrieve metrics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get average response time metrics
     */
    private function getAverageResponseTime(): array
    {
        try {
            $cache = container()->get(CacheStore::class);
            $currentHour = 'response_metrics:' . date('Y-m-d-H');
            $metrics = $cache->get($currentHour, ['global' => []]);

            return [
                'current_avg_ms' => round($metrics['global']['avg_duration'] ?? 0, 2),
                'peak_response_time_ms' => $this->getPeakResponseTime(),
                'percentiles' => $this->getResponseTimePercentiles(),
            ];
        } catch (\Throwable) {
            return [
                'current_avg_ms' => 0,
                'error' => 'Unable to retrieve response time metrics'
            ];
        }
    }

    /**
     * Get error rate metrics
     */
    private function getErrorRate(): array
    {
        try {
            $cache = container()->get(CacheStore::class);
            $currentHour = 'response_metrics:' . date('Y-m-d-H');
            $metrics = $cache->get($currentHour, ['global' => []]);

            return [
                'current_error_rate_percent' => round($metrics['global']['error_rate'] ?? 0, 2),
                'total_errors' => $this->getTotalErrorCount(),
                'error_breakdown' => $this->getErrorBreakdown(),
            ];
        } catch (\Throwable) {
            return [
                'current_error_rate_percent' => 0,
                'error' => 'Unable to retrieve error rate metrics'
            ];
        }
    }

    /**
     * Check middleware pipeline health
     */
    private function checkMiddlewarePipeline(): array
    {
        try {
            $middlewareConfig = config('middleware', []);
            $globalMiddleware = $middlewareConfig['global'] ?? [];

            $health = [
                'status' => 'healthy',
                'total_middleware' => count($globalMiddleware),
                'active_middleware' => $this->getActiveMiddlewareCount(),
                'middleware_order' => array_map(function ($middleware) {
                    return is_array($middleware) ? $middleware['class'] : $middleware;
                }, $globalMiddleware),
                'pipeline_integrity' => $this->validateMiddlewarePipeline($globalMiddleware),
            ];

            // Check if critical middleware is present
            $criticalMiddleware = [
                'SecurityHeadersMiddleware',
                'RateLimiterMiddleware'
            ];

            $missingCritical = [];
            foreach ($criticalMiddleware as $critical) {
                $found = false;
                foreach ($globalMiddleware as $middleware) {
                    $className = is_array($middleware) ? $middleware['class'] : $middleware;
                    if (strpos($className, $critical) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingCritical[] = $critical;
                }
            }

            if (!empty($missingCritical)) {
                $health['status'] = 'warning';
                $health['missing_critical_middleware'] = $missingCritical;
            }

            return $health;
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => 'Unable to check middleware pipeline: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get response cache hit metrics
     */
    private function getResponseCacheHits(): array
    {
        try {
            $cache = container()->get(CacheStore::class);

            // Try to get cache statistics if available
            if (method_exists($cache, 'getStats')) {
                $stats = $cache->getStats();
                return [
                    'hits' => $stats['hits'] ?? 0,
                    'misses' => $stats['misses'] ?? 0,
                    'total_requests' => ($stats['hits'] ?? 0) + ($stats['misses'] ?? 0),
                ];
            }

            // Fallback: estimate from response metrics
            return [
                'hits' => $this->estimateCacheHits(),
                'misses' => $this->estimateCacheMisses(),
                'total_requests' => $this->getTotalCacheRequests(),
            ];
        } catch (\Throwable) {
            return [
                'hits' => 0,
                'misses' => 0,
                'error' => 'Unable to retrieve cache hit metrics'
            ];
        }
    }

    /**
     * Get cache hit ratio
     */
    private function getCacheHitRatio(): float
    {
        try {
            $cacheHits = $this->getResponseCacheHits();
            $total = $cacheHits['total_requests'] ?? 0;

            if ($total === 0) {
                return 0.0;
            }

            return round(($cacheHits['hits'] ?? 0) / $total * 100, 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    // Helper methods (implement based on available services)
    private function getApplicationVersion(): string
    {
        return config('app.version', '1.0.0');
    }

    private function getUptime(): string
    {
        try {
            if (function_exists('sys_getloadavg')) {
                $uptime = shell_exec('uptime');
                return trim($uptime) ?: 'Unable to determine uptime';
            }
            return 'Uptime not available on this system';
        } catch (\Throwable) {
            return 'Unable to determine uptime';
        }
    }

    private function parseMemoryLimit(string $memoryLimit): int
    {
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $memoryLimit;
        }
    }

    private function calculateMemoryUsagePercent(): float
    {
        $used = memory_get_usage(true);
        $limit = $this->parseMemoryLimit(ini_get('memory_limit'));

        if ($limit === 0) {
            return 0.0; // No limit set
        }

        return round(($used / $limit) * 100, 2);
    }

    private function getSystemLoad(): array
    {
        try {
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                return [
                    '1min' => $load[0] ?? 0,
                    '5min' => $load[1] ?? 0,
                    '15min' => $load[2] ?? 0,
                ];
            }
            return ['1min' => 0, '5min' => 0, '15min' => 0];
        } catch (\Throwable) {
            return ['error' => 'Unable to get system load'];
        }
    }

    private function calculateOverallHealthStatus(array $health): string
    {
        $issues = [];

        // Check memory usage
        if (($health['system']['memory_usage_percent'] ?? 0) > 90) {
            $issues[] = 'High memory usage';
        }

        // Check error rate
        if (($health['response_api']['error_rate']['current_error_rate_percent'] ?? 0) > 5) {
            $issues[] = 'High error rate';
        }

        // Check middleware status
        if (($health['middleware']['pipeline_health']['status'] ?? 'healthy') !== 'healthy') {
            $issues[] = 'Middleware pipeline issues';
        }

        // Check cache performance
        if (($health['cache']['cache_hit_ratio'] ?? 100) < 50) {
            $issues[] = 'Low cache hit ratio';
        }

        if (!empty($issues)) {
            return count($issues) > 2 ? 'critical' : 'warning';
        }

        return 'healthy';
    }

    // Placeholder methods for metrics that need implementation
    private function getPeakOpsPerSecond(): float
    {
        return 0.0;
    }
    private function getPeakResponseTime(): float
    {
        return 0.0;
    }
    private function getResponseTimePercentiles(): array
    {
        return ['p50' => 0, 'p95' => 0, 'p99' => 0];
    }
    private function getTotalErrorCount(): int
    {
        return 0;
    }
    private function getErrorBreakdown(): array
    {
        return [];
    }
    private function getActiveMiddlewareCount(): int
    {
        return count(config('middleware.global', []));
    }
    private function validateMiddlewarePipeline(array $middleware): bool
    {
        return true;
    }
    private function getResponseApiThroughput(): array
    {
        return ['requests_per_minute' => 0];
    }
    private function getActiveRequestCount(): int
    {
        return 0;
    }
    private function getAverageResponseSize(): int
    {
        return 0;
    }
    private function getMiddlewarePerformanceMetrics(): array
    {
        return [];
    }
    private function getResponseValidationMetrics(): array
    {
        return [];
    }
    private function getCompressionMetrics(): array
    {
        return [];
    }
    private function getCacheSize(): string
    {
        return '0 MB';
    }
    private function getCacheMemoryUsage(): string
    {
        return '0 MB';
    }
    private function getEdgeCacheMetrics(): array
    {
        return [];
    }
    private function getDatabaseConnectionStatus(): string
    {
        return 'connected';
    }
    private function getConnectionPoolMetrics(): array
    {
        return [];
    }
    private function getQueryPerformanceMetrics(): array
    {
        return [];
    }
    private function getSlowQueryCount(): int
    {
        return 0;
    }
    private function getRateLimitViolations(): int
    {
        return 0;
    }
    private function getAuthenticationFailures(): int
    {
        return 0;
    }
    private function getBlockedIpCount(): int
    {
        return 0;
    }
    private function getSecurityEventCount(): int
    {
        return 0;
    }
    private function getRouteCacheStatus(): array
    {
        return ['enabled' => Router::isUsingCachedRoutes()];
    }
    private function getExtensionsHealth(): array
    {
        return [];
    }
    private function getQueueHealth(): array
    {
        return [];
    }
    private function getNotificationHealth(): array
    {
        return [];
    }
    private function estimateCacheHits(): int
    {
        return 0;
    }
    private function estimateCacheMisses(): int
    {
        return 0;
    }
    private function getTotalCacheRequests(): int
    {
        return 0;
    }
    private function getDiskUsage(): array
    {
        $path = realpath(__DIR__ . '/../../storage');
        $totalSpace = disk_total_space($path);
        $freeSpace = disk_free_space($path);
        $usedSpace = $totalSpace - $freeSpace;

        return [
            'used' => $usedSpace,
            'free' => $freeSpace,
            'total' => $totalSpace,
            'usage_percent' => round(($usedSpace / $totalSpace) * 100, 2)
        ];
    }
}
