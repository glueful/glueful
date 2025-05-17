# Query Optimizer

The Query Optimizer is a powerful component of the Glueful Database system that analyzes SQL queries and implements optimizations for different database engines.

## Overview

The `QueryOptimizer` class provides advanced query optimization capabilities with database-specific enhancements for MySQL, PostgreSQL, and SQLite. It works alongside the `QueryAnalyzer` to identify potential performance issues and automatically apply optimizations.

## Features

- Database-specific optimizations for MySQL, PostgreSQL, and SQLite
- Automatic detection of inefficient query patterns
- Performance improvement estimation
- Detailed suggestions for manual query optimization
- Specialized optimization for JOINs, WHERE clauses, GROUP BY, and ORDER BY operations

## Usage

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

### Accessing Optimization Results

```php
// Get the optimized query
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

## Integration with Query Builder

The Query Optimizer can be integrated with the Glueful Query Builder to automatically optimize complex queries:

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

## Database-Specific Optimizations

The Query Optimizer implements specialized optimization techniques for each supported database engine:

### MySQL Optimizations

- Use of `STRAIGHT_JOIN` hint for complex joins when beneficial
- Reordering of JOIN clauses for better execution
- Optimization of WHERE clauses to leverage indexes
- Addition of `WITH ROLLUP` for appropriate aggregate queries
- Optimizing ORDER BY to minimize filesort operations

### PostgreSQL Optimizations

- JOIN type optimizations based on data volume
- Index usage recommendations
- Multi-dimensional aggregation optimizations with CUBE and ROLLUP
- Optimized CTE (Common Table Expression) handling

### SQLite Optimizations

- Optimized JOIN ordering
- Simplification of complex queries where possible
- Column order optimizations in WHERE clauses

## How It Works

1. The `QueryOptimizer` receives a query and optional parameters
2. It delegates to `QueryAnalyzer` to analyze the query and identify issues
3. Based on the analysis and the detected database driver, it applies specific optimizations
4. It generates improvement metrics and optimization suggestions
5. It returns the original query, optimized query, suggestions, and metrics

## Configuration

The Query Optimizer requires no additional configuration beyond a valid database connection. It automatically detects the database driver and applies appropriate optimizations.

## Performance Impact

- **Complex queries**: 20-40% performance improvement
- **Join-heavy queries**: Up to 50% improvement for inefficient joins
- **Aggregation queries**: 15-30% improvement for GROUP BY operations

## Requirements

- PHP 8.0 or higher
- Glueful Database Connection
- PDO extension with appropriate database driver

## Limitations

- Some optimizations may only be available for specific database versions
- The optimizer favors safe optimizations that maintain query semantics
- Dynamic SQL or extremely complex queries may see limited optimization

## See Also

- [Query Analyzer](query-analyzer.md)
- [QueryBuilder](query-builder.md)
- [Database Connection](database-connection.md)
