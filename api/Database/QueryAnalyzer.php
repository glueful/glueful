<?php

namespace Glueful\Database;

use PDO;
use PDOException;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Connection;

/**
 * Query Analyzer
 *
 * Provides advanced analysis capabilities for SQL queries with features:
 * - Execution plan retrieval and analysis
 * - Detection of potential query performance issues
 * - Optimization suggestions based on query patterns
 * - Index recommendations for improved performance
 * - Support for multiple database engines
 *
 * Design patterns:
 * - Strategy pattern for database-specific analysis
 * - Factory method for creating analysis objects
 */
class QueryAnalyzer
{
    /** @var Connection Database connection instance */
    private $connection;

    /** @var PDO Active PDO connection */
    private $pdo;

    /** @var DatabaseDriver Database-specific driver */
    private $driver;

    /**
     * Initialize the query analyzer with a new database connection
     */
    public function __construct()
    {
        $this->connection = new Connection();
        $this->pdo = $this->connection->getPDO();
        $this->driver = $this->connection->getDriver();
    }

    /**
     * Analyze a query for potential optimizations
     *
     * Performs comprehensive analysis of an SQL query and returns
     * detailed information about its execution characteristics and
     * potential optimizations.
     *
     * @param string $query The SQL query to analyze
     * @param array $params Parameters to bind to the query
     * @return array Analysis results containing execution plan, issues, suggestions and index recommendations
     */
    public function analyzeQuery(string $query, array $params = []): array
    {
        // Perform analysis
        return [
            'execution_plan' => $this->getExecutionPlan($query, $params),
            'potential_issues' => $this->detectIssues($query, $params),
            'optimization_suggestions' => $this->generateSuggestions($query, $params),
            'index_recommendations' => $this->recommendIndexes($query, $params)
        ];
    }

    /**
     * Get the database engine's execution plan for a query
     *
     * Retrieves the execution plan (EXPLAIN) for the provided query
     * using database-specific syntax.
     *
     * @param string $query The SQL query to analyze
     * @param array $params Parameters to bind to the query
     * @return array The execution plan as returned by the database
     */
    protected function getExecutionPlan(string $query, array $params = []): array
    {
        $driverName = $this->connection->getDriverName();

        try {
            // Add EXPLAIN to the query based on the database type
            switch ($driverName) {
                case 'mysql':
                    $stmt = $this->pdo->prepare("EXPLAIN " . $query);
                    break;
                case 'pgsql':
                    $stmt = $this->pdo->prepare("EXPLAIN (FORMAT JSON) " . $query);
                    break;
                case 'sqlite':
                    $stmt = $this->pdo->prepare("EXPLAIN QUERY PLAN " . $query);
                    break;
                default:
                    return ['error' => "Execution plan not supported for driver: {$driverName}"];
            }

            // Bind parameters and execute
            foreach ($params as $key => $value) {
                $paramName = is_string($key) ? $key : ':param' . $key;
                $stmt->bindValue($paramName, $value);
            }

            $stmt->execute();
            $plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalize plan format based on driver
            return $this->normalizeExecutionPlan($plan, $driverName);
        } catch (PDOException $e) {
            return ['error' => 'Error retrieving execution plan: ' . $e->getMessage()];
        }
    }

    /**
     * Normalize execution plan format across different database engines
     *
     * @param array $plan Raw execution plan from database
     * @param string $driverName Database driver name
     * @return array Normalized execution plan
     */
    protected function normalizeExecutionPlan(array $plan, string $driverName): array
    {
        // Normalize plan format based on driver to provide consistent output
        switch ($driverName) {
            case 'mysql':
                return $plan;
            case 'pgsql':
                return isset($plan[0]['QUERY PLAN']) ? json_decode($plan[0]['QUERY PLAN'], true) : $plan;
            case 'sqlite':
                $normalized = [];
                foreach ($plan as $step) {
                    $normalized[] = [
                        'id' => $step['id'] ?? 0,
                        'detail' => $step['detail'] ?? '',
                        'parent' => $step['parent'] ?? null
                    ];
                }
                return $normalized;
            default:
                return $plan;
        }
    }

