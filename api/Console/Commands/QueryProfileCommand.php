<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Tools\QueryProfilerService;
use Glueful\Database\Tools\ExecutionPlanAnalyzer;
use Glueful\Database\Tools\QueryPatternRecognizer;

/**
 * Database Query Profiling Command
 *
 * Provides comprehensive query analysis and profiling:
 * - Executes and profiles SQL queries
 * - Shows detailed execution metrics
 * - Analyzes execution plans
 * - Identifies performance bottlenecks
 * - Recommends query optimizations
 * - Supports multiple output formats
 *
 * @package Glueful\Console\Commands
 * @since 0.27.0
 */

class QueryProfileCommand extends Command
{
    /** @var QueryBuilder Database query builder instance */
    protected QueryBuilder $db;

    /** @var Connection Database connection */
    private Connection $connection;


     /** @var QueryProfilerService Query profiler service */
    private QueryProfilerService $profilerService;

    /** @var ExecutionPlanAnalyzer Execution plan analyzer */
    private ExecutionPlanAnalyzer $planAnalyzer;

    /** @var QueryPatternRecognizer Query pattern recognizer */
    private QueryPatternRecognizer $patternRecognizer;

    public function __construct()
    {
        $this->connection = new Connection();
        $this->profilerService = new QueryProfilerService();
        $this->planAnalyzer = new ExecutionPlanAnalyzer($this->connection);
        $this->patternRecognizer = new QueryPatternRecognizer();
    }


    /**
     * Get Command Name
     *
     * @return string Command identifier
    */
    public function getName(): string
    {
        return 'db:profile';
    }

    /**
     * Get Command Description
     *
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return 'Profile a database query';
    }

    /**
     * Get Command Help
     *
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  db:profile [options]

Description:
  Profiles and analyzes SQL queries for performance optimization.
  Provides execution metrics, plan analysis, and optimization recommendations.

Options:
  -q, --query=SQL         SQL query to profile (required unless --file is used)
  -f, --file=PATH         File containing SQL query to profile
  -e, --explain           Show execution plan analysis
  -p, --patterns          Detect query patterns and provide recommendations
  -o, --output=FORMAT     Output format (table, json) (default: table)
  -h, --help              Display this help message
HELP;
    }


    public function execute(array $args = []): int
    {
        // Display help if requested
        if (isset($args['h']) || isset($args['help'])) {
            echo $this->getHelp() . PHP_EOL;
            return self::SUCCESS;
        }

        // Get query from arguments or file
        $query = $this->getQuery($args);
        if (empty($query)) {
            $this->outputError("Error: No query provided. Use --query or --file option.");
            return self::INVALID;
        }

        // Parse parameters if provided
        $params = $this->parseParams($args);

        // Determine output format
        $format = $args['o'] ?? $args['output'] ?? 'table';

        try {
            // Profile the query
            $profile = $this->profileQuery($query, $params);

            // Get execution plan if requested
            if (isset($args['e']) || isset($args['explain'])) {
                $plan = $this->planAnalyzer->getExecutionPlan($query, $params);
                $analysis = $this->planAnalyzer->analyzeExecutionPlan($plan);
                $profile['execution_plan'] = $plan;
                $profile['plan_analysis'] = $analysis;
            }

            // Identify query patterns if requested
            if (isset($args['p']) || isset($args['patterns'])) {
                $patterns = $this->patternRecognizer->recognizePatterns($query);
                $profile['patterns'] = $patterns;
            }

            // Output the results
            $this->outputResults($profile, $format);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->outputError("Error: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Get query from arguments or file
     *
     * @param array $args Command line arguments
     * @return string SQL query
    */
    private function getQuery(array $args): string
    {
        // Get query directly from arguments
        if (isset($args['q'])) {
            return $args['q'];
        }

        if (isset($args['query'])) {
            return $args['query'];
        }

        // Get query from file
        if (isset($args['f']) || isset($args['file'])) {
            $file = $args['f'] ?? $args['file'];
            if (!file_exists($file)) {
                $this->outputError("Error: File not found: {$file}");
                return '';
            }

            return file_get_contents($file);
        }

        return '';
    }

