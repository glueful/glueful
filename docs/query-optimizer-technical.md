# Query Optimizer Technical Documentation

This document provides detailed technical information about the implementation and internal workings of the `QueryOptimizer` class in the Glueful database system.

## Architecture

The `QueryOptimizer` is designed using the Strategy pattern to provide database-specific optimizations while maintaining a clean interface. It works in conjunction with the `QueryAnalyzer` to identify potential performance issues and apply appropriate optimizations.

```
┌─────────────────┐      ┌──────────────────┐      ┌───────────────────┐
│                 │      │                  │      │                   │
│  QueryBuilder   │─────▶│  QueryOptimizer  │─────▶│  QueryAnalyzer    │
│                 │      │                  │      │                   │
└─────────────────┘      └──────────────────┘      └───────────────────┘
                                 │
                                 │
                                 ▼
                         ┌────────────────┐
                         │                │
                         │  Connection    │
                         │                │
                         └────────────────┘
                                 │
                                 │
                        ┌────────┴─────────┐
                        │                  │
                        │  DatabaseDriver  │
                        │                  │
                        └──────────────────┘
```

## Class Structure

```php
namespace Glueful\Database;

class QueryOptimizer
{
    private $connection;           // Database connection
    private $queryAnalyzer;        // Query analyzer for analysis
    private $driverName;           // Current database driver name
    private $driver;               // DatabaseDriver instance
    
    public function __construct(Connection $connection) {...}
    
    // Public API
    public function optimizeQuery(string $query, array $params = []): array {...}
    
    // Core optimization methods
    protected function applyOptimizations(string $query, array $analysis, array $params = []): string {...}
    protected function generateSuggestions(array $analysis): array {...}
    protected function calculateImprovement(array $analysis): array {...}
    
    // General optimization methods
    protected function optimizeJoins(string $query, array $analysis): string {...}
    protected function optimizeWhereClauses(string $query, array $analysis): string {...}
    protected function optimizeGrouping(string $query, array $analysis): string {...}
    protected function optimizeOrdering(string $query, array $analysis): string {...}
    
    // Database-specific optimization methods
    protected function optimizeMySQLJoins(string $query, array $analysis): string {...}
    protected function optimizePostgreSQLJoins(string $query, array $analysis): string {...}
    protected function optimizeSQLiteJoins(string $query, array $analysis): string {...}
    // ... additional database-specific methods ...
}
```

## Optimization Process

The query optimization process follows these steps:

1. **Analysis**: The query is analyzed using the `QueryAnalyzer` to identify potential issues
2. **Strategy Selection**: Based on the database driver, specific optimization strategies are selected
3. **Optimization Application**: Each optimization method is applied sequentially:
   - JOIN optimizations
   - WHERE clause optimizations
   - GROUP BY optimizations
   - ORDER BY optimizations
4. **Result Construction**: The original query, optimized query, suggestions and estimated improvements are returned

## Database-Specific Optimization Details

### MySQL Optimizations

#### JOIN Optimization

```php
protected function optimizeMySQLJoins(string $query, array $analysis): string
{
    // Use STRAIGHT_JOIN hint for complex joins when beneficial
    if (isset($analysis['execution_plan']) && preg_match('/\bJOIN\b/i', $query)) {
        $potentialIssues = $analysis['potential_issues'] ?? [];
        
        foreach ($potentialIssues as $issue) {
            if (($issue['type'] ?? '') === 'inefficient_join' && ($issue['severity'] ?? '') === 'high') {
                return preg_replace('/\bJOIN\b/i', 'STRAIGHT_JOIN', $query, 1);
            }
        }
    }
    
    return $query;
}
```

#### GROUP BY Optimization

```php
protected function optimizeMySQLGrouping(string $query, array $analysis): string
{
    // Add WITH ROLLUP for aggregate queries when appropriate
    if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $query) && 
        preg_match('/\bGROUP\s+BY\b/i', $query) &&
        !preg_match('/\bWITH\s+ROLLUP\b/i', $query)) {
        
        if (isset($analysis['potential_issues'])) {
            foreach ($analysis['potential_issues'] as $issue) {
                if (($issue['type'] ?? '') === 'complex_aggregation') {
                    return preg_replace('/\bGROUP\s+BY\b(.*?)(?=ORDER BY|LIMIT|HAVING|$)/is', 
                                      'GROUP BY$1 WITH ROLLUP ', $query);
                }
            }
        }
    }
    
    return $query;
}
```

### PostgreSQL Optimizations

PostgreSQL optimizations focus on:

- JOIN type selection based on table sizes
- Index utilization in WHERE clauses
- Advanced aggregation techniques
- CTE (Common Table Expression) optimization

### SQLite Optimizations

SQLite optimizations focus on:

- Simplified query structures
- Join order optimization
- Compound index utilization
- Limiting the use of complex operations

## Extending the Query Optimizer

### Adding Support for New Database Engines

To add support for a new database engine:

1. Add a new driver-specific method for each optimization category:
   ```php
   protected function optimizeNewEngineJoins(string $query, array $analysis): string {...}
   protected function optimizeNewEngineWhereClauses(string $query, array $analysis): string {...}
   protected function optimizeNewEngineGrouping(string $query, array $analysis): string {...}
   protected function optimizeNewEngineOrdering(string $query, array $analysis): string {...}
   ```

2. Update the switch statements in each optimization method:
   ```php
   protected function optimizeJoins(string $query, array $analysis): string
   {
       switch ($this->driverName) {
           case 'mysql': ...
           case 'pgsql': ...
           case 'sqlite': ...
           case 'new_engine':
               return $this->optimizeNewEngineJoins($query, $analysis);
           default:
               return $query;
       }
   }
   ```

### Adding New Optimization Techniques

To add a new optimization technique:

1. Identify the database(s) that will benefit from the optimization
2. Add the new optimization logic to the appropriate database-specific method
3. Update the `calculateImprovement` method to reflect the potential benefits
4. Add appropriate suggestions to the `generateSuggestions` method

## Performance Considerations

- The optimizer should be conservative with transformations to avoid changing query semantics
- Query analysis adds some overhead, so optimization is best for complex queries
- For high-frequency simple queries, the overhead may outweigh benefits
- Query transformations should be idempotent (applying multiple times produces same result)

## Testing

Testing the Query Optimizer requires:

1. **Unit Tests**: For each optimization method and strategy
2. **Integration Tests**: With real database connections
3. **Performance Tests**: Measuring actual performance improvements
4. **Semantic Tests**: Ensuring optimizations don't change query results

## Error Handling

The Query Optimizer should never throw exceptions during optimization. If an optimization cannot be safely applied, the original query should be returned.

## Integration Points

The Query Optimizer integrates with:

- **QueryBuilder**: For automatic query optimization during query building
- **QueryAnalyzer**: For detailed query analysis and issue detection
- **DatabaseConnection**: For driver type detection
- **DatabaseDriver**: For database-specific operations

## Future Improvements

Potential future improvements include:

- Machine learning-based optimization selection
- Query pattern recognition and caching
- Cost-based optimization strategies
- Multi-query optimization for transaction batches
- Adaptive optimization based on execution history
