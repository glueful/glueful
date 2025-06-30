<?php

namespace Glueful\Database;

use Monolog\Level;
use Psr\Log\LoggerInterface;

/**
 * Database Query Logger
 *
 * Framework-level database infrastructure logging with:
 * - Slow query detection (configurable threshold)
 * - N+1 query pattern detection
 * - Performance monitoring and statistics
 * - Event emission for application-level logging
 *
 * Applications should listen to QueryExecutedEvent for business-specific logging.
 */
class QueryLogger
{
    /** @var LoggerInterface|null Framework logger for infrastructure concerns */
    protected ?LoggerInterface $logger;

    /** @var bool Enable debug mode */
    protected bool $debugMode = false;

    /** @var bool Enable query timing */
    protected bool $enableTiming = false;

    /** @var array Recent queries for N+1 detection */
    protected array $recentQueries = [];

    /** @var int Threshold for N+1 query detection */
    protected int $n1Threshold = 5;

    /** @var int Time window for N+1 detection in seconds */
    protected int $n1TimeWindow = 5;

    /** @var array Recent query history */
    protected array $queryLog = [];

    /** @var int Maximum query log size */
    protected int $maxLogSize = 100;

    /** @var array Query statistics */
    protected array $stats = [
        'total' => 0,
        'select' => 0,
        'insert' => 0,
        'update' => 0,
        'delete' => 0,
        'other' => 0,
        'error' => 0,
        'total_time' => 0
    ];

    /** @var array Framework logging configuration */
    protected array $config;


    /**
     * Create a new query logger instance
     *
     * @param LoggerInterface|null $logger Framework logger instance
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;

        // Load framework logging configuration
        $this->config = config('logging.framework.slow_queries', [
            'enabled' => true,
            'threshold_ms' => 200,
            'log_level' => 'warning'
        ]);

        // Default configuration based on application settings
        $this->debugMode = config('app.debug');
        $this->enableTiming = $this->debugMode;
    }

    /**
     * Configure logging options
     *
     * @param bool $enableDebug Enable debug mode
     * @param bool $enableTiming Enable query timing
     * @param int $maxLogSize Maximum query log size
     * @return self
     */
    public function configure(bool $enableDebug = true, bool $enableTiming = true, int $maxLogSize = 100): self
    {
        $this->debugMode = $enableDebug;
        $this->enableTiming = $enableTiming;
        $this->maxLogSize = $maxLogSize;

        return $this;
    }

    /**
     * Set framework logger for infrastructure logging
     *
     * @param LoggerInterface $logger Framework logger instance
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the framework logger
     *
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get query log
     *
     * @return array Query execution history
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Get query statistics
     *
     * @return array Query statistics
     */
    public function getStatistics(): array
    {
        return $this->stats;
    }

    /**
     * Clear query log and reset statistics
     *
     * @return self
     */
    public function clear(): self
    {
        $this->queryLog = [];
        $this->stats = [
            'total' => 0,
            'select' => 0,
            'insert' => 0,
            'update' => 0,
            'delete' => 0,
            'other' => 0,
            'error' => 0,
            'total_time' => 0
        ];
        return $this;
    }