    /**
     * Detect potential issues in the query
     *
     * Analyzes the query for common performance issues like:
     * - Full table scans
     * - Missing WHERE clauses
     * - Non-indexed joins
     * - Cartesian products
     * - Inefficient LIKE patterns
     *
     * @param string $query The SQL query to analyze
     * @param array $params Parameters to bind to the query
     * @return array List of detected issues with severity and description
     */
    protected function detectIssues(string $query, array $params = []): array
    {
        $issues = [];
        $plan = $this->getExecutionPlan($query, $params);

        // Check for error in plan retrieval
        if (isset($plan['error'])) {
            return [['severity' => 'error', 'message' => $plan['error']]];
        }

        // Check for common issues based on execution plan
        $driverName = $this->connection->getDriverName();

        switch ($driverName) {
            case 'mysql':
                $issues = array_merge($issues, $this->detectMySQLIssues($plan, $query));
                break;
            case 'pgsql':
                $issues = array_merge($issues, $this->detectPostgreSQLIssues($plan, $query));
                break;
            case 'sqlite':
                $issues = array_merge($issues, $this->detectSQLiteIssues($plan, $query));
                break;
        }

        // Generic pattern-based checks
        $issues = array_merge($issues, $this->detectGenericIssues($query));

        return $issues;
    }

    /**
     * Detect MySQL-specific query issues
     *
     * @param array $plan Execution plan
     * @param string $query SQL query
     * @return array Detected issues
     */
    protected function detectMySQLIssues(array $plan, string $query): array
    {
        $issues = [];

        foreach ($plan as $step) {
            // Check for full table scans
            if (isset($step['type']) && $step['type'] === 'ALL') {
                $issues[] = [
                    'severity' => 'warning',
                    'message' => "Full table scan detected on table '{$step['table']}'",
                    'details' => "Consider adding an index to improve performance"
                ];
            }

            // Check for temporary tables
            if (isset($step['Extra']) && strpos($step['Extra'], 'Using temporary') !== false) {
                $issues[] = [
                    'severity' => 'warning',
                    'message' => "Query uses temporary table",
                    'details' => "This may indicate a complex join or group by operation"
                ];
            }

            // Check for filesorts
            if (isset($step['Extra']) && strpos($step['Extra'], 'Using filesort') !== false) {
                $issues[] = [
                    'severity' => 'warning',
                    'message' => "Query uses filesort",
                    'details' => "This may indicate sorting without an appropriate index"
                ];
            }
        }

        return $issues;
    }

    /**
     * Detect PostgreSQL-specific query issues
     *
     * @param array $plan Execution plan
     * @param string $query SQL query
     * @return array Detected issues
     */
    protected function detectPostgreSQLIssues(array $plan, string $query): array
    {
        $issues = [];

        // Process PostgreSQL's JSON plan format
        if (is_array($plan) && isset($plan[0]) && isset($plan[0]['Plan'])) {
            $planDetails = $plan[0]['Plan'];

            // Check for sequential scans
            if (isset($planDetails['Node Type']) && $planDetails['Node Type'] === 'Seq Scan') {
                $issues[] = [
                    'severity' => 'warning',
                    'message' => "Sequential scan detected on relation '{$planDetails['Relation Name']}'",
                    'details' => "Consider adding an index to improve performance"
                ];
            }

            // Check for nested loops with many iterations
            if (
                isset($planDetails['Node Type']) && $planDetails['Node Type'] === 'Nested Loop' &&
                isset($planDetails['Plan Rows']) && $planDetails['Plan Rows'] > 1000
            ) {
                $issues[] = [
                    'severity' => 'warning',
                    'message' => "Large nested loop join detected",
                    'details' => "This may cause performance issues with larger datasets"
                ];
            }
        }

        return $issues;
    }

