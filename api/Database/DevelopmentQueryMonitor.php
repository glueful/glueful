<?php

declare(strict_types=1);

namespace Glueful\Database;

/**
 * Development Query Monitor
 *
 * Provides comprehensive query monitoring, logging, and optimization detection
 * specifically designed for development environments to improve developer experience.
 */
class DevelopmentQueryMonitor
{
    /** @var bool Whether monitoring is enabled */
    private static bool $enabled = false;

    /** @var array Query execution log */
    private static array $queryLog = [];

    /** @var array N+1 query detection data */
    private static array $queryPatterns = [];

    /** @var float Slow query threshold in seconds */
    private static float $slowQueryThreshold = 0.5;

    /** @var int Maximum queries before N+1 warning */
    private static int $nPlusOneThreshold = 10;

    /** @var array Current request query statistics */
    private static array $requestStats = [
        'total_queries' => 0,
        'total_time' => 0.0,
        'slow_queries' => 0,
        'duplicate_queries' => 0
    ];

    /**
     * Enable development query monitoring
     */
    public static function enable(): void
    {
        if (env('APP_ENV') !== 'development') {
            return; // Only enable in development
        }

        self::$enabled = true;
        self::$slowQueryThreshold = (float) env('SLOW_QUERY_THRESHOLD', 0.5);
        self::$nPlusOneThreshold = (int) env('N_PLUS_ONE_THRESHOLD', 10);

        // Register shutdown function to display summary
        register_shutdown_function([self::class, 'displayRequestSummary']);
    }

    /**
     * Log a query execution
     */
    public static function logQuery(string $sql, array $params, float $executionTime, string $purpose = ''): void
    {
        if (!self::$enabled) {
            return;
        }

        $queryHash = md5($sql . serialize($params));
        $normalizedSql = self::normalizeQuery($sql);


        $logEntry = [
            'sql' => $sql,
            'normalized_sql' => $normalizedSql,
            'params' => $params,
            'execution_time' => $executionTime,
            'purpose' => $purpose,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'backtrace' => self::getRelevantBacktrace(),
            'hash' => $queryHash
        ];

        self::$queryLog[] = $logEntry;
        self::updateRequestStats($logEntry);
        self::detectQueryPatterns($sql, $logEntry);

        // Check for slow query
        if ($executionTime > self::$slowQueryThreshold) {
            self::handleSlowQuery($logEntry);
        }

        // Log to file if enabled
        if (env('LOG_QUERIES_TO_FILE', true)) {
            self::logToFile($logEntry);
        }
    }

    /**
     * Update request statistics
     */
    private static function updateRequestStats(array $logEntry): void
    {
        self::$requestStats['total_queries']++;
        self::$requestStats['total_time'] += $logEntry['execution_time'];

        if ($logEntry['execution_time'] > self::$slowQueryThreshold) {
            self::$requestStats['slow_queries']++;
        }

        // Check for duplicate queries
        $hash = $logEntry['hash'];
        $existingQueries = array_filter(self::$queryLog, fn($q) => $q['hash'] === $hash);
        if (count($existingQueries) > 1) {
            self::$requestStats['duplicate_queries']++;
        }
    }

    /**
     * Detect query patterns for N+1 detection
     */
    private static function detectQueryPatterns(string $originalSql, array $logEntry): void
    {
        $pattern = self::extractQueryPattern($originalSql);

        if (!isset(self::$queryPatterns[$pattern])) {
            self::$queryPatterns[$pattern] = [
                'count' => 0,
                'first_seen' => microtime(true),
                'queries' => []
            ];
        }

        self::$queryPatterns[$pattern]['count']++;
        self::$queryPatterns[$pattern]['queries'][] = $logEntry;

        // Check for potential N+1
        if (self::$queryPatterns[$pattern]['count'] > self::$nPlusOneThreshold) {
            self::handlePotentialNPlusOne($pattern, self::$queryPatterns[$pattern]);
        }
    }

    /**
     * Handle slow query detection
     */
    private static function handleSlowQuery(array $logEntry): void
    {
        $message = sprintf(
            "SLOW QUERY DETECTED: %.3fs - %s",
            $logEntry['execution_time'],
            substr($logEntry['sql'], 0, 100) . (strlen($logEntry['sql']) > 100 ? '...' : '')
        );

        error_log($message);

        // Display in browser console if possible (only for non-API requests)
        if (!headers_sent() && env('SHOW_QUERY_WARNINGS', true) && !self::isApiRequest()) {
            echo "<script>console.warn(" . json_encode($message) . ");</script>";
        }
    }

