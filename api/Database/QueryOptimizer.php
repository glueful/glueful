<?php

namespace Glueful\Database;

use Glueful\Database\Connection;

/**
 * Query Optimizer
 *
 * Provides optimization capabilities for SQL queries with features:
 * - Query analysis and optimization suggestions
 * - Performance improvement estimations
 * - Automatic query transformation for better performance
 * - Integration with QueryAnalyzer for detailed analysis
 *
 * Design patterns:
 * - Strategy pattern for optimization techniques
 * - Adapter pattern for database-specific optimizations
 */
class QueryOptimizer
{
    /** @var QueryAnalyzer Query analyzer instance */
    private $queryAnalyzer;

    /** @var string Database driver name (mysql, pgsql, sqlite) */
    private $driverName;

    /** @var \Glueful\Database\Driver\DatabaseDriver Database driver instance */
    private $driver;

    /**
     * Initialize the query optimizer
     */
    public function __construct()
    {
        $this->queryAnalyzer = new QueryAnalyzer();
    }

    /**
     * Set the database connection for the optimizer
     *
     * This allows the optimizer to use an existing connection rather than
     * creating a new one, which is especially useful when integrated with
     * the QueryBuilder.
     *
     * @param Connection $connection The database connection
     * @return self For method chaining
     */
    public function setConnection(Connection $connection): self
    {
        // Get database-specific information for optimization
        $this->driverName = $connection->getDriverName();
        $this->driver = $connection->getDriver();

        return $this;
    }

    /**
     * Analyze and optimize a complex query
     *
     * Performs analysis on the given query and returns optimized version
     * along with performance improvement estimates and suggestions.
     *
     * @param string $query The SQL query to optimize
     * @param array $params Parameters to bind to the query
     * @return array Optimization results including original query, optimized query,
     *               suggestions and estimated improvement
     */
    public function optimizeQuery(string $query, array $params = []): array
    {
        $analysis = $this->queryAnalyzer->analyzeQuery($query, $params);

        return [
            'original_query' => $query,
            'optimized_query' => $this->applyOptimizations($query, $analysis, $params),
            'suggestions' => $this->generateSuggestions($analysis),
            'estimated_improvement' => $this->calculateImprovement($analysis)
        ];
    }

    /**
     * Apply optimizations to the original query based on analysis
     *
     * @param string $query The original SQL query
     * @param array $analysis The query analysis results
     * @param array $params Query parameters
     * @return string The optimized query
     */
    protected function applyOptimizations(string $query, array $analysis, array $params = []): string
    {
        $optimizedQuery = $query;

        // Apply various optimization techniques
        $optimizedQuery = $this->optimizeJoins($optimizedQuery, $analysis);
        $optimizedQuery = $this->optimizeWhereClauses($optimizedQuery, $analysis);
        $optimizedQuery = $this->optimizeGrouping($optimizedQuery, $analysis);
        $optimizedQuery = $this->optimizeOrdering($optimizedQuery, $analysis);

        return $optimizedQuery;
    }

    /**
     * Generate optimization suggestions based on query analysis
     *
     * @param array $analysis The query analysis results
     * @return array List of optimization suggestions
     */
    protected function generateSuggestions(array $analysis): array
    {
        $suggestions = [];

        // Add suggestions based on analysis results
        if (isset($analysis['potential_issues'])) {
            foreach ($analysis['potential_issues'] as $issue) {
                $suggestions[] = $this->createSuggestionFromIssue($issue);
            }
        }

        // Add index recommendations
        if (isset($analysis['index_recommendations'])) {
            foreach ($analysis['index_recommendations'] as $recommendation) {
                $suggestions[] = $recommendation;
            }
        }

        return $suggestions;
    }

    /**
     * Convert an identified issue into an actionable suggestion
     *
     * @param array $issue The issue details from analysis
     * @return array The suggestion with description and impact
     */
    protected function createSuggestionFromIssue(array $issue): array
    {
        return [
            'type' => $issue['type'] ?? 'unknown',
            'description' => $issue['description'] ?? 'Unknown issue',
            'solution' => $this->determineSolution($issue),
            'impact' => $issue['impact'] ?? 'medium'
        ];
    }

    /**
     * Determine the solution for a specific issue
     *
     * @param array $issue The issue details from analysis
     * @return string The suggested solution
     */
    protected function determineSolution(array $issue): string
    {
        $type = $issue['type'] ?? '';

        switch ($type) {
            case 'missing_index':
                return 'Add an index to the referenced column';
            case 'full_table_scan':
                return 'Refine your WHERE clause to use indexed columns';
            case 'inefficient_join':
                return 'Reorder joins to start with the table having the most restrictive conditions';
            case 'cartesian_product':
                return 'Add a join condition between the tables';
            default:
                return 'Review the query structure for optimization opportunities';
        }
    }

