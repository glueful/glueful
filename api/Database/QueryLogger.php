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
 */
class QueryLogger
{
    /** @var LogManager Logger implementation */
    protected LogManager $logger;
    
    /** @var bool Enable debug mode */
    protected bool $debugMode = false;
    
    /** @var bool Enable query timing */
    protected bool $enableTiming = false;
    
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
     * @return float|null Execution time in milliseconds if timing was enabled
     */
    public function logQuery(string $sql, array $params = [], $startTime = null, ?\Throwable $error = null): ?float
    {
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
            } else if (is_float($startTime)) {
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
        
        // Create log entry with sanitized parameters
        $logEntry = [
            'sql' => $sql,
            'params' => $this->sanitizeQueryParams($params),
            'type' => $queryType,
            'time' => $executionTime ? $executionTime . ' ms' : null,
            'timestamp' => date('Y-m-d H:i:s'),
            'error' => $error ? $error->getMessage() : null
        ];
        
        // Add to internal log with size limit
        $this->queryLog[] = $logEntry;
        if (count($this->queryLog) > $this->maxLogSize) {
            array_shift($this->queryLog);
        }
        
        // Prepare log context
        $context = [
            'sql' => $sql,
            'params' => $this->sanitizeQueryParams($params),
            'query_type' => $queryType,
            'type' => 'database_query'
        ];
        
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
            // Use appropriate level based on execution time
            if ($executionTime && $executionTime > 1000) {
                $this->logger->warning("Slow query: $sql", $context);
            } else if ($executionTime && $executionTime > 500) {
                $this->logger->notice("Potentially slow query: $sql", $context);
            } else {
                $this->logger->debug("Query executed: $sql", $context);
            }
        }
        
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
        } else if (is_float($timerIdOrStart)) {
            // Calculate duration using microtime
            $duration = (microtime(true) - $timerIdOrStart) * 1000;
            return round($duration, 2);
        }
        
        return null;
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
            } else if (is_array($value)) {
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
}