# Database Profiling Tools

*Version: 1.0.0*  
*Last updated: May 18, 2025*

This document provides an overview of the database profiling tools available in Glueful v0.27.0, focusing on the QueryProfilerService, ExecutionPlanAnalyzer, QueryPatternRecognizer, and the CLI command for database query profiling.

## Table of Contents

1. [Overview](#overview)
2. [Database Tools](#database-tools)
   - [QueryProfilerService](#queryprofilerservice)
   - [ExecutionPlanAnalyzer](#executionplananalyzer)
   - [QueryPatternRecognizer](#querypatternrecognizer)
3. [Query Profile Command](#query-profile-command)
   - [Usage](#usage)
   - [Options](#options)
   - [Examples](#examples)
4. [Integration Examples](#integration-examples)
5. [Performance Considerations](#performance-considerations)

## Overview

The database profiling tools in Glueful provide comprehensive query analysis capabilities, enabling developers to:

- Measure query execution time and memory usage
- Analyze database execution plans
- Identify problematic query patterns
- Receive optimization recommendations
- Profile database operations in real-time

These tools are part of the v0.27.0 Performance Optimization initiative, designed to help developers identify and resolve database performance bottlenecks.

## Database Tools

The profiling functionality is built on three core components:

### QueryProfilerService

The QueryProfilerService is responsible for executing and profiling database queries. It captures detailed metrics about query execution, including:

- Execution time
- Memory usage
- Row count
- Query parameters
- Backtrace information
- Execution status

#### Key Methods

```php
// Profile a database query
public function profile(string $query, array $params, \Closure $executionCallback)

// Get recent query profiles
public function getRecentProfiles(int $limit = 100, float $threshold = null): array

// Log slow queries based on threshold
private function logSlowQuery(array $profile): void
```

### ExecutionPlanAnalyzer

The ExecutionPlanAnalyzer retrieves and analyzes database execution plans across different database engines (MySQL, PostgreSQL, SQLite). It identifies:

- Missing indexes
- Inefficient joins
- Full table scans
- Temporary table usage
- Sorting operations

#### Key Methods

```php
// Get the execution plan for a query
public function getExecutionPlan(string $query, array $params = []): array

// Analyze execution plan and provide recommendations
public function analyzeExecutionPlan(array $plan): array

// Get execution plan from specific database engines
private function getMySQLExecutionPlan(string $query, array $params): array
private function getPostgreSQLExecutionPlan(string $query, array $params): array
private function getSQLiteExecutionPlan(string $query, array $params): array
```

### QueryPatternRecognizer

The QueryPatternRecognizer identifies common query patterns that might indicate performance issues or anti-patterns, such as:

- SELECT * queries
- Missing WHERE clauses
- Inefficient subqueries
- Excessive JOINs
- LIKE with leading wildcards

#### Key Methods

```php
// Analyze a query for known patterns
public function recognizePatterns(string $query): array

// Load default query patterns
private function loadPatterns(): void

// Add a custom pattern for recognition
public function addPattern(string $name, string $regex, string $description, string $recommendation): void
```

## Query Profile Command

The `db:profile` command provides a convenient CLI interface to profile database queries directly from the command line, using the core profiling tools.

### Usage

```
php glueful db:profile [options]
```

### Options

| Option | Description |
|--------|-------------|
| `-q, --query=SQL` | SQL query to profile (required unless --file is used) |
| `-f, --file=PATH` | File containing SQL query to profile |
| `-e, --explain` | Show execution plan analysis |
| `-p, --patterns` | Detect query patterns and provide recommendations |
| `-o, --output=FORMAT` | Output format (table, json) (default: table) |
| `-h, --help` | Display help message |

### Examples

#### Basic Query Profiling

```bash
php glueful db:profile --query="SELECT * FROM users WHERE email LIKE '%example.com'"
```

Output:
```
=== Query Profile Results ===

Query:
SELECT * FROM users WHERE email LIKE '%example.com'

Parameters:
[]

Execution Metrics:
Duration:     125.45 ms
Memory Usage: 2.34 MB
Row Count:    42
```

#### Profile with Execution Plan

```bash
php glueful db:profile --query="SELECT * FROM orders JOIN order_items ON orders.id = order_items.order_id" --explain
```

Output:
```
=== Query Profile Results ===

Query:
SELECT * FROM orders JOIN order_items ON orders.id = order_items.order_id

Parameters:
[]

Execution Metrics:
Duration:     325.12 ms
Memory Usage: 5.67 MB
Row Count:    128

Execution Plan:
{
  "id": 1,
  "select_type": "SIMPLE",
  "table": "orders",
  "type": "ALL",
  "possible_keys": null,
  "key": null,
  "key_len": null,
  "ref": null,
  "rows": 42,
  "Extra": ""
}
...

Issues:
- Missing index on join condition (orders.id)
- Full table scan on orders table

Recommendations:
- Add index on orders.id column
- Consider limiting result set with WHERE clause
```

#### Profile with Pattern Recognition

```bash
php glueful db:profile --query="SELECT * FROM products" --patterns
```

Output:
```
=== Query Profile Results ===

Query:
SELECT * FROM products

Parameters:
[]

Execution Metrics:
Duration:     45.67 ms
Memory Usage: 1.23 MB
Row Count:    1250

Query Patterns:
Pattern: select_star
Description:    Using SELECT * retrieves all columns from the table
Recommendation: Specify only needed columns to reduce I/O and memory usage
```

#### Profile from File with JSON Output

```bash
echo "SELECT u.*, p.* FROM users u JOIN profiles p ON u.id = p.user_id WHERE u.created_at > '2025-01-01'" > query.sql
php glueful db:profile --file=query.sql --explain --patterns --output=json
```

Output (JSON):
```json
{
  "id": "query_6487a2c3e4f5",
  "sql": "SELECT u.*, p.* FROM users u JOIN profiles p ON u.id = p.user_id WHERE u.created_at > '2025-01-01'",
  "params": [],
  "start_time": 1715000240.543,
  "end_time": 1715000240.897,
  "duration": 354.21,
  "memory_before": 2345678,
  "memory_after": 5678912,
  "memory_delta": 3333234,
  "row_count": 87,
  "status": "success",
  "execution_plan": {...},
  "plan_analysis": {
    "issues": [...],
    "recommendations": [...]
  },
  "patterns": {
    "select_star": {
      "description": "Using SELECT * retrieves all columns from the table",
      "recommendation": "Specify only needed columns to reduce I/O and memory usage"
    },
    "multiple_joins": {
      "description": "Query contains JOIN operations",
      "recommendation": "Ensure indices exist on join columns"
    }
  }
}
```

## Integration Examples

### Profiling Database Operations in Code

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

### Using Execution Plan Analysis

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

### Recognizing Query Patterns

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

## Performance Considerations

While the profiling tools provide valuable insights, they do introduce some overhead:

- The profiling process adds approximately 1-3% overhead to query execution.
- The execution plan analysis can be expensive for very complex queries.
- Consider using sampling for high-traffic applications (set `sampling_rate` to a value less than 1.0).
- Use the CLI command for ad-hoc analysis rather than enabling profiling for all queries in production.

For production environments, you can configure the profiler with:

```php
// config/database.php
'profiler' => [
    'enabled' => env('DB_PROFILER_ENABLED', false),
    'threshold' => env('DB_PROFILER_THRESHOLD', 100), // milliseconds
    'sampling_rate' => env('DB_PROFILER_SAMPLING', 0.05), // 5% of queries
    'max_profiles' => env('DB_PROFILER_MAX_PROFILES', 100),
]
```

---

This documentation covers the Database Profiling Tools available in Glueful v0.27.0. For specific implementation details, refer to the source code in the `api/Database/Tools` directory and the `QueryProfileCommand` implementation.