    /**
     * Calculate the estimated performance improvement
     *
     * @param array $analysis The query analysis results
     * @return array Improvement metrics
     */
    protected function calculateImprovement(array $analysis): array
    {
        // Default improvement estimation
        $improvement = [
            'execution_time' => 0,
            'resource_usage' => 0,
            'confidence' => 'medium'
        ];

        // Calculate improvements based on identified issues
        if (isset($analysis['potential_issues'])) {
            foreach ($analysis['potential_issues'] as $issue) {
                // Accumulated improvement percentage based on issue severity
                $severity = $issue['severity'] ?? 'medium';

                switch ($severity) {
                    case 'high':
                        $improvement['execution_time'] += 15;
                        $improvement['resource_usage'] += 20;
                        break;
                    case 'medium':
                        $improvement['execution_time'] += 8;
                        $improvement['resource_usage'] += 10;
                        break;
                    case 'low':
                        $improvement['execution_time'] += 3;
                        $improvement['resource_usage'] += 5;
                        break;
                }
            }
        }

        // Cap improvements at reasonable values
        $improvement['execution_time'] = min($improvement['execution_time'], 95);
        $improvement['resource_usage'] = min($improvement['resource_usage'], 95);

        return $improvement;
    }

    /**
     * Optimize JOIN operations in the query
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized joins
     */
    protected function optimizeJoins(string $query, array $analysis): string
    {
        // Apply database-specific join optimizations
        switch ($this->driverName) {
            case 'mysql':
                return $this->optimizeMySQLJoins($query, $analysis);
            case 'pgsql':
                return $this->optimizePostgreSQLJoins($query, $analysis);
            case 'sqlite':
                return $this->optimizeSQLiteJoins($query, $analysis);
            default:
                return $query; // No optimization for unknown driver
        }
    }

    /**
     * MySQL-specific join optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized joins
     */
    protected function optimizeMySQLJoins(string $query, array $analysis): string
    {
        // MySQL join optimizations:
        // - Use STRAIGHT_JOIN hint for complex joins when beneficial
        // - Reorder joins for better execution based on execution plan

        if (isset($analysis['execution_plan']) && preg_match('/\bJOIN\b/i', $query)) {
            $potentialIssues = $analysis['potential_issues'] ?? [];

            foreach ($potentialIssues as $issue) {
                if (($issue['type'] ?? '') === 'inefficient_join' && ($issue['severity'] ?? '') === 'high') {
                    // For severe join issues in MySQL, consider using STRAIGHT_JOIN hint
                    return preg_replace('/\bJOIN\b/i', 'STRAIGHT_JOIN', $query, 1);
                }
            }
        }

        return $query;
    }

    /**
     * PostgreSQL-specific join optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized joins
     */
    protected function optimizePostgreSQLJoins(string $query, array $analysis): string
    {
        // PostgreSQL join optimizations
        // Potential optimizations could include changing JOIN types based on data size
        return $query;
    }

    /**
     * SQLite-specific join optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized joins
     */
    protected function optimizeSQLiteJoins(string $query, array $analysis): string
    {
        // SQLite join optimizations
        // SQLite has fewer join optimization options, but ordering can still matter
        return $query;
    }

    /**
     * Optimize WHERE clauses in the query
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized where clauses
     */
    protected function optimizeWhereClauses(string $query, array $analysis): string
    {
        // Apply database-specific WHERE clause optimizations
        switch ($this->driverName) {
            case 'mysql':
                return $this->optimizeMySQLWhereClauses($query, $analysis);
            case 'pgsql':
                return $this->optimizePostgreSQLWhereClauses($query, $analysis);
            case 'sqlite':
                return $this->optimizeSQLiteWhereClauses($query, $analysis);
            default:
                return $query; // No optimization for unknown driver
        }
    }

    /**
     * MySQL-specific WHERE clause optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized WHERE clauses
     */
    protected function optimizeMySQLWhereClauses(string $query, array $analysis): string
    {
        // MySQL WHERE optimizations:
        // - Ensure indexed columns appear first in compound conditions
        // - Avoid using functions on indexed columns

        if (isset($analysis['potential_issues'])) {
            foreach ($analysis['potential_issues'] as $issue) {
                if (($issue['type'] ?? '') === 'function_on_indexed_column') {
                    // This would require more complex query parsing and transformation
                    // Placeholder for actual function-to-condition transformation
                }
            }
        }

        return $query;
    }

    /**
     * PostgreSQL-specific WHERE clause optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized WHERE clauses
     */
    protected function optimizePostgreSQLWhereClauses(string $query, array $analysis): string
    {
        // PostgreSQL WHERE optimizations
        return $query;
    }

