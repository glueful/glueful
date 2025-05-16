<?php

namespace Glueful\Database;

use Glueful\Logging\LogManager;
use Monolog\Level;

/**
 * Database Query Logger
 *
 * Provides specialized logging for database operations with:
 * - SQL query logging with parameter sanitization
 * - Query timing and performance tracking
 * - Error reporting
 * - Query statistics
 * - Integration with LogManager for centralized logging
 * - Performance-optimized audit logging for high-volume environments
 */
class QueryLogger
{
    /** @var LogManager Logger implementation */
    protected LogManager $logger;

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

    /** @var bool Enable audit logging for sensitive operations */
    protected bool $enableAuditLogging = true;

    /** @var float Sampling rate for audit logging (0.0-1.0, where 1.0 means 100% logging) */
    protected float $auditLoggingSampleRate = 1.0;

    /** @var array Cached results for sensitive table checks to reduce lookups */
    protected array $sensitiveTableCache = [];

    /** @var array Cached results for audit table checks to reduce lookups */
    protected array $auditTableCache = [];

    /** @var array Performance metrics for audit logging */
    protected array $auditPerformanceMetrics = [
        'total_operations' => 0,
        'logged_operations' => 0,
        'skipped_operations' => 0,
        'total_audit_time' => 0,
        'avg_audit_time' => 0
    ];

    /** @var array Audit log batch for collecting multiple operations before sending */
    protected array $auditLogBatch = [];

    /** @var int Maximum number of operations in an audit log batch before flushing */
    protected int $maxAuditBatchSize = 10;

    /** @var bool Whether batching is enabled for audit logging */
    protected bool $enableAuditBatching = false;

    /**
     * Create a new query logger instance
     *
     * @param LogManager|null $logger LogManager instance
     * @param string $channel Channel name for database logs
     */
    public function __construct(?LogManager $logger = null, string $channel = 'db_queries')
    {
        // Use provided LogManager or create a new one
        if ($logger instanceof LogManager) {
            $this->logger = $logger->channel($channel);
        } else {
            $this->logger = new LogManager($channel);
        }

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

        // Configure LogManager at the same time
        if ($enableDebug) {
            $this->logger->setMinimumLevel(Level::Debug);
        } else {
            // When not in debug mode, only log warning and above
            $this->logger->setMinimumLevel(Level::Warning);
        }

        return $this;
    }

