# Using the Query Optimizer (Example)

The Glueful Query Optimizer is designed to work seamlessly with the Query Builder through a chainable method pattern. This document demonstrates how to use the Query Optimizer in your projects.

## Basic Usage

The most common way to use the Query Optimizer is by calling the `optimize()` method in your query chain:

```php
use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;

// Initialize connection and query builder
$connection = new Connection();
$pdo = $connection->getPDO();
$driver = $connection->getDriver();
$queryBuilder = new QueryBuilder($pdo, $driver);

// Build and execute an optimized query
$users = $queryBuilder
    ->select('users', ['users.*', 'orders.id as order_id'])
    ->join('orders', 'users.id = orders.user_id', 'LEFT')
    ->where(['users.status' => 'active'])
    ->optimize() // This enables optimization and returns the builder for chaining
    ->get();
```

## Configuration Options

You can configure the optimization behavior:

```php
// Enable optimization with custom threshold
$queryBuilder
    ->enableOptimization(true)  // Explicitly enable optimization
    ->setOptimizationThreshold(5.0)  // Only apply optimizations with at least 5% improvement
    ->select('products', ['*'])
    ->where(['category_id' => 3])
    ->get();
```

## Advanced Usage

For more detailed analysis, you can use the Query Optimizer directly:

```php
// Access the optimizer directly
$optimizer = $queryBuilder->getOptimizer();

// Analyze a custom query
$analysis = $optimizer->optimizeQuery(
    "SELECT * FROM orders JOIN order_items ON orders.id = order_items.order_id WHERE orders.status = 'processing'"
);

// Display optimization suggestions
foreach ($analysis['suggestions'] as $suggestion) {
    echo "- {$suggestion['description']}: {$suggestion['solution']}\n";
}

// Use the optimized query
$optimizedQuery = $analysis['optimized_query'];
```

## Best Practices

1. Use the `optimize()` method in your query chain when working with complex queries
2. Consider lowering the optimization threshold during development to see more optimizations
3. Use the debug mode to log applied optimizations
4. For repeated queries in a loop, optimize them outside the loop

```php
// Enable debug mode to see optimization details
$queryBuilder->enableDebug(true);

// Optimize a complex query
$complexQuery = $queryBuilder
    ->select('products', ['products.*', 'categories.name as category_name'])
    ->join('categories', 'products.category_id = categories.id')
    ->join('inventory', 'products.id = inventory.product_id')
    ->where(['inventory.stock' => 0])
    ->optimize()
    ->get();
```