    /**
     * SQLite-specific WHERE clause optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized WHERE clauses
     */
    protected function optimizeSQLiteWhereClauses(string $query, array $analysis): string
    {
        // SQLite WHERE optimizations
        return $query;
    }

    /**
     * Optimize GROUP BY operations in the query
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized grouping
     */
    protected function optimizeGrouping(string $query, array $analysis): string
    {
        // Apply database-specific GROUP BY optimizations
        switch ($this->driverName) {
            case 'mysql':
                return $this->optimizeMySQLGrouping($query, $analysis);
            case 'pgsql':
                return $this->optimizePostgreSQLGrouping($query, $analysis);
            case 'sqlite':
                return $this->optimizeSQLiteGrouping($query, $analysis);
            default:
                return $query; // No optimization for unknown driver
        }
    }

    /**
     * MySQL-specific GROUP BY optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized GROUP BY
     */
    protected function optimizeMySQLGrouping(string $query, array $analysis): string
    {
        // MySQL GROUP BY optimizations:
        // - Add WITH ROLLUP for aggregate queries when appropriate

        // Check if this is an aggregate query with COUNT, SUM, etc.
        if (
            preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $query) &&
            preg_match('/\bGROUP\s+BY\b/i', $query) &&
            !preg_match('/\bWITH\s+ROLLUP\b/i', $query)
        ) {
            // Check if rollup would be beneficial based on analysis
            if (isset($analysis['potential_issues'])) {
                foreach ($analysis['potential_issues'] as $issue) {
                    if (($issue['type'] ?? '') === 'complex_aggregation') {
                        // Add WITH ROLLUP to the GROUP BY clause when it's a complex aggregation
                        return preg_replace(
                            '/\bGROUP\s+BY\b(.*?)(?=ORDER BY|LIMIT|HAVING|$)/is',
                            'GROUP BY$1 WITH ROLLUP ',
                            $query
                        );
                    }
                }
            }
        }

        return $query;
    }

    /**
     * PostgreSQL-specific GROUP BY optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized GROUP BY
     */
    protected function optimizePostgreSQLGrouping(string $query, array $analysis): string
    {
        // PostgreSQL GROUP BY optimizations
        // Consider CUBE or ROLLUP operations for multi-dimensional aggregation
        return $query;
    }

    /**
     * SQLite-specific GROUP BY optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized GROUP BY
     */
    protected function optimizeSQLiteGrouping(string $query, array $analysis): string
    {
        // SQLite GROUP BY optimizations
        // SQLite has fewer GROUP BY optimization options
        return $query;
    }

    /**
     * Optimize ORDER BY operations in the query
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized ordering
     */
    protected function optimizeOrdering(string $query, array $analysis): string
    {
        // Apply database-specific ORDER BY optimizations
        switch ($this->driverName) {
            case 'mysql':
                return $this->optimizeMySQLOrdering($query, $analysis);
            case 'pgsql':
                return $this->optimizePostgreSQLOrdering($query, $analysis);
            case 'sqlite':
                return $this->optimizeSQLiteOrdering($query, $analysis);
            default:
                return $query; // No optimization for unknown driver
        }
    }

    /**
     * MySQL-specific ORDER BY optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized ORDER BY
     */
    protected function optimizeMySQLOrdering(string $query, array $analysis): string
    {
        // MySQL ORDER BY optimizations:
        // - Use indexed columns for ordering when possible
        // - Limit use of filesort operations

        if (isset($analysis['potential_issues'])) {
            foreach ($analysis['potential_issues'] as $issue) {
                if (($issue['type'] ?? '') === 'filesort' && isset($issue['suggestion'])) {
                    // Replace with a more efficient ordering based on available indexes
                    if (preg_match('/\bORDER\s+BY\s+(.*?)(?=LIMIT|$)/is', $query, $matches)) {
                        return str_replace($matches[0], "ORDER BY {$issue['suggestion']} ", $query);
                    }
                }
            }
        }

        return $query;
    }

    /**
     * PostgreSQL-specific ORDER BY optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized ORDER BY
     */
    protected function optimizePostgreSQLOrdering(string $query, array $analysis): string
    {
        // PostgreSQL ORDER BY optimizations
        return $query;
    }

    /**
     * SQLite-specific ORDER BY optimization
     *
     * @param string $query The original query
     * @param array $analysis The query analysis results
     * @return string The query with optimized ORDER BY
     */
    protected function optimizeSQLiteOrdering(string $query, array $analysis): string
    {
        // SQLite ORDER BY optimizations
        return $query;
    }
}
