# Query Caching in Glueful

## Overview

The Query Cache System is a powerful feature in Glueful that improves application performance by storing and reusing database query results. Instead of repeatedly executing the same database queries, the system intelligently caches query results and returns them directly when identical queries are executed.

## Benefits

- **Enhanced Performance**: Significant reduction in database load and query execution time
- **Reduced Database Load**: Fewer repeated queries hitting your database servers
- **Intelligent Invalidation**: Automatic cache invalidation when related data changes
- **Query Analysis**: Sophisticated analysis to determine which queries should be cached
- **Transparent Integration**: Easy to use with the existing Query Builder interface

## How It Works

1. When a query with caching enabled is executed, the system first checks if the results for this exact query exist in the cache
2. If found (cache hit), the cached results are returned immediately without executing the query
3. If not found (cache miss), the query executes normally and the results are stored in cache
4. Subsequent identical queries will use the cached results until the cache expires or is invalidated

## Implementation Components

### 1. QueryCacheService

Central service that manages the query caching logic:

- Determines if a query is cacheable
- Generates cache keys based on query structure and parameters
- Stores query results with appropriate tags for invalidation
- Provides invalidation methods based on table names

### 2. QueryBuilder Integration

The QueryBuilder provides a simple chainable `cache()` method to enable caching for any query:

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

### 3. CacheEngine

Underlying cache storage mechanism with support for:

- Multiple storage backends (Redis, Memcached, file-based)
- Tag-based cache invalidation
- Automatic serialization/deserialization
- Configurable TTL (time-to-live)

## Configuration

The cache system can be configured in `config/database.php`:

```php
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

## Best Practices

### When to Use Query Caching

Query caching is most beneficial for:

1. **Read-heavy operations**: Queries that are read frequently but updated infrequently
2. **Expensive queries**: Complex joins, aggregations, or queries on large tables
3. **Predictable, repeating queries**: Queries that are executed frequently with the same parameters

### When to Avoid Query Caching

Caching may not be appropriate for:

1. **Rapidly changing data**: Tables with frequent updates
2. **Unique queries**: Queries that are rarely executed with the same parameters
3. **Non-deterministic queries**: Queries with functions like RAND(), NOW(), or UUID()
4. **User-specific sensitive data**: Be cautious with user-specific data and privacy concerns

## Usage Examples

### Basic Caching

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

### Custom TTL

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

### Combining with Optimization

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

### Explicitly Disabling Cache

```php
// Ensure fresh data for a specific query
$currentUserData = $queryBuilder
    ->select('users', ['*'])
    ->where(['id' => $userId])
    ->disableCache()
    ->get();
```

## Manually Invalidating Cache

When you make changes to data that might affect cached queries, you can manually invalidate the cache:

```php
// Invalidate all cached queries related to the 'products' table
$cacheService = new QueryCacheService();
$cacheService->invalidateTable('products');
```

## Additional Features

### Query Analysis

The caching system analyzes queries to determine:

- If a query is eligible for caching
- Which tables are referenced by a query for tag-based invalidation
- If a query contains non-deterministic functions that should prevent caching

### Cache Tags

Cached queries are automatically tagged with:

- The tables they reference (e.g., `query_cache:table:users`)
- A general cache tag (`query_cache:all`)

This allows for targeted cache invalidation when specific tables are updated.

## Performance Impact

The query caching system can significantly improve application performance:

- **High-traffic applications**: 30-50% reduction in database load
- **Complex queries**: 50-95% improvement in response time for cached queries
- **API endpoints**: Consistent response times during peak loads

## Debugging

When debugging cached queries, you can enable debug mode on the QueryBuilder:

```php
$results = $queryBuilder
    ->enableDebug(true)
    ->select('products', ['*'])
    ->cache()
    ->get();
```

This will log cache hits, misses, and other cache-related operations to help identify potential issues.

## Conclusion

The Query Caching system provides a powerful yet simple way to improve database performance. By strategically caching appropriate queries, you can significantly reduce database load and improve application response times with minimal code changes.
