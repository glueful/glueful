# Query Optimizer Usage Guide

This guide provides practical examples and best practices for using the Query Optimizer in the Glueful framework.

## Getting Started

The Query Optimizer is designed to enhance database performance by analyzing and optimizing SQL queries. It's particularly useful for complex queries that might not be optimally structured.

### Basic Optimization

```php
use Glueful\Database\Connection;
use Glueful\Database\QueryOptimizer;

// Get a database connection
$connection = new Connection();

// Create the query optimizer
$optimizer = new QueryOptimizer($connection);

// A query that might benefit from optimization
$query = "
    SELECT 
        users.*, 
        orders.id as order_id, 
        products.name as product_name
    FROM users
    JOIN orders ON users.id = orders.user_id
    JOIN order_items ON orders.id = order_items.order_id
    JOIN products ON order_items.product_id = products.id
    WHERE users.status = 'active'
    AND orders.created_at > '2025-01-01'
    ORDER BY orders.created_at DESC
";

// Optimize the query
$result = $optimizer->optimizeQuery($query);

// Use the optimized query
$optimizedQuery = $result['optimized_query'];
```

### Working with Query Parameters

For prepared statements with parameters:

```php
$query = "
    SELECT * FROM products
    WHERE category_id = ?
    AND price > ?
    ORDER BY price ASC
";

$params = [5, 100];

$result = $optimizer->optimizeQuery($query, $params);
```

## Understanding Optimization Results

The `optimizeQuery()` method returns an array with several key components:

```php
$result = [
    'original_query' => '...',     // The original query string
    'optimized_query' => '...',    // The optimized version of the query
    'suggestions' => [...],        // Array of optimization suggestions
    'estimated_improvement' => [   // Performance improvement estimates
        'execution_time' => 25,    // Percentage improvement in execution time
        'resource_usage' => 30,    // Percentage improvement in resource usage
        'confidence' => 'medium'   // Confidence level of the estimation
    ]
];
```

### Interpreting Suggestions

The `suggestions` array contains actionable recommendations:

```php
foreach ($result['suggestions'] as $suggestion) {
    echo "Type: {$suggestion['type']}\n";
    echo "Description: {$suggestion['description']}\n";
    echo "Solution: {$suggestion['solution']}\n";
    echo "Impact: {$suggestion['impact']}\n\n";
}
```

Sample output:
```
Type: missing_index
Description: No index found for column 'created_at' in WHERE clause
Solution: Add an index to the referenced column
Impact: high

Type: inefficient_join
Description: Tables joined in suboptimal order, causing full table scan
Solution: Reorder joins to start with the table having the most restrictive conditions
Impact: medium
```

## Integration with Query Builder

The Query Optimizer can be integrated with Glueful's Query Builder for seamless optimization:

```php
use Glueful\Database\QueryBuilder;

// Create a query builder instance
$qb = new QueryBuilder($connection);

// Build a query
$qb->select('users.*', 'orders.total')
   ->from('users')
   ->join('orders', 'users.id', '=', 'orders.user_id')
   ->where('users.status', '=', 'active')
   ->orderBy('orders.total', 'DESC');

// Enable optimization
$qb->optimize();

// Get results with an optimized query
$results = $qb->get();
```

### Conditional Optimization

You can apply optimization conditionally based on query complexity:

```php
$qb = new QueryBuilder($connection);

// Complex query with multiple joins
$qb->select('products.*', 'categories.name', 'suppliers.name')
   ->from('products')
   ->join('categories', 'products.category_id', '=', 'categories.id')
   ->join('suppliers', 'products.supplier_id', '=', 'suppliers.id')
   ->where('products.price', '>', 100)
   ->groupBy('categories.id')
   ->having('COUNT(*)', '>', 5);

// Only optimize if the query has multiple joins or aggregations
if ($qb->hasMultipleJoins() || $qb->hasAggregation()) {
    $qb->optimize();
}

$results = $qb->get();
```

## Database-Specific Examples

### MySQL Optimization

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

### PostgreSQL Optimization

```php
// PostgreSQL-specific query
$query = "
    SELECT 
        products.name,
        products.price,
        categories.name as category
    FROM products
    JOIN categories ON products.category_id = categories.id
    WHERE products.price > 100
    ORDER BY products.price DESC
";

$result = $optimizer->optimizeQuery($query);

// PostgreSQL might optimize with:
// 1. Join order optimization
// 2. Index scan optimizations
// 3. ORDER BY improvements
```

### SQLite Optimization

```php
// SQLite-specific query
$query = "
    SELECT 
        users.name,
        COUNT(posts.id) as post_count
    FROM users
    LEFT JOIN posts ON users.id = posts.user_id
    GROUP BY users.id
    HAVING post_count > 5
";

$result = $optimizer->optimizeQuery($query);

// SQLite might optimize with:
// 1. Simplified join strategies
// 2. Optimized GROUP BY processing
```

## Best Practices

### When to Use Query Optimization

Query optimization is most beneficial for:

1. **Complex queries** with multiple joins, subqueries, or aggregations
2. **Recurring queries** executed frequently in your application
3. **Performance-critical paths** where response time is crucial
4. **Large dataset operations** where efficiency gains are multiplied

### When Not to Use Query Optimization

Query optimization may not be worthwhile for:

1. **Simple queries** that are already efficient
2. **One-time queries** or administrative operations
3. **Queries handling very small datasets** where the overhead isn't justified

### Monitoring Optimization Effectiveness

To evaluate the effectiveness of query optimization:

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

## Advanced Usage

### Custom Optimization Thresholds

You can set custom thresholds for when optimizations should be applied:

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

### Logging Optimization Results

For debugging and performance monitoring:

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

## Conclusion

The Query Optimizer provides a powerful way to improve database performance with minimal effort. By understanding when and how to apply optimization, you can significantly enhance your application's performance, especially for complex queries and large datasets.

For more technical details about the Query Optimizer's implementation, refer to the [Technical Documentation](query-optimizer-technical.md).