    /**
     * Detect SQLite-specific query issues
     *
     * @param array $plan Execution plan
     * @param string $query SQL query
     * @return array Detected issues
     */
    protected function detectSQLiteIssues(array $plan, string $query): array
    {
        $issues = [];

        foreach ($plan as $step) {
            // Check for scans without index
            if (
                isset($step['detail']) && strpos($step['detail'], 'SCAN TABLE') !== false &&
                strpos($step['detail'], 'USING INDEX') === false
            ) {
                // Extract table name from detail
                preg_match('/SCAN TABLE ([^\s]+)/', $step['detail'], $matches);
                $table = $matches[1] ?? 'unknown';

                $issues[] = [
                    'severity' => 'warning',
                    'message' => "Full table scan detected on table '{$table}'",
                    'details' => "Consider adding an index to improve performance"
                ];
            }
        }

        return $issues;
    }

    /**
     * Detect generic SQL issues that apply to all database types
     *
     * @param string $query SQL query
     * @return array Detected issues
     */
    protected function detectGenericIssues(string $query): array
    {
        $issues = [];
        $query = strtolower($query);

        // Check for SELECT * pattern
        if (preg_match('/select\s+\*\s+from/i', $query)) {
            $issues[] = [
                'severity' => 'info',
                'message' => "Query uses 'SELECT *'",
                'details' => "Consider selecting only needed columns to improve performance"
            ];
        }

        // Check for inefficient LIKE patterns
        if (preg_match('/like\s+[\'"]%/i', $query)) {
            $issues[] = [
                'severity' => 'warning',
                'message' => "Query uses leading wildcard in LIKE pattern",
                'details' => "Leading wildcards (%...) prevent index usage and may cause full table scans"
            ];
        }

        // Check for IN with large number of values
        if (preg_match('/in\s+\([^)]{1000,}\)/i', $query)) {
            $issues[] = [
                'severity' => 'warning',
                'message' => "Query uses IN clause with many values",
                'details' => "Consider using a JOIN with a temporary table for better performance"
            ];
        }

        return $issues;
    }

    /**
     * Generate optimization suggestions based on query analysis
     *
     * @param string $query The SQL query to analyze
     * @param array $params Parameters to bind to the query
     * @return array List of suggestions to improve query performance
     */
    protected function generateSuggestions(string $query, array $params = []): array
    {
        $suggestions = [];
        $issues = $this->detectIssues($query, $params);

        // Convert issues to suggestions
        foreach ($issues as $issue) {
            switch ($issue['message']) {
                case (strpos($issue['message'], 'Full table scan') !== false):
                    $suggestions[] = [
                        'priority' => 'high',
                        'suggestion' => 'Add appropriate indexes to tables',
                        'details' => $issue['details'],
                        'related_issue' => $issue['message']
                    ];
                    break;

                case (strpos($issue['message'], "Query uses 'SELECT *'") !== false):
                    $suggestions[] = [
                        'priority' => 'medium',
                        'suggestion' => 'Select only necessary columns',
                        'details' => 'Selecting specific columns reduces I/O and memory usage',
                        'related_issue' => $issue['message']
                    ];
                    break;

                case (strpos($issue['message'], 'leading wildcard in LIKE pattern') !== false):
                    $suggestions[] = [
                        'priority' => 'high',
                        'suggestion' => 'Avoid leading wildcards in LIKE patterns',
                        'details' => 'Consider using a full-text search index or restructuring the query',
                        'related_issue' => $issue['message']
                    ];
                    break;

                // Add more suggestion mappings based on common issues
            }
        }

        // Add generic suggestions based on query patterns
        $suggestions = array_merge($suggestions, $this->getQueryPatternSuggestions($query));

        return $suggestions;
    }

    /**
     * Get suggestions based on common query patterns
     *
     * @param string $query SQL query
     * @return array List of suggestions
     */
    protected function getQueryPatternSuggestions(string $query): array
    {
        $suggestions = [];
        $query = strtolower($query);

        // Check for repeated subqueries
        if (substr_count($query, '(select') > 2) {
            $suggestions[] = [
                'priority' => 'medium',
                'suggestion' => 'Consider using CTEs (WITH clause) for repeated subqueries',
                'details' => 'Common Table Expressions can improve readability and sometimes performance'
            ];
        }

        // Check for multiple joins
        if (substr_count($query, 'join') > 3) {
            $suggestions[] = [
                'priority' => 'medium',
                'suggestion' => 'Review join order and types',
                'details' => 'For complex queries with many joins, the join order and type can significantly ' .
                    'affect performance'
            ];
        }

        // Check for GROUP BY without appropriate indexes
        if (strpos($query, 'group by') !== false) {
            $suggestions[] = [
                'priority' => 'medium',
                'suggestion' => 'Ensure columns in GROUP BY have appropriate indexes',
                'details' => 'GROUP BY operations perform better with indexes on grouped columns'
            ];
        }

        return $suggestions;
    }

