<?php

declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Exceptions\ApiException;
use Glueful\Api\Exceptions\ValidationException;
use Glueful\Api\Exceptions\AuthenticationException;
use Glueful\Api\Exceptions\NotFoundException;
use Throwable;

class ExceptionHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
        set_error_handler([self::class, 'handleError']);
    }

    public static function handleException(Throwable $exception): void
    {
        // Log error details
        $message = 'Exception: ' . $exception->getMessage();
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];
        Logger::log($message, json_encode($context));

        // Handle different exception types
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

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

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