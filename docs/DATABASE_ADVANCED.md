# Glueful Database Advanced Features

This comprehensive guide covers Glueful's advanced database capabilities, including connection pooling, query optimization, performance monitoring, and enterprise-grade features for high-performance applications.

## Table of Contents

1. [Overview](#overview)
2. [Connection Pooling](#connection-pooling)
3. [Query Builder Advanced Features](#query-builder-advanced-features)
4. [Query Optimization](#query-optimization)
5. [Performance Monitoring](#performance-monitoring)
6. [Query Logging and Analytics](#query-logging-and-analytics)
7. [Database Driver System](#database-driver-system)
8. [Advanced Query Patterns](#advanced-query-patterns)
9. [Transaction Management](#transaction-management)
10. [Configuration](#configuration)
11. [Production Optimization](#production-optimization)

## Overview

Glueful's database system provides enterprise-grade features designed for high-performance applications with advanced capabilities including:

### Key Features

- **Connection Pooling**: Multi-engine connection pool management with health monitoring
- **Query Optimization**: Automatic query analysis and optimization with performance estimates
- **Advanced Query Builder**: Fluent interface with 100+ methods for complex query construction
- **Performance Monitoring**: Real-time query analysis, N+1 detection, and performance statistics
- **Multi-Database Support**: MySQL, PostgreSQL, and SQLite with driver-specific optimizations
- **Transaction Management**: Nested transactions with savepoints and deadlock handling
- **Query Caching**: Intelligent result caching with stampede protection

### Architecture Components

1. **ConnectionPoolManager**: Manages multiple pools for different database engines
2. **QueryBuilder**: Advanced fluent query construction with optimization
3. **QueryLogger**: Comprehensive logging with N+1 detection and performance analysis
4. **QueryOptimizer**: Database-specific query optimization with improvement estimates
5. **DatabaseInterface**: Unified interface across all database drivers

## Connection Pooling

### Overview

Glueful's connection pooling system provides efficient connection management with automatic lifecycle handling, health monitoring, and statistics tracking.

### Basic Usage

```php
use Glueful\Database\ConnectionPoolManager;

// Get pool manager
$poolManager = container()->get(ConnectionPoolManager::class);

// Get connection pool for specific engine
$mysqlPool = $poolManager->getPool('mysql');
$pgsqlPool = $poolManager->getPool('pgsql');

// Acquire connection from pool
$connection = $mysqlPool->acquire();

// Use connection (auto-released when out of scope)
$queryBuilder = new QueryBuilder($connection, $driver, $logger);
$results = $queryBuilder->select('users', ['*'])->where(['active' => 1])->get();

// Manual release (optional - automatic on destruction)
$mysqlPool->release($connection);
```

### Pool Configuration

```php
// config/database.php
return [
    'pooling' => [
        'defaults' => [
            'min_connections' => 5,
            'max_connections' => 20,
            'acquire_timeout' => 30,
            'idle_timeout' => 300,
            'health_check_interval' => 60,
            'max_connection_age' => 3600
        ],
        'engines' => [
            'mysql' => [
                'max_connections' => 25,
                'min_connections' => 8
            ],
            'pgsql' => [
                'max_connections' => 15,
                'min_connections' => 3
            ]
        ]
    ]
];
```

### Pool Statistics and Monitoring

```php
// Get statistics for all pools
$stats = $poolManager->getStats();
/*
[
    'mysql' => [
        'active_connections' => 12,
        'idle_connections' => 3,
        'total_connections' => 15,
        'total_created' => 45,
        'total_destroyed' => 30,
        'total_acquisitions' => 1250,
        'total_releases' => 1238,
        'total_timeouts' => 2,
        'total_health_checks' => 120,
        'failed_health_checks' => 0
    ]
]
*/

// Get aggregate statistics
$aggregate = $poolManager->getAggregateStats();

// Get health status
$health = $poolManager->getHealthStatus();
/*
[
    'mysql' => [
        'healthy' => true,
        'active_connections' => 12,
        'health_check_failure_rate' => 0.0,
        'timeout_rate' => 0.16
    ]
]
*/
```

### Pooled Connection Features

```php
use Glueful\Database\PooledConnection;

// Connection automatically tracks usage
$connection = $pool->acquire();

// Get connection statistics
$stats = $connection->getStats();
/*
[
    'id' => 'conn_abc123',
    'age' => 125.45,              // seconds since creation
    'idle_time' => 5.23,          // seconds since last use
    'use_count' => 47,            // number of times used
    'in_transaction' => false,     // transaction state
    'is_healthy' => true          // health status
]
*/

// Check connection state
$isHealthy = $connection->isHealthy();
$inTransaction = $connection->isInTransaction();
$age = $connection->getAge();
$idleTime = $connection->getIdleTime();
```

## Query Builder Advanced Features

### Complex Query Construction

```php
// Multi-table joins with complex conditions
$results = $queryBuilder
    ->select('users', ['users.*', 'profiles.bio', 'roles.name AS role_name'])
    ->join('profiles', 'profiles.user_id = users.id', 'LEFT')
    ->join('user_roles', 'user_roles.user_id = users.id', 'INNER')
    ->join('roles', 'roles.id = user_roles.role_id', 'INNER')
    ->where(['users.active' => 1])
    ->whereIn('roles.name', ['admin', 'moderator'])
    ->whereGreaterThan('users.created_at', '2024-01-01')
    ->whereOr(function($q) {
        $q->whereNull('users.deleted_at')
          ->orWhereGreaterThan('users.deleted_at', date('Y-m-d H:i:s'));
    })
    ->orderBy(['users.last_login' => 'DESC', 'users.created_at' => 'ASC'])
    ->limit(50)
    ->get();
```

### Advanced Filtering and Search

```php
// Multi-column text search with relevance
$users = $queryBuilder
    ->select('users', ['*'])
    ->search(['username', 'email', 'first_name', 'last_name'], 'john smith', 'OR')
    ->orderBy(['username' => 'ASC'])
    ->get();

// Advanced filtering with multiple operators
$orders = $queryBuilder
    ->select('orders', ['*'])
    ->advancedWhere([
        'status' => ['in' => ['pending', 'processing']],
        'total' => ['between' => [100, 1000]],
        'created_at' => ['gte' => '2024-01-01'],
        'customer_email' => ['like' => '%@company.com']
    ])
    ->get();

// JSON column searching (database-agnostic)
$logs = $queryBuilder
    ->select('logs', ['*'])
    ->whereJsonContains('metadata', 'login_failed')
    ->whereJsonContains('details', 'active', '$.status')  // MySQL path syntax
    ->get();
```

### Query Building with Optimization

```php
// Enable query optimization with custom threshold
$optimizedResults = $queryBuilder
    ->enableOptimization()
    ->setOptimizationThreshold(15.0)  // 15% improvement required
    ->select('orders', ['*'])
    ->join('customers', 'customers.id = orders.customer_id')
    ->join('products', 'products.id = order_items.product_id')
    ->where(['orders.status' => 'completed'])
    ->orderBy(['orders.created_at' => 'DESC'])
    ->optimize()  // Apply optimizations
    ->cache(3600) // Cache results for 1 hour
    ->get();
```

### Raw Expressions and Complex Queries

```php
// Using raw expressions for complex calculations
$salesReport = $queryBuilder
    ->select('orders', [
        'DATE(created_at) as date',
        $queryBuilder->raw('COUNT(*) as order_count'),
        $queryBuilder->raw('SUM(total) as daily_revenue'),
        $queryBuilder->raw('AVG(total) as avg_order_value'),
        $queryBuilder->raw('MAX(total) as highest_order')
    ])
    ->where(['status' => 'completed'])
    ->whereBetween('created_at', $startDate, $endDate)
    ->groupBy(['DATE(created_at)'])
    ->having(['order_count' => 5])  // At least 5 orders per day
    ->havingRaw('SUM(total) > ?', [1000])  // Daily revenue > $1000
    ->orderBy(['date' => 'DESC'])
    ->get();
```

### Pagination with Optimization

```php
// Optimized pagination with count query optimization
$page = $request->get('page', 1);
$perPage = $request->get('per_page', 20);

$paginatedResults = $queryBuilder
    ->select('products', ['*'])
    ->join('categories', 'categories.id = products.category_id')
    ->where(['products.active' => 1])
    ->search(['products.name', 'products.description'], $searchTerm)
    ->orderBy(['products.featured' => 'DESC', 'products.created_at' => 'DESC'])
    ->paginate($page, $perPage);

/*
Returns:
[
    'data' => [...],
    'current_page' => 1,
    'per_page' => 20,
    'total' => 1250,
    'last_page' => 63,
    'has_more' => true,
    'from' => 1,
    'to' => 20,
    'execution_time_ms' => 45.67
]
*/
```

## Query Optimization

### Automatic Query Analysis and Optimization

```php
use Glueful\Database\QueryOptimizer;

$optimizer = new QueryOptimizer();
$optimizer->setConnection($connection);

// Analyze and optimize a complex query
$result = $optimizer->optimizeQuery(
    "SELECT u.*, p.bio FROM users u 
     LEFT JOIN profiles p ON p.user_id = u.id 
     WHERE u.status = ? AND u.created_at > ? 
     ORDER BY u.last_login DESC",
    ['active', '2024-01-01']
);

/*
Returns:
[
    'original_query' => '...',
    'optimized_query' => '...',
    'suggestions' => [
        [
            'type' => 'missing_index',
            'description' => 'Query may benefit from an index',
            'solution' => 'Add an index to the referenced column',
            'impact' => 'high'
        ],
        [
            'type' => 'inefficient_join',
            'description' => 'Join order could be optimized',
            'solution' => 'Reorder joins to start with most restrictive conditions',
            'impact' => 'medium'
        ]
    ],
    'estimated_improvement' => [
        'execution_time' => 25,    // 25% improvement
        'resource_usage' => 30,    // 30% less resources
        'confidence' => 'high'
    ]
]
*/
```

### Database-Specific Optimizations

```php
// MySQL-specific optimizations
$mysqlOptimizer = new QueryOptimizer();
$mysqlOptimizer->setConnection($mysqlConnection);

// Optimization may include:
// - STRAIGHT_JOIN hints for complex joins
// - Index usage optimization
// - WITH ROLLUP for aggregations
$optimized = $mysqlOptimizer->optimizeQuery($complexQuery, $params);

// PostgreSQL-specific optimizations
$pgsqlOptimizer = new QueryOptimizer();
$pgsqlOptimizer->setConnection($pgsqlConnection);

// May include specialized PostgreSQL optimizations
$optimized = $pgsqlOptimizer->optimizeQuery($complexQuery, $params);
```

### Manual Optimization Triggers

```php
// Apply optimization only if improvement exceeds threshold
$queryBuilder
    ->select('orders', ['*'])
    ->join('customers', 'customers.id = orders.customer_id')
    ->where(['status' => 'pending'])
    ->enableOptimization()
    ->setOptimizationThreshold(20.0)  // Only apply if 20%+ improvement
    ->optimize()
    ->get();
```

## Performance Monitoring

### Query Performance Analysis

```php
use Glueful\Database\QueryLogger;

$logger = new QueryLogger($frameworkLogger);

// Configure performance monitoring
$logger->configure(
    enableDebug: true,
    enableTiming: true,
    maxLogSize: 500
);

// Configure N+1 detection
$logger->configureN1Detection(
    threshold: 5,      // 5 similar queries triggers detection
    timeWindow: 5      // within 5 seconds
);

// Get comprehensive statistics
$stats = $logger->getStatistics();
/*
[
    'total' => 1250,
    'select' => 980,
    'insert' => 125,
    'update' => 95,
    'delete' => 35,
    'other' => 15,
    'error' => 3,
    'total_time' => 15670.25  // milliseconds
]
*/

// Get average execution time
$avgTime = $logger->getAverageExecutionTime(); // 12.54 ms

// Format execution time for display
$formattedTime = $logger->formatExecutionTime(1250.5); // "1.25 s"
```

### N+1 Query Detection

```php
// Automatic N+1 detection with recommendations
// The logger will automatically detect patterns like:

// BAD: N+1 pattern
foreach ($users as $user) {
    $profile = $queryBuilder
        ->select('profiles', ['*'])
        ->where(['user_id' => $user->id])
        ->first();
}

// Logger will detect this pattern and recommend:
// "Consider using eager loading or preloading related data in a single query
//  instead of multiple individual lookups"

// GOOD: Optimized approach
$userIds = array_column($users, 'id');
$profiles = $queryBuilder
    ->select('profiles', ['*'])
    ->whereIn('user_id', $userIds)
    ->get();
```

### Query Complexity Analysis

```php
// Queries are automatically analyzed for complexity
$complexQuery = "
    SELECT 
        u.username,
        COUNT(o.id) as order_count,
        SUM(o.total) as total_spent,
        AVG(o.total) as avg_order,
        ROW_NUMBER() OVER (PARTITION BY u.department ORDER BY SUM(o.total) DESC) as rank
    FROM users u
    LEFT JOIN orders o ON o.user_id = u.id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE u.active = 1 
    AND o.created_at > DATE_SUB(NOW(), INTERVAL 1 YEAR)
    GROUP BY u.id, u.username, u.department
    HAVING COUNT(o.id) > 5
    ORDER BY total_spent DESC, u.username
";

// Complexity factors analyzed:
// - JOIN operations (+1 each)
// - Subqueries (+2 each)
// - Aggregation functions (+1)
// - Window functions (+2)
// - GROUP BY/HAVING (+1 each)
// - UNION/INTERSECT/EXCEPT (+2 each)
// Results in complexity score for optimization prioritization
```

## Query Logging and Analytics

### Business Context Logging

```php
// Add business context to queries
$userProfile = $queryBuilder
    ->withPurpose('User profile page data loading')
    ->select('users', ['*'])
    ->join('profiles', 'profiles.user_id = users.id')
    ->where(['users.id' => $userId])
    ->first();

// Query will be logged with business context for better debugging
```

### Query Log Analysis

```php
// Get detailed query log
$queryLog = $logger->getQueryLog();

foreach ($queryLog as $entry) {
    echo "Query: {$entry['sql']}\n";
    echo "Type: {$entry['type']}\n";
    echo "Tables: " . implode(', ', $entry['tables']) . "\n";
    echo "Complexity: {$entry['complexity']}\n";
    echo "Execution time: {$entry['time']}\n";
    echo "Purpose: {$entry['purpose']}\n";
    if ($entry['error']) {
        echo "Error: {$entry['error']}\n";
    }
    echo "---\n";
}
```

### Event-Driven Logging

```php
// Listen to query execution events for custom logging
use Glueful\Events\Database\QueryExecutedEvent;

Event::listen(QueryExecutedEvent::class, function($event) {
    // Custom application-specific query logging
    if ($event->executionTime > 1.0) { // > 1 second
        $this->alertingService->sendSlowQueryAlert([
            'sql' => $event->sql,
            'execution_time' => $event->executionTime,
            'connection' => $event->connectionName,
            'metadata' => $event->metadata
        ]);
    }
    
    // Log to business analytics
    $this->analyticsService->trackDatabaseQuery([
        'query_type' => $this->determineQueryType($event->sql),
        'tables' => $event->metadata['tables'] ?? [],
        'execution_time' => $event->executionTime,
        'purpose' => $event->metadata['purpose'] ?? null
    ]);
});
```

## Database Driver System

### Multi-Database Support

```php
// Database-agnostic query building
$driver = $connection->getDriver(); // MySQLDriver, PostgreSQLDriver, or SQLiteDriver

// Driver-specific identifier wrapping
$wrappedTable = $driver->wrapIdentifier('users');
$wrappedColumn = $driver->wrapIdentifier('user_name');

// Driver-specific query features
if ($driver instanceof MySQLDriver) {
    // MySQL-specific features
    $upsertQuery = $driver->upsert('users', ['username', 'email'], ['last_login']);
} elseif ($driver instanceof PostgreSQLDriver) {
    // PostgreSQL-specific features
    $upsertQuery = $driver->upsert('users', ['username', 'email'], ['last_login']);
}
```

### Driver Capabilities

```php
// Check driver capabilities
$capabilities = $connection->getCapabilities();
/*
[
    'supports_json' => true,
    'supports_window_functions' => true,
    'supports_upsert' => true,
    'supports_returning' => true,  // PostgreSQL
    'supports_full_text_search' => true,
    'max_identifier_length' => 64
]
*/
```

## Advanced Query Patterns

### Bulk Operations

```php
// Bulk insert with batch processing
$users = [
    ['username' => 'user1', 'email' => 'user1@example.com'],
    ['username' => 'user2', 'email' => 'user2@example.com'],
    // ... 1000+ records
];

$batchSize = 100;
$totalInserted = 0;

foreach (array_chunk($users, $batchSize) as $batch) {
    $inserted = $queryBuilder->insertBatch('users', $batch);
    $totalInserted += $inserted;
}

// Bulk update with advanced conditions
$affectedRows = $queryBuilder
    ->update('users', 
        ['last_seen' => date('Y-m-d H:i:s')],
        ['active' => 1, 'created_at <' => date('Y-m-d', strtotime('-30 days'))]
    );
```

### Upsert Operations

```php
// MySQL UPSERT (INSERT ... ON DUPLICATE KEY UPDATE)
$affected = $queryBuilder->upsert(
    'user_stats',
    [
        ['user_id' => 1, 'login_count' => 1, 'last_login' => date('Y-m-d H:i:s')],
        ['user_id' => 2, 'login_count' => 1, 'last_login' => date('Y-m-d H:i:s')]
    ],
    ['login_count', 'last_login'] // columns to update on duplicate
);

// PostgreSQL UPSERT (INSERT ... ON CONFLICT)
$affected = $queryBuilder->upsert(
    'user_stats',
    [
        ['user_id' => 1, 'login_count' => 1, 'last_login' => date('Y-m-d H:i:s')]
    ],
    ['login_count', 'last_login']
);
```

### Soft Delete Management

```php
// Include soft-deleted records
$allUsers = $queryBuilder
    ->select('users', ['*'], [], withTrashed: true)
    ->get();

// Only soft-deleted records
$deletedUsers = $queryBuilder
    ->select('users', ['*'])
    ->whereNotNull('deleted_at')
    ->get();

// Restore soft-deleted records
$restored = $queryBuilder->restore('users', ['id' => $userId]);

// Hard delete (permanent)
$deleted = $queryBuilder->delete('users', ['id' => $userId], softDelete: false);
```

### Window Functions and Analytics

```php
// Complex analytics queries with window functions
$salesAnalytics = $queryBuilder
    ->select('sales', [
        'date',
        'amount',
        'region',
        $queryBuilder->raw('SUM(amount) OVER (PARTITION BY region) as region_total'),
        $queryBuilder->raw('RANK() OVER (PARTITION BY region ORDER BY amount DESC) as region_rank'),
        $queryBuilder->raw('LAG(amount, 1) OVER (ORDER BY date) as previous_day_amount'),
        $queryBuilder->raw('AVG(amount) OVER (ORDER BY date ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) as moving_avg_7_days')
    ])
    ->whereBetween('date', $startDate, $endDate)
    ->orderBy(['date' => 'ASC'])
    ->get();
```

## Transaction Management

### Nested Transactions with Savepoints

```php
// Automatic deadlock handling with retry
$result = $queryBuilder->transaction(function($qb) use ($orderData, $inventoryUpdates) {
    // Create order
    $orderId = $qb->insert('orders', $orderData);
    
    // Create order items in nested transaction
    $qb->transaction(function($qb) use ($orderId, $orderData) {
        foreach ($orderData['items'] as $item) {
            $qb->insert('order_items', [
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ]);
        }
    });
    
    // Update inventory
    foreach ($inventoryUpdates as $update) {
        $qb->update('inventory', 
            ['quantity' => $qb->raw('quantity - ?', [$update['quantity']])],
            ['product_id' => $update['product_id']]
        );
    }
    
    return $orderId;
});
```

### Manual Transaction Control

```php
try {
    $queryBuilder->beginTransaction();
    
    // Complex multi-step operation
    $userId = $queryBuilder->insert('users', $userData);
    $profileId = $queryBuilder->insert('profiles', array_merge($profileData, ['user_id' => $userId]));
    
    // Nested savepoint
    $queryBuilder->beginTransaction();
    try {
        $queryBuilder->insert('user_preferences', ['user_id' => $userId, 'theme' => 'dark']);
        $queryBuilder->commit(); // Commit savepoint
    } catch (Exception $e) {
        $queryBuilder->rollback(); // Rollback to savepoint
        // Continue with main transaction
    }
    
    $queryBuilder->commit(); // Commit main transaction
    
} catch (Exception $e) {
    $queryBuilder->rollback(); // Rollback main transaction
    throw $e;
}
```

### Transaction State Monitoring

```php
// Check transaction state
if ($queryBuilder->isTransactionActive()) {
    echo "Transaction level: " . $queryBuilder->getTransactionLevel();
}

// Connection statistics for pooled connections
if ($queryBuilder->isUsingPooledConnection()) {
    $connectionStats = $queryBuilder->getConnectionStats();
    echo "Connection age: " . $connectionStats['age'] . " seconds\n";
    echo "Use count: " . $connectionStats['use_count'] . "\n";
}
```

## Configuration

### Database Configuration

```php
// config/database.php
return [
    'mysql' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'db' => env('DB_DATABASE'),
        'user' => env('DB_USERNAME'),
        'pass' => env('DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'strict' => true,
        
        // Advanced MySQL options
        'options' => [
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES'"
        ]
    ],
    
    'pgsql' => [
        'driver' => 'pgsql',
        'host' => env('PGSQL_HOST', '127.0.0.1'),
        'port' => env('PGSQL_PORT', 5432),
        'db' => env('PGSQL_DATABASE'),
        'user' => env('PGSQL_USERNAME'),
        'pass' => env('PGSQL_PASSWORD'),
        'sslmode' => env('PGSQL_SSLMODE', 'prefer'),
        'schema' => env('PGSQL_SCHEMA', 'public')
    ],
    
    // Connection pooling configuration
    'pooling' => [
        'enabled' => env('DB_POOL_ENABLED', true),
        'defaults' => [
            'min_connections' => env('DB_POOL_MIN_CONNECTIONS', 5),
            'max_connections' => env('DB_POOL_MAX_CONNECTIONS', 20),
            'acquire_timeout' => env('DB_POOL_ACQUIRE_TIMEOUT', 30),
            'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 300),
            'health_check_interval' => env('DB_POOL_HEALTH_CHECK_INTERVAL', 60),
            'max_connection_age' => env('DB_POOL_MAX_CONNECTION_AGE', 3600)
        ]
    ]
];
```

### Query Optimization Configuration

```php
// config/database_optimization.php
return [
    'query_optimization' => [
        'enabled' => env('DB_OPTIMIZATION_ENABLED', true),
        'default_threshold' => env('DB_OPTIMIZATION_THRESHOLD', 10.0), // 10% improvement required
        'cache_optimizations' => env('DB_OPTIMIZATION_CACHE', true),
        
        'engines' => [
            'mysql' => [
                'use_straight_join' => true,
                'optimize_group_by' => true,
                'index_hints' => true
            ],
            'pgsql' => [
                'use_query_planner_hints' => false,
                'optimize_window_functions' => true
            ]
        ]
    ],
    
    'query_analysis' => [
        'enabled' => env('DB_ANALYSIS_ENABLED', true),
        'complexity_threshold' => 5, // Queries with complexity > 5 get extra analysis
        'execution_plan_analysis' => env('DB_ANALYZE_EXECUTION_PLANS', false)
    ]
];
```

### Performance Monitoring Configuration

```php
// config/database_monitoring.php
return [
    'query_logging' => [
        'enabled' => env('DB_QUERY_LOGGING_ENABLED', true),
        'debug_mode' => env('DB_DEBUG_MODE', false),
        'max_log_size' => env('DB_MAX_LOG_SIZE', 500),
        
        'slow_query_detection' => [
            'enabled' => env('DB_SLOW_QUERY_DETECTION', true),
            'threshold_ms' => env('DB_SLOW_QUERY_THRESHOLD', 200),
            'log_level' => 'warning'
        ],
        
        'n1_detection' => [
            'enabled' => env('DB_N1_DETECTION_ENABLED', true),
            'threshold' => env('DB_N1_THRESHOLD', 5),
            'time_window' => env('DB_N1_TIME_WINDOW', 5)
        ]
    ],
    
    'performance_monitoring' => [
        'enabled' => env('DB_PERFORMANCE_MONITORING', true),
        'track_query_complexity' => true,
        'track_table_usage' => true,
        'emit_events' => true
    ]
];
```

## Production Optimization

### High-Performance Configuration

```php
// Production-optimized settings
return [
    'mysql' => [
        'options' => [
            PDO::ATTR_PERSISTENT => true,              // Use persistent connections
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Buffer query results
            PDO::MYSQL_ATTR_INIT_COMMAND => 
                "SET sql_mode='STRICT_ALL_TABLES', " .
                "SESSION query_cache_type='ON', " .
                "SESSION query_cache_size=67108864",    // 64MB query cache
        ]
    ],
    
    'pooling' => [
        'defaults' => [
            'min_connections' => 10,    // Higher minimum for production
            'max_connections' => 50,    // Higher maximum for production
            'acquire_timeout' => 10,    // Faster timeout
            'idle_timeout' => 600,      // 10 minutes
            'health_check_interval' => 30,
            'max_connection_age' => 1800 // 30 minutes
        ]
    ],
    
    'query_optimization' => [
        'enabled' => true,
        'default_threshold' => 5.0,     // Lower threshold for more optimizations
        'cache_optimizations' => true
    ],
    
    'query_logging' => [
        'debug_mode' => false,          // Disable debug mode in production
        'max_log_size' => 100,          // Smaller log size
        'slow_query_detection' => [
            'threshold_ms' => 100       // Lower threshold for production monitoring
        ]
    ]
];
```

### Monitoring and Alerting

```php
// Production monitoring setup
class DatabaseMonitoringService
{
    private QueryLogger $logger;
    private ConnectionPoolManager $poolManager;
    
    public function getHealthMetrics(): array
    {
        $poolStats = $this->poolManager->getAggregateStats();
        $queryStats = $this->logger->getStatistics();
        
        return [
            'database_health' => [
                'total_connections' => $poolStats['total_active_connections'] + $poolStats['total_idle_connections'],
                'active_connections' => $poolStats['total_active_connections'],
                'connection_pool_utilization' => $this->calculatePoolUtilization($poolStats),
                'average_query_time' => $this->logger->getAverageExecutionTime(),
                'slow_query_rate' => $this->calculateSlowQueryRate($queryStats),
                'error_rate' => $this->calculateErrorRate($queryStats),
                'n1_detections_last_hour' => $this->getN1DetectionsCount()
            ]
        ];
    }
    
    public function checkAlerts(): array
    {
        $alerts = [];
        $metrics = $this->getHealthMetrics()['database_health'];
        
        // Connection pool alerts
        if ($metrics['connection_pool_utilization'] > 0.9) {
            $alerts[] = 'Connection pool utilization above 90%';
        }
        
        // Performance alerts
        if ($metrics['average_query_time'] > 500) { // 500ms
            $alerts[] = 'Average query time above 500ms';
        }
        
        if ($metrics['slow_query_rate'] > 0.1) { // 10%
            $alerts[] = 'Slow query rate above 10%';
        }
        
        // Error rate alerts
        if ($metrics['error_rate'] > 0.05) { // 5%
            $alerts[] = 'Database error rate above 5%';
        }
        
        return $alerts;
    }
}
```

### Performance Optimization Best Practices

```php
// 1. Use connection pooling in production
$poolManager = container()->get(ConnectionPoolManager::class);
$connection = $poolManager->getPool('mysql')->acquire();

// 2. Enable query optimization for complex queries
$results = $queryBuilder
    ->enableOptimization()
    ->setOptimizationThreshold(5.0)  // Lower threshold for production
    ->select('complex_table', ['*'])
    ->join('related_table', 'related_table.id = complex_table.related_id')
    ->where(['status' => 'active'])
    ->optimize()
    ->cache(300)  // Cache for 5 minutes
    ->get();

// 3. Use bulk operations for large datasets
$users = $userService->getActiveUsers();
$userIds = array_column($users, 'id');

// Instead of N+1 queries
$profiles = $queryBuilder
    ->select('profiles', ['*'])
    ->whereIn('user_id', $userIds)
    ->get();

// 4. Monitor query performance
$queryLogger = container()->get(QueryLogger::class);
$queryLogger->configure(enableDebug: false, enableTiming: true);

// 5. Use appropriate indexes and analyze query plans
$result = $queryBuilder
    ->withPurpose('User dashboard data loading')
    ->select('users', ['*'])
    ->join('profiles', 'profiles.user_id = users.id')
    ->join('user_stats', 'user_stats.user_id = users.id')
    ->where(['users.active' => 1])
    ->orderBy(['users.last_login' => 'DESC'])
    ->limit(20)
    ->get();
```

## Summary

Glueful's advanced database features provide enterprise-grade capabilities for high-performance applications:

- **Connection Pooling**: Efficient connection management with health monitoring and statistics
- **Query Optimization**: Automatic analysis and optimization with database-specific improvements
- **Performance Monitoring**: Real-time analysis, N+1 detection, and comprehensive statistics
- **Advanced Query Builder**: 100+ methods for complex query construction with fluent interface
- **Multi-Database Support**: MySQL, PostgreSQL, and SQLite with driver-specific optimizations
- **Transaction Management**: Nested transactions with savepoints and automatic deadlock handling
- **Production Ready**: Comprehensive monitoring, alerting, and optimization for production environments

The system is designed to scale from simple applications to high-traffic, distributed environments while maintaining optimal performance and providing detailed insights into database operations.