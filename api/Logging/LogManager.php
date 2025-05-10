<?php
declare(strict_types=1);

namespace Glueful\Logging;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Glueful\Helpers\Utils;
use Monolog\Level;
use Glueful\Logging\DatabaseLogHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced Application Logger
 * 
 * Provides comprehensive logging functionality with support for:
 * - File-based logging with rotation and channel-based organization
 * - Database logging through DatabaseLogHandler
 * - API request logging with detailed context
 * - Structured logging with JSON or text format
 * - Log batching for performance optimization
 * - Sampling for high-volume environments
 * - Performance tracking with timers
 * - Context sanitization and enrichment
 * - Memory usage monitoring
 * - PSR-3 compliant interface
 * 
 * Usage:
 * ```php
 * // Basic setup
 * $logger = new LogManager();
 * 
 * // Advanced configuration
 * $logger->configure([
 *     'debug_mode' => true,
 *     'max_buffer_size' => 100,
 *     'sampling_rate' => 0.5
 * ])
 * ->setMinimumLevel(Level::Debug)
 * ->setBatchMode(true, 50)
 * ->setFormat('json');
 * 
 * // Channel-specific logging
 * $logger->channel('auth')->info('User logged in', ['user_id' => 123]);
 * 
 * // Performance measurement
 * $timerId = $logger->startTimer('database_operation');
 * // ... perform operation ...
 * $duration = $logger->endTimer($timerId);
 * ```
 * 
 * @package Glueful\Logging
 */
