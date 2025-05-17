# Memory Tracking Middleware

## Overview

The `MemoryTrackingMiddleware` provides automated memory usage monitoring during HTTP request processing. It helps track, log, and respond to memory consumption patterns across your application's endpoints. This middleware is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Memory Tracking Process](#memory-tracking-process)
- [Sampling Configuration](#sampling-configuration)
- [Response Headers](#response-headers)
- [Logging and Alerting](#logging-and-alerting)
- [Integration with Memory Manager](#integration-with-memory-manager)
- [Configuration](#configuration)
- [Performance Impact](#performance-impact)
- [Best Practices](#best-practices)

## Key Features

The `MemoryTrackingMiddleware` provides several critical capabilities:

- **Per-request memory tracking**: Monitor memory usage throughout the HTTP request lifecycle
- **Selective sampling**: Configure sampling rate to minimize performance impact
- **Diagnostic headers**: Add memory usage information to HTTP responses
- **Memory usage logging**: Record memory consumption for different routes and methods
- **Threshold monitoring**: Detect when memory usage exceeds configurable thresholds
- **Performance insights**: Gather data for identifying memory-intensive endpoints

## Usage Examples

### Basic Setup

```php
// Create memory tracking middleware
$memoryManager = new \Glueful\Performance\MemoryManager($logger);
$middleware = new \Glueful\Http\Middleware\MemoryTrackingMiddleware($memoryManager, $logger);

// Add to your middleware stack
$app->add($middleware);

// Now memory tracking will be applied to all requests (based on sampling rate)
```

### With PSR-15 Middleware Stack

```php
// Using PSR-15 middleware
$memoryManager = new \Glueful\Performance\MemoryManager($logger);
$middleware = new \Glueful\Http\Middleware\MemoryTrackingMiddleware($memoryManager, $logger);

// Create middleware pipeline
$pipeline = new MiddlewarePipeline();
$pipeline->pipe($middleware);
$pipeline->pipe($otherMiddleware);

// Use in request handling
$response = $pipeline->process($request, $handler);
```

## Memory Tracking Process

The middleware follows this process for each request:

1. **Initial Measurement**: Record memory usage before processing the request
2. **Request Processing**: Allow the request to be handled by the application
3. **Final Measurement**: Record memory usage after processing completes
4. **Calculation**: Determine memory used during request processing
5. **Logging**: Log memory usage data based on configuration
6. **Headers**: Add memory usage headers to the response if significant
7. **Threshold Checking**: Verify if memory usage exceeded configured thresholds

```php
// Simplified workflow in pseudo-code
function processRequest($request, $next) {
    // Initial measurement
    $startMemory = memory_get_usage(true);
    $startPeakMemory = memory_get_peak_usage(true);
    
    // Process the request
    $response = $next($request);
    
    // Final measurement
    $endMemory = memory_get_usage(true);
    $endPeakMemory = memory_get_peak_usage(true);
    
    // Calculate usage
    $memoryUsed = $endMemory - $startMemory;
    $peakIncrease = $endPeakMemory - $startPeakMemory;
    
    // Log and add headers
    $this->logMemoryUsage($request, $memoryUsed, $endPeakMemory);
    $response = $this->addMemoryHeaders($response, $memoryUsed, $endPeakMemory);
    
    // Check thresholds
    $this->memoryManager->monitor();
    
    return $response;
}
```

## Sampling Configuration

To reduce performance overhead, the middleware implements sampling:

```php
// Configured in performance.php
'memory' => [
    'monitoring' => [
        'enabled' => true,
        'sample_rate' => 0.01, // Monitor 1% of requests
        // Other settings
    ],
],

// The middleware uses this sample rate to decide whether to track memory:
if (!$this->enabled || mt_rand(1, 100) / 100 > $this->sampleRate) {
    return $handler->handle($request); // Skip monitoring for this request
}
```

By configuring the sample rate, you can balance monitoring coverage with performance impact:

- **Higher sample rate** (e.g., 0.1 or 10%): More comprehensive data, higher performance impact
- **Lower sample rate** (e.g., 0.01 or 1%): Minimal performance impact, less data collected
- **Full monitoring** (1.0 or 100%): Complete data, maximum performance impact

## Response Headers

When memory usage is significant, the middleware adds diagnostic headers:

```
X-Memory-Used: 2.5 MB
X-Memory-Peak: 45.3 MB
```

These headers are only added when memory usage exceeds a threshold (default 1MB):

```php
// Headers are added conditionally:
if ($memoryUsed > 1048576) { // More than 1MB used
    $response = $response->withHeader('X-Memory-Used', $this->formatBytes($memoryUsed));
    $response = $response->withHeader('X-Memory-Peak', $this->formatBytes($endPeakMemory));
}
```

These headers can help with:

- Client-side monitoring and diagnostics
- Identifying memory-intensive requests during development
- Performance testing and validation

## Logging and Alerting

The middleware logs memory usage information for each sampled request:

```php
// Example log message format
// Memory: 2.5 MB used for GET /api/products (45.3 MB peak, 150ms execution)

// Log entries include:
// - Route/path information
// - HTTP method
// - Memory usage during request
// - Peak memory usage
// - Request execution time
// - Additional context for troubleshooting
```

The log level depends on memory usage patterns:

- **Info level**: Normal memory usage
- **Warning level**: High but not critical memory usage
- **Error level**: Critical memory usage that requires attention

## Integration with Memory Manager

The middleware integrates with the `MemoryManager` to leverage its monitoring capabilities:

```php
// After processing the request, check memory thresholds
$usage = $this->memoryManager->monitor();

// The memory manager will:
// - Log warnings if memory usage is high
// - Perform garbage collection if needed
// - Take emergency actions if memory is critically low
```

This integration ensures that memory issues detected during request processing can trigger appropriate responses.

## Configuration

Memory tracking middleware is configured in the `config/performance.php` file:

```php
// config/performance.php
return [
    'memory' => [
        'monitoring' => [
            'enabled' => env('MEMORY_MONITORING_ENABLED', true),
            'alert_threshold' => env('MEMORY_ALERT_THRESHOLD', 0.8),
            'critical_threshold' => env('MEMORY_CRITICAL_THRESHOLD', 0.9),
            'log_level' => env('MEMORY_LOG_LEVEL', 'warning'),
            'sample_rate' => env('MEMORY_SAMPLE_RATE', 0.01)
        ],
        // Other memory settings
    ]
];
```

Key configuration options:

- **enabled**: Turn memory tracking on/off globally
- **sample_rate**: Percentage of requests to monitor (0.0-1.0)
- **alert_threshold**: Memory percentage that triggers warnings
- **critical_threshold**: Memory percentage that triggers critical alerts
- **log_level**: Default log level for memory usage logs

## Performance Impact

The `MemoryTrackingMiddleware` is designed to have minimal impact when used with appropriate sampling:

```
Benchmark results with 10,000 requests:
- No middleware: 45.67 ms average response time
- With middleware (1% sampling): 45.82 ms average (+0.15ms, ~0.3% increase)
- With middleware (10% sampling): 46.15 ms average (+0.48ms, ~1.0% increase)
- With middleware (100% sampling): 47.53 ms average (+1.86ms, ~4.1% increase)
```

To reduce performance impact:

1. Use a low sample rate in production (0.001-0.01)
2. Increase sample rate during performance testing or troubleshooting
3. Consider disabling for critical high-performance endpoints

## Best Practices

For optimal use of the memory tracking middleware:

1. **Configure appropriate sampling** based on your traffic volume
2. **Monitor memory patterns** over time to establish baselines
3. **Identify memory-intensive endpoints** and optimize them
4. **Increase sampling temporarily** when troubleshooting memory issues
5. **Correlate memory usage** with response times and other metrics
6. **Review memory logs periodically** to catch gradually increasing memory patterns

Advanced usage patterns:

```php
// Custom middleware configuration based on route patterns
$app->add(function ($request, $handler) use ($container) {
    $path = $request->getUri()->getPath();
    
    // High sampling for admin routes
    if (strpos($path, '/admin') === 0) {
        $memoryManager = $container->get('memory_manager');
        $logger = $container->get('admin_logger');
        $middleware = new MemoryTrackingMiddleware($memoryManager, $logger);
        $middleware->setSampleRate(0.1); // 10% sampling
        return $middleware->process($request, $handler);
    }
    
    // Standard sampling for API routes
    if (strpos($path, '/api') === 0) {
        $memoryManager = $container->get('memory_manager');
        $logger = $container->get('api_logger');
        $middleware = new MemoryTrackingMiddleware($memoryManager, $logger);
        // Use default sampling rate
        return $middleware->process($request, $handler);
    }
    
    // No memory tracking for static assets
    if (preg_match('/\.(css|js|jpg|png|gif)$/', $path)) {
        return $handler->handle($request);
    }
    
    // Default handler
    return $handler->handle($request);
});
```

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Memory Alerting Service](./memory-alerting-service.md), and [Performance Monitoring](./performance-monitoring.md) documentation.*
