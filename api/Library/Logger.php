<?php

declare(strict_types=1);

namespace Glueful\Api\Library;

require_once __DIR__ . '/../bootstrap.php';

use Exception;

/**
 * Application logging system
 * 
 * Provides standardized logging functionality with support for different log levels,
 * context data, and sensitive data masking. Logs are written to configurable
 * file locations with proper permissions and locking mechanisms.
 */
class Logger
{
    private static string $logFile = 'api_debug.log';
    private static bool $enabled = false;
    private static ?string $logDirectory = null;

    /**
     * Initialize the logging system
     * 
     * Sets up the logging environment, creates necessary directories,
     * and validates write permissions.
     * 
     * @param bool $enabled Whether logging is enabled
     * @param string|null $logFile Optional custom log filename
     */
    public static function init(bool $enabled = false, string $logFile = null): void
    {
        self::$enabled = $enabled;
        if ($logFile) {
            self::$logFile = $logFile;
        }

        // Get log directory from config
        self::$logDirectory = config('paths.logs') ?: dirname(dirname(__FILE__)) . '/logs';

        // Create logs directory if it doesn't exist
        if (!is_dir(self::$logDirectory) && !mkdir(self::$logDirectory, 0755, true)) {
            error_log("Failed to create logs directory: " . self::$logDirectory);
            return;
        }

        // Ensure directory is writable
        if (!is_writable(self::$logDirectory)) {
            error_log("Logs directory is not writable: " . self::$logDirectory);
            return;
        }

        error_log("Logger successfully initialized");
    }

    /**
     * Log a message with specified level and context
     * 
     * Writes a timestamped log entry to the configured log file. Handles file locking,
     * permissions, and context data sanitization.
     * 
     * @param string $level Log level (info, error, debug, warning)
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$enabled || !self::$logDirectory) {
            return;
        }

        try {
            $timestamp = date('Y-m-d H:i:s');
            $logPath = self::$logDirectory . '/' . self::$logFile;
            $sanitizedContext = self::sanitizeContext($context);
            $contextStr = !empty($sanitizedContext) ? "\nContext: " . json_encode($sanitizedContext, JSON_PRETTY_PRINT) : '';

            $logMessage = sprintf("[%s] [%s] %s%s\n----------------------------------------\n", $timestamp, strtoupper($level), $message, $contextStr);
            file_put_contents($logPath, $logMessage, FILE_APPEND | LOCK_EX);

            chmod($logPath, 0644);
        } catch (Exception $e) {
            error_log("Logging failed: " . $e->getMessage());
        }
    }

    /**
     * Sanitize sensitive data in context arrays
     * 
     * Recursively processes context arrays to mask sensitive values
     * like passwords, tokens, and API keys.
     * 
     * @param array $context Context data to sanitize
     * @return array Sanitized context data
     */
    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'api_key', 'secret', 'pin'];
        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '******';
            }
        });

        return $context;
    }

    /**
     * Log an informational message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * Log an error message
     * 
     * @param string $message Error message
     * @param array $context Additional context data
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * Log a debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context data
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * Log a warning message
     * 
     * @param string $message Warning message
     * @param array $context Additional context data
     */
    public static function warn(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }
}