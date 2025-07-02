# Glueful Logging System Guide

This comprehensive guide covers Glueful's sophisticated logging system, including the LogManager, multiple channels, performance monitoring, database logging, and advanced features like N+1 query detection.

## Table of Contents

1. [Overview](#overview)
2. [LogManager Core Features](#logmanager-core-features)
3. [Channel-Based Logging](#channel-based-logging)
4. [Database Logging](#database-logging)
5. [Query Performance Logging](#query-performance-logging)
6. [HTTP Request Logging](#http-request-logging)
7. [Performance Monitoring](#performance-monitoring)
8. [Configuration](#configuration)
9. [Log Maintenance](#log-maintenance)
10. [Production Best Practices](#production-best-practices)
11. [Troubleshooting](#troubleshooting)

## Overview

Glueful provides a comprehensive logging system built on Monolog with advanced features designed for production environments:

### Key Features

- **PSR-3 Compliant**: Standard logging interface with all log levels
- **Advanced Performance Monitoring**: Built-in timing, memory tracking, and performance analysis
- **N+1 Query Detection**: Automatic detection of N+1 query problems with recommendations
- **Multiple Output Channels**: App, API, framework, error, and debug channels
- **Database Logging**: Structured storage with automatic cleanup
- **Intelligent Sampling**: Configurable sampling rates for high-volume environments
- **Security-First**: Automatic sanitization of sensitive data
- **Production Ready**: File rotation, cleanup, and memory management

### Architecture

The logging system separates concerns between:
- **Framework Logging**: Performance metrics, protocol errors, system health
- **Application Logging**: Business logic, user actions, custom events
- **Query Logging**: Database performance, slow queries, N+1 detection
- **Request Logging**: HTTP requests, responses, middleware processing

## LogManager Core Features

### Basic Usage

```php
use Glueful\Logging\LogManager;

// Get logger instance (singleton)
$logger = LogManager::getInstance();

// Basic logging with all PSR-3 levels
$logger->emergency('System is unusable');
$logger->alert('Action must be taken immediately');
$logger->critical('Critical conditions');
$logger->error('Error conditions', ['error' => $exception->getMessage()]);
$logger->warning('Warning conditions');
$logger->notice('Normal but significant condition');
$logger->info('Informational messages');
$logger->debug('Debug-level messages');

// Contextual logging
$logger->info('User login', [
    'user_id' => 123,
    'ip_address' => $request->getClientIp(),
    'user_agent' => $request->headers->get('User-Agent')
]);
```

### Advanced Features

#### Performance Timing

```php
// Time operations
$timerId = $logger->startTimer('database_operation');

// Perform your operation
$results = $this->performDatabaseOperation();

// End timing (automatically logs duration)
$duration = $logger->endTimer($timerId);

// Manual timing
$logger->timeOperation('user_lookup', function() {
    return $this->userRepository->findById($userId);
});
```

#### Memory Monitoring

```php
// Get current memory usage
$memoryUsage = $logger->getCurrentMemoryUsage();

// Log with automatic memory context
$logger->info('Processing complete', [], true); // true = include memory info

// Memory warnings are automatic when thresholds exceeded
```

#### Batch Logging

```php
// Configure batch mode for high-volume logging
$logger->configure([
    'batch_mode' => true,
    'batch_size' => 100,
    'flush_interval' => 30 // seconds
]);

// Manual flush when needed
$logger->flushBatch();
```

## Channel-Based Logging

Glueful uses multiple channels to organize different types of logs:

### Available Channels

1. **app** - General application logging
2. **api** - API request/response logging
3. **framework** - Framework internals and performance
4. **error** - Error and exception logging
5. **debug** - Development and debugging information

### Using Channels

```php
// Channel-specific logging
$logger->channel('api')->info('API request processed', $context);
$logger->channel('error')->error('Database connection failed', $context);
$logger->channel('debug')->debug('Cache miss', ['key' => $cacheKey]);

// Switch channels
$apiLogger = $logger->channel('api');
$apiLogger->info('Request started');
$apiLogger->info('Request completed');
```

### Channel Configuration

```php
// config/logging.php
'channels' => [
    'app' => [
        'driver' => 'daily',
        'path' => 'storage/logs/app.log',
        'level' => 'info',
        'days' => 14
    ],
    'api' => [
        'driver' => 'daily',
        'path' => 'storage/logs/api.log',
        'level' => 'info',
        'days' => 30
    ],
    'error' => [
        'driver' => 'daily',
        'path' => 'storage/logs/error.log',
        'level' => 'error',
        'days' => 90
    ]
]
```

## Database Logging

Store logs in the database for structured querying and analysis.

### Setup

Database logging uses the `app_logs` table with automatic schema creation:

```sql
CREATE TABLE app_logs (
    id VARCHAR(255) PRIMARY KEY,
    level VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    execution_time DECIMAL(10,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_created_at (created_at)
);
```

### Usage

```php
use Glueful\Logging\DatabaseLogHandler;

// Enable database logging
$logger->pushHandler(new DatabaseLogHandler($connection));

// Logs are automatically stored in database
$logger->info('User action', [
    'user_id' => 123,
    'action' => 'profile_update',
    'ip_address' => $request->getClientIp()
]);
```

### Querying Database Logs

```php
use Glueful\Logging\DatabaseLogPruner;

$pruner = new DatabaseLogPruner($connection);

// Get recent logs
$recentErrors = $pruner->getLogsByLevel('error', 100);

// Get logs by date range
$logs = $pruner->getLogsByDateRange('2025-01-01', '2025-01-31');

// Search logs by context
$userLogs = $pruner->searchLogs(['user_id' => 123]);
```

## Query Performance Logging

Glueful includes sophisticated database query logging with performance analysis.

### Features

- **Slow Query Detection**: Configurable thresholds with automatic alerts
- **N+1 Query Detection**: Pattern recognition with recommendations
- **Query Complexity Analysis**: Scoring based on joins, subqueries, aggregations
- **Performance Statistics**: Comprehensive tracking by query type and performance

### Basic Usage

```php
use Glueful\Database\QueryLogger;

$queryLogger = new QueryLogger($logger);

// Enable query logging
$queryLogger->configure(true, true); // enable logging, enable analysis

// Log a query (usually automatic via database layer)
$startTime = $queryLogger->startTiming();
$result = $connection->select($sql, $params);
$queryLogger->logQuery($sql, $params, $startTime, null, 'user_lookup');
```

### N+1 Query Detection

```php
// Configure N+1 detection
$queryLogger->configureN1Detection(
    threshold: 5,        // Detect when 5+ similar queries
    timeWindow: 5        // Within 5 seconds
);

// Automatic detection and recommendations
// Example log output:
// "N+1 Query Pattern Detected: 15 similar queries in 2.3 seconds
//  Query: SELECT * FROM orders WHERE user_id = ?
//  Recommendation: Use eager loading or joins to reduce query count"
```

### Slow Query Analysis

```php
// Configure slow query detection
$queryLogger->setSlowQueryThreshold(100); // 100ms

// Automatic logging of slow queries with analysis
// Example output:
// "Slow Query Detected (234ms): Complex JOIN operation
//  Suggestions: Add index on user_id, consider query optimization"
```

### Query Statistics

```php
// Get comprehensive query statistics
$stats = $queryLogger->getQueryStatistics();
/*
[
    'total_queries' => 1247,
    'slow_queries' => 23,
    'n1_patterns' => 3,
    'avg_execution_time' => 45.2,
    'query_types' => [
        'SELECT' => 1100,
        'INSERT' => 89,
        'UPDATE' => 45,
        'DELETE' => 13
    ],
    'complexity_distribution' => [
        'simple' => 1000,
        'moderate' => 200,
        'complex' => 47
    ]
]
*/
```

## HTTP Request Logging

Comprehensive HTTP request and response logging via middleware.

### Features

- **Complete Request Lifecycle**: From request start to response completion
- **Performance Monitoring**: Request timing and slow request detection
- **Error Correlation**: Request ID tracking across logs
- **Context Injection**: Automatic request context in all logs

### Setup

```php
use Glueful\Http\Middleware\LoggerMiddleware;

// Add to middleware stack
Router::addMiddleware(new LoggerMiddleware());

// Or with custom configuration
Router::addMiddleware(new LoggerMiddleware('api', 'info'));
```

### Features

```php
// Automatic logging includes:
// - Request method, URL, headers
// - Request body (sanitized)
// - Response status, headers
// - Execution time
// - Memory usage
// - User context (if authenticated)

// Example log output:
// [INFO] HTTP Request: POST /api/users
// Context: {
//   "request_id": "req_abc123",
//   "method": "POST",
//   "url": "/api/users",
//   "user_id": 123,
//   "ip_address": "192.168.1.100",
//   "execution_time": 45.2,
//   "memory_usage": "2.3MB",
//   "response_status": 201
// }
```

## Performance Monitoring

### Built-in Performance Tracking

```php
// Memory usage monitoring
$logger->logMemoryUsage('After database operation');

// Execution time tracking
$logger->timeOperation('complex_calculation', function() {
    return $this->performComplexCalculation();
});

// Request correlation
$logger->setRequestId($requestId);
$logger->info('Processing started'); // Automatically includes request_id
```

### Performance Alerts

```php
// Configure performance thresholds
$logger->configure([
    'slow_request_threshold' => 1000,    // 1 second
    'memory_warning_threshold' => '128M',
    'memory_critical_threshold' => '256M'
]);

// Automatic alerts when thresholds exceeded
// [WARNING] Slow request detected: 1.2s execution time
// [CRITICAL] Memory usage critical: 280MB used
```

### Performance Reports

```php
// Get performance summary
$performance = $logger->getPerformanceReport();
/*
[
    'request_count' => 1000,
    'avg_response_time' => 234.5,
    'slow_requests' => 45,
    'memory_warnings' => 12,
    'peak_memory' => '89MB',
    'query_performance' => [
        'total_queries' => 5000,
        'slow_queries' => 89,
        'n1_detections' => 3
    ]
]
*/
```

## Configuration

### Main Configuration

```php
// config/logging.php
return [
    'framework_logging' => [
        'enabled' => true,
        'level' => env('FRAMEWORK_LOG_LEVEL', 'info'),
        'performance_monitoring' => true,
        'query_logging' => true,
        'request_logging' => true
    ],
    
    'application_logging' => [
        'enabled' => true,
        'level' => env('APP_LOG_LEVEL', 'info'),
        'database_logging' => false,
        'sampling_rate' => 1.0
    ],
    
    'performance' => [
        'slow_request_threshold' => 1000,
        'slow_query_threshold' => 100,
        'memory_warning_threshold' => '128M',
        'n1_detection_threshold' => 5,
        'n1_time_window' => 5
    ],
    
    'channels' => [
        'app' => [
            'driver' => 'daily',
            'path' => 'storage/logs/app.log',
            'level' => 'info',
            'days' => 14
        ],
        'api' => [
            'driver' => 'daily', 
            'path' => 'storage/logs/api.log',
            'level' => 'info',
            'days' => 30
        ]
    ],
    
    'database' => [
        'enabled' => false,
        'table' => 'app_logs',
        'retention_days' => 30
    ]
];
```

### Environment Variables

```env
# Basic logging
FRAMEWORK_LOG_LEVEL=info
APP_LOG_LEVEL=info

# Performance monitoring
SLOW_REQUEST_THRESHOLD=1000
SLOW_QUERY_THRESHOLD=100
MEMORY_WARNING_THRESHOLD=128M

# Database logging
DATABASE_LOGGING_ENABLED=false
LOG_RETENTION_DAYS=30

# Sampling (for high-volume)
LOG_SAMPLING_RATE=1.0
```

## Log Maintenance

### Automatic Cleanup

```php
use Glueful\Cron\LogCleaner;

$cleaner = new LogCleaner();

// Clean old log files
$fileStats = $cleaner->cleanLogFiles(30); // 30 days retention

// Clean database logs
$dbStats = $cleaner->cleanDatabaseLogs(30);

// Get cleanup summary
$summary = $cleaner->getCleanupSummary();
/*
[
    'files_cleaned' => 45,
    'files_size_freed' => '234MB',
    'db_records_cleaned' => 15000,
    'errors' => []
]
*/
```

### Manual Maintenance

```bash
# CLI commands for log maintenance
php glueful logs:clean --days=30
php glueful logs:rotate
php glueful logs:analyze --performance
```

### Database Log Pruning

```php
use Glueful\Logging\DatabaseLogPruner;

$pruner = new DatabaseLogPruner($connection);

// Clean logs older than 30 days
$cleaned = $pruner->pruneLogs(30);

// Clean by quantity (keep last 10000 records)
$cleaned = $pruner->pruneByQuantity(10000);

// Get statistics
$stats = $pruner->getLogStatistics();
```

## Production Best Practices

### High-Volume Environments

```php
// Configure for production
$logger->configure([
    'sampling_rate' => 0.1,           // Log only 10% of entries
    'batch_mode' => true,             // Batch writes for performance
    'batch_size' => 100,              // Write 100 entries at once
    'flush_interval' => 30,           // Flush every 30 seconds
    'minimum_level' => 'warning',     // Only log warnings and above
    'memory_limit' => '64M'           // Limit memory usage
]);
```

### Performance Optimization

```php
// Optimize for performance
$logger->configure([
    'async_writing' => true,          // Write logs asynchronously
    'compress_old_logs' => true,      // Gzip old log files
    'max_file_size' => '100MB',       // Rotate when files get large
    'max_files' => 10                 // Keep only 10 rotated files
]);
```

### Security Configuration

```php
// Ensure sensitive data is sanitized
$logger->configure([
    'sanitize_data' => true,
    'sensitive_keys' => ['password', 'token', 'api_key', 'secret'],
    'redaction_text' => '[REDACTED]'
]);
```

### Monitoring and Alerting

```php
// Set up monitoring
$logger->configure([
    'error_alerting' => true,
    'alert_threshold' => 10,          // Alert after 10 errors in window
    'alert_window' => 300,            // 5-minute window
    'alert_channels' => ['email', 'slack']
]);
```

## Troubleshooting

### Common Issues

#### High Memory Usage

```php
// Check memory configuration
$logger->configure([
    'memory_limit' => '32M',          // Lower memory limit
    'batch_size' => 50,               // Smaller batches
    'flush_interval' => 15            // More frequent flushes
]);
```

#### Slow Logging Performance

```php
// Optimize for speed
$logger->configure([
    'async_writing' => true,
    'sampling_rate' => 0.05,          // Log only 5%
    'minimum_level' => 'error',       // Only errors
    'batch_mode' => true
]);
```

#### Disk Space Issues

```php
// Aggressive cleanup
$cleaner = new LogCleaner();
$cleaner->cleanLogFiles(7);          // Keep only 7 days
$cleaner->compressOldLogs();         // Compress old logs
```

### Debug Logging

```php
// Enable debug mode for troubleshooting
$logger->configure([
    'debug_mode' => true,
    'log_queries' => true,
    'log_memory' => true,
    'log_execution_time' => true
]);

// Get internal statistics
$stats = $logger->getInternalStatistics();
```

### Log Analysis

```php
// Analyze log patterns
$analyzer = new LogAnalyzer($logger);

// Find error patterns
$errorPatterns = $analyzer->findErrorPatterns();

// Performance analysis
$performanceReport = $analyzer->analyzePerformance();

// Generate recommendations
$recommendations = $analyzer->getOptimizationRecommendations();
```

## Summary

Glueful's logging system provides enterprise-grade logging capabilities with:

- **Advanced Performance Monitoring**: Automatic detection of slow queries, N+1 problems, and performance issues
- **Production-Ready Features**: Sampling, batching, rotation, and cleanup
- **Multiple Storage Options**: Files, database, and custom handlers
- **Security-First Design**: Automatic data sanitization and secure logging practices
- **Developer-Friendly**: Rich debugging information and performance insights

The system is designed to handle both development debugging and production monitoring with minimal configuration required to get started, while providing advanced features for sophisticated production environments.