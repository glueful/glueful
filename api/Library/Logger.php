<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

require_once __DIR__ . '/../bootstrap.php';

class Logger {
    private static string $logFile = 'api_debug.log';
    private static bool $enabled = false;
    private static ?string $logDirectory = null;

    public static function init(bool $enabled = false, string $logFile = null): void {
        self::$enabled = $enabled;
        if ($logFile) {
            self::$logFile = $logFile;
        }
        
        // Use the configured logs directory
        self::$logDirectory = config('paths.logs') ? config('paths.logs') : dirname(dirname(__FILE__)) . '/logs';
        
        // Debug output
        // error_log("Logger initialized with directory: " . self::$logDirectory);
        
        // Create logs directory if it doesn't exist
        if (!is_dir(self::$logDirectory)) {
            if (!mkdir(self::$logDirectory, 0755, true)) {
                error_log("Failed to create logs directory: " . self::$logDirectory);
                return;
            }
            error_log("Created logs directory: " . self::$logDirectory);
        }
        
        // Verify directory is writable
        if (!is_writable(self::$logDirectory)) {
            error_log("Logs directory is not writable: " . self::$logDirectory);
            return;
        }
        
        error_log("Logger successfully initialized");
    }

    public static function log(string $message, array $context = []): void {
        if (!self::$enabled || !self::$logDirectory) {
            return;
        }

        try {
            $timestamp = date('Y-m-d H:i:s');
            $logPath = self::$logDirectory . '/' . self::$logFile;
            
            // Sanitize and format context data
            $contextStr = '';
            if (!empty($context)) {
                // Remove sensitive data
                $sanitizedContext = self::sanitizeContext($context);
                $contextStr = "\nContext: " . json_encode($sanitizedContext, JSON_PRETTY_PRINT);
            }
            
            $logMessage = sprintf(
                "[%s] %s%s\n----------------------------------------\n",
                $timestamp,
                $message,
                $contextStr
            );
            
            if (file_put_contents($logPath, $logMessage, FILE_APPEND | LOCK_EX) === false) {
                error_log("Failed to write to log file: $logPath");
            }
            
            // Set proper permissions on log file
            chmod($logPath, 0644);
            
        } catch (\Exception $e) {
            error_log("Logging failed: " . $e->getMessage());
        }
    }

    private static function sanitizeContext(array $context): array {
        $sensitiveKeys = ['password', 'token', 'api_key', 'secret', 'pin'];
        
        array_walk_recursive($context, function(&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '******';
            }
        });
        
        return $context;
    }
}