    /**
     * Recommend indexes that could improve query performance
     *
     * @param string $query The SQL query to analyze
     * @param array $params Parameters to bind to the query
     * @return array List of recommended indexes
     */
    protected function recommendIndexes(string $query, array $params = []): array
    {
        $recommendations = [];
        $plan = $this->getExecutionPlan($query, $params);
        $driverName = $this->connection->getDriverName();

        // Get table and column references from the query
        $tables = $this->extractTablesFromQuery($query);
        $whereColumns = $this->extractWhereColumnsFromQuery($query);
        $joinColumns = $this->extractJoinColumnsFromQuery($query);
        $orderByColumns = $this->extractOrderByColumnsFromQuery($query);
        $groupByColumns = $this->extractGroupByColumnsFromQuery($query);

        // Recommend indexes based on WHERE clauses
        foreach ($whereColumns as $table => $columns) {
            if (count($columns) > 0) {
                $recommendations[] = [
                    'table' => $table,
                    'columns' => $columns,
                    'type' => 'where_condition',
                    'priority' => 'high',
                    'suggestion' => "Consider adding index on table '{$table}' for columns: " . implode(', ', $columns)
                ];
            }
        }

        // Recommend indexes based on JOIN conditions
        foreach ($joinColumns as $table => $columns) {
            if (count($columns) > 0) {
                $recommendations[] = [
                    'table' => $table,
                    'columns' => $columns,
                    'type' => 'join_condition',
                    'priority' => 'high',
                    'suggestion' => "Consider adding index on table '{$table}' for join columns: " .
                        implode(', ', $columns)
                ];
            }
        }

        // Recommend indexes based on ORDER BY
        foreach ($orderByColumns as $table => $columns) {
            if (count($columns) > 0) {
                $recommendations[] = [
                    'table' => $table,
                    'columns' => $columns,
                    'type' => 'order_by',
                    'priority' => 'medium',
                    'suggestion' => "Consider adding index on table '{$table}' for ORDER BY columns: " .
                        implode(', ', $columns)
                ];
            }
        }

        // Recommend indexes based on GROUP BY
        foreach ($groupByColumns as $table => $columns) {
            if (count($columns) > 0) {
                $recommendations[] = [
                    'table' => $table,
                    'columns' => $columns,
                    'type' => 'group_by',
                    'priority' => 'medium',
                    'suggestion' => "Consider adding index on table '{$table}' for GROUP BY columns: " .
                        implode(', ', $columns)
                ];
            }
        }

        // Add driver-specific recommendations
        switch ($driverName) {
            case 'mysql':
                $recommendations = array_merge($recommendations, $this->getMySQLIndexRecommendations($plan, $query));
                break;
            case 'pgsql':
                $recommendations = array_merge(
                    $recommendations,
                    $this->getPostgreSQLIndexRecommendations($plan, $query)
                );
                break;
            case 'sqlite':
                $recommendations = array_merge($recommendations, $this->getSQLiteIndexRecommendations($plan, $query));
                break;
        }

        return $recommendations;
    }