    /**
     * Log a query execution
     *
     * @param string $sql SQL statement
     * @param array $params Query parameters
     * @param float|string|null $startTime Query start time or timer ID
     * @param \Throwable|null $error Error if one occurred
     * @param string|null $purpose Business purpose of the query
     * @return float|null Execution time in milliseconds if timing was enabled
     */
    public function logQuery(
        string $sql,
        array $params = [],
        $startTime = null,
        ?\Throwable $error = null,
        ?string $purpose = null
    ): ?float {
        if (!$this->debugMode) {
            return null;
        }

        // Determine query type
        $queryType = $this->determineQueryType($sql);

        // Calculate execution time
        $executionTime = null;
        if ($startTime !== null && $this->enableTiming) {
            if (is_float($startTime)) {
                // Using simple microtime
                $executionTime = (microtime(true) - $startTime) * 1000; // Convert to ms
                $executionTime = round($executionTime, 2);
            }
        }

        // Update statistics
        $this->stats['total']++;
        $this->stats[$queryType]++;

        if ($error) {
            $this->stats['error']++;
        }

        if ($executionTime !== null) {
            $this->stats['total_time'] += $executionTime;
        }

        // Extract table names
        $tables = $this->extractTableNames($sql);

        // Calculate query complexity
        $complexity = $this->analyzeQueryComplexity($sql);

        // Create log entry with sanitized parameters
        $logEntry = [
            'sql' => $sql,
            'params' => $this->sanitizeQueryParams($params),
            'type' => $queryType,
            'tables' => $tables,
            'complexity' => $complexity,
            'time' => $executionTime ? $executionTime . ' ms' : null,
            'timestamp' => date('Y-m-d H:i:s'),
            'purpose' => $purpose,
            'error' => $error ? $error->getMessage() : null
        ];

        // Add to internal log with size limit
        $this->queryLog[] = $logEntry;
        if (count($this->queryLog) > $this->maxLogSize) {
            array_shift($this->queryLog);
        }

        // Add to recent queries for N+1 detection
        $this->addToRecentQueries($sql, $executionTime, $tables);

        // Send to development query monitor if enabled
        if (class_exists('\\Glueful\\Database\\DevelopmentQueryMonitor')) {
            \Glueful\Database\DevelopmentQueryMonitor::logQuery(
                $sql,
                $params,
                $executionTime ? $executionTime / 1000 : 0.0, // Convert ms to seconds
                $purpose ?? ''
            );
        }

        // Prepare log context
        $context = [
            'sql' => $sql,
            'params' => $this->sanitizeQueryParams($params),
            'query_type' => $queryType,
            'tables' => $tables,
            'complexity' => $complexity,
            'type' => 'database_query'
        ];

        if ($purpose) {
            $context['purpose'] = $purpose;
        }

        if ($executionTime) {
            $context['execution_time'] = $executionTime;
        }

        // Framework logs slow queries (configurable infrastructure concern)
        if ($this->config['enabled'] && $executionTime && $executionTime > $this->config['threshold_ms']) {
            $this->logSlowQuery($sql, $executionTime);
        }

        // Emit QueryExecutedEvent for application-level logging
        // Applications can listen to this event for business-specific query logging
        if (class_exists('\\Glueful\\Events\\Event')) {
            $metadata = [];
            if ($error) {
                $metadata['error'] = [
                    'message' => $error->getMessage(),
                    'code' => $error->getCode(),
                    'file' => $error->getFile(),
                    'line' => $error->getLine()
                ];
            }
            if ($purpose) {
                $metadata['purpose'] = $purpose;
            }
            if ($complexity > 0) {
                $metadata['complexity'] = $complexity;
            }
            if (!empty($tables)) {
                $metadata['tables'] = $tables;
            }

            \Glueful\Events\Event::dispatch(new \Glueful\Events\Database\QueryExecutedEvent(
                $sql,
                $params,
                $executionTime ? $executionTime / 1000 : 0.0, // Convert ms to seconds
                'default', // connection name
                $metadata
            ));
        }

        // Check for N+1 query patterns after logging
        $this->detectN1Patterns();

        return $executionTime;
    }

    /**
     * Start timing a query
     *
     * @param string|null $operation Optional operation name (for backward compatibility)
     * @return float Current microtime
     */
    public function startTiming(?string $operation = null): float
    {
        return microtime(true);
    }

    /**
     * End timing for an operation
     *
     * @param float $startTime Start time from startTiming()
     * @return float|null Duration in milliseconds
     */
    public function endTiming(float $startTime): ?float
    {
        if (!$this->enableTiming) {
            return null;
        }

        $duration = (microtime(true) - $startTime) * 1000;
        return round($duration, 2);
    }

