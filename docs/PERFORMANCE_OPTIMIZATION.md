# Database Performance Optimization Guide

This comprehensive guide covers all database performance optimization features in Glueful, including query optimization, caching, profiling, and analysis tools.

## Table of Contents

1. [Overview](#overview)
2. [Query Optimization](#query-optimization)
3. [Query Caching System](#query-caching-system)
4. [Query Analysis Tools](#query-analysis-tools)
5. [Database Profiling Tools](#database-profiling-tools)
6. [Query Logger Optimizations](#query-logger-optimizations)
7. [Best Practices](#best-practices)
8. [Performance Metrics](#performance-metrics)
9. [Troubleshooting](#troubleshooting)

## Overview

Glueful v0.27.0 introduces a comprehensive suite of database performance optimization tools designed to:

- **Automatically optimize queries** for different database engines (MySQL, PostgreSQL, SQLite)
- **Cache query results** intelligently with automatic invalidation
- **Analyze and profile** database operations for bottlenecks
- **Detect performance issues** and provide actionable recommendations
- **Monitor query patterns** and identify N+1 problems

### Performance Improvements

The optimization features provide significant performance gains:

- **Complex queries**: 20-40% performance improvement
- **Join-heavy queries**: Up to 50% improvement for inefficient joins
- **Cached queries**: 50-95% improvement for frequently accessed data
- **High-traffic applications**: 30-50% reduction in database load

## Query Optimization

The Query Optimizer analyzes SQL queries and implements database-specific optimizations automatically.

### Features

- Database-specific optimizations for MySQL, PostgreSQL, and SQLite
- Automatic detection of inefficient query patterns
- Performance improvement estimation
- Detailed suggestions for manual query optimization
- Specialized optimization for JOINs, WHERE clauses, GROUP BY, and ORDER BY operations

### Basic Usage

```php
use Glueful\Database\Connection;
use Glueful\Database\QueryOptimizer;

// Get a database connection
$connection = new Connection();

// Create the query optimizer
$optimizer = new QueryOptimizer($connection);

// Optimize a query
$result = $optimizer->optimizeQuery(
    "SELECT * FROM users JOIN orders ON users.id = orders.user_id WHERE users.status = 'active'",
    [] // Query parameters (if using prepared statements)
);

// The result contains:
// - original_query: The original query string
// - optimized_query: The optimized version of the query
// - suggestions: Array of optimization suggestions
// - estimated_improvement: Estimated performance improvement metrics
```

### Integration with Query Builder

```php
use Glueful\Database\QueryBuilder;

$users = (new QueryBuilder($connection))
    ->select('users.*', 'orders.id as order_id')
    ->from('users')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.status', '=', 'active')
    ->optimize() // Enable optimization
    ->get();
```

### Database-Specific Optimizations

#### MySQL Optimizations

- Use of `STRAIGHT_JOIN` hint for complex joins when beneficial
- Reordering of JOIN clauses for better execution
- Optimization of WHERE clauses to leverage indexes
- Addition of `WITH ROLLUP` for appropriate aggregate queries
- Optimizing ORDER BY to minimize filesort operations

```php
// MySQL-specific query with potential for optimization
$query = "
    SELECT 
        customers.name,
        COUNT(orders.id) as order_count,
        SUM(orders.total) as total_spent
    FROM customers
    LEFT JOIN orders ON customers.id = orders.customer_id
    WHERE customers.region = 'Europe'
    GROUP BY customers.id
    ORDER BY total_spent DESC
";

$result = $optimizer->optimizeQuery($query);

// MySQL might optimize this with:
// 1. STRAIGHT_JOIN to enforce join order
// 2. WITH ROLLUP for the GROUP BY if appropriate
// 3. Index hints for better performance
```

#### PostgreSQL Optimizations

- JOIN type optimizations based on data volume
- Index usage recommendations
- Multi-dimensional aggregation optimizations with CUBE and ROLLUP
- Optimized CTE (Common Table Expression) handling

#### SQLite Optimizations

- Optimized JOIN ordering
- Simplification of complex queries where possible
- Column order optimizations in WHERE clauses

### Optimization Results

```php
// Accessing optimization results
$optimizedQuery = $result['optimized_query'];

// View improvement metrics
$improvement = $result['estimated_improvement'];
echo "Estimated execution time improvement: {$improvement['execution_time']}%";
echo "Estimated resource usage improvement: {$improvement['resource_usage']}%";

// Review optimization suggestions
foreach ($result['suggestions'] as $suggestion) {
    echo "Suggestion: {$suggestion['description']}";
    echo "Solution: {$suggestion['solution']}";
}
```

### Advanced Usage

#### Custom Optimization Thresholds

```php
// Custom QueryBuilder extension
class OptimizedQueryBuilder extends QueryBuilder
{
    protected $optimizationThreshold = 10; // Default: apply optimization if 10% improvement
    
    public function setOptimizationThreshold(int $percentage): self
    {
        $this->optimizationThreshold = $percentage;
        return $this;
    }
    
    public function get()
    {
        if ($this->optimizeQuery) {
            $optimizer = new QueryOptimizer($this->connection);
            $result = $optimizer->optimizeQuery($this->toSql(), $this->getBindings());
            
            // Only use optimized query if improvement exceeds threshold
            if ($result['estimated_improvement']['execution_time'] > $this->optimizationThreshold) {
                return $this->connection->select(
                    $result['optimized_query'], 
                    $this->getBindings()
                );
            }
        }
        
        return parent::get();
    }
}

// Usage
$qb = new OptimizedQueryBuilder($connection);
$qb->setOptimizationThreshold(20) // Only optimize if 20% or better improvement
   ->select('*')
   ->from('products')
   ->optimize()
   ->get();
```

#### Monitoring Optimization Effectiveness

```php
// Enable query timing
$startTime = microtime(true);

// Execute with optimization
$optimizer = new QueryOptimizer($connection);
$result = $optimizer->optimizeQuery($query);
$optimizedQuery = $result['optimized_query'];
$optimizedResults = $connection->select($optimizedQuery);

$optimizedTime = microtime(true) - $startTime;

// Execute without optimization
$startTime = microtime(true);
$originalResults = $connection->select($query);
$originalTime = microtime(true) - $startTime;

// Compare performance
$improvementPercentage = (($originalTime - $optimizedTime) / $originalTime) * 100;
echo "Original query execution time: {$originalTime}s\n";
echo "Optimized query execution time: {$optimizedTime}s\n";
echo "Actual improvement: {$improvementPercentage}%\n";
echo "Estimated improvement: {$result['estimated_improvement']['execution_time']}%\n";
```

## Query Caching System

The Query Cache System improves application performance by storing and reusing database query results.

### How It Works

1. When a query with caching enabled is executed, the system first checks if the results for this exact query exist in the cache
2. If found (cache hit), the cached results are returned immediately without executing the query
3. If not found (cache miss), the query executes normally and the results are stored in cache
4. Subsequent identical queries will use the cached results until the cache expires or is invalidated

### Implementation Components

#### QueryBuilder Integration

```php
// Basic usage with default TTL
$users = $queryBuilder
    ->select('users', ['id', 'name', 'email'])
    ->where(['status' => 'active'])
    ->cache()
    ->get();

// With custom TTL (1 hour)
$orders = $queryBuilder
    ->select('orders', ['*'])
    ->where(['status' => 'pending'])
    ->cache(3600)
    ->get();
```

#### Repository Method Caching with Attributes

```php
<?php

namespace App\Repositories;

use Glueful\Database\Attributes\CacheResult;

class ProductRepository
{
    /**
     * Cache the results of this method for 2 hours
     */
    #[CacheResult(ttl: 7200, keyPrefix: 'products', tags: ['products', 'catalog'])]
    public function getFeaturedProducts(): array
    {
        // Method implementation that might include complex database queries
        return $this->queryBuilder
            ->select('products', ['*'])
            ->where(['featured' => true])
            ->orderBy(['popularity' => 'DESC'])
            ->get();
    }
    
    /**
     * Cache results with default TTL (1 hour)
     */
    #[CacheResult]
    public function getProductsByCategory(string $category): array
    {
        // Database query implementation
    }
    
    /**
     * Cache with custom tags for targeted invalidation
     */
    #[CacheResult(ttl: 1800, tags: ['product-counts', 'dashboard-stats'])]
    public function countProductsByStatus(): array
    {
        // Database query implementation
    }
}
```

To use a repository method with the `CacheResult` attribute:

```php
// Inject the QueryCacheService
public function __construct(
    private ProductRepository $repository,
    private QueryCacheService $cacheService
) {}

// Get results using the cache service
public function getProducts(): array
{
    return $this->cacheService->cacheRepositoryMethod(
        $this->repository, 
        'getFeaturedProducts'
    );
}

// With method arguments
public function getProductsByCategory(string $category): array
{
    return $this->cacheService->cacheRepositoryMethod(
        $this->repository, 
        'getProductsByCategory',
        [$category]
    );
}
```

#### CacheResult Attribute Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `ttl` | int | 3600 | Time-to-live in seconds for the cached result |
| `keyPrefix` | string | '' | Custom prefix for the cache key. If empty, uses class and method name |
| `tags` | array | [] | Array of cache tags for targeted invalidation |

### Configuration

```php
// config/database.php
'query_cache' => [
    'enabled' => env('QUERY_CACHE_ENABLED', true),
    'default_ttl' => env('QUERY_CACHE_TTL', 3600),
    'exclude_tables' => ['logs', 'sessions', 'cache'],
    'exclude_patterns' => [
        '/RAND\(\)/i',
        '/NOW\(\)/i',
        '/CURRENT_TIMESTAMP/i'
    ]
]
```

### Usage Examples

#### Basic Caching

```php
// Enable caching with default TTL
$popularProducts = $queryBuilder
    ->select('products', ['*'])
    ->where(['featured' => true])
    ->orderBy(['popularity' => 'DESC'])
    ->limit(10)
    ->cache()
    ->get();
```

#### Custom TTL

```php
// Cache for 5 minutes (300 seconds)
$recentArticles = $queryBuilder
    ->select('articles', ['id', 'title', 'excerpt'])
    ->where(['published' => true])
    ->orderBy(['published_at' => 'DESC'])
    ->limit(5)
    ->cache(300)
    ->get();
```

#### Combining with Optimization

```php
// Both optimize and cache a complex query
$analyticsData = $queryBuilder
    ->select('sales', ['region', 'product_category', $queryBuilder->raw('SUM(amount) as total')])
    ->join('products', 'sales.product_id = products.id')
    ->where(['sales.date' => $queryBuilder->raw('BETWEEN ? AND ?'), ['2025-01-01', '2025-03-31']])
    ->groupBy(['region', 'product_category'])
    ->orderBy(['total' => 'DESC'])
    ->optimize()
    ->cache(1800)  // 30 minutes
    ->get();
```

#### Manually Invalidating Cache

```php
// Invalidate all cached queries related to the 'products' table
$cacheService = new QueryCacheService();
$cacheService->invalidateTable('products');
```

### Best Practices

#### When to Use Query Caching

Query caching is most beneficial for:

1. **Read-heavy operations**: Queries that are read frequently but updated infrequently
2. **Expensive queries**: Complex joins, aggregations, or queries on large tables
3. **Predictable, repeating queries**: Queries that are executed frequently with the same parameters

#### When to Avoid Query Caching

Caching may not be appropriate for:

1. **Rapidly changing data**: Tables with frequent updates
2. **Unique queries**: Queries that are rarely executed with the same parameters
3. **Non-deterministic queries**: Queries with functions like RAND(), NOW(), or UUID()
4. **User-specific sensitive data**: Be cautious with user-specific data and privacy concerns

## Query Analysis Tools

The Query Analyzer provides advanced analysis capabilities for SQL queries, helping developers identify performance issues and optimize database operations.

### Key Features

- **Execution Plan Retrieval**: Fetches and normalizes database execution plans across different engines
- **Performance Issue Detection**: Identifies common SQL anti-patterns and inefficient query structures
- **Optimization Suggestions**: Provides actionable recommendations to improve query performance
- **Index Recommendations**: Suggests indexes that could enhance query execution speed
- **Multi-database Support**: Works with MySQL, PostgreSQL, and SQLite engines

### Usage Examples

#### Basic Usage

```php
// Create a new query analyzer instance
$analyzer = new \Glueful\Database\QueryAnalyzer();

// Analyze a query
$query = "SELECT * FROM users WHERE last_login < '2025-01-01' ORDER BY created_at";
$results = $analyzer->analyzeQuery($query);

// Display analysis results
print_r($results);
```

#### Analysis with Parameters

```php
$query = "SELECT * FROM products WHERE category_id = ? AND price > ?";
$params = [5, 99.99];
$results = $analyzer->analyzeQuery($query, $params);
```

### Execution Plan Analysis

```php
$plan = $results['execution_plan'];

// Example output for MySQL:
// [
//   [
//     'id' => 1,
//     'select_type' => 'SIMPLE',
//     'table' => 'products',
//     'type' => 'ref',
//     'possible_keys' => 'category_id_index',
//     'key' => 'category_id_index',
//     'key_len' => 4,
//     'ref' => 'const',
//     'rows' => 243,
//     'Extra' => 'Using where; Using filesort'
//   ]
// ]
```

### Issue Detection

```php
foreach ($results['potential_issues'] as $issue) {
    echo "Severity: {$issue['severity']}\n";
    echo "Issue: {$issue['message']}\n";
    echo "Details: {$issue['details']}\n\n";
}
```

Common detected issues include:
- Full table scans
- Use of temporary tables
- Filesort operations
- Inefficient LIKE patterns with leading wildcards
- Large IN clauses
- Missing WHERE clauses
- Non-indexed joins

### Optimization Suggestions

```php
foreach ($results['optimization_suggestions'] as $suggestion) {
    echo "Priority: {$suggestion['priority']}\n";
    echo "Suggestion: {$suggestion['suggestion']}\n";
    echo "Details: {$suggestion['details']}\n\n";
}
```

### Index Recommendations

```php
foreach ($results['index_recommendations'] as $recommendation) {
    echo "Table: {$recommendation['table']}\n";
    echo "Columns: " . implode(', ', $recommendation['columns']) . "\n";
    echo "Type: {$recommendation['type']}\n";
    echo "Priority: {$recommendation['priority']}\n";
    echo "Suggestion: {$recommendation['suggestion']}\n\n";
}
```

### Database Compatibility

| Feature | MySQL | PostgreSQL | SQLite |
|---------|-------|------------|--------|
| Execution Plan | ✓ | ✓ | ✓ |
| Issue Detection | ✓ | ✓ | ✓ |
| Optimization Suggestions | ✓ | ✓ | ✓ |
| Index Recommendations | ✓ | ✓ | ✓ |

## Database Profiling Tools

The database profiling tools provide comprehensive query analysis capabilities, enabling developers to measure query execution time, analyze database execution plans, and identify problematic query patterns.

### Components

#### QueryProfilerService

The QueryProfilerService executes and profiles database queries, capturing:

- Execution time
- Memory usage
- Row count
- Query parameters
- Backtrace information
- Execution status

```php
use Glueful\Database\Tools\QueryProfilerService;

$profiler = new QueryProfilerService();

// Profile a query
$results = $profiler->profile(
    "SELECT * FROM users WHERE status = ?",
    ['active'],
    function() use ($db, $status) {
        return $db->select("SELECT * FROM users WHERE status = ?", ['active']);
    }
);

// Get recent profiles
$recentProfiles = $profiler->getRecentProfiles(10, 100); // 10 profiles with min 100ms duration
```

#### ExecutionPlanAnalyzer

```php
use Glueful\Database\Tools\ExecutionPlanAnalyzer;
use Glueful\Database\Connection;

$connection = new Connection();
$analyzer = new ExecutionPlanAnalyzer($connection);

// Get and analyze a query execution plan
$plan = $analyzer->getExecutionPlan(
    "SELECT products.*, categories.name FROM products JOIN categories ON products.category_id = categories.id"
);

$analysis = $analyzer->analyzeExecutionPlan($plan);

// Output recommendations
foreach ($analysis['recommendations'] as $recommendation) {
    echo "- {$recommendation}\n";
}
```

#### QueryPatternRecognizer

```php
use Glueful\Database\Tools\QueryPatternRecognizer;

$recognizer = new QueryPatternRecognizer();

// Add a custom pattern
$recognizer->addPattern(
    'no_limit',
    '/SELECT .+ FROM .+ WHERE .+ ORDER BY .+ (?!LIMIT)/i',
    'Query with ORDER BY but no LIMIT clause',
    'Add a LIMIT clause to avoid sorting entire result sets'
);

// Analyze a query
$patterns = $recognizer->recognizePatterns(
    "SELECT id, name FROM products WHERE stock > 0 ORDER BY price DESC"
);

// Output pattern matches
foreach ($patterns as $name => $info) {
    echo "Pattern: {$name}\n";
    echo "Description: {$info['description']}\n";
    echo "Recommendation: {$info['recommendation']}\n";
}
```

### Query Profile CLI Command

The `db:profile` command provides a convenient CLI interface to profile database queries:

```bash
# Basic query profiling
php glueful db:profile --query="SELECT * FROM users WHERE email LIKE '%example.com'"

# Profile with execution plan
php glueful db:profile --query="SELECT * FROM orders JOIN order_items ON orders.id = order_items.order_id" --explain

# Profile with pattern recognition
php glueful db:profile --query="SELECT * FROM products" --patterns

# Profile from file with JSON output
php glueful db:profile --file=query.sql --explain --patterns --output=json
```

#### Options

| Option | Description |
|--------|-------------|
| `-q, --query=SQL` | SQL query to profile (required unless --file is used) |
| `-f, --file=PATH` | File containing SQL query to profile |
| `-e, --explain` | Show execution plan analysis |
| `-p, --patterns` | Detect query patterns and provide recommendations |
| `-o, --output=FORMAT` | Output format (table, json) (default: table) |

## Query Logger Optimizations

The QueryLogger class has been enhanced with several performance optimizations for high-volume environments.

### Key Optimizations

#### 1. Audit Logging Sampling

```php
// Configure to log only 10% of operations
$queryLogger->configureAuditLogging(true, 0.1);
```

#### 2. Table Name Caching

Lookup results for sensitive and audit tables are cached, eliminating redundant checks.

#### 3. Batch Processing

```php
// Enable batching with a batch size of 10
$queryLogger->configureAuditLogging(true, 1.0, true, 10);

// Manually flush any remaining batched entries when needed
$queryLogger->flushAuditLogBatch();
```

#### 4. Enhanced N+1 Query Detection

```php
// Configure N+1 detection sensitivity
$queryLogger->configureN1Detection(5, 5); // threshold, time window in seconds
```

### Performance Impact

In benchmark tests, these optimizations show significant performance improvements:

- **Table Lookup Caching**: 15-30% faster
- **10% Sampling**: 70-80% faster
- **Batched Processing**: 40-60% faster
- **All Optimizations Combined**: 90-95% faster

### Usage Example

```php
use Glueful\Database\QueryLogger;
use Glueful\Logging\LogManager;

$logger = new LogManager('app_logs');
$queryLogger = new QueryLogger($logger);

// Configure for high-volume environment
$queryLogger->configure(true, true);
$queryLogger->configureAuditLogging(
    true,     // Enable audit logging
    0.1,      // Sample 10% of operations
    true,     // Enable batching
    50        // Process in batches of 50
);

// Use the logger as normal
$queryLogger->logQuery(
    "SELECT * FROM users WHERE id = ?",
    [1],
    $queryLogger->startTiming()
);

// Don't forget to flush any remaining batched entries at the end of the request
register_shutdown_function(function() use ($queryLogger) {
    $queryLogger->flushAuditLogBatch();
});
```

### Performance Metrics

```php
$metrics = $queryLogger->getAuditPerformanceMetrics();
/*
[
    'total_operations' => 1000,
    'logged_operations' => 100,
    'skipped_operations' => 900,
    'total_audit_time' => 150.5,  // milliseconds
    'avg_audit_time' => 1.505     // milliseconds per logged operation
]
*/
```

## Best Practices

### When to Use Performance Optimization

Performance optimization is most beneficial for:

1. **Complex queries** with multiple joins, subqueries, or aggregations
2. **Recurring queries** executed frequently in your application
3. **Performance-critical paths** where response time is crucial
4. **Large dataset operations** where efficiency gains are multiplied

### When Not to Use Performance Optimization

Performance optimization may not be worthwhile for:

1. **Simple queries** that are already efficient
2. **One-time queries** or administrative operations
3. **Queries handling very small datasets** where the overhead isn't justified

### General Guidelines

1. **Start with query analysis** before applying optimizations
2. **Use caching for read-heavy operations** on stable data
3. **Monitor performance metrics** to validate improvements
4. **Apply optimizations systematically** rather than randomly
5. **Test in staging environments** before deploying to production

### Production Considerations

For production environments:

```php
// config/database.php
'profiler' => [
    'enabled' => env('DB_PROFILER_ENABLED', false),
    'threshold' => env('DB_PROFILER_THRESHOLD', 100), // milliseconds
    'sampling_rate' => env('DB_PROFILER_SAMPLING', 0.05), // 5% of queries
    'max_profiles' => env('DB_PROFILER_MAX_PROFILES', 100),
],

'query_cache' => [
    'enabled' => env('QUERY_CACHE_ENABLED', true),
    'default_ttl' => env('QUERY_CACHE_TTL', 3600),
    'exclude_tables' => ['logs', 'sessions', 'cache'],
]
```

## Performance Metrics

### Query Performance Metrics

The optimization features provide detailed performance metrics:

- **High-traffic applications**: 30-50% reduction in database load
- **Complex queries**: 50-95% improvement in response time for cached queries
- **API endpoints**: Consistent response times during peak loads

### Monitoring Optimization Effectiveness

```php
use Glueful\Logging\Logger;

function logQueryOptimization($query, $result)
{
    $logger = new Logger('query-optimization');
    
    $logger->info('Query optimization result', [
        'original_query' => $result['original_query'],
        'optimized_query' => $result['optimized_query'],
        'estimated_improvement' => $result['estimated_improvement'],
        'suggestions_count' => count($result['suggestions'])
    ]);
    
    if ($result['estimated_improvement']['execution_time'] > 30) {
        // Log high-impact optimizations separately
        $logger->notice('High-impact query optimization', [
            'original_query' => $result['original_query'],
            'optimized_query' => $result['optimized_query'],
            'estimated_improvement' => $result['estimated_improvement'],
            'suggestions' => $result['suggestions']
        ]);
    }
}

// Usage
$result = $optimizer->optimizeQuery($query);
logQueryOptimization($query, $result);
```

## Troubleshooting

### Optimization Not Improving Performance

If optimization isn't yielding expected improvements:

1. **Verify database indexes**: The optimizer can suggest indexes but can't create them
2. **Check query complexity**: Some queries may already be optimized
3. **Database configuration**: Server settings may limit optimization benefits
4. **Data volume**: Benefits often increase with data volume

### Incorrect Results After Optimization

If the optimized query returns different results:

1. **Verify query semantics**: Ensure the optimized query maintains the original logic
2. **Check for edge cases**: Some optimizations may not handle all edge cases
3. **Database-specific behaviors**: Different databases may interpret SQL constructs differently

### Performance Regression

If optimization causes performance regression:

1. **Analyze the execution plan**: Compare execution plans of original and optimized queries
2. **Consider database statistics**: Ensure database statistics are up-to-date
3. **Query complexity**: Very complex queries might confuse the optimizer

### Cache Issues

Common cache-related issues:

1. **Cache not invalidating**: Check cache tags and invalidation logic
2. **Memory usage**: Monitor cache size and implement appropriate TTL values
3. **Cache misses**: Verify cache key generation for parameterized queries

### Debugging

When debugging cached queries, enable debug mode:

```php
$results = $queryBuilder
    ->enableDebug(true)
    ->select('products', ['*'])
    ->cache()
    ->get();
```

This will log cache hits, misses, and other cache-related operations to help identify potential issues.

---

This comprehensive guide covers all aspects of database performance optimization in Glueful. For specific implementation details and advanced configuration options, refer to the individual component documentation and source code.