    /**
     * Parse query parameters
     *
     * @param array $args Command line arguments
     * @return array Query parameters
     */
    private function parseParams(array $args): array
    {
        $params = [];

        if (isset($args['params'])) {
            // Convert JSON string to array
            try {
                $jsonParams = json_decode($args['params'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($jsonParams)) {
                    $params = $jsonParams;
                }
            } catch (\JsonException $e) {
                $this->outputError("Warning: Invalid JSON in parameters. Using empty parameters.");
            }
        }

        return $params;
    }

    /**
     * Profile a database query
     *
     * @param string $query SQL query to profile
     * @param array $params Query parameters
     * @return array Profile results
     */
    private function profileQuery(string $query, array $params): array
    {
        $profileData = [];

        $this->db = new QueryBuilder($this->connection->getPDO(), $this->connection->getDriver());
        // Use query profiler service to execute and profile the query
        $result = $this->profilerService->profile($query, $params, function () use ($query, $params) {
            return $this->db->select($query, $params);
        });

        // Get the profile information
        $profiles = $this->profilerService->getRecentProfiles(1);

        if (!empty($profiles)) {
            $profileData = $profiles[0];
            $profileData['result_count'] = is_countable($result) ? count($result) : 'N/A';
            $profileData['result_sample'] = $this->getSampleResults($result);
        }

        return $profileData;
    }

    /**
     * Get a sample of the results
     *
     * @param mixed $result Query results
     * @return array Sample of results (limited to first few records)
     */
    private function getSampleResults($result): array
    {
        if (!is_array($result) || empty($result)) {
            return [];
        }

        // Limit to first 5 records for display
        $sample = array_slice($result, 0, 5);

        return $sample;
    }

    /**
     * Output profile results
     *
     * @param array $profile Profile data
     * @param string $format Output format (table or json)
     * @return void
     */
    private function outputResults(array $profile, string $format): void
    {
        if ($format === 'json') {
            echo json_encode($profile, JSON_PRETTY_PRINT) . PHP_EOL;
            return;
        }

        // Default to table format
        $this->outputTableFormat($profile);
    }

    /**
     * Output results in table format
     *
     * @param array $profile Profile data
     * @return void
     */
    private function outputTableFormat(array $profile): void
    {
        echo "=== Query Profile Results ===" . PHP_EOL . PHP_EOL;

        echo "Query:" . PHP_EOL;
        echo $profile['sql'] . PHP_EOL . PHP_EOL;

        echo "Parameters:" . PHP_EOL;
        echo json_encode($profile['params'] ?? [], JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

        echo "Execution Metrics:" . PHP_EOL;
        echo "Duration:     " . number_format($profile['duration'] ?? 0, 2) . " ms" . PHP_EOL;
        echo "Memory Usage: " . $this->formatBytes($profile['memory_delta'] ?? 0) . PHP_EOL;
        echo "Row Count:    " . ($profile['result_count'] ?? 'N/A') . PHP_EOL . PHP_EOL;

        // Output execution plan if available
        if (isset($profile['execution_plan'])) {
            echo "Execution Plan:" . PHP_EOL;
            echo $this->formatExecutionPlan($profile['execution_plan']) . PHP_EOL . PHP_EOL;

            if (isset($profile['plan_analysis'])) {
                echo "Issues:" . PHP_EOL;
                foreach ($profile['plan_analysis']['issues'] ?? [] as $issue) {
                    echo "- " . $issue . PHP_EOL;
                }
                echo PHP_EOL;

                echo "Recommendations:" . PHP_EOL;
                foreach ($profile['plan_analysis']['recommendations'] ?? [] as $recommendation) {
                    echo "- " . $recommendation . PHP_EOL;
                }
                echo PHP_EOL;
            }
        }

        // Output pattern information if available
        if (isset($profile['patterns']) && !empty($profile['patterns'])) {
            echo "Query Patterns:" . PHP_EOL;
            foreach ($profile['patterns'] as $patternName => $info) {
                echo "Pattern: " . $patternName . PHP_EOL;
                echo "Description:    " . $info['description'] . PHP_EOL;
                echo "Recommendation: " . $info['recommendation'] . PHP_EOL . PHP_EOL;
            }
        }

        // Output result sample if available
        if (!empty($profile['result_sample'])) {
            echo "Result Sample:" . PHP_EOL;
            print_r($profile['result_sample']);
            echo PHP_EOL;
        }
    }

    /**
     * Format execution plan for display
     *
     * @param array $plan Execution plan data
     * @return string Formatted execution plan
     */
    private function formatExecutionPlan(array $plan): string
    {
        // Simple implementation - can be extended for better formatting
        return json_encode($plan, JSON_PRETTY_PRINT);
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted size string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Output error message
     *
     * @param string $message Error message
     * @return void
    */
    private function outputError(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
