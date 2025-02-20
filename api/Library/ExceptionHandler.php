<?php

declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Exceptions\ApiException;
use Glueful\Api\Exceptions\ValidationException;
use Glueful\Api\Exceptions\AuthenticationException;
use Glueful\Api\Exceptions\NotFoundException;
use Throwable;

/**
 * Global exception handler for the API
 * 
 * Handles all uncaught exceptions and errors, providing consistent
 * error responses and logging across the application.
 */
class ExceptionHandler
{
    /**
     * Register all error and exception handlers
     * 
     * Sets up exception handling, error handling, and shutdown functions
     * to ensure all errors are properly caught and handled.
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
        set_error_handler([self::class, 'handleError']);
    }

    /**
     * Handle uncaught exceptions
     * 
     * Logs exception details and returns appropriate HTTP response based on
     * exception type. Different exception types result in different HTTP
     * status codes and response formats.
     * 
     * @param Throwable $exception The uncaught exception
     */
    public static function handleException(Throwable $exception): void
    {
        // Log error details with context
        $message = 'Exception: ' . $exception->getMessage();
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];
        Logger::log($message, json_encode($context));

        // Map exceptions to responses
        switch (true) {
            case $exception instanceof ValidationException:
                self::outputJsonResponse(422, 'Validation Error', $exception->getErrors());
                break;
            case $exception instanceof AuthenticationException:
                self::outputJsonResponse(401, 'Unauthorized', $exception->getMessage());
                break;
            case $exception instanceof NotFoundException:
                self::outputJsonResponse(404, 'Not Found', $exception->getMessage());
                break;
            case $exception instanceof ApiException:
                self::outputJsonResponse($exception->getStatusCode(), $exception->getMessage(), $exception->getData());
                break;
            default:
                self::outputJsonResponse(500, 'Internal Server Error');
                break;
        }
    }

    /**
     * Handle fatal errors during script shutdown
     * 
     * Catches fatal PHP errors that would otherwise not be caught by the
     * exception handler.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            Logger::log('Fatal Error', json_encode([
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]));
            self::outputJsonResponse(500, 'Internal Server Error');
        }
    }

    /**
     * Convert PHP errors to exceptions
     * 
     * Transforms PHP errors into ErrorException instances that can be
     * handled by the exception handler.
     * 
     * @param int $severity Error severity level
     * @param string $message Error message
     * @param string $file File where error occurred
     * @param int $line Line number where error occurred
     * @return bool Always returns false to ensure error is logged
     * @throws \ErrorException
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Output a JSON formatted error response
     * 
     * Sets appropriate HTTP status code and headers, then outputs
     * JSON encoded error details.
     * 
     * @param int $statusCode HTTP status code
     * @param string $message Error message
     * @param mixed $data Additional error data
     */
    private static function outputJsonResponse(int $statusCode, string $message, mixed $data = null): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $statusCode,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
}