    /**
     * Access the underlying LogManager
     *
     * @return LogManager
     */
    public function getLogger(): LogManager
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
     * @param bool $debug Whether to include debug information
     * @param string|null $purpose Business purpose of the query
     * @return float|null Execution time in milliseconds if timing was enabled
     */
    public function logQuery(
        string $sql,
        array $params = [],
        $startTime = null,
        ?\Throwable $error = null,
        bool $debug = false,
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
            if (is_string($startTime)) {
                // Using LogManager timer system
                $executionTime = $this->logger->endTimer($startTime, ['sql' => $sql]);
            } elseif (is_float($startTime)) {
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

        // Send to audit logger for sensitive operations if not an audit table operation
        $hasAuditTables = false;
        foreach ($tables as $table) {
            if ($this->isAuditTable($table)) {
                $hasAuditTables = true;
                break;
            }
        }

        // Only log to audit system if not operating on audit tables (prevents recursion)
        // and if it's a write operation or a sensitive read
        if (!$hasAuditTables && ($queryType !== 'select' || $this->containsSensitiveTable($tables))) {
            $this->logSensitiveOperationToAudit($queryType, $tables, $purpose, $params);
        }

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

        // Log through LogManager
        if ($error) {
            // Add error details
            $context['error'] = [
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
                'file' => $error->getFile(),
                'line' => $error->getLine()
            ];

            $this->logger->error("Query failed: $sql", $context);
        } else {
            // Use appropriate level based on execution time and complexity
            if ($executionTime && $executionTime > 1000) {
                $this->logger->warning("Slow query: $sql", $context);
            } elseif ($executionTime && $executionTime > 500) {
                $this->logger->notice("Potentially slow query: $sql", $context);
            } elseif ($complexity > 7) {
                $this->logger->notice("Complex query: $sql", $context);
            } else {
                $this->logger->debug("Query executed: $sql", $context);
            }
        }

        // Check for N+1 query patterns after logging
        $this->detectN1Patterns();

        return $executionTime;
    }

    /**
     * Start timing a query
     *
     * @param string|null $operation Optional name for the operation
     * @return string|float Timer ID from LogManager or microtime
     */
    public function startTiming(?string $operation = null): string|float
    {
        if (!$this->enableTiming) {
            return microtime(true); // Return current time even if timing disabled
        }

        if ($operation) {
            // Use LogManager's timer system
            return $this->logger->startTimer("db_query:" . $operation);
        }

        // Simple timing fallback
        return microtime(true);
    }

    /**
     * End timing for an operation
     *
     * @param string|float $timerIdOrStart Timer ID or start time
     * @param array $context Additional context for the timing log
     * @return float|null Duration in milliseconds
     */
    public function endTiming($timerIdOrStart, array $context = []): ?float
    {
        if (!$this->enableTiming) {
            return null;
        }

        if (is_string($timerIdOrStart)) {
            // Use LogManager's timer system
            return $this->logger->endTimer($timerIdOrStart, $context);
        } elseif (is_float($timerIdOrStart)) {
            // Calculate duration using microtime
            $duration = (microtime(true) - $timerIdOrStart) * 1000;
            return round($duration, 2);
        } else {
            // Default fallback for any other type
            return 0.0;
        }
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

        // Log the event through LogManager
        $this->logger->log($level, $message, $context);
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
     * Configure audit logging integration
     *
     * @param bool $enable Whether to enable audit logging for sensitive operations
     * @param float $sampleRate Sampling rate (0.0-1.0) for audit logging
     * @param bool $enableBatching Enable batch processing of audit logs
     * @param int $batchSize Maximum batch size before flushing (if batching enabled)
     * @return self
     */
    public function configureAuditLogging(
        bool $enable = true,
        float $sampleRate = 1.0,
        bool $enableBatching = false,
        int $batchSize = 10
    ): self {
        $this->enableAuditLogging = $enable;
        $this->auditLoggingSampleRate = max(0.0, min(1.0, $sampleRate)); // Ensure between 0 and 1
        $this->enableAuditBatching = $enableBatching;
        $this->maxAuditBatchSize = max(1, $batchSize); // Ensure at least 1

        // Reset caches when configuration changes
        $this->sensitiveTableCache = [];
        $this->auditTableCache = [];

        return $this;
    }

    /**
     * Get audit performance metrics
     *
     * @return array Audit performance metrics
     */
    public function getAuditPerformanceMetrics(): array
    {
        // Calculate average audit time if operations were logged
        if ($this->auditPerformanceMetrics['logged_operations'] > 0) {
            $this->auditPerformanceMetrics['avg_audit_time'] =
                $this->auditPerformanceMetrics['total_audit_time'] /
                $this->auditPerformanceMetrics['logged_operations'];
        }

        return $this->auditPerformanceMetrics;
    }

    /**
     * Flush any batched audit log entries
     *
     * @return int Number of entries flushed
     */
    public function flushAuditLogBatch(): int
    {
        if (!$this->enableAuditBatching || empty($this->auditLogBatch)) {
            return 0;
        }

        $count = count($this->auditLogBatch);

        try {
            // Get audit logger singleton
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();

            // Process each batched operation
            foreach ($this->auditLogBatch as $entry) {
                $auditLogger->dataEvent(
                    $entry['action'],
                    $entry['actor_id'],
                    $entry['target_id'],
                    $entry['target_type'],
                    $entry['details']
                );
            }

            // Clear batch after successful processing
            $this->auditLogBatch = [];

            return $count;
        } catch (\Throwable $e) {
            // Log error but don't throw it to avoid disrupting application flow
            $this->logger->error("Failed to flush audit log batch: {$e->getMessage()}", [
                'error' => $e->getMessage(),
                'batch_size' => $count
            ]);

            // Clear batch to avoid retry loops with bad data
            $this->auditLogBatch = [];

            return 0;
        }
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

        // Extract tables from updates
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
                    $this->logger->warning("Potential N+1 query pattern detected", [
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
            return "Consider adding appropriate JOINs to retrieve related data in a single query, "
                . "or implement batch loading.";
        } elseif (strpos($lowercaseQuery, 'limit 1') !== false) {
            return "Multiple single-row lookups detected. Consider using a batch query with WHERE IN clause " .
                "to fetch all needed records at once.";
        } else {
            return "Review the application code for loops that execute database queries. Consider implementing " .
                "eager loading, batch fetching, or query optimization.";
        }
    }

    /**
     * Check if any of the tables in the array contain sensitive data
     *
     * @param array $tables List of table names
     * @return bool True if at least one table contains sensitive data
     */
    protected function containsSensitiveTable(array $tables): bool
    {
        foreach ($tables as $table) {
            if ($this->isSensitiveTable($table)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a table is considered sensitive (contains important/protected data)
     *
     * @param string $table Table name
     * @return bool True if table contains sensitive data
     */
    protected function isSensitiveTable(string $table): bool
    {
        // Strip table prefix if any
        $prefix = config('database.connections.mysql.prefix', '');
        if (!empty($prefix) && strpos($table, $prefix) === 0) {
            $table = substr($table, strlen($prefix));
        }

        // Check cache first
        if (isset($this->sensitiveTableCache[$table])) {
            return $this->sensitiveTableCache[$table];
        }

        // List of sensitive tables that require audit logging
        $sensitiveTables = [
            'users',
            'permissions',
            'roles',
            'user_roles_lookup',
            'profiles',
            'api_keys',
            'tokens',
            'auth_sessions',
            'oauth_access_tokens',
            'oauth_auth_codes',
            'oauth_clients',
            'oauth_personal_access_clients',
            'oauth_refresh_tokens',
            'password_resets',
            'personal_data',
            'financial_records',
            'payment_methods',
            'billing_info',
            'customer_data'
        ];

        // Cache the result
        $isSensitive = in_array($table, $sensitiveTables);
        $this->sensitiveTableCache[$table] = $isSensitive;

        return $isSensitive;
    }

    /**
     * Log a sensitive database operation to the audit system
     *
     * @param string $queryType Type of query (select, insert, update, delete)
     * @param array $tables Tables affected by the query
     * @param string|null $purpose Business purpose of the query
     * @param array $params Query parameters
     * @return void
     */
    /**
     * Log a sensitive database operation to the audit system
     *
     * Improved with performance optimizations:
     * - Sampling for high-volume environments
     * - Batching support to reduce overhead
     * - Performance metrics tracking
     * - Cached table lookups
     *
     * @param string $queryType Type of query (select, insert, update, delete)
     * @param array $tables Tables affected by the query
     * @param string|null $purpose Business purpose of the query
     * @param array $params Query parameters
     * @return void
     */
    protected function logSensitiveOperationToAudit(
        string $queryType,
        array $tables,
        ?string $purpose = null,
        array $params = []
    ): void {
        // Skip if audit logging is disabled or no tables identified
        if (!$this->enableAuditLogging || empty($tables)) {
            return;
        }

        // Track total operations considered for auditing
        $this->auditPerformanceMetrics['total_operations']++;

        // Apply sampling if configured (skip randomly based on sampling rate)
        if ($this->auditLoggingSampleRate < 1.0 && mt_rand(1, 100) > ($this->auditLoggingSampleRate * 100)) {
            $this->auditPerformanceMetrics['skipped_operations']++;
            return;
        }

        // Convert query type to action name
        $action = match ($queryType) {
            'insert' => 'create',
            'update' => 'update',
            'delete' => 'delete',
            'select' => 'access',
            default => 'other'
        };

        $startTime = microtime(true);

        try {
            foreach ($tables as $table) {
                // Skip logging for audit tables to prevent recursion (use cached results)
                if ($this->isAuditTable($table)) {
                    continue;
                }

                // Only log appropriate operations - select queries only for sensitive tables
                if (
                    ($queryType === 'select' && !$this->isSensitiveTable($table)) ||
                    ($queryType === 'other')
                ) {
                    continue;
                }

                // Prepare the audit entry
                $auditEntry = [
                    'action' => "{$action}_record",
                    'actor_id' => null, // Will be determined by audit logger
                    'target_id' => null, // Not available at this level
                    'target_type' => $table,
                    'details' => [
                        'table' => $table,
                        'operation' => $queryType,
                        'purpose' => $purpose ?? 'Unknown',
                        'params' => $this->sanitizeQueryParams($params)
                    ]
                ];

                // If batching is enabled, add to batch instead of sending immediately
                if ($this->enableAuditBatching) {
                    $this->auditLogBatch[] = $auditEntry;

                    // Flush if we've reached the batch size limit
                    if (count($this->auditLogBatch) >= $this->maxAuditBatchSize) {
                        $this->flushAuditLogBatch();
                    }
                } else {
                    // Process immediately if not batching
                    $auditLogger = \Glueful\Logging\AuditLogger::getInstance();

                    $auditLogger->dataEvent(
                        $auditEntry['action'],
                        $auditEntry['actor_id'],
                        $auditEntry['target_id'],
                        $auditEntry['target_type'],
                        $auditEntry['details']
                    );
                }

                // Update metrics
                $this->auditPerformanceMetrics['logged_operations']++;
            }
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking application flow
            // But log the failure for debugging
            $this->logger->error("Failed to log sensitive operation to audit: {$e->getMessage()}", [
                'error' => $e->getMessage(),
                'tables' => $tables,
                'queryType' => $queryType
            ]);
        } finally {
            // Track timing for performance metrics
            $duration = (microtime(true) - $startTime) * 1000; // ms
            $this->auditPerformanceMetrics['total_audit_time'] += $duration;
        }
    }

    /**
     * Check if a table is an audit-related table
     *
     * Used to prevent recursive logging when modifying audit tables
     *
     * @param string $table Table name
     * @return bool True if table is an audit-related table
     */
    protected function isAuditTable(string $table): bool
    {
        // Strip table prefix if any
        $prefix = config('database.connections.mysql.prefix', '');
        if (!empty($prefix) && strpos($table, $prefix) === 0) {
            $table = substr($table, strlen($prefix));
        }

        // Check cache first
        if (isset($this->auditTableCache[$table])) {
            return $this->auditTableCache[$table];
        }

        // List of audit-related tables that should be excluded from audit logging
        $auditTables = [
            'audit_logs',
            'audit_entities',
            'audit_changes',
            'audit_snapshots',
            'log_entries',
            'system_logs'
        ];

        // Cache the result
        $isAuditTable = in_array($table, $auditTables);
        $this->auditTableCache[$table] = $isAuditTable;

        return $isAuditTable;
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
