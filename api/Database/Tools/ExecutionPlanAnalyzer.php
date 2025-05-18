<?php

namespace Glueful\Database\Tools;

use Glueful\Database\Connection;
use PDO;

/**
 * Query Execution Plan Analyzer
 *
 * Provides analysis of database query execution plans for different database engines:
 * - Retrieves execution plans from MySQL, PostgreSQL, and SQLite
 * - Analyzes plans for performance issues and bottlenecks
 * - Generates optimization recommendations
 * - Identifies missing indexes and inefficient joins
 *
 * @since 0.27.0
 */
class ExecutionPlanAnalyzer
{
    /**
     * Database connection
     *
     * @var Connection
     */
    private $connection;

       /** @var PDO Active database connection */
    protected PDO $pdo;

    /**
     * Constructor
     *
     * @param Connection $connection Database connection
     */


    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->pdo = $connection->getPDO();
    }

    /**
     * Get the execution plan for a query
     *
     * @param string $query SQL query to analyze
     * @param array $params Query parameters
     * @return array Execution plan details
     * @throws \RuntimeException When execution plan not supported for the current driver
     */
    public function getExecutionPlan(string $query, array $params = []): array
    {
        $driver = $this->connection->getDriverName();

        return match ($driver) {
            'mysql' => $this->getMySQLExecutionPlan($query, $params),
            'pgsql' => $this->getPostgreSQLExecutionPlan($query, $params),
            'sqlite' => $this->getSQLiteExecutionPlan($query, $params),
            default => throw new \RuntimeException("Execution plan not supported for driver: {$driver}")
        };
    }

    /**
     * Analyze execution plan and provide recommendations
     *
     * @param array $plan The execution plan to analyze
     * @return array Issues and recommendations
     */
    public function analyzeExecutionPlan(array $plan): array
    {
        $issues = [];
        $recommendations = [];

        // Determine the database type from the plan structure
        $dbType = $this->detectDatabaseType($plan);

        switch ($dbType) {
            case 'mysql':
                return $this->analyzeMySQLPlan($plan);
            case 'pgsql':
                return $this->analyzePostgreSQLPlan($plan);
            case 'sqlite':
                return $this->analyzeSQLitePlan($plan);
            default:
                $issues[] = 'Unknown database type, unable to provide specific recommendations';
        }

        return [
            'issues' => $issues,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Get MySQL execution plan (EXPLAIN)
     *
     * @param string $query SQL query to analyze
     * @param array $params Query parameters
     * @return array MySQL execution plan
     */
    private function getMySQLExecutionPlan(string $query, array $params = []): array
    {
        // Prepare the EXPLAIN query
        $explainQuery = "EXPLAIN FORMAT=JSON " . $query;

        // Execute the query with parameters
        $stmt = $this->pdo->prepare($explainQuery);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        // Get the execution plan
        $plan = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Parse the JSON format if available
        if (isset($plan[0]['EXPLAIN'])) {
            return json_decode($plan[0]['EXPLAIN'], true);
        }

        return $plan;
    }

    /**
     * Get PostgreSQL execution plan (EXPLAIN)
     *
     * @param string $query SQL query to analyze
     * @param array $params Query parameters
     * @return array PostgreSQL execution plan
     */
    private function getPostgreSQLExecutionPlan(string $query, array $params = []): array
    {
        // Prepare the EXPLAIN query
        $explainQuery = "EXPLAIN (FORMAT JSON, ANALYZE, VERBOSE, BUFFERS) " . $query;

        // Execute the query with parameters
        $stmt = $this->pdo->prepare($explainQuery);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        // Get the execution plan
        $plan = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Parse the JSON format
        if (isset($plan[0]['QUERY PLAN'])) {
            return json_decode($plan[0]['QUERY PLAN'], true);
        }

        return $plan;
    }

    /**
     * Get SQLite execution plan (EXPLAIN QUERY PLAN)
     *
     * @param string $query SQL query to analyze
     * @param array $params Query parameters
     * @return array SQLite execution plan
     */
    private function getSQLiteExecutionPlan(string $query, array $params = []): array
    {
        // Prepare the EXPLAIN query
        $explainQuery = "EXPLAIN QUERY PLAN " . $query;

        // Execute the query with parameters
        $stmt = $this->pdo->prepare($explainQuery);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        // Get the execution plan
        $plan = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Structure the results
        $result = [];
        foreach ($plan as $row) {
            $result[] = [
                'id' => $row['id'] ?? null,
                'parent' => $row['parent'] ?? null,
                'detail' => $row['detail'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Detect database type from plan structure
     *
     * @param array $plan The execution plan to analyze
     * @return string Database type ('mysql', 'pgsql', 'sqlite', or 'unknown')
     */
    private function detectDatabaseType(array $plan): string
    {
        if (empty($plan)) {
            return 'unknown';
        }

        // Check MySQL specific structures
        if (isset($plan[0]['id']) || isset($plan['query_block'])) {
            return 'mysql';
        }

        // Check PostgreSQL specific structures
        if (isset($plan[0]['Plan']) || isset($plan['Plan'])) {
            return 'pgsql';
        }

        // Check SQLite specific structures
        if (isset($plan[0]['detail'])) {
            return 'sqlite';
        }

        return 'unknown';
    }

    /**
     * Analyze MySQL execution plan
     *
     * @param array $plan MySQL execution plan
     * @return array Issues and recommendations
     */
    private function analyzeMySQLPlan(array $plan): array
    {
        $issues = [];
        $recommendations = [];

        // Extract plan details from MySQL format
        $details = $plan['query_block'] ?? $plan;

        // Check for full table scans
        if ($this->hasFullTableScan($details)) {
            $issues[] = 'Query is performing a full table scan without using indexes';
            $recommendations[] = 'Consider adding an index on the columns used in WHERE clauses';
        }

        // Check for filesort operations
        if ($this->hasFilesort($details)) {
            $issues[] = 'Query is using filesort which can be inefficient for large datasets';
            $recommendations[] = 'Add an index on the columns used in ORDER BY clause';
        }

        // Check for temporary tables
        if ($this->hasTemporaryTable($details)) {
            $issues[] = 'Query is creating temporary tables which can slow down execution';
            $recommendations[] = 'Simplify JOIN conditions or add appropriate indexes';
        }

        // Check for inefficient join operations
        if ($this->hasIneffientJoins($details)) {
            $issues[] = 'Query has inefficient join operations';
            $recommendations[] = 'Ensure proper indexes on join columns';
        }

        return [
            'issues' => $issues,
            'recommendations' => $recommendations,
            'details' => $this->extractMySQLPlanDetails($details)
        ];
    }

    /**
     * Analyze PostgreSQL execution plan
     *
     * @param array $plan PostgreSQL execution plan
     * @return array Issues and recommendations
     */
    private function analyzePostgreSQLPlan(array $plan): array
    {
        $issues = [];
        $recommendations = [];

        // Extract plan from PostgreSQL format
        $planNode = $plan[0]['Plan'] ?? ($plan['Plan'] ?? []);

        // Check for sequential scans
        if ($this->hasSequentialScan($planNode)) {
            $issues[] = 'Query is performing a sequential scan instead of using an index';
            $recommendations[] = 'Consider creating an index on the columns used in WHERE clauses';
        }

        // Check for hash joins (might be inefficient for large tables)
        if ($this->hasHashJoin($planNode)) {
            $issues[] = 'Query is using hash joins which can be memory intensive';
            $recommendations[] = 'For large tables, consider adding indexes that enable merge joins instead';
        }

        // Check for high cost operations
        if ($this->hasHighCost($planNode)) {
            $issues[] = 'Query has operations with high cost';
            $recommendations[] = 'Simplify the query or add more specific indexes';
        }

        return [
            'issues' => $issues,
            'recommendations' => $recommendations,
            'details' => $this->extractPostgreSQLPlanDetails($planNode)
        ];
    }

    /**
     * Analyze SQLite execution plan
     *
     * @param array $plan SQLite execution plan
     * @return array Issues and recommendations
     */
    private function analyzeSQLitePlan(array $plan): array
    {
        $issues = [];
        $recommendations = [];

        // Look for full scan operations
        foreach ($plan as $step) {
            $detail = $step['detail'] ?? '';

            if (strpos($detail, 'SCAN TABLE') !== false && strpos($detail, 'USING INDEX') === false) {
                $issues[] = 'Query is performing a full table scan: ' . $detail;
                $recommendations[] = 'Consider adding an index on the columns used in this query';
            }

            if (strpos($detail, 'TEMP TABLE') !== false) {
                $issues[] = 'Query is using a temporary table: ' . $detail;
                $recommendations[] = 'Simplify your query to avoid temporary tables';
            }
        }

        return [
            'issues' => $issues,
            'recommendations' => $recommendations,
            'details' => $plan
        ];
    }

    /**
     * Check if MySQL plan contains a full table scan
     *
     * @param array $plan MySQL execution plan details
     * @return bool True if plan contains full table scan
     */
    private function hasFullTableScan(array $plan): bool
    {
        // Check for 'ALL' in type field, indicating full table scan
        if (isset($plan['table']) && isset($plan['table']['access_type']) && $plan['table']['access_type'] === 'ALL') {
            return true;
        }

        // Check nested structures like joins
        foreach (['table', 'tables', 'nested_loop'] as $key) {
            if (isset($plan[$key]) && is_array($plan[$key])) {
                foreach ((array)$plan[$key] as $item) {
                    if (is_array($item) && $this->hasFullTableScan($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if MySQL plan contains a filesort operation
     *
     * @param array $plan MySQL execution plan details
     * @return bool True if plan contains filesort
     */
    private function hasFilesort(array $plan): bool
    {
        // Check for 'using_filesort' flag
        if (isset($plan['table']) && isset($plan['table']['using_filesort']) && $plan['table']['using_filesort']) {
            return true;
        }

        // Check nested structures
        foreach (['table', 'tables', 'nested_loop'] as $key) {
            if (isset($plan[$key]) && is_array($plan[$key])) {
                foreach ((array)$plan[$key] as $item) {
                    if (is_array($item) && $this->hasFilesort($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if MySQL plan contains temporary table usage
     *
     * @param array $plan MySQL execution plan details
     * @return bool True if plan contains temporary table
     */
    private function hasTemporaryTable(array $plan): bool
    {
        // Check for 'using_temporary_table' flag
        if (
            isset($plan['table'])
            && isset($plan['table']['using_temporary_table'])
            && $plan['table']['using_temporary_table']
        ) {
            return true;
        }

        // Check nested structures
        foreach (['table', 'tables', 'nested_loop'] as $key) {
            if (isset($plan[$key]) && is_array($plan[$key])) {
                foreach ((array)$plan[$key] as $item) {
                    if (is_array($item) && $this->hasTemporaryTable($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if MySQL plan contains inefficient join operations
     *
     * @param array $plan MySQL execution plan details
     * @return bool True if plan contains inefficient joins
     */
    private function hasIneffientJoins(array $plan): bool
    {
        // Check for inefficient join types (e.g., ALL)
        if (isset($plan['nested_loop'])) {
            foreach ($plan['nested_loop'] as $join) {
                if (
                    isset($join['table'])
                    && isset($join['table']['access_type'])
                    && $join['table']['access_type'] === 'ALL'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if PostgreSQL plan contains sequential scan
     *
     * @param array $plan PostgreSQL execution plan node
     * @return bool True if plan contains sequential scan
     */
    private function hasSequentialScan(array $plan): bool
    {
        // Check if the current node is a sequential scan
        if (isset($plan['Node Type']) && $plan['Node Type'] === 'Seq Scan') {
            return true;
        }

        // Check child plans
        if (isset($plan['Plans']) && is_array($plan['Plans'])) {
            foreach ($plan['Plans'] as $childPlan) {
                if ($this->hasSequentialScan($childPlan)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if PostgreSQL plan contains hash join
     *
     * @param array $plan PostgreSQL execution plan node
     * @return bool True if plan contains hash join
     */
    private function hasHashJoin(array $plan): bool
    {
        // Check if the current node is a hash join
        if (isset($plan['Node Type']) && $plan['Node Type'] === 'Hash Join') {
            return true;
        }

        // Check child plans
        if (isset($plan['Plans']) && is_array($plan['Plans'])) {
            foreach ($plan['Plans'] as $childPlan) {
                if ($this->hasHashJoin($childPlan)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if PostgreSQL plan contains high cost operations
     *
     * @param array $plan PostgreSQL execution plan node
     * @return bool True if plan contains high cost operations
     */
    private function hasHighCost(array $plan): bool
    {
        // Check if the current node has a high cost (arbitrary threshold)
        $highCostThreshold = 1000;
        if (isset($plan['Total Cost']) && $plan['Total Cost'] > $highCostThreshold) {
            return true;
        }

        // Check child plans
        if (isset($plan['Plans']) && is_array($plan['Plans'])) {
            foreach ($plan['Plans'] as $childPlan) {
                if ($this->hasHighCost($childPlan)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract important details from MySQL execution plan
     *
     * @param array $plan MySQL execution plan details
     * @return array Simplified plan details
     */
    private function extractMySQLPlanDetails(array $plan): array
    {
        $details = [];

        // Extract table information
        if (isset($plan['table'])) {
            $table = $plan['table'];
            $details['table_name'] = $table['table_name'] ?? 'unknown';
            $details['access_type'] = $table['access_type'] ?? 'unknown';
            $details['rows_examined_per_scan'] = $table['rows_examined_per_scan'] ?? 0;
            $details['used_key'] = $table['key'] ?? null;
            $details['used_key_parts'] = $table['used_key_parts'] ?? [];
            $details['filtered'] = $table['filtered'] ?? 100;
        }

        // Extract join information
        if (isset($plan['nested_loop'])) {
            $details['joins'] = [];
            foreach ($plan['nested_loop'] as $join) {
                $details['joins'][] = $this->extractMySQLPlanDetails($join);
            }
        }

        return $details;
    }

    /**
     * Extract important details from PostgreSQL execution plan
     *
     * @param array $plan PostgreSQL execution plan node
     * @return array Simplified plan details
     */
    private function extractPostgreSQLPlanDetails(array $plan): array
    {
        $details = [];

        // Extract node information
        $details['node_type'] = $plan['Node Type'] ?? 'unknown';
        $details['total_cost'] = $plan['Total Cost'] ?? 0;
        $details['startup_cost'] = $plan['Startup Cost'] ?? 0;
        $details['plan_rows'] = $plan['Plan Rows'] ?? 0;
        $details['actual_rows'] = $plan['Actual Rows'] ?? null;
        $details['actual_time'] = isset($plan['Actual Total Time']) ? $plan['Actual Total Time'] : null;

        // For table scans, include the relation name
        if (isset($plan['Relation Name'])) {
            $details['table_name'] = $plan['Relation Name'];
        }

        // Include index information if available
        if (isset($plan['Index Name'])) {
            $details['index_name'] = $plan['Index Name'];
        }

        // Extract child plans
        if (isset($plan['Plans']) && is_array($plan['Plans'])) {
            $details['child_plans'] = [];
            foreach ($plan['Plans'] as $childPlan) {
                $details['child_plans'][] = $this->extractPostgreSQLPlanDetails($childPlan);
            }
        }

        return $details;
    }

    /**
     * Bind parameters to a prepared statement
     *
     * @param \PDOStatement $stmt Prepared statement
     * @param array $params Parameters to bind
     * @return void
     */
    private function bindParams($stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $paramName = is_numeric($key) ? ($key + 1) : $key;
            $stmt->bindValue(
                is_numeric($paramName) ? $paramName : ":{$paramName}",
                $value,
                $this->getPDOType($value)
            );
        }
    }

    /**
     * Get PDO parameter type for a value
     *
     * @param mixed $value Value to check
     * @return int PDO parameter type
     */
    private function getPDOType($value): int
    {
        return match (true) {
            is_int($value) => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            is_null($value) => \PDO::PARAM_NULL,
            default => \PDO::PARAM_STR
        };
    }

    /**
     * Get configuration from database settings
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @return mixed Configuration value
     */
    private function getConfig(string $key, $default = null)
    {
        // Try to get configuration from database.profiler settings
        if (function_exists('config')) {
            return config("database.profiler.{$key}", $default);
        }

        return $default;
    }
}