    /**
     * Handle potential N+1 query detection
     */
    private static function handlePotentialNPlusOne(string $pattern, array $patternData): void
    {
        $count = $patternData['count'];
        $timeSpan = microtime(true) - $patternData['first_seen'];

        $message = sprintf(
            "POTENTIAL N+1 QUERY: Pattern '%s' executed %d times in %.3fs",
            $pattern,
            $count,
            $timeSpan
        );

        error_log($message);

        // Display warning (only for non-API requests)
        if (!headers_sent() && env('SHOW_QUERY_WARNINGS', true) && !self::isApiRequest()) {
            echo "<script>console.warn(" . json_encode($message) . ");</script>";
        }

        // Suggest optimization
        $suggestion = self::generateOptimizationSuggestion($pattern, $patternData);
        if ($suggestion) {
            error_log("OPTIMIZATION SUGGESTION: $suggestion");
            if (!headers_sent() && env('SHOW_QUERY_WARNINGS', true) && !self::isApiRequest()) {
                echo "<script>console.info(" . json_encode("💡 " . $suggestion) . ");</script>";
            }
        }
    }

    /**
     * Display request summary
     */
    public static function displayRequestSummary(): void
    {
        if (!self::$enabled || empty(self::$queryLog)) {
            return;
        }

        $stats = self::$requestStats;
        $avgTime = $stats['total_queries'] > 0 ? $stats['total_time'] / $stats['total_queries'] : 0;

        $summary = [
            'total_queries' => $stats['total_queries'],
            'total_time' => round($stats['total_time'], 3),
            'avg_time' => round($avgTime, 3),
            'slow_queries' => $stats['slow_queries'],
            'duplicate_queries' => $stats['duplicate_queries'],
            'memory_peak' => memory_get_peak_usage(true)
        ];

        // Log summary
        error_log("QUERY SUMMARY: " . json_encode($summary));

        // Log duplicate query details if any found
        if ($stats['duplicate_queries'] > 0) {
            self::logDuplicateQueries();
        }

        // Display in browser console (only for non-API requests)
        if (!headers_sent() && env('SHOW_QUERY_SUMMARY', true) && !self::isApiRequest()) {
            echo "<script>console.group('🔍 Database Query Summary');";
            echo "console.table(" . json_encode($summary) . ");";

            if ($stats['slow_queries'] > 0) {
                echo "console.warn('⚠️ Found {$stats['slow_queries']} slow queries');";
            }

            if ($stats['duplicate_queries'] > 0) {
                echo "console.warn('⚠️ Found {$stats['duplicate_queries']} duplicate queries');";
            }

            echo "console.groupEnd();</script>";
        }
    }

    /**
     * Normalize query for pattern detection
     */
    private static function normalizeQuery(string $sql): string
    {
        // Remove specific values, keep structure
        $normalized = preg_replace('/\b\d+\b/', '?', $sql);
        $normalized = preg_replace("/'[^']*'/", '?', $normalized);
        $normalized = preg_replace('/"[^"]*"/', '?', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);


        return trim(strtoupper($normalized));
    }

    /**
     * Extract query pattern for N+1 detection
     */
    private static function extractQueryPattern(string $originalSql): string
    {
        // Extract table and operation pattern from original SQL (before normalization)
        // Handle queries with backticks, quotes around table names
        $pattern = '/^(SELECT|INSERT|UPDATE|DELETE).*?(FROM|INTO|UPDATE)\s+[`"\'"]?(\w+)[`"\'"]?/i';
        if (preg_match($pattern, $originalSql, $matches)) {
            $operation = $matches[1];
            $table = $matches[3];

            // Add more specificity for SELECT queries to avoid false N+1 detection
            if ($operation === 'SELECT') {
                // Check for WHERE IN clauses (batch operations)
                if (preg_match('/WHERE.*?IN\s*\(/i', $originalSql)) {
                    return $operation . ' ' . $table . ' BATCH_IN';
                }
                // Check for specific column selections vs SELECT *
                if (preg_match('/SELECT\s+\*/i', $originalSql)) {
                    return $operation . ' ' . $table . ' ALL_COLUMNS';
                } else {
                    return $operation . ' ' . $table . ' SPECIFIC_COLUMNS';
                }
            }

            // For INSERT, check if it's batch insert
            if ($operation === 'INSERT' && preg_match('/VALUES\s*\([^)]+\)\s*,/i', $originalSql)) {
                return $operation . ' ' . $table . ' BATCH';
            }

            return $operation . ' ' . $table;
        }

        // Try to extract at least the operation type
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\s+/i', $originalSql, $matches)) {
            return $matches[1] . ' UNKNOWN_TABLE';
        }

