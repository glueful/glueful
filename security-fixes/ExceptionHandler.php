<?php

namespace Glueful\Exceptions;

use Glueful\Logging\LogManagerInterface;
use Glueful\Logging\LogManager;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Exceptions\NotFoundException;
use Glueful\Exceptions\ApiException;

/**
 * SECURITY-FIXED Exception Handler
 *
 * Enhanced exception handler with production-safe error disclosure:
 * - Filters sensitive information in production
 * - Sanitizes error messages
 * - Prevents information leakage
 */
class ExceptionHandler
{
    /**
     * @var LogManagerInterface|null
     */
    private static ?LogManagerInterface $logManager = null;

    /**
     * @var bool Flag to disable exit for testing
     */
    private static bool $testMode = false;

    /**
     * @var array|null Captured response for testing
     */
    private static ?array $testResponse = null;

    /**
     * Map of exception types to log channels
     * @var array<string, string>
     */
    private static array $channelMap = [
        ValidationException::class => 'validation',
        AuthenticationException::class => 'auth',
        NotFoundException::class => 'http',
        ApiException::class => 'api',
        'default' => 'error',
    ];

    /**
     * Enable or disable test mode (disables exit calls)
     *
     * @param bool $enabled
     * @return void
     */
    public static function setTestMode(bool $enabled): void
    {
        self::$testMode = $enabled;
        self::$testResponse = null; // Reset test response
    }

    /**
     * Get the last captured response in test mode
     *
     * @return array|null
     */
    public static function getTestResponse(): ?array
    {
        return self::$testResponse;
    }

    /**
     * Set the log manager instance for testing
     *
     * @param LogManagerInterface|null $logManager
     */
    public static function setLogManager(?LogManagerInterface $logManager): void
    {
        self::$logManager = $logManager;
    }

    /**
     * Get the log manager instance
     *
     * @return LogManagerInterface
     */
    private static function getLogManager(): LogManagerInterface
    {
        if (self::$logManager === null) {
            self::$logManager = LogManager::getInstance();
        }

        return self::$logManager;
    }

    /**
     * Handle uncaught exceptions with production-safe error disclosure
     *
     * @param \Throwable $exception
     * @return void
     */
    public static function handleException(\Throwable $exception): void
    {
        // Log the full error details (internal use)
        self::logError($exception);

        // Determine appropriate status code and message
        $statusCode = 500;
        $message = 'Server Error';
        $data = null;
        $isDebugMode = config('app.debug', false);

        // Handle exception by exact class type
        if ($exception instanceof ApiException) {
            $statusCode = $exception->getStatusCode();
            $message = self::sanitizeMessage($exception->getMessage(), $isDebugMode);
            $data = $exception->getData();
        } elseif ($exception instanceof ValidationException) {
            $statusCode = 422;
            $message = 'Validation Error';
            $data = $exception->getErrors();
        } elseif ($exception instanceof AuthenticationException) {
            $statusCode = 401;
            // SECURITY FIX: Don't expose detailed auth failure reasons in production
            $message = $isDebugMode ? $exception->getMessage() : 'Authentication failed';
        } elseif ($exception instanceof NotFoundException) {
            $statusCode = 404;
            $message = $isDebugMode ? $exception->getMessage() : 'Resource not found';
        } else {
            // SECURITY FIX: Generic error for unknown exceptions in production
            $message = $isDebugMode ? $exception->getMessage() : 'An unexpected error occurred';
        }

        // SECURITY FIX: Add debug information only in development
        $debugInfo = null;
        if ($isDebugMode) {
            $debugInfo = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'type' => get_class($exception)
            ];
        }

        // Output the JSON response
        self::outputJsonResponse($statusCode, $message, $data, $debugInfo);
    }

    /**
     * SECURITY FIX: Sanitize error messages for production
     *
     * @param string $message
     * @param bool $isDebugMode
     * @return string
     */
    private static function sanitizeMessage(string $message, bool $isDebugMode): string
    {
        if ($isDebugMode) {
            return $message;
        }

        // Remove potentially sensitive information from error messages
        $sanitized = $message;

        // Remove file paths
        $sanitized = preg_replace('/\/[^\s]+\.php/', '[file path hidden]', $sanitized);

        // Remove SQL statements
        $sanitized = preg_replace('/SELECT.*?FROM/i', '[SQL query hidden]', $sanitized);
        $sanitized = preg_replace('/INSERT INTO.*?VALUES/i', '[SQL query hidden]', $sanitized);
        $sanitized = preg_replace('/UPDATE.*?SET/i', '[SQL query hidden]', $sanitized);
        $sanitized = preg_replace('/DELETE FROM.*?WHERE/i', '[SQL query hidden]', $sanitized);

        // Remove database connection details
        $sanitized = preg_replace('/host=[\w\.-]+/', 'host=[hidden]', $sanitized);
        $sanitized = preg_replace('/password=\S+/', 'password=[hidden]', $sanitized);

        return $sanitized;
    }

    /**
     * Log an exception to the appropriate channel
     *
     * @param \Throwable $exception
     * @param array $customContext Optional additional context for the log
     * @return void
     */
    public static function logError(\Throwable $exception, array $customContext = []): void
    {
        // Get the appropriate log channel based on exception type
        $channel = self::$channelMap['default'];

        foreach (self::$channelMap as $exceptionClass => $mappedChannel) {
            if ($exceptionClass === 'default') {
                continue;
            }

            if ($exception instanceof $exceptionClass) {
                $channel = $mappedChannel;
                break;
            }
        }

        // Build the context array with exception information
        $context = [
            'exception' => $exception,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'type' => get_class($exception),
            // SECURITY FIX: Add request context for security analysis
            'request_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'request_uri' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];

        // Merge custom context if provided
        if (!empty($customContext)) {
            $context = array_merge($context, $customContext);
        }

        try {
            // Get log manager and log the exception
            $logManager = self::getLogManager();

            // Get logger for the specific channel and log the exception
            $logger = $logManager->getLogger($channel);
            $logger->error($exception->getMessage(), $context);
        } catch (\Throwable $e) {
            // Fallback to error_log if logging fails
            error_log("Error logging exception: {$exception->getMessage()} - {$e->getMessage()}");
            error_log($exception->getTraceAsString());
        }
    }

    /**
     * Output a JSON response and exit (enhanced with debug info)
     *
     * @param int $statusCode
     * @param string $message
     * @param mixed $data
     * @param array|null $debugInfo
     * @return void
     */
    private static function outputJsonResponse(
        int $statusCode,
        string $message,
        $data = null,
        ?array $debugInfo = null
    ): void {
        // Build response array
        $response = [
            'status' => $statusCode,
            'message' => $message,
            'timestamp' => date('c'), // ISO 8601 timestamp
        ];

        // Add data if provided
        if ($data !== null) {
            $response['data'] = $data;
        }

        // SECURITY FIX: Add debug info only in development
        if ($debugInfo !== null) {
            $response['debug'] = $debugInfo;
        }

        if (self::$testMode) {
            // In test mode, capture the response instead of outputting it
            self::$testResponse = $response;
            return; // Don't output or exit
        }

        // Set HTTP response code
        http_response_code($statusCode);

        // Set JSON content type
        header('Content-Type: application/json');

        // SECURITY FIX: Add security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        // Output JSON
        echo json_encode($response, JSON_UNESCAPED_SLASHES);

        // Exit
        exit;
    }
}
