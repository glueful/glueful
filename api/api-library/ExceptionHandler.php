<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

class ExceptionHandler
{
    public function __construct() 
    {
        set_exception_handler([$this, 'handleException']);
    }

    public function handleException(\Throwable $e): void 
    {
        // Prepare error response
        $error = [
            'ERR' => $e->getMessage(),
            'CODE' => $e->getCode()
        ];

        // Send JSON response
        header('Content-Type: application/json');
        echo json_encode($error);

        // Log the error with additional details
        $logData = [
            ...$error,
            'FILE' => $e->getFile(),
            'LINE' => $e->getLine()
        ];

        $this->logError($logData);
    }

    private function logError(array $data): void 
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?: 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $timestamp = gmdate('Y-M-d H:i:s');
        
        $message = sprintf(
            '%s %s %s %s',
            $timestamp,
            $ip,
            $scriptName,
            json_encode($data)
        );

        $monthYear = gmdate('Ym');
        $baseLogPath = config('paths.logs');

        // Write to general error log
        error_log($message . "\n", 3, "{$baseLogPath}errors.{$monthYear}.log");

        // Write to SQL log if it's a database error
        if (str_contains($message, 'MYSQL')) {
            error_log(
                str_replace('\n', '', $message) . "\n",
                3,
                "{$baseLogPath}sql.{$monthYear}.log"
            );
        }
    }
}

// Initialize exception handler
new ExceptionHandler();
?>