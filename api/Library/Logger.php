<?php

declare(strict_types=1);

namespace Glueful\Api\Library;

require_once __DIR__ . '/../bootstrap.php';

use Exception;

class Logger
{
    private static string $logFile = 'api_debug.log';
    private static bool $enabled = false;
    private static ?string $logDirectory = null;

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

    // Shortcut methods for logging at different levels
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }
}