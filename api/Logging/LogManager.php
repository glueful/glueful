<?php
declare(strict_types=1);

namespace Glueful\Logging;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Glueful\Helpers\Utils;
use Monolog\Level;
use Glueful\Logging\DatabaseLogHandler;

/**
 * Application Logger
 * 
 * Provides centralized logging functionality using Monolog with support for:
 * - File-based logging with rotation and separate files by level:
 *   * error-*.log: ERROR and above levels
 *   * debug-*.log: DEBUG level messages
 *   * app-*.log: INFO, WARNING, and NOTICE levels
 * - Database logging through DatabaseLogHandler
 * - API request logging with detailed context
 * - Multiple channels with channel-specific routing
 * - Execution time tracking for performance monitoring
 * - Context enrichment with request and auth data
 * - Automatic log rotation with configurable retention
 * 
 * Usage:
 * ```php
 * $logger = new LogManager();
 * 
 * // Basic logging
 * $logger->log("User logged in", ['user_id' => 123], Level::Info);
 * 
 * // API request logging
 * $logger->logApiRequest($request, $response, null, $startTime);
 * ```
 * 
 * @package Glueful\Logging
 */
class LogManager
{
    /** @var Logger Monolog logger instance */
    private Logger $logger;

    /** @var string Default logging channel */
    private string $defaultChannel;

    /**
     * Initialize the application logger
     * 
     * Sets up Monolog with multiple rotating file handlers:
     * - Error handler for ERROR and above
     * - Debug handler for DEBUG level
     * - Default handler for INFO, WARNING, NOTICE
     * - Database handler for all levels
     * 
     * Configures:
     * - Log rotation and retention
     * - File paths from application config
     * - Custom line formatting
     * - Directory permissions
     *
     * @param string $logFile Base path for log files (default: uses config)
     * @param int $maxFiles Maximum number of daily log files to keep (default: 30)
     * @param string $defaultChannel Default logging channel name (default: 'app')
     * @throws \RuntimeException If log directory creation fails
     */
    public function __construct(string $logFile = "", int $maxFiles = 30, string $defaultChannel = 'app')
    {
        $this->defaultChannel = $defaultChannel;

        // Get log directory from config
        $logDirectory = config('app.logging.log_file_path') ?: dirname(dirname(__FILE__)) . '/logs/';
         // Create logs directory if it doesn't exist
         if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true)) {
            error_log("Failed to create logs directory: " . $logDirectory);
            return;
        }

        // Ensure directory is writable
        if (!is_writable($logDirectory)) {
            error_log("Logs directory is not writable: " . $logDirectory);
            return;
        }

        $maxFiles = config('app.logging.log_rotation_days', 30);

        // Create separate loggers for different levels
        $this->logger = new Logger($defaultChannel);

        // Add rotating file handler for errors (ERROR, CRITICAL, ALERT, EMERGENCY)
        $errorHandler = new RotatingFileHandler(
            $logDirectory . 'error.log',
            $maxFiles,
            Level::Error
        );
        
        // Add rotating file handler for debug logs
        $debugHandler = new RotatingFileHandler(
            $logDirectory . 'debug.log',
            $maxFiles,
            Level::Debug
        );

        // Add rotating file handler for other logs (INFO, WARNING, NOTICE)
        $defaultHandler = new RotatingFileHandler(
            $logDirectory . 'app.log',
            $maxFiles,
            Level::Debug,
            false // Don't include messages handled by error handler
        );

        // Set custom formatter if needed
        $formatter = new \Monolog\Formatter\LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            "Y-m-d H:i:s"
        );
        $errorHandler->setFormatter($formatter);
        $defaultHandler->setFormatter($formatter);

        // Add handlers to logger
        $this->logger->pushHandler($debugHandler);     // Debug logs go to debug log
        $this->logger->pushHandler($errorHandler);     // Errors go to error log
        $this->logger->pushHandler($defaultHandler);  // Other logs go to default log
        $this->logger->pushHandler(new DatabaseLogHandler()); // All logs go to database
    }

    /**
     * Log a message with specified level and context
     * 
     * Writes log entry to both file and database with proper formatting
     * and context enrichment.
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int $level Log level (from Monolog\Level)
     * @param string|null $channel Optional channel override
     */
    public function log(string $message, array $context = [], Level $level = Level::Info, string $channel = null): void 
    {
        $channel = $channel ?? $this->defaultChannel;
        $context['channel'] = $channel;
        
        $this->logger->log($level, $message, $context);
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
     */
    public function logApiRequest($request, $response, $error = null, $startTime = null) 
    {
        $endTime = microtime(true);
        $execTime = $startTime ? round($endTime - $startTime, 6) : null; // Calculate execution time
    
        $context = [
            "type"       => "api_request",
            "method"     => $request->getMethod(),
            "url"        => $request->getUri(),
            "status"     => $response->getStatusCode(),
            "referer"    => $_SERVER['HTTP_REFERER'] ?? null,
            "remote_ip"  => $_SERVER['REMOTE_ADDR'] ?? null,
            "user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? null,
            "user_ip"    => $this->getClientIp(),
            "execTime"   => $execTime, // Execution time in seconds
            "details"    => [
                "headers" => getallheaders(),
                "body"    => $this->getRequestBody($request),
                "query"   => $_GET
            ],
            "auth"       => $this->getAuthInfo(),
        ];
    
        // Add error details if present
        if ($error) {
            $context["error"] = [
                "message" => $error->getMessage(),
                "file"    => $error->getFile(),
                "line"    => $error->getLine(),
                "trace"   => $error->getTraceAsString(),
            ];
        }
        $this->log("API request logged", $context, Level::Info, 'api');
    }

    /**
     * Get client IP address with proxy support
     * 
     * Attempts to get the real client IP by checking various headers
     * and falling back to REMOTE_ADDR if needed.
     *
     * @return string|null Client IP address or null if not available
     */
    private function getClientIp(): ?string 
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Get user's IP address
     * 
     * Simple helper to get IP address with X-Forwarded-For support
     *
     * @return string IP address or 'Unknown'
     */
    private function getUserIP() 
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    /**
     * Extract and decode request body
     * 
     * Attempts to decode JSON request body, returns indication
     * if body is non-JSON.
     *
     * @param mixed $request Request object
     * @return mixed Decoded JSON body or string indicating non-JSON
     */
    private function getRequestBody($request) 
    {
        $body = json_decode($request->getBody(), true);
        return $body ?: "Non-JSON Body";
    }
    
    /**
     * Get authentication information
     * 
     * Extracts authentication token and user information
     * from request headers and session.
     *
     * @return array Authentication details including user UUID and token
     */
    private function getAuthInfo() 
    {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $token = str_replace('Bearer ', '', $headers['Authorization']);
        }
        $user = Utils::getUser($token);
        return [
            "user_uuid" => $user['uuid'] ?? null,
            "token"   => $token ?? null
        ];
    }

}