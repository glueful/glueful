# Query Optimizer Documentation

Welcome to the documentation for the Glueful Query Optimizer, a powerful tool for improving database query performance across multiple database engines.

## Contents

- [Overview](#overview)
- [Documentation](#documentation)
- [Examples](#examples)
- [Related Components](#related-components)

## Overview

The Query Optimizer analyzes SQL queries and applies database-specific optimizations to improve performance. It works with MySQL, PostgreSQL, and SQLite databases, automatically detecting the appropriate optimizations for each engine.

Key features include:
- Database driver-specific optimizations
- Performance improvement estimation
- Optimization suggestions
- Integration with the Query Builder

## Documentation

- [Query Optimizer User Guide](query-optimizer-usage-guide.md) - Practical guide to using the Query Optimizer
- [Technical Documentation](query-optimizer-technical.md) - Detailed technical information about the implementation
- [API Reference](#api-reference) - Reference documentation for the QueryOptimizer class

## Examples

### Basic Optimization

```php
use Glueful\Database\Connection;
use Glueful\Database\QueryOptimizer;

$connection = new Connection();
$optimizer = new QueryOptimizer($connection);

$query = "SELECT * FROM users JOIN orders ON users.id = orders.user_id WHERE users.status = 'active'";
$result = $optimizer->optimizeQuery($query);

// Use the optimized query
$optimizedQuery = $result['optimized_query'];
```

### With Query Builder

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

## API Reference

### QueryOptimizer Class

#### Constructor

```php
public function __construct(Connection $connection)
```

Creates a new QueryOptimizer instance with the specified database connection.

#### Methods

```php
public function optimizeQuery(string $query, array $params = []): array
```

Analyzes and optimizes the given SQL query.

**Parameters:**
- `$query` (string): The SQL query to optimize
- `$params` (array, optional): Parameters for prepared statements

**Returns:**
- array: Optimization results containing:
  - `original_query`: The original query string
  - `optimized_query`: The optimized version of the query
  - `suggestions`: Array of optimization suggestions
  - `estimated_improvement`: Performance improvement metrics

## Related Components

- [Query Analyzer](query-analyzer.md) - Analyzes SQL queries for potential performance issues
- [Query Builder](query-builder.md) - Builds SQL queries with a fluent interface
- [Database Connection](database-connection.md) - Manages database connections and drivers