        return 'UNKNOWN';
    }

    /**
     * Generate optimization suggestion
     */
    private static function generateOptimizationSuggestion(string $pattern, array $patternData): ?string
    {
        $queries = $patternData['queries'];
        $count = $patternData['count'];

        if (strpos($pattern, 'SELECT') === 0) {
            return "Consider using eager loading or a single query with JOINs instead of {$count} " .
                   "separate SELECT queries.";
        }

        if (strpos($pattern, 'INSERT') === 0) {
            return "Consider using batch INSERT or bulk operations instead of {$count} separate INSERT queries.";
        }

        return null;
    }

    /**
     * Get relevant backtrace (excluding framework internals)
     */
    private static function getRelevantBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $relevant = [];

        foreach ($trace as $frame) {
            // Skip framework internal files
            if (isset($frame['file']) && !str_contains($frame['file'], '/api/Database/')) {
                $relevant[] = [
                    'file' => basename($frame['file']),
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function']
                ];

                if (count($relevant) >= 3) {
                    break;
                }
            }
        }

        return $relevant;
    }

    /**
     * Log query to file
     */
    private static function logToFile(array $logEntry): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/queries-' . date('Y-m-d') . '.log';

        $logLine = sprintf(
            "[%s] %.3fs - %s %s\n",
            date('H:i:s'),
            $logEntry['execution_time'],
            $logEntry['purpose'] ? "[{$logEntry['purpose']}]" : '',
            $logEntry['sql']
        );

        file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get current query statistics
     */
    public static function getStats(): array
    {
        return [
            'enabled' => self::$enabled,
            'request_stats' => self::$requestStats,
            'query_count' => count(self::$queryLog),
            'patterns_detected' => count(self::$queryPatterns),
            'slow_query_threshold' => self::$slowQueryThreshold
        ];
    }

    /**
     * Reset monitoring state (useful for testing)
     */
    public static function reset(): void
    {
        self::$queryLog = [];
        self::$queryPatterns = [];
        self::$requestStats = [
            'total_queries' => 0,
            'total_time' => 0.0,
            'slow_queries' => 0,
            'duplicate_queries' => 0
        ];
    }

    /**
     * Log details about duplicate queries
     */
    private static function logDuplicateQueries(): void
    {
        $hashes = [];
        foreach (self::$queryLog as $query) {
            $hash = $query['hash'];
            if (!isset($hashes[$hash])) {
                $hashes[$hash] = [];
            }
            $hashes[$hash][] = $query;
        }
        foreach ($hashes as $hash => $queries) {
            if (count($queries) > 1) {
                $sql = $queries[0]['sql'];
                $params = $queries[0]['params'] ?? [];
                $message = "DUPLICATE QUERY: " . $sql . " | Params: " . json_encode($params) .
                          " | Count: " . count($queries);
                error_log($message);
                // Log backtraces to identify source
                foreach ($queries as $i => $query) {
                    $backtrace = $query['backtrace'] ?? [];
                    if (!empty($backtrace)) {
                        $traceItems = array_map(function ($frame) {
                            if (is_string($frame)) {
                                return $frame;
                            }
                            if (is_array($frame)) {
                                $location = '';
                                if (isset($frame['file']) && isset($frame['line'])) {
                                    $location = basename($frame['file']) . ':' . $frame['line'];
                                }
                                if (isset($frame['function'])) {
                                    $function = $frame['function'];
                                    if (isset($frame['class'])) {
                                        $function = $frame['class'] . $frame['type'] . $function;
                                    }
                                    return $function . ($location ? ' (' . $location . ')' : '');
                                }
                                return $location ?: 'Unknown';
                            }
                            return 'Unknown';
                        }, array_slice($backtrace, 0, 3));
                        $trace = "  Instance " . ($i + 1) . ": " . implode(' -> ', $traceItems);
                        error_log($trace);
                    }
                }
            }
        }
    }

    /**
     * Check if the current request is an API request
     */
    private static function isApiRequest(): bool
    {
        // Check if Content-Type header indicates JSON API
        $contentType = $_SERVER['HTTP_ACCEPT'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        // Check if request URI indicates API endpoint
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_starts_with($requestUri, '/v') && preg_match('/^\/v\d+\//', $requestUri)) {
            return true;
        }

        // Check if we're in API context (CLI or API entry point)
        if (PHP_SAPI === 'cli' || defined('API_REQUEST')) {
            return true;
        }

        return false;
    }
}
