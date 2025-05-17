# Query Analyzer

## Overview

The `QueryAnalyzer` class provides advanced analysis capabilities for SQL queries, helping developers identify performance issues and optimize database operations. This tool is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Execution Plan Analysis](#execution-plan-analysis)
- [Issue Detection](#issue-detection)
- [Optimization Suggestions](#optimization-suggestions)
- [Index Recommendations](#index-recommendations)
- [Database Compatibility](#database-compatibility)
- [Advanced Usage](#advanced-usage)
- [Performance Considerations](#performance-considerations)
- [Integration with Query Builder](#integration-with-query-builder)

## Key Features

The `QueryAnalyzer` provides several critical capabilities:

- **Execution Plan Retrieval**: Fetches and normalizes database execution plans across different engines
- **Performance Issue Detection**: Identifies common SQL anti-patterns and inefficient query structures
- **Optimization Suggestions**: Provides actionable recommendations to improve query performance
- **Index Recommendations**: Suggests indexes that could enhance query execution speed
- **Multi-database Support**: Works with MySQL, PostgreSQL, and SQLite engines

## Usage Examples

### Basic Usage

```php
// Create a new query analyzer instance
$analyzer = new \Glueful\Database\QueryAnalyzer();

// Analyze a query
$query = "SELECT * FROM users WHERE last_login < '2025-01-01' ORDER BY created_at";
$results = $analyzer->analyzeQuery($query);

// Display analysis results
print_r($results);
```

### Analysis with Parameters

For parameterized queries:

```php
$query = "SELECT * FROM products WHERE category_id = ? AND price > ?";
$params = [5, 99.99];
$results = $analyzer->analyzeQuery($query, $params);
```

## Execution Plan Analysis

The execution plan provides insights into how the database will execute the query:

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

The analyzer normalizes execution plans across different database engines to provide a consistent interface.

## Issue Detection

The analyzer identifies potential performance issues in queries:

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

## Optimization Suggestions

Based on identified issues, the analyzer generates optimization suggestions:

```php
foreach ($results['optimization_suggestions'] as $suggestion) {
    echo "Priority: {$suggestion['priority']}\n";
    echo "Suggestion: {$suggestion['suggestion']}\n";
    echo "Details: {$suggestion['details']}\n\n";
}
```

Suggestions are prioritized as high, medium, or low to help focus on the most impactful changes.

## Index Recommendations

The analyzer recommends indexes that could improve query performance:

```php
foreach ($results['index_recommendations'] as $recommendation) {
    echo "Table: {$recommendation['table']}\n";
    echo "Columns: " . implode(', ', $recommendation['columns']) . "\n";
    echo "Type: {$recommendation['type']}\n";
    echo "Priority: {$recommendation['priority']}\n";
    echo "Suggestion: {$recommendation['suggestion']}\n\n";
}
```

Index recommendations are based on:
- WHERE clause conditions
- JOIN conditions
- ORDER BY clauses
- GROUP BY clauses
- Database-specific execution plan analysis

## Database Compatibility

The `QueryAnalyzer` supports multiple database engines:

| Feature | MySQL | PostgreSQL | SQLite |
|---------|-------|------------|--------|
| Execution Plan | ✓ | ✓ | ✓ |
| Issue Detection | ✓ | ✓ | ✓ |
| Optimization Suggestions | ✓ | ✓ | ✓ |
| Index Recommendations | ✓ | ✓ | ✓ |

Each database engine has specialized analysis functionality that leverages unique features of that platform.

## Advanced Usage

### Analyzing Complex Queries

For complex queries, it's often helpful to focus on specific aspects of the analysis:

```php
$analyzer = new \Glueful\Database\QueryAnalyzer();
$query = "SELECT u.username, COUNT(p.id) FROM users u 
          LEFT JOIN posts p ON u.id = p.user_id 
          WHERE u.status = 'active' 
          GROUP BY u.username 
          ORDER BY COUNT(p.id) DESC";

// Get only execution plan
$plan = $analyzer->getExecutionPlan($query);

// Check for issues
$issues = $analyzer->detectIssues($query);

// Get optimization suggestions
$suggestions = $analyzer->generateSuggestions($query);

// Get index recommendations
$indexRecommendations = $analyzer->recommendIndexes($query);
```

### Integration in Development Workflow

The QueryAnalyzer is particularly valuable during development and optimization phases:

1. **Development**: Use it to identify potential issues before deploying to production
2. **Troubleshooting**: Analyze slow queries reported by users or monitoring tools
3. **Optimization**: Systematically improve application performance by addressing recommendations

## Performance Considerations

While the `QueryAnalyzer` is a powerful tool, it does introduce some overhead:

- Analysis requires executing `EXPLAIN` queries against the database
- Complex queries may take longer to analyze
- Analysis should typically be performed in development environments or selectively in production

For production use, consider:
- Using a dedicated analyzer instance with a read-only database connection
- Limiting analysis to specifically flagged queries
- Implementing analysis on a sampling basis rather than for every query

## Integration with Query Builder

The QueryAnalyzer can be integrated with Glueful's Query Builder for automated analysis:

```php
// Coming in v0.27.0
$queryBuilder = new \Glueful\Database\QueryBuilder($pdo, $driver);
$queryBuilder->optimize(true);

// Execute with automatic optimization
$results = $queryBuilder->table('users')
    ->where('status', 'active')
    ->orderBy('created_at')
    ->get();
```

---

*For more information on database performance optimization, see the [Database Performance Tuning Guide](./performance-tuning.md) and the [Query Result Caching System](./query-result-caching.md).*
