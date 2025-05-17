# Memory Manager

## Overview

The `MemoryManager` class provides comprehensive memory monitoring and management capabilities, helping developers track, optimize, and control memory usage in their applications. This tool is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Memory Monitoring](#memory-monitoring)
- [Garbage Collection](#garbage-collection)
- [Memory Thresholds](#memory-thresholds)
- [Memory Formatting](#memory-formatting)
- [Integration with Middleware](#integration-with-middleware)
- [Console Commands](#console-commands)
- [Configuration](#configuration)
- [Best Practices](#best-practices)

## Key Features

The `MemoryManager` provides several critical capabilities:

- **Real-time memory monitoring**: Track current and peak memory usage
- **Automated threshold monitoring**: Configure alerts when memory usage exceeds defined thresholds
- **Forced garbage collection**: Reclaim memory when usage is high
- **Memory usage reporting**: Get detailed memory statistics in human-readable format
- **Integration with logging**: Log memory issues with appropriate severity levels

## Usage Examples

### Basic Usage

```php
// Create a new memory manager instance with default logger
$memoryManager = new \Glueful\Performance\MemoryManager();

// Get current memory usage
$usage = $memoryManager->getCurrentUsage();

// Display memory information
echo "Current memory: {$usage['formatted']['current']}\n";
echo "Peak memory: {$usage['formatted']['peak']}\n";
echo "Memory limit: {$usage['formatted']['limit']}\n";
echo "Usage percentage: " . round($usage['percentage'] * 100, 2) . "%\n";
```

### Monitoring Memory and Taking Action

```php
$memoryManager = new \Glueful\Performance\MemoryManager($logger);

// Periodically monitor memory
$usage = $memoryManager->monitor();

// Check if memory usage is high and take action
if ($usage['percentage'] > 0.80) {
    // Do emergency cleanup
    $memoryManager->forceGarbageCollection();
    
    // Log the action
    $logger->warning('Memory usage is high. Garbage collection performed.');
    
    // Take additional actions like clearing caches
    // ...
}
```

## Memory Monitoring

The memory monitoring features provide real-time insights into your application's memory usage:

```php
$memoryManager = new \Glueful\Performance\MemoryManager();

// Check if memory usage is above thresholds
$isHigh = $memoryManager->isMemoryHighUsage(); // Above alert threshold
$isCritical = $memoryManager->isMemoryCritical(); // Above critical threshold

// Get detailed memory usage information
$usage = $memoryManager->getCurrentUsage();
print_r($usage);

// Sample output:
// [
//   'current' => 8388608,         // Current memory usage in bytes
//   'peak' => 10485760,           // Peak memory usage in bytes
//   'limit' => 134217728,         // Memory limit in bytes
//   'percentage' => 0.0625,       // Current usage as percentage of limit (0-1)
//   'peak_percentage' => 0.078125,// Peak usage as percentage of limit (0-1)
//   'formatted' => [
//     'current' => '8 MB',        // Formatted current usage
//     'peak' => '10 MB',          // Formatted peak usage
//     'limit' => '128 MB',        // Formatted memory limit
//   ],
// ]
```

## Garbage Collection

The `MemoryManager` can help reclaim memory through garbage collection:

```php
$memoryManager = new \Glueful\Performance\MemoryManager();

// Force PHP's garbage collector to run
$gcPerformed = $memoryManager->forceGarbageCollection();

if ($gcPerformed) {
    // Garbage collection was performed
    $before = $beforeUsage['formatted']['current'];
    $after = $memoryManager->getCurrentUsage()['formatted']['current'];
    echo "Memory reduced from {$before} to {$after}";
} else {
    // Garbage collection is disabled
    echo "Garbage collection is disabled in PHP settings";
}
```

## Memory Thresholds

The `MemoryManager` uses configurable thresholds to determine when to trigger alerts or take action:

```php
// The thresholds are configured in performance.php
// and can be accessed through the MemoryManager

$memoryManager = new \Glueful\Performance\MemoryManager();

// Monitor will automatically handle alert and critical thresholds
$usage = $memoryManager->monitor();

// When alert threshold is exceeded:
// - A warning will be logged
// - Garbage collection will be attempted

// When critical threshold is exceeded:
// - An error will be logged
// - Emergency memory reclamation will be performed
// - Internal caches will be cleared
```

## Memory Formatting

The `MemoryManager` provides human-readable memory values:

```php
$memoryManager = new \Glueful\Performance\MemoryManager();

// Get formatted memory limit
$formattedLimit = $memoryManager->getFormattedMemoryLimit();
echo "PHP Memory Limit: {$formattedLimit}";  // e.g., "128 MB"

// All memory values in getCurrentUsage() include formatted versions
$usage = $memoryManager->getCurrentUsage();
echo "Current memory: {$usage['formatted']['current']}";  // e.g., "45.5 MB"
```

## Integration with Middleware

The `MemoryManager` integrates with the `MemoryTrackingMiddleware` to monitor memory usage during HTTP requests:

```php
// In your middleware configuration
$app->add(new \Glueful\Http\Middleware\MemoryTrackingMiddleware(
    new \Glueful\Performance\MemoryManager(),
    $logger
));

// The middleware will:
// - Sample memory usage based on configured sample rate
// - Track memory used during request processing
// - Log anomalous memory usage
// - Add memory usage headers to responses when usage is significant
// - Take action when thresholds are exceeded
```

## Console Commands

The `MemoryManager` is used by the `MemoryMonitorCommand` to provide memory insights via the command line:

```bash
# Monitor memory usage of the application
php glueful memory:monitor

# Monitor with custom interval and threshold
php glueful memory:monitor --interval=2 --threshold=50

# Log memory usage to CSV file
php glueful memory:monitor --log --csv=memory-log.csv

# Monitor for a specific duration (in seconds)
php glueful memory:monitor --duration=60
```

## Configuration

Memory management is configured in the `config/performance.php` file:

```php
// config/performance.php
return [
    'memory' => [
        'monitoring' => [
            'enabled' => env('MEMORY_MONITORING_ENABLED', true),
            'alert_threshold' => env('MEMORY_ALERT_THRESHOLD', 0.8),  // 80% of limit
            'critical_threshold' => env('MEMORY_CRITICAL_THRESHOLD', 0.9),  // 90% of limit
            'log_level' => env('MEMORY_LOG_LEVEL', 'warning'),
            'sample_rate' => env('MEMORY_SAMPLE_RATE', 0.01)  // 1% of requests
        ],
        'limits' => [
            'query_cache' => env('MEMORY_LIMIT_QUERY_CACHE', 1000),
            'object_pool' => env('MEMORY_LIMIT_OBJECT_POOL', 500),
            'result_limit' => env('MEMORY_LIMIT_RESULTS', 10000)
        ],
        'gc' => [
            'auto_trigger' => env('MEMORY_AUTO_GC', true),
            'threshold' => env('MEMORY_GC_THRESHOLD', 0.85)  // 85% of limit
        ]
    ]
];
```

## Best Practices

For optimal memory management:

1. **Monitor memory usage** in long-running processes and commands
2. **Configure appropriate thresholds** based on your application's memory characteristics
3. **Implement memory checks** in memory-intensive operations like bulk imports/exports
4. **Use the memory-efficient iterators** for large dataset processing
5. **Check memory usage** before and after resource-intensive operations
6. **Log memory anomalies** for later investigation

For larger applications:

```php
// Before running memory-intensive operation
$beforeUsage = $memoryManager->getCurrentUsage();

// Run operation
$result = processLargeDataset($data);

// After operation
$afterUsage = $memoryManager->getCurrentUsage();
$memoryUsed = $afterUsage['current'] - $beforeUsage['current'];

// Log memory usage for this operation
$logger->info('Large dataset processing complete', [
    'memory_used' => $memoryManager->formatBytes($memoryUsed),
    'peak_memory' => $afterUsage['formatted']['peak'],
    'items_processed' => count($data)
]);

// Force cleanup if necessary
if ($afterUsage['percentage'] > 0.7) {
    $memoryManager->forceGarbageCollection();
}
```

---

*For more information on performance optimization, see the [Memory Pool](./memory-pool.md), [Memory-Efficient Iterators](./memory-efficient-iterators.md), and [Performance Monitoring](./performance-monitoring.md) documentation.*