    /**
     * Log a database-related event
     *
     * @param string $message Event message
     * @param array $context Event context
     * @param Level|string $level Log level
     * @return void
     */
    public function logEvent(string $message, array $context = [], $level = Level::Debug): void
    {
        if (!$this->debugMode && ($level === Level::Debug || $level === 'debug')) {
            return;
        }

        // Add standard context
        $context['type'] = 'database_event';

        // Convert string level to Monolog Level if needed
        if (is_string($level)) {
            $level = match (strtolower($level)) {
                'info' => Level::Info,
                'warning' => Level::Warning,
                'error' => Level::Error,
                'notice' => Level::Notice,
                'critical' => Level::Critical,
                'alert' => Level::Alert,
                'emergency' => Level::Emergency,
                default => Level::Debug,
            };
        }

        // Log the event through framework logger
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Log slow query to framework logger (framework concern)
     *
     * @param string $sql SQL statement
     * @param float $executionTime Execution time in milliseconds
     * @return void
     */
    private function logSlowQuery(string $sql, float $executionTime): void
    {
        if (!$this->logger) {
            return;
        }

        $this->logger->log($this->config['log_level'], 'Slow query detected', [
            'type' => 'performance',
            'message' => 'Database query exceeded threshold',
            'execution_time_ms' => round($executionTime, 2),
            'threshold_ms' => $this->config['threshold_ms'],
            'sql' => $this->sanitizeSql($sql),
            'timestamp' => date('c')
        ]);
    }

    /**
     * Sanitize SQL for framework logging (remove potential sensitive data)
     *
     * @param string $sql SQL statement
     * @return string Sanitized SQL
     */
    private function sanitizeSql(string $sql): string
    {
        // Remove potential sensitive data from SQL for framework logging
        return preg_replace('/\b\d{16,}\b/', '***', $sql); // Hide credit card numbers
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebugEnabled(): bool
    {
        return $this->debugMode;
    }

    /**
     * Sanitize query parameters for logging (remove sensitive data)
     *
     * @param array $params Query parameters
     * @return array Sanitized parameters
     */
    protected function sanitizeQueryParams(array $params): array
    {
        $sanitized = [];
        foreach ($params as $key => $value) {
            // Mask sensitive parameters
            if (is_string($key) && preg_match('/(password|token|secret|key|auth|credential)/i', $key)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeQueryParams($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Determine query type from SQL statement
     *
     * @param string $sql SQL statement
     * @return string Query type (select, insert, update, delete, other)
     */
    protected function determineQueryType(string $sql): string
    {
        $sql = trim(strtolower($sql));

        if (strpos($sql, 'select') === 0) {
            return 'select';
        } elseif (strpos($sql, 'insert') === 0) {
            return 'insert';
        } elseif (strpos($sql, 'update') === 0) {
            return 'update';
        } elseif (strpos($sql, 'delete') === 0) {
            return 'delete';
        } else {
            return 'other';
        }
    }

    /**
     * Format execution time for display
     *
     * @param float $time Time in milliseconds
     * @return string Formatted time with units
     */
    public function formatExecutionTime(float $time): string
    {
        if ($time < 1) {
            return round($time * 1000, 2) . ' µs';
        } elseif ($time < 1000) {
            return round($time, 2) . ' ms';
        } else {
            return round($time / 1000, 2) . ' s';
        }
    }

    /**
     * Get average query execution time
     *
     * @return float|null Average time in milliseconds or null if no queries
     */
    public function getAverageExecutionTime(): ?float
    {
        if ($this->stats['total'] === 0) {
            return null;
        }

        return $this->stats['total_time'] / $this->stats['total'];
    }

    /**
     * Get query count by type
     *
     * @param string|null $type Query type or null for all types
     * @return int Number of queries
     */
    public function getQueryCount(?string $type = null): int
    {
        if ($type === null) {
            return $this->stats['total'];
        }

        return $this->stats[$type] ?? 0;
    }

    /**
     * Extract table names from SQL statement
     *
     * @param string $sql SQL statement
     * @return array Array of table names
     */
    protected function extractTableNames(string $sql): array
    {
        $tables = [];

        // Normalize query for easier parsing
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $sql = strtolower($sql);

        // Extract main table from queries
        if (preg_match('/\bfrom\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract tables from joins
        preg_match_all('/\bjoin\s+[`"]?(\w+)[`"]?/i', $sql, $matches);
        if (!empty($matches[1])) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Extract tables from inserts
        if (preg_match('/\binsert\s+into\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract tables from updates - using case-insensitive matching
        if (preg_match('/\bupdate\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Extract tables from deletes
        if (preg_match('/\bdelete\s+from\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        return array_unique($tables);
    }

    /**
     * Analyze query complexity
     *
     * Scores the complexity of a query based on various factors:
     * - Number of joins
     * - Presence of subqueries
     * - Use of aggregation functions
     * - Use of window functions
     * - Presence of GROUP BY, HAVING
     * - UNION/INTERSECT operations
     *
     * @param string $sql SQL statement
     * @return int Complexity score (higher = more complex)
     */
    protected function analyzeQueryComplexity(string $sql): int
    {
        $complexity = 0;

        // Normalize query for easier analysis
        $normalizedSql = preg_replace('/\s+/', ' ', strtolower($sql));

        // Count JOIN operations (+1 for each join)
        $complexity += substr_count($normalizedSql, ' join ');

        // Check for subqueries (+2 for each subquery)
        $subqueryCount = substr_count($normalizedSql, '(select ');
        $complexity += $subqueryCount * 2;

        // Check for aggregation functions (+1)
        if (preg_match('/\b(count|sum|avg|min|max)\s*\(/i', $normalizedSql)) {
            $complexity += 1;
        }

        // Check for window functions (+2)
        if (preg_match('/\bover\s*\(/i', $normalizedSql)) {
            $complexity += 2;
        }

        // Check for GROUP BY (+1)
        if (strpos($normalizedSql, ' group by ') !== false) {
            $complexity += 1;
        }

        // Check for HAVING (+1)
        if (strpos($normalizedSql, ' having ') !== false) {
            $complexity += 1;
        }

        // Check for UNION/INTERSECT/EXCEPT operations (+2 each)
        $unionCount = substr_count($normalizedSql, ' union ');
        $intersectCount = substr_count($normalizedSql, ' intersect ');
        $exceptCount = substr_count($normalizedSql, ' except ');
        $complexity += ($unionCount + $intersectCount + $exceptCount) * 2;

        // Check for ordering complexity (+1 for complex ordering)
        if (preg_match('/order by .+,.+/i', $normalizedSql)) {
            $complexity += 1;
        }

        return $complexity;
    }

    /**
     * Add a query to the recent queries cache for N+1 detection
     *
     * @param string $sql SQL statement
     * @param float|null $executionTime Execution time in ms
     * @param array $tables Affected tables
     */
    /**
     * Add a query to the recent queries cache for N+1 detection
     *
     * @param string $sql SQL statement
     * @param float|null $executionTime Execution time in ms
     * @param array $tables Affected tables
     */
    protected function addToRecentQueries(string $sql, ?float $executionTime, array $tables): void
    {
        $now = time();

        // Remove old queries outside the time window
        $this->recentQueries = array_filter(
            $this->recentQueries,
            fn($q) => ($now - $q['timestamp']) < $this->n1TimeWindow
        );

        // Add current query to the cache
        $this->recentQueries[] = [
            'signature' => $this->generateQuerySignature($sql),
            'sql' => $sql,
            'execution_time' => $executionTime,
            'tables' => $tables,
            'timestamp' => $now
        ];

        // Limit the size of recent queries to avoid excessive memory usage
        if (count($this->recentQueries) > 1000) {
            // Keep only the most recent 500 entries
            $this->recentQueries = array_slice($this->recentQueries, -500);
        }
    }

    /**
     * Generate a query signature for pattern detection
     *
     * Creates a normalized signature of the query by:
     * - Removing literals
     * - Normalizing whitespace
     * - Preserving query structure
     *
     * @param string $sql SQL statement
     * @return string Query signature
     */
    /**
     * Generate a query signature for pattern detection
     *
     * Creates a normalized signature of the query by:
     * - Removing literals
     * - Normalizing whitespace
     * - Preserving query structure
     *
     * @param string $sql SQL statement
     * @return string Query signature
     */
    protected function generateQuerySignature(string $sql): string
    {
        // Convert to lowercase for case-insensitive comparison
        $signature = strtolower($sql);

        // Replace all literal values with placeholders
        $signature = preg_replace('/\b\d+\b/', '?', $signature); // Numbers
        $signature = preg_replace('/\'[^\']*\'/', '?', $signature); // Strings
        $signature = preg_replace('/\s+/', ' ', $signature); // Normalize whitespace

        // Replace IN clauses with standardized form
        $signature = preg_replace('/\bin\s*\([^)]+\)/i', 'in(?)', $signature);

        // Replace parameter placeholders (? or :param)
        $signature = preg_replace('/\?/', '$param', $signature);
        $signature = preg_replace('/:\w+/', '$param', $signature);

        // Strip schema prefixes from table names for better pattern matching
        $signature = preg_replace('/([`"\[])[^`"\]]+\./', '$1', $signature);

        // Create a hash of the normalized query
        return md5($signature);
    }

    /**
     * Detect potential N+1 query patterns
     *
     * Analyzes recent queries to detect patterns indicating N+1 problems
     */
    /**
     * Detect potential N+1 query patterns
     *
     * Analyzes recent queries to detect patterns indicating N+1 problems
     * and implements fixes to prevent duplicate alerts
     */
    protected function detectN1Patterns(): void
    {
        // Skip detection if we don't have enough queries
        if (count($this->recentQueries) < $this->n1Threshold) {
            return;
        }

        // Group queries by signature
        $patterns = [];
        $timestamps = [];
        $tables = [];

        foreach ($this->recentQueries as $query) {
            $signature = $query['signature'];

            if (!isset($patterns[$signature])) {
                $patterns[$signature] = 0;
                $timestamps[$signature] = [];
                $tables[$signature] = $query['tables'];
            }

            $patterns[$signature]++;
            $timestamps[$signature][] = $query['timestamp'];
        }

        // Find patterns that exceed the threshold and occurred within the time window
        foreach ($patterns as $signature => $count) {
            if ($count >= $this->n1Threshold) {
                // Check if queries occurred within a tight time window (potential N+1)
                $signatureTimestamps = $timestamps[$signature];
                $timespan = max($signatureTimestamps) - min($signatureTimestamps);

                // Only alert if the queries happened in a short timeframe
                if ($timespan <= $this->n1TimeWindow) {
                    // Get a sample query for this pattern
                    $sampleQuery = '';
                    foreach ($this->recentQueries as $query) {
                        if ($query['signature'] === $signature) {
                            $sampleQuery = $query['sql'];
                            break;
                        }
                    }

                    // Get average execution time
                    $executionTimes = array_map(
                        fn($q) => $q['execution_time'] ?? 0,
                        array_filter(
                            $this->recentQueries,
                            fn($q) => $q['signature'] === $signature && $q['execution_time'] !== null
                        )
                    );

                    $avgExecutionTime = !empty($executionTimes)
                        ? array_sum($executionTimes) / count($executionTimes)
                        : null;

                    // Log the potential N+1 issue
                    if ($this->logger) {
                        $this->logger->warning("Potential N+1 query pattern detected", [
                            'type' => 'performance',
                            'pattern_count' => $count,
                            'threshold' => $this->n1Threshold,
                            'time_window_seconds' => $this->n1TimeWindow,
                            'timespan' => $timespan,
                            'sample_query' => $sampleQuery,
                            'tables' => $tables[$signature],
                            'avg_execution_time' => $avgExecutionTime,
                            'total_execution_time' => $avgExecutionTime ? $avgExecutionTime * $count : null,
                            'recommendation' => $this->generateN1FixRecommendation($sampleQuery, $tables[$signature])
                        ]);
                    }

                    // Clear out this pattern to avoid repeated alerts for the same issue
                    foreach ($this->recentQueries as $key => $query) {
                        if ($query['signature'] === $signature) {
                            unset($this->recentQueries[$key]);
                        }
                    }
                    // Reindex the array
                    $this->recentQueries = array_values($this->recentQueries);
                }
            }
        }
    }

    /**
     * Generate recommendations for fixing N+1 query issues
     *
     * @param string $sampleQuery A sample query from the N+1 pattern
     * @param array $tables Tables involved in the query
     * @return string Recommendation for fixing the N+1 issue
     */
    protected function generateN1FixRecommendation(string $sampleQuery, array $tables): string
    {
        $lowercaseQuery = strtolower($sampleQuery);

        if (
            strpos($lowercaseQuery, 'where') !== false &&
            (strpos($lowercaseQuery, ' id =') !== false ||
             strpos($lowercaseQuery, ' id in') !== false)
        ) {
            return "Consider using eager loading or preloading related data in a single query " .
                  "instead of multiple individual lookups. " .
                  "Replace multiple individual queries with a single query using WHERE IN clause or JOIN.";
        } elseif (count($tables) === 1 && strpos($lowercaseQuery, 'join') === false) {
            return "Consider adding appropriate JOINs to retrieve related data in a single query, " .
                "or implement batch loading with eager loading techniques using WHERE IN clause.";
        } elseif (strpos($lowercaseQuery, 'limit 1') !== false) {
            return "Multiple single-row lookups detected. Consider using a batch query with WHERE IN clause " .
                "to fetch all needed records at once.";
        } else {
            return "Review the application code for loops that execute database queries. Consider implementing " .
                "eager loading, batch fetching, or query optimization.";
        }
    }

    /**
     * Configure N+1 detection settings
     *
     * @param int $threshold Number of similar queries that triggers detection
     * @param int $timeWindow Time window in seconds for detection
     * @return self
     */
    public function configureN1Detection(int $threshold = 5, int $timeWindow = 5): self
    {
        $this->n1Threshold = $threshold;
        $this->n1TimeWindow = $timeWindow;
        return $this;
    }
}