class LogManager implements LoggerInterface, LogManagerInterface
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var Logger Monolog logger instance */
    private Logger $logger;

    /** @var string Default logging channel */
    private string $defaultChannel;
    
    /** @var bool Debug mode flag */
    private bool $debugMode = true;
    
    /** @var array Recent log entries buffer */
    private array $recentLogs = [];
    
    /** @var int Maximum size for recent logs buffer */
    private int $maxBufferSize = 100;
    
    /** @var array Active timers for performance tracking */
    private array $timers = [];
    
    /** @var int Maximum number of daily log files to keep */
    private int $maxFiles = 30;
    
    /** @var Level Minimum logging level */
    private Level $minimumLevel;
    
    /** @var string Log format: 'text' or 'json' */
    private string $logFormat = 'text';
    
    /** @var float Log sampling rate (1.0 = log everything, 0.1 = log 10%) */
    private float $samplingRate = 1.0;
    
    /** @var bool Whether to suppress logging exceptions */
    private bool $suppressExceptions = true;
    
    /** @var array Log entries waiting to be flushed */
    private array $logBatch = [];
    
    /** @var bool Whether batch mode is enabled */
    private bool $batchMode = false;
    
    /** @var int Maximum batch size before auto-flush */
    private int $maxBatchSize = 50;
    
    /** @var string Log rotation strategy: 'daily', 'weekly', 'monthly', 'size' */
    private string $rotationStrategy = 'daily';
    
    /** @var mixed Additional parameter for rotation strategy */
    private mixed $rotationParameter = null;

    /** @var bool Enable context enrichment */
    private bool $enableContextEnrichment = true;

    /** @var bool Include performance metrics in context */
    private bool $includePerformanceMetrics = false;

    /** @var bool Enable memory usage monitoring */
    private bool $enableMemoryMonitoring = false;

    /** @var bool Track log statistics */
    private bool $trackLogStatistics = false;

    /** @var array Log statistics counters */
    private array $logStatistics = [];

    /**
     * Initialize the application logger
     * 
     * Sets up Monolog with multiple rotating file handlers for different log levels.
     *
     * @param string $logFile Base path for log files (default: uses config)
     * @param int $maxFiles Maximum number of daily log files to keep (default: 30)
     * @param string $defaultChannel Default logging channel name (default: 'app')
     * @throws \RuntimeException If log directory creation fails
     */
    public function __construct(string $logFile = "", int $maxFiles = 30, string $defaultChannel = 'app')
    {
        $this->defaultChannel = $defaultChannel;
        $this->maxFiles = $maxFiles;
        $this->minimumLevel = Level::Debug;

        // Get log directory from config
        $logDirectory = config('app.logging.log_file_path') ?: dirname(dirname(__FILE__)) . '/storage/logs/';
        
        // Create logs directory if it doesn't exist
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true)) {
            throw new \RuntimeException("Failed to create logs directory: $logDirectory");
        }

        // Ensure directory is writable
        if (!is_writable($logDirectory)) {
            throw new \RuntimeException("Logs directory is not writable: $logDirectory");
        }

        $this->maxFiles = config('app.logging.log_rotation_days', 30);

        // Create logger
        $this->logger = new Logger($defaultChannel);

        // Add rotating file handler for errors (ERROR, CRITICAL, ALERT, EMERGENCY)
        $errorHandler = new RotatingFileHandler(
            $logDirectory . 'error.log',
            $this->maxFiles,
            Level::Error
        );
        
        // Add rotating file handler for debug logs
        $debugHandler = new RotatingFileHandler(
            $logDirectory . 'debug.log',
            $this->maxFiles,
            Level::Debug,
            false // Don't bubble up to other handlers
        );

        // Add rotating file handler for other logs (INFO, WARNING, NOTICE)
        $defaultHandler = new RotatingFileHandler(
            $logDirectory . 'app.log',
            $this->maxFiles,
            Level::Info,
            false // Don't bubble up to other handlers
        );

        // Set default formatter
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            "Y-m-d H:i:s"
        );
        $errorHandler->setFormatter($formatter);
        $defaultHandler->setFormatter($formatter);
        $debugHandler->setFormatter($formatter);

        // Add handlers to logger
        $this->logger->pushHandler($errorHandler);    // Errors go to error log
        $this->logger->pushHandler($debugHandler);    // Debug logs go to debug log
        $this->logger->pushHandler($defaultHandler);  // Other logs go to default log
        
        // Add database handler if configured
        if (config('app.logging.database_logging', false)) {
            $this->logger->pushHandler(new DatabaseLogHandler());
        }
        
        // Register shutdown handler to flush logs
        $this->registerShutdownHandler();
        
        // Set up memory monitoring
        $this->setupMemoryMonitoring();
    }

     /**
     * Get singleton instance
     * 
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Reset instance (for testing)
     * 
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
    
    /**
     * Get a logger for a specific channel
     * 
     * @param string $channel
     * @return LoggerInterface
     */
    public function getLogger(string $channel): LoggerInterface
    {
        return $this->channel($channel);
    }

    /**
     * Configure logging options
     * 
     * @param array $options Configuration options
     * @return self
     */
    public function configure(array $options = []): self
    {
        if (isset($options['debug_mode'])) {
            $this->debugMode = (bool)$options['debug_mode'];
        }
        
        if (isset($options['max_files'])) {
            $this->maxFiles = (int)$options['max_files'];
        }
        
        if (isset($options['default_channel'])) {
            $this->defaultChannel = $options['default_channel'];
        }
        
        if (isset($options['max_buffer_size'])) {
            $this->maxBufferSize = (int)$options['max_buffer_size'];
        }
        
        if (isset($options['sampling_rate'])) {
            $this->setSamplingRate((float)$options['sampling_rate']);
        }
        
        if (isset($options['log_format'])) {
            $this->setFormat($options['log_format']);
        }
        
        if (isset($options['batch_mode'])) {
            $this->setBatchMode(
                (bool)$options['batch_mode'],
                $options['batch_size'] ?? $this->maxBatchSize
            );
        }
        
        if (isset($options['suppress_exceptions'])) {
            $this->suppressExceptions = (bool)$options['suppress_exceptions'];
        }
        
        if (isset($options['minimum_level'])) {
            if ($options['minimum_level'] instanceof Level) {
                $this->setMinimumLevel($options['minimum_level']);
            } elseif (is_string($options['minimum_level'])) {
                $this->setMinimumLevelByName($options['minimum_level']);
            }
        }
        
        return $this;
    }
    
    /**
     * Set log output format
     * 
     * @param string $format 'text' or 'json'
     * @param array $options Formatter options
     * @return self
     */
    public function setFormat(string $format, array $options = []): self
    {
        if (!in_array($format, ['text', 'json'])) {
            throw new \InvalidArgumentException("Log format must be 'text' or 'json'");
        }
        
        $this->logFormat = $format;
        
        // Create the appropriate formatter
        if ($format === 'json') {
            $batchMode = $options['batch_mode'] ?? JsonFormatter::BATCH_MODE_JSON;
            $appendNewline = $options['append_newline'] ?? true;
            $formatter = new JsonFormatter($batchMode, $appendNewline);
        } else {
            $lineFormat = $options['line_format'] ?? 
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
            $dateFormat = $options['date_format'] ?? "Y-m-d H:i:s";
            $allowInlineLineBreaks = $options['allow_inline_line_breaks'] ?? false;
            $ignoreEmptyContextAndExtra = $options['ignore_empty_context_and_extra'] ?? false;
            
            $formatter = new LineFormatter(
                $lineFormat,
                $dateFormat,
                $allowInlineLineBreaks,
                $ignoreEmptyContextAndExtra
            );
        }
        
        // Apply formatter to handlers
        foreach ($this->logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface || $handler instanceof AbstractProcessingHandler) {
                $handler->setFormatter($formatter);
            }
        }
        
        return $this;
    }
    
    /**
     * Set sampling rate for logs
     * 
     * @param float $rate Sampling rate between 0.0 and 1.0
     * @return self
     */
    public function setSamplingRate(float $rate): self
    {
        if ($rate < 0.0 || $rate > 1.0) {
            throw new \InvalidArgumentException("Sampling rate must be between 0.0 and 1.0");
        }
        
        $this->samplingRate = $rate;
        return $this;
    }
    
    /**
     * Set minimum logging level
     * 
     * @param Level $level Minimum level to log
     * @return self
     */
    public function setMinimumLevel(Level $level): self
    {
        $this->minimumLevel = $level;
        return $this;
    }
    
    /**
     * Set minimum logging level by name
     * 
     * @param string $levelName Level name (debug, info, notice, warning, error, critical, alert, emergency)
     * @return self
     */
    public function setMinimumLevelByName(string $levelName): self
    {
        $levelName = strtolower($levelName);
        
        $level = match ($levelName) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => throw new \InvalidArgumentException("Unknown log level: $levelName")
        };
        
        $this->minimumLevel = $level;
        return $this;
    }
    
    /**
     * Enable or disable log batching
     * 
     * @param bool $enabled Whether to enable batching
     * @param int $maxSize Maximum batch size before auto-flush
     * @return self
     */
    public function setBatchMode(bool $enabled, int $maxSize = 50): self
    {
        $this->batchMode = $enabled;
        $this->maxBatchSize = $maxSize;
        return $this;
    }
    
   /**
     * Configure log rotation strategy
     * 
     * @param string $strategy 'daily', 'weekly', 'monthly', 'size'
     * @param mixed $parameter For 'size': max file size in MB
     * @return self
     */
    public function configureRotation(string $strategy = 'daily', $parameter = null): self
    {
        $this->rotationStrategy = $strategy;
        $this->rotationParameter = $parameter;
        
        // For time-based rotation, use the existing setFilenameFormat method
        foreach ($this->logger->getHandlers() as $handler) {
            if ($handler instanceof RotatingFileHandler) {
                $handler->setFilenameFormat(
                    '{filename}-{date}',
                    $strategy === 'daily' ? 'Y-m-d' : 
                        ($strategy === 'monthly' ? 'Y-m' : 'Y-W')
                );
            }
        }
        
        if ($strategy === 'size' && is_numeric($parameter)) {
            $this->warning('Size-based log rotation not supported in this version of Monolog. Using daily rotation instead.', [
                'requested_size' => $parameter . 'MB'
            ]);
        }
        
        return $this;
    }
    
    /**
     * Configure exception handling for logging failures
     * 
     * @param bool $suppress Whether to suppress logging exceptions
     * @return self
     */
    public function suppressExceptions(bool $suppress): self
    {
        $this->suppressExceptions = $suppress;
        return $this;
    }
    
    /**
     * Configure database logging options
     * 
     * @param bool $enabled Whether to enable database logging
     * @param array $options Database logging options
     * @return self
     */
    public function configureDatabaseLogging(bool $enabled, array $options = []): self
    {
        // Get current handlers
        $handlers = $this->logger->getHandlers();
        
        // Remove existing database handlers
        foreach ($handlers as $key => $handler) {
            if ($handler instanceof DatabaseLogHandler) {
                $this->logger->popHandler();
            }
        }
        
        // Add new database handler if enabled
        if ($enabled) {
            $dbHandler = new DatabaseLogHandler($options);
            
            // Configure database handler
            if (isset($options['min_level'])) {
                $dbHandler->setLevel($options['min_level']);
            }
            
            if (isset($options['table'])) {
                $dbHandler->setTable($options['table']);
            }
            
            $this->logger->pushHandler($dbHandler);
        }
        
        return $this;
    }
    
    /**
     * Create a new logger instance for a specific channel
     * 
     * @param string $channel Channel name
     * @return self New logger instance with channel set
     */
    public function channel(string $channel): self
    {
        $channelLogger = clone $this;
        $channelLogger->defaultChannel = $channel;
        
        // Update any relevant state that should be reset for the new channel
        // For example, you might want to reset the log batch
        if ($this->batchMode) {
            $channelLogger->logBatch = [];
        }
        
        return $channelLogger;
    }

    /**
     * Start timing an operation
     *
     * @param string $operation Name of operation being timed
     * @return string Timer ID
     */
    public function startTimer(string $operation): string
    {
        $timerId = uniqid('timer_');
        $this->timers[$timerId] = [
            'operation' => $operation,
            'start' => microtime(true)
        ];
        return $timerId;
    }

    /**
     * End timing and log the result
     *
     * @param string $timerId Timer ID from startTimer()
     * @param array $context Additional context
     * @return float|null Duration in milliseconds or null if timer not found
     */
    public function endTimer(string $timerId, array $context = []): ?float
    {
        if (!isset($this->timers[$timerId])) {
            return null;
        }
        
        $timer = $this->timers[$timerId];
        $duration = (microtime(true) - $timer['start']) * 1000;
        $duration = round($duration, 2);
        
        $this->debug(
            "Operation completed: {$timer['operation']} ({$duration}ms)",
            array_merge($context, ['duration_ms' => $duration])
        );
        
        unset($this->timers[$timerId]);
        return $duration;
    }
    
    /**
     * Get recent logs from in-memory buffer
     * 
     * @return array Recent log entries
     */
    public function getRecentLogs(): array
    {
        return $this->recentLogs;
    }
    
    /**
     * Clear recent logs buffer
     * 
     * @return self
     */
    public function clearRecentLogs(): self
    {
        $this->recentLogs = [];
        return $this;
    }
    
    /**
     * Get current memory usage
     * 
     * @param bool $real Whether to get real size or emalloc() size
     * @return string Formatted memory usage
     */
    public function getMemoryUsage(bool $real = true): string
    {
        $memory = memory_get_usage($real);
        return $this->formatBytes($memory);
    }
    
    /**
     * Clean up log resources
     * 
     * @return void
     */
    public function cleanup(): void
    {
        // Clear recent logs buffer
        $this->recentLogs = [];
        
        // Clear timers
        $this->timers = [];
        
        // Clear log batch
        $this->logBatch = [];
        
        // Force garbage collection if possible
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Flush batched log entries
     * 
     * @return self
     */
    public function flush(): self
    {
        if (empty($this->logBatch)) {
            return $this;
        }
        
        foreach ($this->logBatch as $entry) {
            $this->logger->withName($entry['channel'])
                        ->log($entry['level'], $entry['message'], $entry['context']);
        }
        
        $this->logBatch = [];
        return $this;
    }

    /**
     * Log a message with the specified level
     * 
     * PSR-3 compliant log method
     *
     * @param mixed $level Log level
     * @param string|\Stringable $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function log($level, $message, array $context = []): void 
    {
        try {
            // Extract channel from context if present
            $channel = null;
            if (isset($context['_channel'])) {
                $channel = $context['_channel'];
                unset($context['_channel']); // Remove special parameter
            }
            
            // Skip if below minimum level
            if (!$this->shouldLog($level)) {
                return;
            }
            
            // Apply sampling for non-critical logs
            if (!$this->shouldSample($level)) {
                return;
            }
            
            $currentChannel = $channel ?? $this->defaultChannel;
            $context['channel'] = $currentChannel;
            
            // Convert message to string if it's Stringable
            $messageStr = $message instanceof \Stringable ? (string)$message : $message;
            
            // Sanitize sensitive data in context
            $sanitizedContext = $this->sanitizeContext($context);
            
            // Enrich context with standard information
            $enrichedContext = $this->enrichContext($sanitizedContext);
            
            // Add to recent logs buffer
            $this->addToRecentLogs($level, $messageStr, $enrichedContext);
            
            // Handle batch mode
            if ($this->batchMode && 
                (($level instanceof Level && $level->value < Level::Error->value) || 
                (is_int($level) && $level < Level::Error->value) ||
                (is_string($level) && strtolower($level) != 'error' && 
                strtolower($level) != 'critical' && strtolower($level) != 'alert' && 
                strtolower($level) != 'emergency'))) {
                    
                // Add to batch (but log errors immediately)
                $this->logBatch[] = [
                    'channel' => $currentChannel,
                    'level' => $level,
                    'message' => $messageStr,
                    'context' => $enrichedContext
                ];
                
                // Auto-flush if batch is full
                if (count($this->logBatch) >= $this->maxBatchSize) {
                    $this->flush();
                }
            } else {
                // Log immediately
                $this->logger->withName($currentChannel)->log($level, $messageStr, $enrichedContext);
            }
            
            // Track log metrics
            $this->incrementLogCounter($level);
            
            // Log memory usage warnings if needed
            $this->checkMemoryUsage();
            
        } catch (\Throwable $e) {
            if (!$this->suppressExceptions) {
                throw $e;
            }
            
            // Try to log the failure using error_log as fallback
            error_log("Logging failure: {$e->getMessage()} - Original message: $messageStr");
            
            // Try to log to a fallback file if possible
            try {
                $fallbackLog = dirname(dirname(__FILE__)) . '/logs/fallback.log';
                file_put_contents(
                    $fallbackLog,
                    date('[Y-m-d H:i:s]') . " LOGGING FAILURE: {$e->getMessage()} - Original: $messageStr\n",
                    FILE_APPEND
                );
            } catch (\Throwable $inner) {
                // At this point we can't do much more
            }
        }
    }

    /**
     * Log a message with specified level, context, and channel
     * 
     * Extended version of log() that allows specifying a channel.
     * This provides backwards compatibility with the original API.
     * 
     * @param string|\Stringable $message Log message
     * @param array $context Additional context data
     * @param Level|mixed $level Log level (from Monolog\Level)
     * @param string|null $channel Optional channel override
     * @return void
     */
    public function logWithChannel($message, array $context = [], $level = Level::Info, ?string $channel = null): void 
    {
        if ($channel !== null) {
            $context['_channel'] = $channel;
        }
        $this->log($level, $message, $context);
    }

    

    /**
     * Check memory usage and log warnings if approaching limit
     *
     * @return void
     */
    private function checkMemoryUsage(): void
    {
        // Skip if memory monitoring is disabled
        if (!$this->enableMemoryMonitoring) {
            return;
        }
        
        // Check only occasionally (1/100 calls) to reduce overhead
        if (mt_rand(1, 100) !== 1) {
            return;
        }
        
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();
        
        $usagePercentage = ($memoryUsage / $memoryLimit) * 100;
        
        // Log if memory usage is over 75% of limit
        if ($usagePercentage > 75) {
            // Use direct logger access to avoid recursive logging
            $this->logger->warning('High memory usage detected', [
                'peak_usage' => $this->formatBytes($memoryUsage),
                'limit' => $this->formatBytes($memoryLimit),
                'percentage' => round($usagePercentage, 2) . '%'
            ]);
        }
    }

    /**
     * Track log statistics
     *
     * @param Level|mixed $level The log level
     * @return void
     */
    private function incrementLogCounter($level): void
    {
        if (!$this->trackLogStatistics) {
            return;
        }
        
        $levelName = $level instanceof Level ? $level->getName() : (string)$level;
        
        if (!isset($this->logStatistics['total'])) {
            $this->logStatistics['total'] = 0;
        }
        
        if (!isset($this->logStatistics[$levelName])) {
            $this->logStatistics[$levelName] = 0;
        }
        
        $this->logStatistics['total']++;
        $this->logStatistics[$levelName]++;
    }

    /**
     * Log detailed API request information
     * 
     * Captures comprehensive request/response data including:
     * - Request method, URL, headers
     * - Response status
     * - Execution time
     * - Client information
     * - Authentication details
     * - Error information if present
     *
     * @param mixed $request Request object
     * @param mixed $response Response object
     * @param \Throwable|null $error Optional error object
     * @param float|null $startTime Optional request start time for execution timing
     * @return void
     */
    public function logApiRequest($request, $response, $error = null, $startTime = null): void 
    {
        $endTime = microtime(true);
        $execTime = $startTime ? round(($endTime - $startTime) * 1000, 2) : null; // Calculate execution time in ms
    
        $context = [
            "type"       => "api_request",
            "method"     => $request->getMethod(),
            "url"        => (string)$request->getUri(),
            "status"     => $response->getStatusCode(),
            "referer"    => $_SERVER['HTTP_REFERER'] ?? null,
            "remote_ip"  => $_SERVER['REMOTE_ADDR'] ?? null,
            "user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? null,
            "user_ip"    => $this->getClientIp(),
            "execution_time" => $execTime
        ];
        
        // Add request body if available and not too large
        $requestBody = $this->getRequestBody($request);
        if ($requestBody !== null) {
            $context['request_body'] = $requestBody;
        }
        
        // Add auth info
        $authInfo = $this->getAuthInfo();
        if ($authInfo) {
            $context['auth'] = $authInfo;
        }
    
        // Add error details if present
        if ($error) {
            $context['error'] = [
                'message' => $error->getMessage(),
                'code'    => $error->getCode(),
                'file'    => $error->getFile(),
                'line'    => $error->getLine(),
            ];
            
            $this->error("API request failed", $context);
        } else {
            $this->info("API request logged", $context);
        }
    }

    /**
     * Get appropriate log filename based on channel and level
     * 
     * @param string $channel Logger channel
     * @param Level $level Log level
     * @return string Log file path
     */
    private function getLogFilename(string $channel, Level $level): string
    {
        $baseDir = config('app.logging.log_file_path') ?: dirname(dirname(__FILE__)) . '/logs/';
        
        // Organize logs in subdirectories by channel for better organization
        $channelDir = $baseDir . ($channel !== 'app' ? $channel . '/' : '');
        
        // Create channel directory if needed
        if (!is_dir($channelDir) && !mkdir($channelDir, 0755, true)) {
            error_log("Failed to create log directory: $channelDir");
            return $baseDir . 'fallback.log';
        }
        
        // Use appropriate filename based on level
        if ($level->value >= Level::Error->value) {
            return $channelDir . 'error.log';
        } elseif ($level === Level::Debug) {
            return $channelDir . 'debug.log';
        } else {
            return $channelDir . 'info.log';
        }
    }

    /**
     * Format bytes to human-readable format
     * 
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "2.5 MB")
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private function getMemoryLimitBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $memoryLimit = (int)$memoryLimit;
        
        switch ($unit) {
            case 'g':
                $memoryLimit *= 1024;
            case 'm':
                $memoryLimit *= 1024;
            case 'k':
                $memoryLimit *= 1024;
        }
        
        return $memoryLimit;
    }

    /**
     * Get client IP address with proxy support
     * 
     * @return string|null Client IP address
     */
    private function getClientIp(): ?string 
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ipList[0]);
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Get the request body from a request object
     * 
     * @param mixed $request Request object
     * @return mixed Request body or null if not available
     */
    private function getRequestBody($request) 
    {
        if (!method_exists($request, 'getBody')) {
            return null;
        }
        
        $body = (string)$request->getBody();
        
        // Check if JSON and parse if possible
        if ($this->isJson($body)) {
            $parsedBody = json_decode($body, true);
            return $this->sanitizeContext($parsedBody);
        }
        
        // Limit the size of request body in logs
        if (strlen($body) > 1000) {
            return substr($body, 0, 1000) . '... [truncated]';
        }
        
        return $body;
    }
    
    /**
     * Check if a string is valid JSON
     * 
     * @param string $string String to check
     * @return bool True if valid JSON
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Get authentication information
     * 
     * @return array|null Authentication details
     */
    private function getAuthInfo() 
    {
        // This is a placeholder - implement based on your auth system
        // Example with JWT tokens or session auth
        
        $authInfo = [];
        
        // If using JWT
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($token) {
            $authInfo['auth_type'] = 'bearer';
            // Don't include actual token in logs
            $authInfo['token_present'] = true;
        }
        
        // If using session auth
        if (session_status() === PHP_SESSION_ACTIVE) {
            $authInfo['session_id'] = session_id();
            $authInfo['auth_type'] = 'session';
            
            // Safely get user ID if available
            if (isset($_SESSION['user_id'])) {
                $authInfo['user_id'] = $_SESSION['user_id'];
            }
        }
        
        return empty($authInfo) ? null : $authInfo;
    }
    
    /**
     * Register shutdown function to flush logs
     * 
     * @return void
     */
    private function registerShutdownHandler(): void
    {
        register_shutdown_function(function() {
            $this->flush();
        });
    }
    
    /**
     * Set up memory usage monitoring
     * 
     * @return void
     */
    private function setupMemoryMonitoring(): void
    {
        // Monitor memory usage and log warnings when high
        register_shutdown_function(function() {
            $memoryUsage = memory_get_peak_usage(true);
            $memoryLimit = $this->getMemoryLimitBytes();
            
            $usagePercentage = ($memoryUsage / $memoryLimit) * 100;
            
            // Log if memory usage is over 75% of limit
            if ($usagePercentage > 75) {
                $this->warning('High memory usage detected', [
                    'peak_usage' => $this->formatBytes($memoryUsage),
                    'limit' => $this->formatBytes($memoryLimit),
                    'percentage' => round($usagePercentage, 2) . '%'
                ]);
            }
        });
    }

    /**
     * Check if a message at the given level should be logged
     * 
     * @param Level $level Log level
     * @return bool Whether the message should be logged
     */
   /**
     * Determine if log level meets minimum threshold
     *
     * @param Level|mixed $level Log level to check
     * @return bool True if this level should be logged
     */
    private function shouldLog($level): bool
    {
        // If level is a string, convert it to a Monolog Level
        if (is_string($level)) {
            $level = Level::fromName(ucfirst($level));
        } elseif (is_int($level)) {
            $level = Level::fromValue($level);
        }
        
        // Skip logging if level is below minimum configured level
        return $level->value >= $this->minimumLevel->value;
    }

    /**
     * Determine if this log entry should be sampled
     *
     * @param Level|mixed $level Log level
     * @return bool True if this entry should be sampled
     */
    private function shouldSample($level): bool
    {
        // Always log errors and above regardless of sampling
        if ($level->value >= Level::Error->value) {
            return true;
        }
        
        // Apply sampling rate
        if ($this->samplingRate < 1.0) {
            return (mt_rand() / mt_getrandmax()) <= $this->samplingRate;
        }
        
        return true;
    }

    /**
     * Enrich log context with standard application information
     *
     * @param array $context Original context array
     * @return array Enriched context
     */
    private function enrichContext(array $context): array
    {
        // Skip enrichment if disabled
        if (!$this->enableContextEnrichment) {
            return $context;
        }
        
        // Only add standard context if not already present
        if (!isset($context['request_id'])) {
            $context['request_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req-');
        }
        
        if (!isset($context['session_id']) && session_status() === PHP_SESSION_ACTIVE) {
            $context['session_id'] = session_id();
        }
        
        // Add performance metrics
        if ($this->includePerformanceMetrics) {
            if (!isset($context['memory_usage'])) {
                $context['memory_usage'] = $this->formatBytes(memory_get_usage(true));
            }
            
            if (!isset($context['memory_peak'])) {
                $context['memory_peak'] = $this->formatBytes(memory_get_peak_usage(true));
            }
            
            if (!isset($context['runtime']) && isset($_SERVER['REQUEST_TIME_FLOAT'])) {
                $context['runtime'] = round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . ' ms';
            }
        }
        
        // Add environment info
        if (!isset($context['environment'])) {
            $context['environment'] = config('app.env') ?? 'production';
        }
        
        // Add user info if available and not sensitive
        // if (!isset($context['user_id']) && function_exists('auth') && auth()->check()) {
        //     $context['user_id'] = auth()->id();
        // }
        
        return $context;
    }
    

    /**
     * Add entry to recent logs buffer with size limit
     * 
     * @param mixed $level Log level
     * @param string $message Log message
     * @param array $context Log context
     * @return void
     */
    private function addToRecentLogs($level, string $message, array $context): void
    {
        // Convert Monolog Level to string if needed
        $levelName = $level instanceof Level ? $level->getName() : (string)$level;
        
        $this->recentLogs[] = [
            'level' => $levelName,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (count($this->recentLogs) > $this->maxBufferSize) {
            array_shift($this->recentLogs);
        }
    }
    
   /**
     * Sanitize context parameters for logging (remove sensitive data)
     * 
     * @param array $context Context parameters
     * @return array Sanitized context
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            // Mask potential sensitive information
            if (is_string($key) && preg_match('/(password|token|key|secret|auth|credential)/i', $key)) {
                $sanitized[$key] = '***REDACTED***';
            } else if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    

   /**
     * System is unusable.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function alert($message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    /**
     * Critical conditions.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function critical($message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should be logged.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function notice($message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    /**
     * Interesting events.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

}