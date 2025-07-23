# Glueful Performance Optimization Guide

This comprehensive guide covers all performance optimization features in Glueful, including response optimization, database optimization, caching, profiling, and analysis tools.

## Table of Contents

1. [Overview](#overview)
2. [Response Performance Optimization](#response-performance-optimization)
3. [Query Optimization](#query-optimization)
4. [Query Caching System](#query-caching-system)
5. [Query Analysis Tools](#query-analysis-tools)
6. [Database Profiling Tools](#database-profiling-tools)
7. [Query Logger Optimizations](#query-logger-optimizations)
8. [Session Analytics Optimization](#session-analytics-optimization)
9. [API Metrics System Performance](#api-metrics-system-performance)
10. [Response Caching Strategies](#response-caching-strategies)
11. [Memory Management Features](#memory-management-features)
12. [Best Practices](#best-practices)
13. [Performance Metrics](#performance-metrics)
14. [Troubleshooting](#troubleshooting)

## Overview

Glueful provides a comprehensive suite of performance optimization tools designed to maximize application performance across all layers:

### Response Performance
- **40,000+ operations per second** for Response API
- **25μs average response time** for response generation
- **Zero memory overhead** compared to direct JsonResponse usage
- **HTTP caching** with proper headers and ETag validation
- **Application-level caching** for expensive operations

### Database Performance
- **Automatically optimize queries** for different database engines (MySQL, PostgreSQL, SQLite)
- **Cache query results** intelligently with automatic invalidation
- **Analyze and profile** database operations for bottlenecks
- **Detect performance issues** and provide actionable recommendations
- **Monitor query patterns** and identify N+1 problems

### System Performance
- **Session analytics optimization** with intelligent caching
- **API metrics** with asynchronous recording and batch processing
- **Memory management** with monitoring, pooling, and efficient processing
- **Response caching strategies** with multiple layers and invalidation

### Performance Improvements

The optimization features provide significant performance gains:

- **Response generation**: 40,000+ operations per second
- **Complex queries**: 20-40% performance improvement
- **Join-heavy queries**: Up to 50% improvement for inefficient joins
- **Cached queries**: 50-95% improvement for frequently accessed data
- **High-traffic applications**: 30-50% reduction in database load
- **Memory efficiency**: Up to 98% reduction in memory usage for large datasets

## Response Performance Optimization

The Glueful Response API provides excellent out-of-the-box performance with 40,000+ operations per second and 25μs average response time.

### 📊 When Additional Optimization is Needed

Most applications will never need optimization beyond the standard Response class. Consider additional caching only when:

- **Serving > 50,000 requests per minute**
- **Response generation becomes a bottleneck** (profiling shows high CPU usage)
- **Identical responses generated repeatedly** (e.g., configuration endpoints)

### 🎯 Recommended Optimization Strategies

#### 1. HTTP Caching (Recommended)

Use proper HTTP caching headers instead of application-level caching:

```php
// Add caching headers to responses
public function getConfiguration(): Response
{
    $config = $this->configService->getPublicConfig();
    
    return Response::success($config, 'Configuration retrieved')
        ->setMaxAge(3600)           // Cache for 1 hour
        ->setPublic()               // Allow CDN/proxy caching
        ->setEtag(md5(serialize($config))); // Enable conditional requests
}

// For user-specific data
public function getUserProfile(int $userId): Response
{
    $profile = $this->userService->getProfile($userId);
    
    return Response::success($profile, 'Profile retrieved')
        ->setMaxAge(300)            // 5 minutes
        ->setPrivate()              // Don't cache in shared caches
        ->setLastModified($profile->updated_at);
}
```

#### 2. Application-Level Caching

Cache expensive operations, not responses:

```php
class UserService
{
    public function getProfile(int $userId): array
    {
        return cache()->remember("user_profile:$userId", 600, function() use ($userId) {
            return $this->repository->getUserWithPermissions($userId);
        });
    }
}

// Controller stays clean
public function show(int $userId): Response
{
    $profile = $this->userService->getProfile($userId);
    return Response::success($profile, 'Profile retrieved');
}
```

#### 3. Middleware-Based Response Caching

For repeated identical responses:

```php
class ResponseCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheStore $cache,
        private array $cacheableRoutes = []
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$this->shouldCache($request)) {
            return $handler->handle($request);
        }

        $cacheKey = $this->generateCacheKey($request);
        
        // Try to get from cache
        if ($cached = $this->cache->get($cacheKey)) {
            return unserialize($cached);
        }

        // Generate response
        $response = $handler->handle($request);

        // Cache successful responses
        if ($response->getStatusCode() === 200) {
            $this->cache->set($cacheKey, serialize($response), 300);
        }

        return $response;
    }

    private function shouldCache(Request $request): bool
    {
        return $request->getMethod() === 'GET' && 
               in_array($request->getPathInfo(), $this->cacheableRoutes);
    }
}
```

#### 4. Reverse Proxy Caching

Use Nginx, Varnish, or CDN for maximum performance:

```nginx
# Nginx configuration
location /api/config {
    proxy_pass http://backend;
    proxy_cache api_cache;
    proxy_cache_valid 200 1h;
    proxy_cache_key "$request_uri";
    add_header X-Cache-Status $upstream_cache_status;
}
```

### 🔧 Implementation Examples

#### HTTP Cache Helper

Add to BaseController for easy HTTP caching:

```php
abstract class BaseController
{
    protected function cached(Response $response, int $maxAge = 300, bool $public = false): Response
    {
        $response->setMaxAge($maxAge);
        
        if ($public) {
            $response->setPublic();
        } else {
            $response->setPrivate();
        }
        
        // Add ETag for conditional requests
        $response->setEtag(md5($response->getContent()));
        
        return $response;
    }
    
    protected function notModified(): Response
    {
        return new Response('', 304);
    }
}

// Usage
class ConfigController extends BaseController
{
    public function show(): Response
    {
        $config = $this->configService->getPublicConfig();
        
        return $this->cached(
            Response::success($config, 'Configuration retrieved'),
            3600,  // 1 hour
            true   // public caching
        );
    }
}
```

#### Smart Caching Service

For application-level caching with tags:

```php
class SmartCache
{
    public function __construct(private CacheStore $cache) {}
    
    public function rememberResponse(string $key, int $ttl, callable $callback, array $tags = []): Response
    {
        $cached = $this->cache->get($key);
        
        if ($cached) {
            return unserialize($cached);
        }
        
        $response = $callback();
        
        if ($response instanceof Response && $response->getStatusCode() === 200) {
            $this->cache->set($key, serialize($response), $ttl);
            
            // Tag the cache entry for easy invalidation
            foreach ($tags as $tag) {
                $this->cache->tag($tag, $key);
            }
        }
        
        return $response;
    }
    
    public function invalidateTag(string $tag): void
    {
        $keys = $this->cache->getTaggedKeys($tag);
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
    }
}

// Usage
class UserController extends BaseController
{
    public function show(int $userId): Response
    {
        return $this->smartCache->rememberResponse(
            "user_profile:$userId",
            600,
            fn() => Response::success(
                $this->userService->getProfile($userId),
                'Profile retrieved'
            ),
            ["user:$userId", 'user_profiles']
        );
    }
}
```

### 📈 Performance Monitoring

#### Track Response Performance

```php
class ResponsePerformanceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $duration = (microtime(true) - $start) * 1000;
        
        // Add performance headers in development
        if (app()->isDebug()) {
            $response->headers->set('X-Response-Time', $duration . 'ms');
            $response->headers->set('X-Memory-Usage', memory_get_usage(true));
        }
        
        // Log slow responses
        if ($duration > 100) { // 100ms threshold
            logger()->warning('Slow response detected', [
                'url' => $request->getUri(),
                'duration' => $duration,
                'memory' => memory_get_usage(true)
            ]);
        }
        
        return $response;
    }
}
```

#### Cache Hit Rate Monitoring

```php
class CacheMetrics
{
    private static int $hits = 0;
    private static int $misses = 0;
    
    public static function hit(): void { self::$hits++; }
    public static function miss(): void { self::$misses++; }
    
    public static function getStats(): array
    {
        $total = self::$hits + self::$misses;
        return [
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => $total > 0 ? (self::$hits / $total) * 100 : 0
        ];
    }
}
```

### 🎯 Performance Targets

#### Benchmarks for Different Application Types

**Small Applications (< 1K requests/min)**
- Standard Response API: ✅ Sufficient
- Additional optimizations: ❌ Not needed

**Medium Applications (1K-10K requests/min)**  
- HTTP caching: ✅ Recommended
- Application caching: ✅ For expensive operations
- Response caching: ⚠️ Only if needed

**Large Applications (> 10K requests/min)**
- All above optimizations: ✅ Required
- Reverse proxy caching: ✅ Essential  
- CDN integration: ✅ Recommended

### 🔍 Response Optimization Best Practices

#### 1. Measure First, Optimize Second

```php
// Use profiling to identify bottlenecks
$profiler = app()->get(ProfilerInterface::class);
$profiler->start('user_profile_generation');

$profile = $this->userService->getProfile($userId);

$profiler->end('user_profile_generation');
```

#### 2. Cache Invalidation Strategy

```php
class UserService
{
    public function updateProfile(int $userId, array $data): User
    {
        $user = $this->repository->update($userId, $data);
        
        // Clear related caches
        cache()->forget("user_profile:$userId");
        cache()->invalidateTag("user:$userId");
        
        return $user;
    }
}
```

#### 3. Gradual Optimization

```php
// Start with simple HTTP caching
return Response::success($data)->setMaxAge(300);

// Add application caching if needed
$data = cache()->remember($key, 300, $callback);

// Add response caching only for high-traffic endpoints
// (via middleware or custom implementation)
```

### ✅ Response Performance Summary

The standard Glueful Response API provides excellent performance (40K+ ops/sec) for the vast majority of applications. When additional performance is needed:

1. **Start with HTTP caching** - proper, standards-compliant, works with CDNs
2. **Add application-level caching** - cache expensive operations, not responses  
3. **Use reverse proxy caching** - for maximum performance at scale
4. **Implement response caching selectively** - only for specific high-traffic endpoints

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

## Session Analytics Optimization

The SessionAnalytics class provides comprehensive session tracking with performance optimizations for high-traffic applications.

### Key Features

- **Cache-optimized analytics**: Intelligent caching with configurable TTL
- **Geographic distribution analysis**: Efficient country/region tracking
- **Device and browser analytics**: Performance-optimized user agent parsing
- **Security event tracking**: Real-time suspicious activity detection
- **Memory-efficient filtering**: Optimized session aggregation and filtering

### Basic Usage

```php
use Glueful\Auth\SessionAnalytics;

// Initialize analytics service
$analytics = container()->get(SessionAnalytics::class);

// Get performance-optimized session metrics
$metrics = $analytics->getSessionMetrics($userUuid);
/*
[
    'total_sessions' => 45,
    'active_sessions' => 3,
    'average_duration' => 1847.5,
    'geographic_distribution' => [
        'US' => 25,
        'UK' => 12,
        'CA' => 8
    ],
    'device_breakdown' => [
        'desktop' => 32,
        'mobile' => 13
    ]
]
*/
```

### Advanced Analytics Features

#### Session Behavior Analysis

```php
// Analyze session patterns with caching
$patterns = $analytics->analyzeSessionPatterns($userUuid);
/*
[
    'login_frequency' => [
        'daily_avg' => 2.3,
        'peak_hours' => [9, 14, 20],
        'peak_days' => ['monday', 'wednesday']
    ],
    'session_duration_trends' => [
        'avg_duration' => 1847,
        'trend' => 'increasing',
        'variance' => 234.5
    ],
    'geographic_patterns' => [
        'primary_locations' => ['New York', 'London'],
        'travel_detected' => false
    ]
]
*/
```

#### Security Risk Assessment

```php
// Calculate security risk score efficiently
$riskScore = $analytics->calculateRiskScore($sessionId);
/*
[
    'overall_score' => 85, // 0-100 scale
    'risk_factors' => [
        'unusual_location' => false,
        'suspicious_timing' => false,
        'device_mismatch' => true,
        'multiple_concurrent' => false
    ],
    'recommendations' => [
        'Verify new device authentication',
        'Monitor for concurrent sessions'
    ]
]
*/
```

### Configuration for High Performance

```php
// config/session.php
'analytics' => [
    'enabled' => true,
    'cache_ttl' => 300, // 5 minutes for session metrics
    'geographic_cache_ttl' => 3600, // 1 hour for geographic data
    'bulk_processing_enabled' => true,
    'max_concurrent_analysis' => 10,
    'memory_limit_per_analysis' => '64M'
]
```

## API Metrics System Performance

The ApiMetricsService provides comprehensive API performance monitoring with optimization features for production environments.

### Key Features

- **Asynchronous metric recording**: Non-blocking metric collection
- **Batch processing**: Configurable batch sizes for optimal database performance
- **Daily aggregation**: Automatic data compression for long-term storage
- **Rate limiting integration**: Performance monitoring with circuit breakers
- **Memory-efficient processing**: Chunked data processing for large datasets

### Basic Usage

```php
use Glueful\Services\ApiMetricsService;

// Initialize metrics service
$metrics = container()->get(ApiMetricsService::class);

// Record API call (asynchronous)
$metrics->recordApiCall($endpoint, $method, $responseTime, $statusCode, [
    'user_id' => $userId,
    'ip_address' => $clientIp,
    'user_agent' => $userAgent
]);

// Get performance metrics
$performanceData = $metrics->getEndpointPerformance($endpoint, [
    'time_range' => '24h',
    'include_percentiles' => true
]);
```

### Performance Optimization Features

#### Asynchronous Processing

```php
// Configure async processing for high-traffic applications
$metrics->configureAsyncProcessing([
    'enabled' => true,
    'batch_size' => 100,
    'flush_interval' => 30, // seconds
    'max_memory_usage' => '128M'
]);

// Record metrics without blocking the main request
$metrics->recordApiCallAsync($endpoint, $method, $responseTime, $statusCode);
```

#### Daily Aggregation

```php
// Automatic daily aggregation reduces storage and improves query performance
$dailyStats = $metrics->getDailyAggregatedStats($date);
/*
[
    'total_requests' => 15420,
    'avg_response_time' => 245.7,
    'error_rate' => 2.3,
    'top_endpoints' => [
        '/api/users' => 3245,
        '/api/orders' => 2156
    ],
    'performance_percentiles' => [
        'p50' => 198.2,
        'p95' => 567.8,
        'p99' => 1234.5
    ]
]
*/
```

### Configuration for Production

```php
// config/api_metrics.php
'performance' => [
    'async_enabled' => true,
    'batch_processing' => true,
    'batch_size' => 500,
    'flush_interval' => 30,
    'daily_aggregation' => true,
    'retention_days' => 90,
    'memory_limit' => '256M',
    'max_concurrent_processing' => 5
]
```

## Response Caching Strategies

The ResponseCachingTrait provides multiple caching strategies optimized for different use cases and performance requirements.

### Key Features

- **Multiple caching strategies**: Response, query, fragment, and edge caching
- **Permission-aware caching**: Different TTL for user types and roles
- **ETag validation**: Efficient cache revalidation with conditional requests
- **CDN integration**: Edge cache headers for maximum performance
- **Tag-based invalidation**: Intelligent cache invalidation
- **Performance tracking**: Cache hit/miss metrics and optimization insights

### Basic Usage

```php
use Glueful\Controllers\Traits\ResponseCachingTrait;

class ProductController extends BaseController
{
    use ResponseCachingTrait;
    
    public function index(): Response
    {
        return $this->cacheResponse('products.index', 3600, function() {
            $products = $this->productService->getAllProducts();
            return Response::success($products, 'Products retrieved');
        });
    }
}
```

### Advanced Caching Strategies

#### Permission-Aware Caching

```php
// Different cache TTL based on user permissions
public function getProducts(): Response
{
    $cacheKey = $this->getPermissionAwareCacheKey('products', auth()->user());
    $ttl = auth()->user()->hasRole('admin') ? 1800 : 3600; // Shorter cache for admins
    
    return $this->cacheResponse($cacheKey, $ttl, function() {
        return $this->productService->getProductsForUser(auth()->user());
    });
}
```

#### CDN Edge Caching

```php
// Optimize for CDN edge caching
public function getPublicContent(): Response
{
    return $this->cacheForCDN('public.content', 7200, function() {
        return $this->contentService->getPublicContent();
    }, [
        'vary_headers' => ['Accept-Language'],
        'edge_ttl' => 3600,
        'browser_ttl' => 1800
    ]);
}
```

### Cache Invalidation Strategies

```php
// Cache with tags for intelligent invalidation
public function getOrderSummary(int $orderId): Response
{
    return $this->cacheWithTags(
        "order.summary.{$orderId}", 
        1800,
        ['orders', "order.{$orderId}", "user." . auth()->id()],
        function() use ($orderId) {
            return $this->orderService->getOrderSummary($orderId);
        }
    );
}
```

### Configuration

```php
// config/cache.php
'response_caching' => [
    'enabled' => true,
    'default_ttl' => 3600,
    'permission_aware' => true,
    'cdn_integration' => true,
    'etag_validation' => true,
    'performance_tracking' => true
]
```

## Memory Management Features

Glueful includes comprehensive memory management features to optimize performance and prevent memory issues in production environments.

### MemoryManager

The MemoryManager class provides real-time memory monitoring and management capabilities.

#### Basic Usage

```php
use Glueful\Performance\MemoryManager;

$memoryManager = new MemoryManager();

// Get current memory usage
$usage = $memoryManager->getCurrentUsage();
/*
[
    'current' => '64MB',
    'peak' => '89MB',
    'limit' => '256MB',
    'percentage' => 25.0
]
*/

// Check memory thresholds
if ($memoryManager->isMemoryWarning()) {
    // Implement memory cleanup strategies
    $this->performMemoryCleanup();
}

if ($memoryManager->isMemoryCritical()) {
    // Emergency memory management
    $this->emergencyMemoryCleanup();
}
```

### MemoryPool

The MemoryPool class provides efficient object pooling to reduce memory allocation overhead.

```php
use Glueful\Performance\MemoryPool;

$pool = new MemoryPool();

// Acquire and release resources
$resource = $pool->acquire('database_connections');
try {
    // Use the resource
    $results = $resource->query($sql);
} finally {
    $pool->release('database_connections', $resource);
}
```

### ChunkedDatabaseProcessor

Process large datasets efficiently with minimal memory usage.

```php
use Glueful\Performance\ChunkedDatabaseProcessor;

$processor = new ChunkedDatabaseProcessor($connection, 1000);

// Process large result sets in chunks
$totalProcessed = $processor->processSelectQuery(
    "SELECT * FROM users WHERE status = ? AND created_at > ?",
    function($rows) {
        foreach ($rows as $row) {
            $this->processUser($row);
        }
        return count($rows);
    },
    ['active', '2024-01-01'],
    500 // chunk size
);
```

### Configuration

```php
// config/performance.php
'memory_management' => [
    'monitoring_enabled' => true,
    'warning_threshold' => '128M',
    'critical_threshold' => '200M',
    'auto_cleanup_enabled' => true,
    'pool_size_limits' => [
        'default' => 100,
        'database_connections' => 50,
        'api_clients' => 25
    ],
    'chunked_processing' => [
        'default_chunk_size' => 1000,
        'max_chunk_size' => 10000,
        'memory_limit' => '256M'
    ]
]
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