    /**
     * Extract tables referenced in a query
     *
     * @param string $query SQL query
     * @return array List of tables
     */
    protected function extractTablesFromQuery(string $query): array
    {
        $tables = [];

        // Simple regex to extract tables from FROM and JOIN clauses
        // Note: This is a basic implementation and may not cover all SQL syntax variations

        // Extract from FROM clause
        if (preg_match('/from\s+([a-z0-9_\.]+)/i', $query, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract from JOIN clauses
        preg_match_all('/join\s+([a-z0-9_\.]+)/i', $query, $matches);
        if (!empty($matches[1])) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique($tables);
    }

    /**
     * Extract columns used in WHERE clauses
     *
     * @param string $query SQL query
     * @return array Associative array of table => columns
     */
    protected function extractWhereColumnsFromQuery(string $query): array
    {
        $columns = [];

        // This is a simplified implementation and may not catch all WHERE conditions
        if (preg_match('/where\s+(.*?)(?:group by|order by|limit|$)/is', $query, $matches)) {
            $whereClause = $matches[1];

            // Extract column names from conditions
            preg_match_all('/([a-z0-9_\.]+)\s*(?:=|>|<|>=|<=|!=|<>|like|in)/i', $whereClause, $columnMatches);

            if (!empty($columnMatches[1])) {
                foreach ($columnMatches[1] as $col) {
                    // If column has table prefix, separate it
                    if (strpos($col, '.') !== false) {
                        list($table, $column) = explode('.', $col, 2);
                        $columns[$table][] = $column;
                    } else {
                        // If no table prefix, assign to generic bucket
                        $columns['unknown_table'][] = $col;
                    }
                }
            }
        }

        // Deduplicate columns per table
        foreach ($columns as $table => $cols) {
            $columns[$table] = array_unique($cols);
        }

        return $columns;
    }

    /**
     * Extract columns used in JOIN conditions
     *
     * @param string $query SQL query
     * @return array Associative array of table => columns
     */
    protected function extractJoinColumnsFromQuery(string $query): array
    {
        $columns = [];

        // Extract all JOIN ... ON conditions
        $joinPattern = '/join\s+([a-z0-9_\.]+)\s+(?:as\s+([a-z0-9_]+)\s+)?on\s+(.*?)' .
                       '(?:(?:inner|left|right|outer|cross|join|where|group|order|limit)\s|$)/is';
        preg_match_all($joinPattern, $query, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = $match[1];
            $alias = !empty($match[2]) ? $match[2] : $table;
            $condition = $match[3];

            // Extract column names from the JOIN condition
            preg_match_all('/([a-z0-9_\.]+)\s*(?:=|>|<|>=|<=)/i', $condition, $columnMatches);

            if (!empty($columnMatches[1])) {
                foreach ($columnMatches[1] as $col) {
                    // If column has table prefix, separate it
                    if (strpos($col, '.') !== false) {
                        list($colTable, $column) = explode('.', $col, 2);

                        // Only include if it matches our join table or alias
                        if ($colTable === $table || $colTable === $alias) {
                            $columns[$table][] = $column;
                        }
                    }
                }
            }
        }

        // Deduplicate columns per table
        foreach ($columns as $table => $cols) {
            $columns[$table] = array_unique($cols);
        }

        return $columns;
    }

    /**
     * Extract columns used in ORDER BY clauses
     *
     * @param string $query SQL query
     * @return array Associative array of table => columns
     */
    protected function extractOrderByColumnsFromQuery(string $query): array
    {
        $columns = [];

        // Extract ORDER BY clause
        if (preg_match('/order by\s+(.*?)(?:limit|$)/is', $query, $matches)) {
            $orderByClause = $matches[1];

            // Split by commas to get individual columns
            $orderColumns = preg_split('/\s*,\s*/', $orderByClause);

            foreach ($orderColumns as $col) {
                // Remove ASC/DESC if present
                $col = preg_replace('/\s+(?:asc|desc)$/i', '', trim($col));

                // If column has table prefix, separate it
                if (strpos($col, '.') !== false) {
                    list($table, $column) = explode('.', $col, 2);
                    $columns[$table][] = $column;
                } else {
                    // If no table prefix, assign to generic bucket
                    $columns['unknown_table'][] = $col;
                }
            }
        }

        // Deduplicate columns per table
        foreach ($columns as $table => $cols) {
            $columns[$table] = array_unique($cols);
        }

        return $columns;
    }

    /**
     * Extract columns used in GROUP BY clauses
     *
     * @param string $query SQL query
     * @return array Associative array of table => columns
     */
    protected function extractGroupByColumnsFromQuery(string $query): array
    {
        $columns = [];

        // Extract GROUP BY clause
        if (preg_match('/group by\s+(.*?)(?:having|order by|limit|$)/is', $query, $matches)) {
            $groupByClause = $matches[1];

            // Split by commas to get individual columns
            $groupColumns = preg_split('/\s*,\s*/', $groupByClause);

            foreach ($groupColumns as $col) {
                $col = trim($col);

                // If column has table prefix, separate it
                if (strpos($col, '.') !== false) {
                    list($table, $column) = explode('.', $col, 2);
                    $columns[$table][] = $column;
                } else {
                    // If no table prefix, assign to generic bucket
                    $columns['unknown_table'][] = $col;
                }
            }
        }

        // Deduplicate columns per table
        foreach ($columns as $table => $cols) {
            $columns[$table] = array_unique($cols);
        }

        return $columns;
    }

    /**
     * Get MySQL-specific index recommendations
     *
     * @param array $plan Execution plan
     * @param string $query SQL query
     * @return array Index recommendations
     */
    protected function getMySQLIndexRecommendations(array $plan, string $query): array
    {
        $recommendations = [];

        foreach ($plan as $step) {
            // Check for missing indexes in MySQL plan
            if (
                (isset($step['possible_keys']) && $step['possible_keys'] === '') &&
                (isset($step['key']) && $step['key'] === '')
            ) {
                $recommendations[] = [
                    'table' => $step['table'] ?? 'unknown',
                    'columns' => [],
                    'type' => 'missing_index',
                    'priority' => 'high',
                    'suggestion' => "Table '{$step['table']}' has no usable index for this query"
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Get PostgreSQL-specific index recommendations
     *
     * @param array $plan Execution plan
     * @param string $query SQL query
     * @return array Index recommendations
     */
    protected function getPostgreSQLIndexRecommendations(array $plan, string $query): array
    {
        $recommendations = [];

        // Process PostgreSQL's JSON plan format
        if (is_array($plan) && isset($plan[0]) && isset($plan[0]['Plan'])) {
            $this->analyzePgPlan($plan[0]['Plan'], $recommendations);
        }

        return $recommendations;
    }

    /**
     * Recursively analyze PostgreSQL execution plan
     *
     * @param array $planNode Current plan node
     * @param array &$recommendations Recommendations array to append to
     */
    protected function analyzePgPlan(array $planNode, array &$recommendations): void
    {
        // Check for sequential scans that could benefit from indexes
        if (
            isset($planNode['Node Type']) && $planNode['Node Type'] === 'Seq Scan' &&
            isset($planNode['Relation Name']) && isset($planNode['Filter'])
        ) {
            $recommendations[] = [
                'table' => $planNode['Relation Name'],
                'columns' => [],
                'type' => 'missing_index',
                'priority' => 'high',
                'suggestion' => "Consider an index on table '{$planNode['Relation Name']}' " .
                    "for filter: {$planNode['Filter']}"
            ];
        }

        // Recursively check child plans
        if (isset($planNode['Plans']) && is_array($planNode['Plans'])) {
            foreach ($planNode['Plans'] as $childPlan) {
                $this->analyzePgPlan($childPlan, $recommendations);
            }
        }
    }

    /**
     * Get SQLite-specific index recommendations
     *
     * @param array $plan Execution plan
     * @param string $query SQL query
     * @return array Index recommendations
     */
    protected function getSQLiteIndexRecommendations(array $plan, string $query): array
    {
        $recommendations = [];

        foreach ($plan as $step) {
            // Check for scans without index
            if (
                isset($step['detail']) && strpos($step['detail'], 'SCAN TABLE') !== false &&
                strpos($step['detail'], 'USING INDEX') === false
            ) {
                // Extract table name from detail
                preg_match('/SCAN TABLE ([^\s]+)/', $step['detail'], $matches);
                $table = $matches[1] ?? 'unknown';

                $recommendations[] = [
                    'table' => $table,
                    'columns' => [],
                    'type' => 'missing_index',
                    'priority' => 'high',
                    'suggestion' => "Consider adding an index on table '{$table}' based on query conditions"
                ];
            }
        }

        return $recommendations;
    }
}
