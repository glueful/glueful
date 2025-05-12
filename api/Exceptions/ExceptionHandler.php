<?php

namespace Glueful\Exceptions;

use Glueful\Logging\LogManagerInterface;
use Glueful\Logging\LogManager;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Exceptions\NotFoundException;
use Glueful\Exceptions\ApiException;

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
     * Handle uncaught exceptions
     *
     * @param \Throwable $exception
     * @return void
     */
    public static function handleException(\Throwable $exception): void
    {
        // Log the error
        self::logError($exception);

        // Determine appropriate status code and message
        $statusCode = 500;
        $message = 'Server Error';
        $data = null;

        if ($exception instanceof ApiException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
            $data = $exception->getData();
        } elseif ($exception instanceof ValidationException) {
            $statusCode = 422;
            $message = 'Validation Error';
            $data = $exception->getErrors();
        } elseif ($exception instanceof AuthenticationException) {
            $statusCode = 401;
            $message = $exception->getMessage();
        } elseif ($exception instanceof NotFoundException) {
            $statusCode = 404;
            $message = $exception->getMessage();
        }

        // Output the JSON response
        self::outputJsonResponse($statusCode, $message, $data);
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
            'type' => get_class($exception)
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
     * Output a JSON response and exit
     *
     * @param int $statusCode
     * @param string $message
     * @param mixed $data
     * @return void
     */
    private static function outputJsonResponse(int $statusCode, string $message, $data = null): void
    {
        // Build response array
        $response = [
            'status' => $statusCode,
            'message' => $message,
        ];

        // Add data if provided
        if ($data !== null) {
            $response['data'] = $data;
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

        // Output JSON
        echo json_encode($response);

        // Exit
        exit;
    }
}
