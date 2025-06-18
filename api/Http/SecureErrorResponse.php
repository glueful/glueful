<?php

declare(strict_types=1);

namespace Glueful\Http;

use Glueful\Exceptions\ExceptionHandler;

/**
 * Secure Error Response Helper
 *
 * Provides standardized, secure error responses that prevent information leakage
 * while maintaining useful error handling for developers and users.
 *
 * Security Features:
 * - Environment-aware error detail exposure
 * - Sanitized error messages for production
 * - Standardized error codes and messages
 * - Prevention of stack trace and file path leakage
 * - Database error sanitization
 *
 * @package Glueful\Http
 */
class SecureErrorResponse
{
    /** @var array Standard error messages for common scenarios */
    private const ERROR_MESSAGES = [
        'general' => 'An error occurred while processing your request',
        'validation' => 'The provided data is invalid',
        'database' => 'A database error occurred',
        'authentication' => 'Authentication failed',
        'authorization' => 'Access denied',
        'not_found' => 'The requested resource was not found',
        'file_upload' => 'File upload failed',
        'external_service' => 'External service is temporarily unavailable',
        'rate_limit' => 'Too many requests. Please try again later',
    ];

    /** @var array HTTP status codes for different error types */
    private const STATUS_CODES = [
        'general' => 500,
        'validation' => 400,
        'database' => 500,
        'authentication' => 401,
        'authorization' => 403,
        'not_found' => 404,
        'file_upload' => 400,
        'external_service' => 503,
        'rate_limit' => 429,
    ];

    /**
     * Create a secure error response from an exception
     *
     * @param \Throwable $exception The exception to handle
     * @param string|null $errorType Optional error type override
     * @param array $context Additional context for development environments
     * @return array Secure error response
     */
    public static function fromException(\Throwable $exception, ?string $errorType = null, array $context = []): array
    {
        $errorType = $errorType ?? self::detectErrorType($exception);
        $isDebugMode = self::isDebugMode();

        $response = [
            'success' => false,
            'message' => self::getSecureMessage($exception, $errorType),
            'code' => self::STATUS_CODES[$errorType] ?? 500,
            'error' => [
                'type' => strtoupper($errorType) . '_ERROR',
                'timestamp' => date('c'),
                'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true)
            ]
        ];

        // Only include detailed information in debug mode
        if ($isDebugMode) {
            $response['error']['debug'] = [
                'exception_class' => get_class($exception),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'context' => $context
            ];

            // Include trace only in local/development
            if (self::isLocalEnvironment()) {
                $response['error']['debug']['trace'] = $exception->getTraceAsString();
            }
        }

        // Log the error for monitoring
        ExceptionHandler::logError($exception, $context);

        return $response;
    }

    /**
     * Create a secure validation error response
     *
     * @param array $errors Validation errors
     * @param string $message Custom message
     * @return array Secure validation error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => 400,
            'error' => [
                'type' => 'VALIDATION_ERROR',
                'timestamp' => date('c'),
                'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
                'validation_errors' => $errors
            ]
        ];
    }

    /**
     * Create a secure error response for database operations
     *
     * @param \Throwable $exception Database exception
     * @param string $operation Operation that failed (e.g., 'create', 'update', 'delete')
     * @return array Secure database error response
     */
    public static function databaseError(\Throwable $exception, string $operation = 'operation'): array
    {
        $message = self::isDebugMode()
            ? "Database {$operation} failed: " . self::sanitizeDatabaseError($exception->getMessage())
            : "Database {$operation} failed";

        return self::fromException($exception, 'database', [
            'operation' => $operation,
            'sanitized_message' => $message
        ]);
    }

    /**
     * Create a secure error response for file operations
     *
     * @param \Throwable $exception File operation exception
     * @param string $operation File operation type
     * @return array Secure file error response
     */
    public static function fileError(\Throwable $exception, string $operation = 'file operation'): array
    {
        return self::fromException($exception, 'file_upload', [
            'operation' => $operation
        ]);
    }

    /**
     * Create a secure error response for external service failures
     *
     * @param \Throwable $exception Service exception
     * @param string $service Service name
     * @return array Secure service error response
     */
    public static function externalServiceError(\Throwable $exception, string $service = 'external service'): array
    {
        return self::fromException($exception, 'external_service', [
            'service' => $service
        ]);
    }

