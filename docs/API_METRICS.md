# Glueful API Metrics System

This comprehensive guide covers Glueful's enterprise-grade API metrics and monitoring system, which provides real-time performance tracking, comprehensive analytics, rate limiting monitoring, and detailed endpoint statistics for production API management.

## Table of Contents

1. [Overview](#overview)
2. [ApiMetricsService Core Features](#apimetricsservice-core-features)
3. [ApiMetricsMiddleware Integration](#apimetricsiddleware-integration)
4. [MetricsController API Endpoints](#metricscontroller-api-endpoints)
5. [Database Schema](#database-schema)
6. [Real-time Metrics Collection](#real-time-metrics-collection)
7. [Rate Limiting Integration](#rate-limiting-integration)
8. [Performance Analytics](#performance-analytics)
9. [System Health Monitoring](#system-health-monitoring)
10. [Configuration](#configuration)
11. [Usage Examples](#usage-examples)
12. [Production Optimization](#production-optimization)

## Overview

Glueful's API Metrics system provides comprehensive monitoring and analytics for API performance, usage patterns, error tracking, and system health. The system is designed for production environments with asynchronous processing, intelligent caching, and minimal performance impact.

### Key Features

- **Asynchronous Metrics Collection**: Non-blocking metrics recording with cache-based queuing
- **Dual-Table Storage**: Raw metrics and daily aggregates for optimal query performance
- **Rate Limiting Integration**: Built-in rate limiting monitoring and threshold alerts
- **Endpoint Categorization**: Automatic categorization based on URL patterns
- **Comprehensive Analytics**: Response times, error rates, usage patterns, and system health
- **Permission-Based Access**: Role-based data filtering and access control
- **Production-Ready**: Automatic data retention, bulk processing, and memory optimization

### Architecture Components

1. **ApiMetricsService**: Core metrics collection and aggregation engine
2. **ApiMetricsMiddleware**: PSR-15 middleware for request interception
3. **MetricsController**: REST API endpoints for metrics retrieval
4. **Database Schema**: Optimized tables for raw and aggregated data storage

## ApiMetricsService Core Features

### Basic Usage

```php
use Glueful\Services\ApiMetricsService;

// Initialize service
$metricsService = new ApiMetricsService();

// Record metric asynchronously (non-blocking)
$metricsService->recordMetricAsync([
    'endpoint' => '/api/users',
    'method' => 'GET',
    'response_time' => 156.4,
    'status_code' => 200,
    'is_error' => false,
    'timestamp' => time(),
    'ip' => '192.168.1.100'
]);

// Get comprehensive metrics
$metrics = $metricsService->getApiMetrics();

// Reset all metrics
$success = $metricsService->resetApiMetrics();
```

### Asynchronous Processing

The metrics system uses cache-based queuing to avoid impacting API performance:

```php
// Metrics are queued in cache for batch processing
$metric = [
    'endpoint' => $request->getPathInfo(),
    'method' => $request->getMethod(),
    'response_time' => 234.5,
    'status_code' => 200,
    'is_error' => false,
    'timestamp' => time(),
    'ip' => $request->getClientIp()
];

// Queue metric (non-blocking)
$metricsService->recordMetricAsync($metric);

// Automatic batch flush when threshold reached (default: 50 metrics)
// Or manual flush
$metricsService->flushMetrics();
```

### Comprehensive Metrics Report

The `getApiMetrics()` method returns detailed analytics:

```php
[
    'endpoints' => [
        [
            'endpoint' => '/api/users',
            'method' => 'GET',
            'route' => '/api/users',
            'calls' => 1247,
            'avgResponseTime' => 145.6,
            'errorRate' => 2.1,
            'lastCalled' => '2025-01-02 14:30:25',
            'category' => 'Users'
        ],
        [
            'endpoint' => '/api/auth/login',
            'method' => 'POST',
            'route' => '/api/auth/login',
            'calls' => 892,
            'avgResponseTime' => 89.3,
            'errorRate' => 0.8,
            'lastCalled' => '2025-01-02 14:28:45',
            'category' => 'Auth'
        ]
    ],
    'total_requests' => 15847,
    'avg_response_time' => 156.4,
    'total_errors' => 234,
    'error_rate' => 1.48,
    'rate_limits' => [
        [
            'ip' => '192.168.1.100',
            'endpoint' => '/api/users',
            'remaining' => 15,
            'limit' => 100,
            'reset_time' => '2025-01-02 15:00:00',
            'usage_percentage' => 85.0
        ]
    ],
    'requests_over_time' => [
        ['date' => '2025-01-01', 'count' => 5234],
        ['date' => '2025-01-02', 'count' => 6789]
    ],
    'categories' => ['Users', 'Auth', 'Products', 'Orders'],
    'category_distribution' => [
        ['category' => 'Users', 'count' => 4567],
        ['category' => 'Auth', 'count' => 2345],
        ['category' => 'Products', 'count' => 3456],
        ['category' => 'Orders', 'count' => 1234]
    ],
    'top_endpoints' => [
        // Top 5 endpoints by call volume
    ]
]
```

## ApiMetricsMiddleware Integration

The middleware automatically captures metrics for all API requests:

### Setup

```php
use Glueful\Http\Middleware\ApiMetricsMiddleware;

// Add to middleware stack
Router::addMiddleware(new ApiMetricsMiddleware());

// Or in middleware configuration
$middlewares = [
    'api_metrics' => ApiMetricsMiddleware::class,
    // ... other middleware
];
```

### Features

- **PSR-15 Compatible**: Standard middleware implementation
- **Automatic Request Tracking**: Captures endpoint, method, response time, status codes
- **Error Handling**: Graceful failure - metrics issues don't break API functionality
- **Shutdown Function Registration**: Ensures metrics are recorded even on errors
- **IP Address Tracking**: Client IP recording for rate limiting and analytics

### Implementation Details

```php
class ApiMetricsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $startTime = microtime(true);
        
        // Store request information
        $metricData = [
            'endpoint' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp() ?: '0.0.0.0',
            'timestamp' => time()
        ];
        
        try {
            $response = $handler->handle($request);
            $metricData['status_code'] = $response->getStatusCode();
            $metricData['is_error'] = $response->getStatusCode() >= 400;
            
            return $response;
        } catch (Exception $e) {
            $metricData['status_code'] = 500;
            $metricData['is_error'] = true;
            throw $e;
        } finally {
            // Record metrics on shutdown
            $metricData['response_time'] = (microtime(true) - $startTime) * 1000;
            $this->metricsService->recordMetricAsync($metricData);
        }
    }
}
```

## MetricsController API Endpoints

The controller provides REST endpoints for accessing metrics data:

### API Endpoints

#### Get API Metrics

```http
GET /metrics/api
Authorization: Bearer <token>
```

**Response:**
```json
{
    "success": true,
    "message": "API metrics retrieved successfully",
    "data": {
        "endpoints": [...],
        "total_requests": 15847,
        "avg_response_time": 156.4,
        "error_rate": 1.48,
        "rate_limits": [...],
        "requests_over_time": [...]
    }
}
```

#### Reset API Metrics

```http
POST /metrics/api/reset
Authorization: Bearer <token>
```

**Response:**
```json
{
    "success": true,
    "message": "API metrics reset successfully"
}
```

#### System Health Metrics

```http
GET /metrics/system
Authorization: Bearer <token>
```

**Response:**
```json
{
    "success": true,
    "message": "System health metrics retrieved successfully",
    "data": {
        "php": {
            "version": "8.2.15",
            "memory_limit": "256M",
            "max_execution_time": "30"
        },
        "memory": {
            "current_usage": "45.2 MB",
            "peak_usage": "67.8 MB"
        },
        "database": {
            "status": "connected",
            "response_time_ms": 12.4,
            "table_count": 15,
            "total_size": "124.5 MB"
        },
        "cache": {
            "type": "Redis",
            "status": "enabled",
            "memory_usage": "89.3 MB",
            "hit_rate": "95.6%"
        }
    }
}
```

### Permission-Based Access Control

```php
// Different data levels based on user roles
if ($this->isAdmin()) {
    // Full access to all metrics and sensitive data
    $ttl = 60; // 1 minute cache for admins
} else {
    // Limited data for regular users
    $ttl = 300; // 5 minutes cache for users
    // Remove sensitive information
}

// Permission checks
$this->requirePermission('system.metrics.view', 'metrics:api');
$this->requirePermission('system.health.view', 'metrics:system');
$this->requirePermission('system.metrics.reset', 'metrics:api');
```

## Database Schema

The metrics system uses three optimized tables:

### api_metrics (Raw Metrics)

```sql
CREATE TABLE api_metrics (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(12) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    response_time FLOAT NOT NULL,
    status_code INT NOT NULL,
    is_error TINYINT(1) NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL,
    ip VARCHAR(45) NOT NULL,
    INDEX idx_api_metrics_timestamp (timestamp),
    INDEX idx_api_metrics_endpoint_method (endpoint, method)
);
```

### api_metrics_daily (Aggregated Metrics)

```sql
CREATE TABLE api_metrics_daily (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(12) NOT NULL,
    date DATE NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint_key VARCHAR(266) NOT NULL,
    calls INT NOT NULL DEFAULT 0,
    total_response_time FLOAT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    last_called DATETIME NULL,
    UNIQUE KEY idx_api_metrics_daily_date_endpoint_key (date, endpoint_key),
    INDEX idx_api_metrics_daily_date (date)
);
```

### api_rate_limits (Rate Limiting)

```sql
CREATE TABLE api_rate_limits (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(12) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    remaining INT NOT NULL,
    limit INT NOT NULL,
    reset_time DATETIME NOT NULL,
    usage_percentage FLOAT NOT NULL,
    UNIQUE KEY idx_api_rate_limits_ip_endpoint (ip, endpoint)
);
```

## Real-time Metrics Collection

### Batch Processing

Metrics are collected asynchronously and processed in batches for optimal performance:

```php
// Configuration
private int $metricsFlushThreshold = 50; // Flush after 50 metrics
private int $metricsTTL = 86400 * 30; // 30 days retention
private int $aggregatedMetricsTTL = 86400 * 365; // 1 year retention

// Automatic batch processing
public function recordMetricAsync(array $metric): void
{
    $pendingMetrics = $this->cache->get('api_metrics_pending') ?? [];
    $pendingMetrics[] = $metric;
    
    $this->cache->set('api_metrics_pending', $pendingMetrics, 86400);
    
    // Auto-flush when threshold reached
    if (count($pendingMetrics) >= $this->metricsFlushThreshold) {
        $this->flushMetrics();
    }
}
```

### Daily Aggregation

Raw metrics are aggregated daily for efficient querying:

```php
private function updateDailyAggregatesBulk(array $metrics): void
{
    $aggregates = [];
    
    foreach ($metrics as $metric) {
        $date = date('Y-m-d', $metric['timestamp']);
        $key = $metric['endpoint'] . '|' . $metric['method'];
        $combinedKey = $date . '|' . $key;
        
        if (!isset($aggregates[$combinedKey])) {
            $aggregates[$combinedKey] = [
                'date' => $date,
                'endpoint' => $metric['endpoint'],
                'method' => $metric['method'],
                'endpoint_key' => $key,
                'calls' => 0,
                'total_response_time' => 0,
                'error_count' => 0
            ];
        }
        
        $aggregates[$combinedKey]['calls']++;
        $aggregates[$combinedKey]['total_response_time'] += $metric['response_time'];
        $aggregates[$combinedKey]['error_count'] += $metric['is_error'] ? 1 : 0;
    }
    
    // Bulk insert/update operations
    $this->bulkUpdateAggregates($aggregates);
}
```

## Rate Limiting Integration

### Rate Limit Monitoring

The metrics system automatically tracks rate limiting:

```php
private function updateRateLimit(string $ip, string $endpoint): void
{
    $key = 'rate_limit|' . $ip . '|' . $endpoint;
    $minute = floor(time() / 60) * 60;
    
    $rateLimit = $this->cache->get($key) ?? [
        'count' => 0,
        'minute' => $minute,
        'limit' => 100 // Default or endpoint-specific limit
    ];
    
    if ($rateLimit['minute'] != $minute) {
        // Reset for new minute
        $rateLimit = ['count' => 1, 'minute' => $minute, 'limit' => $rateLimit['limit']];
    } else {
        $rateLimit['count']++;
    }
    
    $this->cache->set($key, $rateLimit, 3600);
    
    // Store in database when approaching threshold (80%)
    if ($rateLimit['count'] >= $rateLimit['limit'] * 0.8) {
        $this->storeRateLimitAlert($ip, $endpoint, $rateLimit);
    }
}
```

### Rate Limit Analytics

```php
// Get rate limits approaching threshold
$rateLimits = $this->db->select('api_rate_limits')
    ->where(['usage_percentage' => ['>', 80]])
    ->orderBy(['usage_percentage' => 'DESC'])
    ->get();

// Example rate limit data
[
    'ip' => '192.168.1.100',
    'endpoint' => '/api/users',
    'remaining' => 15,
    'limit' => 100,
    'reset_time' => '2025-01-02 15:00:00',
    'usage_percentage' => 85.0
]
```

## Performance Analytics

### Response Time Analysis

```php
// Endpoint performance metrics
$endpointMetrics = [
    'endpoint' => '/api/users',
    'method' => 'GET',
    'calls' => 1247,
    'avgResponseTime' => 145.6, // milliseconds
    'errorRate' => 2.1, // percentage
    'lastCalled' => '2025-01-02 14:30:25',
    'category' => 'Users'
];

// Time series data
$requestsOverTime = [
    ['date' => '2025-01-01', 'count' => 5234],
    ['date' => '2025-01-02', 'count' => 6789],
    ['date' => '2025-01-03', 'count' => 4567]
];
```

### Endpoint Categorization

```php
private function getCategoryFromEndpoint(string $endpoint): string
{
    $parts = explode('/', trim($endpoint, '/'));
    
    // Skip 'api' prefix if it exists
    $categoryIndex = ($parts[0] === 'api' && count($parts) > 1) ? 1 : 0;
    
    return isset($parts[$categoryIndex]) ? ucfirst($parts[$categoryIndex]) : 'Other';
}

// Example categories: Auth, Users, Products, Orders, Other
```

### Performance Insights

```php
// Performance analysis
function analyzeEndpointPerformance($metrics) {
    $insights = [];
    
    foreach ($metrics['endpoints'] as $endpoint) {
        if ($endpoint['avgResponseTime'] > 500) {
            $insights[] = "Slow endpoint: {$endpoint['endpoint']} ({$endpoint['avgResponseTime']}ms)";
        }
        
        if ($endpoint['errorRate'] > 5) {
            $insights[] = "High error rate: {$endpoint['endpoint']} ({$endpoint['errorRate']}%)";
        }
        
        if ($endpoint['calls'] > 10000) {
            $insights[] = "High traffic: {$endpoint['endpoint']} ({$endpoint['calls']} calls)";
        }
    }
    
    return $insights;
}
```

## System Health Monitoring

### Comprehensive Health Metrics

```php
private function generateSystemHealthMetrics(): array
{
    return [
        'php' => [
            'version' => phpversion(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => get_loaded_extensions() // Admin only
        ],
        'memory' => [
            'current_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_usage' => $this->formatBytes(memory_get_peak_usage(true))
        ],
        'database' => [
            'status' => 'connected',
            'response_time_ms' => 12.4,
            'table_count' => 15,
            'total_size' => '124.5 MB' // Admin only
        ],
        'file_system' => [
            'storage_free_space' => $this->formatBytes(disk_free_space($storagePath)),
            'storage_total_space' => $this->formatBytes(disk_total_space($storagePath)),
            'storage_usage_percent' => $this->calculateStoragePercentage($storagePath)
        ],
        'cache' => [
            'type' => 'Redis',
            'status' => 'enabled',
            'memory_usage' => '89.3 MB',
            'hit_rate' => '95.6%',
            'connected_clients' => 24
        ],
        'logs' => [
            'log_file_count' => 12,
            'recent_logs' => [
                'file' => 'app-2025-01-02.log',
                'last_modified' => '2025-01-02 14:30:25',
                'recent_entries' => [...] // Limited for non-admins
            ]
        ],
        'extensions' => [
            'total_count' => 8,
            'enabled_count' => 6,
            'extensions' => [...]
        ],
        'server_load' => [
            '1min' => 0.45,
            '5min' => 0.38,
            '15min' => 0.41
        ]
    ];
}
```

### Permission-Based Filtering

```php
private function filterMetricsByUserContext(array $metrics): array
{
    if ($this->isAdmin()) {
        return $metrics; // Full access
    }
    
    // Remove sensitive data for non-admins
    unset($metrics['file_system']['storage_path']);
    
    // Limit log entries
    if (isset($metrics['logs']['recent_logs']['recent_entries'])) {
        $metrics['logs']['recent_logs']['recent_entries'] = array_slice(
            $metrics['logs']['recent_logs']['recent_entries'], -3
        );
    }
    
    // Convert extensions list to count
    if (isset($metrics['php']['extensions'])) {
        $metrics['php']['extensions'] = count($metrics['php']['extensions']);
    }
    
    return $metrics;
}
```

## Configuration

### Environment Variables

```env
# API Metrics Configuration
API_METRICS_ENABLED=true
API_METRICS_FLUSH_THRESHOLD=50
API_METRICS_TTL=2592000
API_METRICS_AGGREGATED_TTL=31536000

# Rate Limiting
API_RATE_LIMIT_DEFAULT=100
API_RATE_LIMIT_WINDOW=60

# Performance Monitoring
API_PERFORMANCE_MONITORING=true
API_SLOW_THRESHOLD=500

# Cache Configuration
API_METRICS_CACHE_PREFIX=api_metrics_
API_METRICS_CACHE_TTL=86400
```

### Service Configuration

```php
// config/api_metrics.php
return [
    'enabled' => env('API_METRICS_ENABLED', true),
    
    'collection' => [
        'flush_threshold' => env('API_METRICS_FLUSH_THRESHOLD', 50),
        'cache_ttl' => env('API_METRICS_CACHE_TTL', 86400),
        'cache_prefix' => env('API_METRICS_CACHE_PREFIX', 'api_metrics_')
    ],
    
    'retention' => [
        'raw_metrics_ttl' => env('API_METRICS_TTL', 2592000), // 30 days
        'aggregated_ttl' => env('API_METRICS_AGGREGATED_TTL', 31536000) // 1 year
    ],
    
    'rate_limiting' => [
        'default_limit' => env('API_RATE_LIMIT_DEFAULT', 100),
        'window_seconds' => env('API_RATE_LIMIT_WINDOW', 60),
        'alert_threshold' => 0.8 // 80% usage triggers alert
    ],
    
    'performance' => [
        'monitoring_enabled' => env('API_PERFORMANCE_MONITORING', true),
        'slow_threshold_ms' => env('API_SLOW_THRESHOLD', 500),
        'error_threshold_percent' => 10
    ],
    
    'categorization' => [
        'auto_categorize' => true,
        'custom_categories' => [
            '/api/auth/*' => 'Authentication',
            '/api/admin/*' => 'Administration',
            '/api/v1/*' => 'API v1',
            '/api/v2/*' => 'API v2'
        ]
    ]
];
```

## Usage Examples

### Dashboard Implementation

```php
class ApiMetricsDashboard
{
    private ApiMetricsService $metricsService;
    
    public function __construct(ApiMetricsService $metricsService)
    {
        $this->metricsService = $metricsService;
    }
    
    public function getDashboardData(): array
    {
        $metrics = $this->metricsService->getApiMetrics();
        
        return [
            'overview' => $this->getOverviewMetrics($metrics),
            'performance' => $this->getPerformanceInsights($metrics),
            'rate_limits' => $this->getRateLimitAlerts($metrics),
            'trends' => $this->getTrendAnalysis($metrics),
            'top_endpoints' => $metrics['top_endpoints']
        ];
    }
    
    private function getOverviewMetrics(array $metrics): array
    {
        return [
            'total_requests' => $metrics['total_requests'],
            'avg_response_time' => $metrics['avg_response_time'],
            'error_rate' => $metrics['error_rate'],
            'active_endpoints' => count($metrics['endpoints']),
            'categories' => count($metrics['categories'])
        ];
    }
    
    private function getPerformanceInsights(array $metrics): array
    {
        $insights = [];
        
        // Slow endpoints
        $slowEndpoints = array_filter($metrics['endpoints'], function($endpoint) {
            return $endpoint['avgResponseTime'] > 500;
        });
        
        if (!empty($slowEndpoints)) {
            $insights['slow_endpoints'] = count($slowEndpoints);
        }
        
        // High error rates
        $errorEndpoints = array_filter($metrics['endpoints'], function($endpoint) {
            return $endpoint['errorRate'] > 5;
        });
        
        if (!empty($errorEndpoints)) {
            $insights['high_error_endpoints'] = count($errorEndpoints);
        }
        
        return $insights;
    }
    
    private function getRateLimitAlerts(array $metrics): array
    {
        return array_filter($metrics['rate_limits'], function($limit) {
            return $limit['usage_percentage'] > 90;
        });
    }
}
```

### Performance Monitoring

```php
class ApiPerformanceMonitor
{
    private ApiMetricsService $metricsService;
    
    public function runPerformanceCheck(): array
    {
        $metrics = $this->metricsService->getApiMetrics();
        $alerts = [];
        
        // Check for slow endpoints
        foreach ($metrics['endpoints'] as $endpoint) {
            if ($endpoint['avgResponseTime'] > 1000) {
                $alerts[] = [
                    'type' => 'slow_endpoint',
                    'severity' => 'high',
                    'endpoint' => $endpoint['endpoint'],
                    'response_time' => $endpoint['avgResponseTime'],
                    'recommendation' => 'Optimize endpoint performance or increase resources'
                ];
            }
        }
        
        // Check overall error rate
        if ($metrics['error_rate'] > 10) {
            $alerts[] = [
                'type' => 'high_error_rate',
                'severity' => 'critical',
                'error_rate' => $metrics['error_rate'],
                'recommendation' => 'Investigate error causes and implement fixes'
            ];
        }
        
        // Check rate limit violations
        foreach ($metrics['rate_limits'] as $limit) {
            if ($limit['usage_percentage'] > 95) {
                $alerts[] = [
                    'type' => 'rate_limit_critical',
                    'severity' => 'high',
                    'ip' => $limit['ip'],
                    'endpoint' => $limit['endpoint'],
                    'usage' => $limit['usage_percentage'],
                    'recommendation' => 'Consider increasing rate limits or blocking IP'
                ];
            }
        }
        
        return $alerts;
    }
}
```

### Custom Metrics Collection

```php
class CustomMetricsCollector
{
    private ApiMetricsService $metricsService;
    
    public function recordCustomMetric(string $endpoint, array $additionalData = []): void
    {
        $metric = array_merge([
            'endpoint' => $endpoint,
            'method' => 'CUSTOM',
            'response_time' => 0,
            'status_code' => 200,
            'is_error' => false,
            'timestamp' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'internal'
        ], $additionalData);
        
        $this->metricsService->recordMetricAsync($metric);
    }
    
    public function recordBusinessMetric(string $action, float $value, string $unit = 'count'): void
    {
        $this->recordCustomMetric("/internal/business/{$action}", [
            'value' => $value,
            'unit' => $unit,
            'business_metric' => true
        ]);
    }
}
```

## Production Optimization

### High-Volume Environments

```php
// Optimized configuration for high-traffic APIs
class HighVolumeMetricsConfig
{
    public static function getOptimizedConfig(): array
    {
        return [
            'collection' => [
                'flush_threshold' => 100, // Larger batches
                'cache_ttl' => 3600, // Longer cache TTL
                'sampling_rate' => 0.1 // Sample 10% of requests
            ],
            
            'performance' => [
                'async_processing' => true,
                'background_aggregation' => true,
                'compression_enabled' => true
            ],
            
            'retention' => [
                'raw_metrics_ttl' => 604800, // 7 days only
                'aggregated_ttl' => 2592000, // 30 days
                'cleanup_frequency' => 3600 // Hourly cleanup
            ]
        ];
    }
}
```

### Memory Optimization

```php
class MemoryOptimizedMetrics extends ApiMetricsService
{
    public function flushMetrics(): void
    {
        // Process in smaller chunks to avoid memory issues
        $pendingMetrics = $this->cache->get($this->cacheKeyPrefix . 'pending') ?? [];
        
        $chunkSize = 25; // Smaller chunks
        $chunks = array_chunk($pendingMetrics, $chunkSize);
        
        foreach ($chunks as $chunk) {
            $this->processMetricsChunk($chunk);
            gc_collect_cycles(); // Force garbage collection
        }
    }
    
    private function processMetricsChunk(array $metrics): void
    {
        $rawMetricsToInsert = [];
        
        foreach ($metrics as $metric) {
            $rawMetricsToInsert[] = [
                'uuid' => Utils::generateNanoID(),
                'endpoint' => $metric['endpoint'],
                'method' => $metric['method'],
                'response_time' => $metric['response_time'],
                'status_code' => $metric['status_code'],
                'is_error' => $metric['is_error'] ? 1 : 0,
                'timestamp' => date('Y-m-d H:i:s', $metric['timestamp']),
                'ip' => $metric['ip']
            ];
        }
        
        if (!empty($rawMetricsToInsert)) {
            $this->db->insertBatch($this->metricsTable, $rawMetricsToInsert);
        }
    }
}
```

### Monitoring and Alerting

```php
class MetricsMonitoringService
{
    public function setupMonitoring(): void
    {
        // Monitor key metrics thresholds
        $this->monitorMetric('avg_response_time', 500, 'ms');
        $this->monitorMetric('error_rate', 5, '%');
        $this->monitorMetric('requests_per_minute', 1000, 'req/min');
    }
    
    private function monitorMetric(string $metric, float $threshold, string $unit): void
    {
        // Implementation would set up alerts when thresholds are exceeded
        // Could integrate with external monitoring services like DataDog, New Relic
    }
}
```

## Summary

Glueful's API Metrics system provides enterprise-grade monitoring and analytics capabilities:

- **Asynchronous Collection**: Non-blocking metrics recording with intelligent batching
- **Comprehensive Analytics**: Response times, error rates, usage patterns, and system health
- **Rate Limiting Integration**: Built-in monitoring and threshold alerts
- **Production-Ready**: Automatic data retention, bulk processing, and memory optimization
- **Permission-Based Access**: Role-based data filtering and secure endpoints
- **Scalable Design**: Optimized for high-volume production environments

The system is designed to provide actionable insights into API performance and usage patterns while maintaining minimal impact on application performance.