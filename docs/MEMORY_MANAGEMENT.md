# Memory Management

This guide provides comprehensive documentation for Glueful's memory management and optimization features. It consolidates all memory-related tools and techniques into a single reference.

## Table of Contents

1. [Memory Manager](#memory-manager)
2. [Memory Alerting Service](#memory-alerting-service)
3. [Memory Efficient Iterators](#memory-efficient-iterators)
4. [Memory Pool](#memory-pool)
5. [Memory Monitor Command](#memory-monitor-command)
6. [Memory Tracking Middleware](#memory-tracking-middleware)
7. [Chunked Database Processor](#chunked-database-processor)
8. [Lazy Container](#lazy-container)

---

## Memory Manager

The Memory Manager provides advanced memory monitoring, tracking, and management capabilities with configurable thresholds and automatic garbage collection.

### Features

- **Real-time Memory Monitoring**: Track current, peak, and limit usage
- **Automatic Garbage Collection**: Trigger collection based on configurable thresholds
- **Memory State Tracking**: Monitor allocation patterns and system health
- **Configurable Alerts**: Set custom thresholds for different memory states

### Basic Usage

```php
use Glueful\API\Performance\MemoryManager;

// Initialize with custom configuration
$memoryManager = new MemoryManager([
    'warning_threshold' => 0.75,  // 75% of memory limit
    'critical_threshold' => 0.9,  // 90% of memory limit
    'auto_gc_threshold' => 0.8,   // Auto GC at 80%
    'enable_detailed_tracking' => true
]);

// Monitor memory usage
$usage = $memoryManager->getMemoryUsage();
echo "Current: {$usage['current_mb']}MB, Peak: {$usage['peak_mb']}MB";

// Check memory state
$state = $memoryManager->getMemoryState();
if ($state === MemoryManager::STATE_CRITICAL) {
    // Handle critical memory situation
    $memoryManager->emergencyCleanup();
}
```

### Configuration Options

```php
$config = [
    'warning_threshold' => 0.75,        // Warning at 75% usage
    'critical_threshold' => 0.9,        // Critical at 90% usage
    'auto_gc_threshold' => 0.8,         // Auto GC at 80% usage
    'enable_detailed_tracking' => true, // Track allocation patterns
    'gc_probability' => 0.1,            // 10% chance of GC per check
    'emergency_threshold' => 0.95       // Emergency cleanup at 95%
];
```

### Memory States

- **NORMAL**: Memory usage below warning threshold
- **WARNING**: Usage between warning and critical thresholds
- **CRITICAL**: Usage above critical threshold
- **EMERGENCY**: Usage above emergency threshold

### Advanced Features

```php
// Set custom memory limits
$memoryManager->setMemoryLimit('512M');

// Force garbage collection
$memoryManager->forceGarbageCollection();

// Get detailed memory statistics
$stats = $memoryManager->getDetailedStats();
print_r($stats);

// Register memory state change callbacks
$memoryManager->onStateChange(function($oldState, $newState) {
    error_log("Memory state changed from {$oldState} to {$newState}");
});
```

---

## Memory Alerting Service

The Memory Alerting Service provides intelligent memory monitoring with configurable thresholds, alert channels, and automatic escalation.

### Features

- **Multi-Channel Alerting**: Email, Slack, webhook notifications
- **Intelligent Throttling**: Prevent alert spam with configurable intervals
- **Escalation Policies**: Automatic escalation for critical situations
- **Historical Tracking**: Maintain alert history and patterns

### Basic Setup

```php
use Glueful\API\Performance\MemoryAlertingService;

$alertService = new MemoryAlertingService([
    'channels' => [
        'email' => [
            'enabled' => true,
            'recipients' => ['admin@example.com', 'ops@example.com'],
            'threshold' => 'warning'
        ],
        'slack' => [
            'enabled' => true,
            'webhook_url' => 'https://hooks.slack.com/...',
            'channel' => '#alerts',
            'threshold' => 'critical'
        ]
    ],
    'thresholds' => [
        'warning' => 75,   // 75% memory usage
        'critical' => 90,  // 90% memory usage
        'emergency' => 95  // 95% memory usage
    ]
]);

// Check memory and send alerts if needed
$alertService->checkAndAlert();
```

### Alert Channels

#### Email Alerts

```php
$emailConfig = [
    'enabled' => true,
    'recipients' => ['admin@example.com'],
    'threshold' => 'warning',
    'throttle_minutes' => 15,
    'template' => 'memory_alert'
];
```

#### Slack Alerts

```php
$slackConfig = [
    'enabled' => true,
    'webhook_url' => 'https://hooks.slack.com/services/...',
    'channel' => '#alerts',
    'username' => 'Glueful Monitor',
    'threshold' => 'critical',
    'throttle_minutes' => 5
];
```

#### Webhook Alerts

```php
$webhookConfig = [
    'enabled' => true,
    'url' => 'https://your-monitoring-system.com/alerts',
    'threshold' => 'warning',
    'timeout' => 30,
    'retry_attempts' => 3
];
```

### Escalation Policies

```php
$escalationConfig = [
    'enabled' => true,
    'levels' => [
        1 => ['email' => ['admin@example.com']],
        2 => ['email' => ['manager@example.com'], 'slack' => true],
        3 => ['webhook' => 'https://pager-duty.com/...']
    ],
    'escalation_intervals' => [5, 15, 30] // minutes
];
```

### Advanced Configuration

```php
$advancedConfig = [
    'history_retention_days' => 30,
    'alert_cooldown_minutes' => 10,
    'batch_alerts' => true,
    'include_system_info' => true,
    'custom_metrics' => [
        'cpu_usage' => true,
        'disk_usage' => true,
        'active_connections' => true
    ]
];
```

---

## Memory Efficient Iterators

Memory efficient iterators for processing large datasets without loading everything into memory.

### StreamingIterator

Process large datasets chunk by chunk:

```php
use Glueful\API\Performance\StreamingIterator;

$iterator = new StreamingIterator($dataSource, [
    'chunk_size' => 1000,
    'memory_limit' => '128M',
    'auto_gc' => true
]);

foreach ($iterator as $chunk) {
    // Process each chunk (array of 1000 items)
    foreach ($chunk as $item) {
        processItem($item);
    }
    
    // Memory is automatically managed
    unset($chunk);
}
```

### LazyIterator

Load items on-demand:

```php
use Glueful\API\Performance\LazyIterator;

$lazyIterator = new LazyIterator(function($offset, $limit) {
    return $database->getRecords($offset, $limit);
}, [
    'batch_size' => 500,
    'prefetch' => true
]);

foreach ($lazyIterator as $item) {
    // Items are loaded on-demand
    processItem($item);
}
```

### FilteredIterator

Apply filters without loading entire dataset:

```php
use Glueful\API\Performance\FilteredIterator;

$filteredIterator = new FilteredIterator($sourceIterator, [
    'filters' => [
        function($item) { return $item['status'] === 'active'; },
        function($item) { return $item['score'] > 50; }
    ],
    'early_exit' => true
]);

foreach ($filteredIterator as $item) {
    // Only items passing all filters
    processActiveHighScoreItem($item);
}
```

### Configuration Options

```php
$config = [
    'chunk_size' => 1000,           // Items per chunk
    'memory_limit' => '128M',       // Memory limit per chunk
    'auto_gc' => true,              // Automatic garbage collection
    'prefetch' => false,            // Prefetch next chunk
    'cache_chunks' => false,        // Cache processed chunks
    'parallel_processing' => false, // Process chunks in parallel
    'error_handling' => 'continue'  // 'continue', 'stop', 'retry'
];
```

---

## Memory Pool

Object storage and reuse system to reduce memory allocation overhead.

### Features

- **Object Pooling**: Reuse expensive objects
- **Automatic Cleanup**: Remove stale objects
- **Type Safety**: Strongly typed object pools
- **Statistics**: Monitor pool usage and efficiency

### Basic Usage

```php
use Glueful\API\Performance\MemoryPool;

// Create a pool for database connections
$connectionPool = new MemoryPool([
    'factory' => function() {
        return new DatabaseConnection($config);
    },
    'max_size' => 10,
    'min_size' => 2,
    'max_idle_time' => 300 // 5 minutes
]);

// Get object from pool
$connection = $connectionPool->acquire();

// Use the connection
$result = $connection->query('SELECT * FROM users');

// Return to pool
$connectionPool->release($connection);
```

### Typed Pools

```php
use Glueful\API\Performance\TypedMemoryPool;

class DatabaseConnectionPool extends TypedMemoryPool
{
    protected function createObject(): DatabaseConnection
    {
        return new DatabaseConnection($this->config);
    }
    
    protected function resetObject($object): void
    {
        $object->rollback(); // Reset state
        $object->clearCache();
    }
    
    protected function validateObject($object): bool
    {
        return $object->isConnected();
    }
}

$pool = new DatabaseConnectionPool(['max_size' => 15]);
```

### Pool Configuration

```php
$config = [
    'max_size' => 10,           // Maximum pool size
    'min_size' => 2,            // Minimum pool size
    'max_idle_time' => 300,     // Max idle time (seconds)
    'validation_interval' => 60, // Validation check interval
    'auto_cleanup' => true,     // Automatic cleanup
    'statistics' => true        // Enable statistics
];
```

### Pool Statistics

```php
$stats = $pool->getStatistics();
echo "Active: {$stats['active']}, Idle: {$stats['idle']}";
echo "Hit Rate: {$stats['hit_rate']}%";
echo "Created: {$stats['total_created']}, Destroyed: {$stats['total_destroyed']}";
```

---

## Memory Monitor Command

CLI tool for monitoring and analyzing memory usage patterns.

### Basic Usage

```bash
# Real-time memory monitoring
php glueful memory:monitor

# Monitor with custom interval
php glueful memory:monitor --interval=5

# Monitor specific process
php glueful memory:monitor --pid=1234

# Export monitoring data
php glueful memory:monitor --export=memory_report.json
```

### Command Options

```bash
# Monitoring options
--interval=N      # Check interval in seconds (default: 1)
--duration=N      # Monitor for N seconds (default: unlimited)
--threshold=N     # Alert threshold percentage (default: 80)
--pid=N          # Monitor specific process ID

# Output options
--format=FORMAT   # Output format: table, json, csv (default: table)
--export=FILE     # Export data to file
--quiet          # Suppress real-time output
--verbose        # Show detailed information

# Alert options
--email=ADDRESS   # Send alerts to email
--webhook=URL     # Send alerts to webhook
--slack=URL       # Send alerts to Slack
```

### Real-time Monitoring

```bash
# Display live memory usage
php glueful memory:monitor --interval=1 --format=table

┌─────────────────┬──────────────┬─────────────┬─────────────┐
│ Time            │ Current (MB) │ Peak (MB)   │ Limit (MB)  │
├─────────────────┼──────────────┼─────────────┼─────────────┤
│ 2023-10-15 14:30│ 245.7       │ 267.3       │ 512.0       │
│ 2023-10-15 14:31│ 248.2       │ 267.3       │ 512.0       │
│ 2023-10-15 14:32│ 251.8       │ 267.3       │ 512.0       │
└─────────────────┴──────────────┴─────────────┴─────────────┘
```

### Memory Analysis

```bash
# Generate comprehensive memory report
php glueful memory:monitor --duration=300 --export=analysis.json

# Analyze memory patterns
php glueful memory:analyze analysis.json

Memory Usage Analysis Report
============================
Average Usage: 245.7 MB
Peak Usage: 312.4 MB
Memory Efficiency: 87.3%
Potential Issues: 2 memory spikes detected
```

### Integration with Monitoring Systems

```bash
# Send alerts to external systems
php glueful memory:monitor \
    --threshold=85 \
    --webhook=https://monitoring.example.com/alerts \
    --email=ops@example.com
```

---

## Memory Tracking Middleware

HTTP middleware for tracking memory usage per request with detailed analytics.

### Features

- **Per-Request Tracking**: Monitor memory usage for each HTTP request
- **Detailed Analytics**: Track allocation patterns and peak usage
- **Performance Impact Analysis**: Correlate memory usage with response times
- **Configurable Reporting**: Flexible logging and alerting options

### Basic Setup

```php
use Glueful\API\Http\Middleware\MemoryTrackingMiddleware;

$middleware = new MemoryTrackingMiddleware([
    'enabled' => true,
    'track_peak' => true,
    'log_high_usage' => true,
    'threshold_mb' => 50,
    'detailed_tracking' => false
]);

// Add to middleware stack
$app->add($middleware);
```

### Configuration Options

```php
$config = [
    'enabled' => true,              // Enable/disable tracking
    'track_peak' => true,           // Track peak memory usage
    'log_high_usage' => true,       // Log requests with high usage
    'threshold_mb' => 50,           // High usage threshold (MB)
    'detailed_tracking' => false,   // Enable detailed allocation tracking
    'include_headers' => true,      // Add memory info to response headers
    'log_file' => 'memory.log',     // Custom log file
    'sample_rate' => 1.0            // Sampling rate (0.0-1.0)
];
```

### Response Headers

When enabled, adds memory information to response headers:

```http
X-Memory-Usage: 45.7
X-Memory-Peak: 52.3
X-Memory-Limit: 128.0
X-Memory-Efficiency: 89.2
```

### Detailed Tracking

```php
$middleware = new MemoryTrackingMiddleware([
    'detailed_tracking' => true,
    'track_allocations' => true,
    'track_deallocations' => true,
    'include_stack_traces' => false
]);
```

### Custom Handlers

```php
$middleware->setHighUsageHandler(function($usage, $request) {
    // Custom handling for high memory usage
    if ($usage > 100) {
        $alertService->sendAlert("High memory usage: {$usage}MB");
    }
});

$middleware->setAnalyticsHandler(function($analytics) {
    // Send analytics to monitoring system
    $metricsService->recordMemoryMetrics($analytics);
});
```

---

## Chunked Database Processor

Process large database result sets in memory-efficient chunks.

### Features

- **Chunked Processing**: Process large datasets without memory issues
- **Configurable Chunk Sizes**: Optimize for your specific use case
- **Progress Tracking**: Monitor processing progress
- **Error Handling**: Robust error handling and recovery

### Basic Usage

```php
use Glueful\API\Performance\ChunkedDatabaseProcessor;

$processor = new ChunkedDatabaseProcessor($connection, [
    'chunk_size' => 1000,
    'memory_limit' => '128M',
    'progress_callback' => function($processed, $total) {
        echo "Processed: {$processed}/{$total}\n";
    }
]);

// Process large dataset
$processor->process(
    'SELECT * FROM large_table WHERE active = 1',
    function($row) {
        // Process each row
        updateUserRecord($row);
    }
);
```

### Advanced Processing

```php
// Process with custom query builder
$processor->processQuery(
    $queryBuilder->select('*')->from('users')->where('status', 'active'),
    function($batch) {
        // Process entire batch
        foreach ($batch as $user) {
            sendNotification($user);
        }
    },
    [
        'batch_size' => 500,
        'parallel' => true
    ]
);
```

### Configuration Options

```php
$config = [
    'chunk_size' => 1000,           // Records per chunk
    'memory_limit' => '128M',       // Memory limit per chunk
    'timeout' => 300,               // Query timeout (seconds)
    'retry_attempts' => 3,          // Retry failed chunks
    'parallel_chunks' => 1,         // Process chunks in parallel
    'progress_callback' => null,    // Progress callback function
    'error_callback' => null        // Error callback function
];
```

### Progress Tracking

```php
$processor->setProgressCallback(function($stats) {
    $percentage = ($stats['processed'] / $stats['total']) * 100;
    echo "Progress: {$percentage}% ({$stats['processed']}/{$stats['total']})\n";
    echo "Memory: {$stats['memory_usage']}MB\n";
    echo "ETA: {$stats['estimated_remaining']} seconds\n";
});
```

### Error Handling

```php
$processor->setErrorCallback(function($error, $chunk) {
    error_log("Error processing chunk {$chunk}: {$error->getMessage()}");
    
    // Return true to continue, false to stop
    return true;
});
```

---

## Lazy Container

Deferred object creation container for improved memory efficiency and performance.

### Features

- **Lazy Loading**: Create objects only when needed
- **Dependency Injection**: Automatic dependency resolution
- **Circular Dependency Detection**: Prevent infinite loops
- **Performance Optimization**: Reduce startup memory and time

### Basic Usage

```php
use Glueful\API\Performance\LazyContainer;

$container = new LazyContainer();

// Register lazy services
$container->lazy('database', function() {
    return new DatabaseConnection($config);
});

$container->lazy('userService', function($container) {
    return new UserService($container->get('database'));
});

// Objects are created only when first accessed
$userService = $container->get('userService'); // Database connection created here
```

### Service Registration

```php
// Simple factory
$container->lazy('logger', function() {
    return new Logger('app');
});

// With dependencies
$container->lazy('emailService', function($container) {
    return new EmailService(
        $container->get('logger'),
        $container->get('config')
    );
});

// Singleton services
$container->singleton('cache', function() {
    return new RedisCache($config);
});
```

### Configuration

```php
$container = new LazyContainer([
    'auto_wire' => true,            // Automatic dependency injection
    'circular_detection' => true,   // Detect circular dependencies
    'cache_instances' => true,      // Cache created instances
    'debug_mode' => false          // Debug dependency resolution
]);
```

### Advanced Features

```php
// Conditional services
$container->lazy('paymentProcessor', function($container) {
    $config = $container->get('config');
    
    if ($config->get('payment.provider') === 'stripe') {
        return new StripeProcessor($config);
    }
    
    return new PayPalProcessor($config);
});

// Service aliases
$container->alias('db', 'database');
$container->alias('log', 'logger');

// Service tags
$container->tag('emailService', ['notification', 'communication']);
$container->tag('smsService', ['notification', 'communication']);

// Get all services with tag
$notificationServices = $container->getByTag('notification');
```

### Performance Monitoring

```php
// Monitor container performance
$stats = $container->getStatistics();
echo "Services created: {$stats['created']}\n";
echo "Services cached: {$stats['cached']}\n";
echo "Average creation time: {$stats['avg_creation_time']}ms\n";
echo "Memory saved: {$stats['memory_saved']}MB\n";
```

---

## Best Practices

### Memory Management Guidelines

1. **Monitor Continuously**: Use real-time monitoring for production systems
2. **Set Appropriate Thresholds**: Configure warnings before critical situations
3. **Use Object Pooling**: Reuse expensive objects when possible
4. **Process in Chunks**: Handle large datasets with chunked processing
5. **Lazy Load Resources**: Create objects only when needed

### Performance Optimization

1. **Choose Right Tools**: Select appropriate iterator for your use case
2. **Configure Limits**: Set memory limits for all processing tasks
3. **Enable Alerting**: Get notified before problems occur
4. **Track Metrics**: Monitor memory patterns and trends
5. **Regular Cleanup**: Implement automated cleanup processes

### Common Patterns

```php
// Combine multiple tools for optimal performance
$container = new LazyContainer();
$pool = new MemoryPool(['max_size' => 10]);
$processor = new ChunkedDatabaseProcessor($connection);

// Process large dataset with pooled connections
$processor->process($query, function($batch) use ($pool) {
    $worker = $pool->acquire();
    try {
        $worker->processBatch($batch);
    } finally {
        $pool->release($worker);
    }
});
```

This comprehensive memory management system provides all the tools needed to build memory-efficient, scalable applications with Glueful.