    /**
     * Create a generic secure error response
     *
     * @param string $message User-friendly message
     * @param int $statusCode HTTP status code
     * @param string $errorType Error type
     * @param array $context Additional context
     * @return array Secure error response
     */
    public static function create(
        string $message,
        int $statusCode = 500,
        string $errorType = 'general',
        array $context = []
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'code' => $statusCode,
            'error' => [
                'type' => strtoupper($errorType) . '_ERROR',
                'timestamp' => date('c'),
                'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true),
                'context' => self::isDebugMode() ? $context : null
            ]
        ];
    }

    /**
     * Detect error type from exception
     *
     * @param \Throwable $exception Exception to analyze
     * @return string Error type
     */
    private static function detectErrorType(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        $class = get_class($exception);

        // Database errors
        if (
            stripos($class, 'PDO') !== false ||
            stripos($message, 'sqlstate') !== false ||
            stripos($message, 'database') !== false ||
            stripos($message, 'connection') !== false
        ) {
            return 'database';
        }

        // Validation errors
        if (
            stripos($class, 'Validation') !== false ||
            stripos($message, 'validation') !== false ||
            stripos($message, 'invalid') !== false
        ) {
            return 'validation';
        }

        // Authentication errors
        if (
            stripos($class, 'Auth') !== false ||
            stripos($message, 'token') !== false ||
            stripos($message, 'unauthorized') !== false
        ) {
            return 'authentication';
        }

        // Authorization errors
        if (
            stripos($message, 'permission') !== false ||
            stripos($message, 'access denied') !== false ||
            stripos($message, 'forbidden') !== false
        ) {
            return 'authorization';
        }

        // File operation errors
        if (
            stripos($message, 'file') !== false ||
            stripos($message, 'upload') !== false ||
            stripos($message, 'storage') !== false
        ) {
            return 'file_upload';
        }

        // Not found errors
        if (
            stripos($class, 'NotFound') !== false ||
            stripos($message, 'not found') !== false ||
            stripos($message, "doesn't exist") !== false
        ) {
            return 'not_found';
        }

        return 'general';
    }

    /**
     * Get secure message for error type
     *
     * @param \Throwable $exception Exception
     * @param string $errorType Error type
     * @return string Secure message
     */
    private static function getSecureMessage(\Throwable $exception, string $errorType): string
    {
        // In debug mode, return sanitized original message
        if (self::isDebugMode()) {
            return self::sanitizeMessage($exception->getMessage());
        }

        // In production, return generic message
        return self::ERROR_MESSAGES[$errorType] ?? self::ERROR_MESSAGES['general'];
    }

    /**
     * Sanitize database error message
     *
     * @param string $message Original database error message
     * @return string Sanitized message
     */
    private static function sanitizeDatabaseError(string $message): string
    {
        // Remove sensitive information but keep useful error type
        if (stripos($message, 'duplicate entry') !== false) {
            return 'Duplicate entry detected';
        }
        if (stripos($message, 'foreign key constraint') !== false) {
            return 'Data constraint violation';
        }
        if (stripos($message, "table") !== false && stripos($message, "doesn't exist") !== false) {
            return 'Resource table not found';
        }
        if (stripos($message, 'connection') !== false) {
            return 'Database connection failed';
        }

        return 'Database operation failed';
    }

    /**
     * Sanitize general error message
     *
     * @param string $message Original message
     * @return string Sanitized message
     */
    private static function sanitizeMessage(string $message): string
    {
        // Remove file paths
        $message = preg_replace('/[\/\\\\][^\s]*\.php/', '[file]', $message);

        // Remove sensitive connection details
        $message = preg_replace('/password=[^\s;,)]+/i', 'password=[REDACTED]', $message);
        $message = preg_replace('/host=[^\s;,)]+/i', 'host=[REDACTED]', $message);

        // Remove full namespaces
        $message = preg_replace('/\\\\[A-Za-z\\\\]+\\\\/', '', $message);

        // Limit message length
        if (strlen($message) > 150) {
            $message = substr($message, 0, 147) . '...';
        }

        return $message;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug mode is enabled
     */
    private static function isDebugMode(): bool
    {
        $environment = env('APP_ENV', 'production');
        $debugMode = config('app.debug', false);

        return $debugMode && in_array($environment, ['development', 'local']);
    }

    /**
     * Check if running in local environment
     *
     * @return bool True if local environment
     */
    private static function isLocalEnvironment(): bool
    {
        return env('APP_ENV', 'production') === 'local';
    }
}
