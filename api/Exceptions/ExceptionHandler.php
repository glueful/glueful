<?php

namespace Glueful\Exceptions;

use Glueful\Logging\LogManagerInterface;
use Glueful\Logging\LogManager;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
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
     * @var AuditLogger|null Audit logger instance
     */
    private static ?AuditLogger $auditLogger = null;


    /**
     * @var array Error response rate limits by IP
     */
    private static array $errorResponseLimits = [];

    /**
     * @var int Maximum error responses per IP per minute
     */
    private static int $maxErrorResponsesPerMinute = 60;

    /**
     * @var array Cache for request context to avoid repeated calculations
     */
    private static array $contextCache = [];

    /**
     * @var bool Flag to enable/disable verbose context building
     */
    private static bool $verboseContext = true;

    /**
     * @var array Lightweight context for high-frequency errors
     */
    private static array $lightweightContext = [];

    /**
     * Map of exception types to log channels
     * @var array<string, string>
     */
    private static array $channelMap = [
        ValidationException::class => 'validation',
        AuthenticationException::class => 'auth',
        NotFoundException::class => 'http',
        ApiException::class => 'api',
        DatabaseException::class => 'database',
        SecurityException::class => 'security',
        RateLimitExceededException::class => 'ratelimit',
        HttpException::class => 'http_client',
        ExtensionException::class => 'extensions',
        \Glueful\Permissions\Exceptions\PermissionException::class => 'permissions',
        \Glueful\Permissions\Exceptions\UnauthorizedException::class => 'auth',
        \Glueful\Permissions\Exceptions\ProviderNotFoundException::class => 'permissions',
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
     * Register the exception handler for global exception and error handling
     *
     * This method sets up the global exception and error handlers to ensure
     * all uncaught exceptions and PHP errors are processed consistently.
     *
     * @return void
     */
    public static function register(): void
    {
        // Register exception handler for uncaught exceptions
        set_exception_handler([self::class, 'handleException']);

        // Register error handler to convert PHP errors to exceptions
        set_error_handler([self::class, 'handleError']);

        // Register shutdown handler for fatal errors
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Handle PHP errors by converting them to exceptions
     *
     * This allows PHP errors (warnings, notices, etc.) to be handled
     * by the same exception handling system for consistency.
     *
     * @param int $severity Error severity level
     * @param string $message Error message
     * @param string $filename File where error occurred
     * @param int $lineno Line number where error occurred
     * @return bool Returns true to prevent PHP's internal error handler from running
     * @throws \ErrorException Throws to convert error to exception for consistency
     */
    public static function handleError(int $severity, string $message, string $filename, int $lineno): bool
    {
        // Check if error should be reported based on error_reporting setting
        if (!(error_reporting() & $severity)) {
            // This error code is not included in error_reporting, so let PHP handle it
            return false;
        }

        // Convert PHP error to ErrorException so it can be handled by handleException
        throw new \ErrorException($message, 0, $severity, $filename, $lineno);
    }

    /**
     * Handle fatal errors during shutdown
     *
     * Catches fatal errors that occur during script execution and
     * provides a consistent error response even for fatal errors.
     *
     * @return void
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        // Check if this was a fatal error
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            // Create an exception from the fatal error
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );

            // Handle it like any other exception
            self::handleException($exception);
        }
    }

    /**
     * Extract current user UUID from request for audit logging
     *
     * Attempts to get the current user context through multiple methods:
     * 1. Session data (if available)
     * 2. Authentication headers (JWT/API Key)
     *
     * @return string|null User UUID if found, null otherwise
     */
    private static function getCurrentUserFromRequest(): ?string
    {
        // Try session first (fastest method)
        if (isset($_SESSION['user_uuid'])) {
            return $_SESSION['user_uuid'];
        }

        // Try extracting from authentication headers
        try {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            $authManager = \Glueful\Auth\AuthBootstrap::getManager();
            $userData = $authManager->authenticateWithProviders(['jwt', 'api_key'], $request);

            if ($userData) {
                // Check common UUID locations in auth data
                return $userData['user_uuid'] ?? $userData['uuid'] ?? $userData['user']['uuid'] ?? null;
            }
        } catch (\Throwable) {
            // Silently fail during exception handling - we don't want to throw
            // exceptions while handling exceptions
        }

        return null;
    }

    /**
     * Build comprehensive context for error logging
     *
     * Creates a context array with request information, user data,
     * and system state for comprehensive error tracking.
     *
     * @param string|null $userUuid User UUID if available
     * @return array Context array for logging
     */
    private static function buildContextFromRequest(?string $userUuid = null): array
    {
        $context = [
            // User context
            'user_uuid' => $userUuid,
            'session_id' => session_id() ?: null,

            // Request context
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'query_string' => $_SERVER['QUERY_STRING'] ?? null,
            'request_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'unknown',
            'request_scheme' => $_SERVER['REQUEST_SCHEME'] ?? ($_SERVER['HTTPS'] ?? false ? 'https' : 'http'),
            'request_host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'request_port' => $_SERVER['SERVER_PORT'] ?? null,

            // Client context
            'ip_address' => self::getRealIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,

            // Authentication context
            'auth_header' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : 'missing',
            'api_key_header' => isset($_SERVER['HTTP_X_API_KEY']) ? 'present' : 'missing',

            // Request timing
            'timestamp' => date('c'), // ISO 8601 format
            'request_time' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
            'processing_time' => (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))),

            // System context
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'environment' => env('APP_ENV', 'unknown'),

            // Request body info (without actual content for security) - lazy loaded
            'request_body_info' => self::getRequestBodyInfo(),

            // Additional server context
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
            'request_id' => self::generateRequestId()
        ];

        // Add request headers (filtered for security)
        $context['request_headers'] = self::getFilteredHeaders();

        // Add rate limiting context if available
        $context['rate_limit_info'] = self::getRateLimitInfo();

        return $context;
    }

    /**
     * Get the real IP address of the client
     *
     * @return string Client IP address
     */
    private static function getRealIpAddress(): string
    {
        // Check for various proxy headers
        $ipHeaders = [
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Handle comma-separated IPs (X-Forwarded-For can have multiple)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get filtered request headers (excluding sensitive information)
     *
     * @return array Filtered headers
     */
    private static function getFilteredHeaders(): array
    {
        $headers = [];
        $sensitiveHeaders = [
            'authorization',
            'x-api-key',
            'cookie',
            'x-csrf-token',
            'x-auth-token'
        ];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace(['HTTP_', '_'], ['', '-'], $key));

                if (in_array($headerName, $sensitiveHeaders)) {
                    $headers[$headerName] = 'redacted';
                } else {
                    $headers[$headerName] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * Generate a unique request ID for tracking
     *
     * @return string Unique request ID
     */
    private static function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Get rate limiting information if available
     *
     * @return array Rate limit context
     */
    private static function getRateLimitInfo(): array
    {
        $info = [];

        // Check if we have error response rate limit data
        if (!empty(self::$errorResponseLimits)) {
            $clientIp = self::getRealIpAddress();
            $currentTime = time();
            $windowStart = $currentTime - 60;

            $recentRequests = array_filter(
                self::$errorResponseLimits,
                fn($timestamp, $key) => strpos($key, $clientIp) === 0 && $timestamp > $windowStart,
                ARRAY_FILTER_USE_BOTH
            );

            $info['error_requests_in_window'] = count($recentRequests);
            $info['error_limit'] = self::$maxErrorResponsesPerMinute;
            $info['window_remaining'] = 60 - ($currentTime % 60);
        }

        return $info;
    }

    /**
     * Get optimized context based on exception type and frequency
     *
     * @param string|null $userUuid User UUID
     * @param \Throwable $exception Exception being handled
     * @return array Optimized context array
     */
    private static function getOptimizedContext(?string $userUuid, \Throwable $exception): array
    {
        // Use lightweight context for high-frequency, low-priority exceptions
        if (self::shouldUseLightweightContext($exception)) {
            return self::getLightweightContext($userUuid);
        }

        // Use cached context if available and recent
        $cacheKey = 'context_' . ($userUuid ?? 'anonymous');
        if (isset(self::$contextCache[$cacheKey])) {
            $cached = self::$contextCache[$cacheKey];
            // Use cached context if it's less than 30 seconds old
            if ((microtime(true) - $cached['_cache_time']) < 30) {
                $cached['user_uuid'] = $userUuid; // Update user UUID
                unset($cached['_cache_time']); // Remove cache metadata
                return $cached;
            }
        }

        // Build full context and cache it
        $context = self::$verboseContext
            ? self::buildContextFromRequest($userUuid)
            : self::buildBasicContext($userUuid);

        $context['_cache_time'] = microtime(true);
        self::$contextCache[$cacheKey] = $context;

        // Clean up cache if it gets too large
        if (count(self::$contextCache) > 10) {
            self::$contextCache = array_slice(self::$contextCache, -5, null, true);
        }

        unset($context['_cache_time']);
        return $context;
    }

    /**
     * Determine if lightweight context should be used
     *
     * @param \Throwable $exception Exception being handled
     * @return bool True if lightweight context should be used
     */
    private static function shouldUseLightweightContext(\Throwable $exception): bool
    {
        // Use lightweight context for validation errors and other frequent exceptions
        $lightweightExceptions = [
            ValidationException::class,
            NotFoundException::class,
        ];

        $exceptionClass = get_class($exception);
        return in_array($exceptionClass, $lightweightExceptions);
    }

    /**
     * Get lightweight context for high-frequency exceptions
     *
     * @param string|null $userUuid User UUID
     * @return array Lightweight context
     */
    private static function getLightweightContext(?string $userUuid): array
    {
        // Cache lightweight context to avoid repeated calculations
        if (!empty(self::$lightweightContext)) {
            $context = self::$lightweightContext;
            $context['user_uuid'] = $userUuid;
            $context['timestamp'] = date('c');
            return $context;
        }

        self::$lightweightContext = [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'ip_address' => self::getRealIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'environment' => env('APP_ENV', 'unknown'),
            'request_id' => self::generateRequestId(),
            'lightweight' => true
        ];

        $context = self::$lightweightContext;
        $context['user_uuid'] = $userUuid;
        $context['timestamp'] = date('c');

        return $context;
    }

    /**
     * Build basic context (less detailed than full context)
     *
     * @param string|null $userUuid User UUID
     * @return array Basic context
     */
    private static function buildBasicContext(?string $userUuid): array
    {
        return [
            'user_uuid' => $userUuid,
            'session_id' => session_id() ?: null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'ip_address' => self::getRealIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => date('c'),
            'memory_usage' => memory_get_usage(true),
            'environment' => env('APP_ENV', 'unknown'),
            'request_id' => self::generateRequestId(),
            'context_level' => 'basic'
        ];
    }

    /**
     * Enable or disable verbose context building
     *
     * @param bool $enabled Whether to use verbose context
     */
    public static function setVerboseContext(bool $enabled): void
    {
        self::$verboseContext = $enabled;
    }

    /**
     * Clear context cache (useful for testing or memory management)
     */
    public static function clearContextCache(): void
    {
        self::$contextCache = [];
        self::$lightweightContext = [];
    }

    /**
     * Get context cache statistics
     *
     * @return array Cache statistics
     */
    public static function getContextCacheStats(): array
    {
        return [
            'cache_size' => count(self::$contextCache),
            'lightweight_cached' => !empty(self::$lightweightContext),
            'verbose_mode' => self::$verboseContext
        ];
    }

    /**
     * Get request body information with caching to avoid multiple reads
     *
     * @return array Request body information
     */
    private static function getRequestBodyInfo(): array
    {
        static $bodyInfo = null;

        if ($bodyInfo === null) {
            $input = file_get_contents('php://input') ?: '';
            $bodyInfo = [
                'has_request_body' => !empty($input),
                'request_body_size' => strlen($input),
                'content_length_header' => $_SERVER['CONTENT_LENGTH'] ?? null
            ];
        }

        return $bodyInfo;
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
     * Set the audit logger instance
     *
     * @param AuditLogger|null $auditLogger
     */
    public static function setAuditLogger(?AuditLogger $auditLogger): void
    {
        self::$auditLogger = $auditLogger;
    }

    /**
     * Get the audit logger instance
     *
     * @return AuditLogger
     */
    private static function getAuditLogger(): AuditLogger
    {
        if (self::$auditLogger === null) {
            self::$auditLogger = AuditLogger::getInstance();
        }
        return self::$auditLogger;
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
     * Handle uncaught exceptions with comprehensive coverage
     *
     * Processes all types of exceptions with proper status codes, logging,
     * and user context extraction for audit trails.
     *
     * @param \Throwable $exception The exception to handle
     * @return void
     */
    public static function handleException(\Throwable $exception): void
    {
        // Check rate limiting for error responses
        if (!self::checkErrorRateLimit()) {
            // If rate limited, return a generic error without detailed logging
            self::outputJsonResponse(429, 'Too many error requests', ['retry_after' => 60]);
            return;
        }

        // Get user context for enhanced logging (with performance optimization)
        $userUuid = self::getCurrentUserFromRequest();
        $context = self::getOptimizedContext($userUuid, $exception);

        // Log the error with enhanced context
        self::logError($exception, $context);

        // Log security-relevant exceptions to audit system
        self::logToAuditSystem($exception, $userUuid, $context);

        // Determine appropriate status code, message, and data based on exception type
        $statusCode = 500;
        $message = 'Internal server error';
        $data = null;

        // Handle all exception types with proper status codes and messages
        if ($exception instanceof ValidationException) {
            $statusCode = 422;
            $message = $exception->getMessage();
            $data = $exception->getErrors();
        } elseif ($exception instanceof AuthenticationException) {
            $statusCode = 401;
            $message = $exception->getMessage();
        } elseif ($exception instanceof \Glueful\Permissions\Exceptions\UnauthorizedException) {
            $statusCode = 403;
            $message = $exception->getMessage();
        } elseif ($exception instanceof NotFoundException) {
            $statusCode = 404;
            $message = $exception->getMessage();
        } elseif ($exception instanceof RateLimitExceededException) {
            $statusCode = 429;
            $message = $exception->getMessage();
            $data = $exception->getData(); // Contains retry_after
        } elseif ($exception instanceof SecurityException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        } elseif ($exception instanceof HttpException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
            $data = $exception->getData();
        } elseif ($exception instanceof DatabaseException) {
            $statusCode = 500;
            $message = 'Database operation failed';
            // Don't expose database details in production
            $data = self::shouldShowErrorDetails() ?
                ['error' => self::sanitizeErrorMessage($exception->getMessage())] : null;
        } elseif ($exception instanceof ExtensionException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
            $data = $exception->getData();
        } elseif ($exception instanceof \Glueful\Permissions\Exceptions\PermissionException) {
            $statusCode = 403;
            $message = 'Permission denied';
            $data = self::shouldShowErrorDetails() ? ['context' => $exception->getContext()] : null;
        } elseif ($exception instanceof \Glueful\Permissions\Exceptions\ProviderNotFoundException) {
            $statusCode = 500;
            $message = 'Permission system unavailable';
        } elseif ($exception instanceof ApiException) {
            // Handle general ApiException (should come after specific ones)
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
            $data = $exception->getData();
        } elseif ($exception instanceof \ErrorException) {
            // Handle converted PHP errors
            $statusCode = 500;
            $message = 'System error occurred';
            $data = self::shouldShowErrorDetails() ? [
                'error' => self::sanitizeErrorMessage($exception->getMessage()),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine()
            ] : null;
        } else {
            // Handle any other throwable (Error, Exception)
            $statusCode = 500;
            $message = 'Unexpected error occurred';
            $data = self::shouldShowErrorDetails() ? [
                'type' => get_class($exception),
                'error' => self::sanitizeErrorMessage($exception->getMessage())
            ] : null;
        }

        // Output the JSON response using Response class
        self::outputJsonResponse($statusCode, $message, $data);
    }

    /**
     * Check if error details should be shown based on environment
     *
     * @return bool True if detailed errors should be shown
     */
    private static function shouldShowErrorDetails(): bool
    {
        $environment = env('APP_ENV', 'production');
        $debugMode = config('app.debug', false);

        // Only show detailed errors in development environment with debug enabled
        return $debugMode && ($environment === 'development' || $environment === 'local');
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
     * Check error response rate limit for the current IP
     *
     * @return bool True if within rate limit, false if rate limited
     */
    private static function checkErrorRateLimit(): bool
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $currentTime = time();
        $windowStart = $currentTime - 60; // 1-minute window

        // Clean up old entries
        self::$errorResponseLimits = array_filter(
            self::$errorResponseLimits,
            fn($timestamp) => $timestamp > $windowStart
        );

        // Count current IP's requests in the window
        $ipRequests = array_filter(
            self::$errorResponseLimits,
            fn($timestamp, $ip) => $ip === $clientIp && $timestamp > $windowStart,
            ARRAY_FILTER_USE_BOTH
        );

        if (count($ipRequests) >= self::$maxErrorResponsesPerMinute) {
            return false;
        }

        // Record this request
        self::$errorResponseLimits[$clientIp . '_' . $currentTime] = $currentTime;
        return true;
    }

    /**
     * Sanitize error message to prevent information leakage
     *
     * @param string $message Original error message
     * @return string Sanitized error message
     */
    private static function sanitizeErrorMessage(string $message): string
    {
        $environment = env('APP_ENV', 'production');

        // Always sanitize sensitive information unless in development/local
        if (!in_array($environment, ['development', 'local']) || !config('app.debug', false)) {
            // Remove all file paths (both Unix and Windows)
            $message = preg_replace('/[\/\\\\][^\s]*\.php/', '[file]', $message);
            $message = preg_replace('/[\/\\\\][^\s]*\.inc/', '[file]', $message);

            // Remove database connection details
            $message = preg_replace('/password\s*=\s*[^\s;,)]+/i', 'password=[REDACTED]', $message);
            $message = preg_replace('/pwd\s*=\s*[^\s;,)]+/i', 'pwd=[REDACTED]', $message);
            $message = preg_replace('/host\s*=\s*[^\s;,)]+/i', 'host=[REDACTED]', $message);
            $message = preg_replace('/server\s*=\s*[^\s;,)]+/i', 'server=[REDACTED]', $message);
            $message = preg_replace('/database\s*=\s*[^\s;,)]+/i', 'database=[REDACTED]', $message);
            $message = preg_replace('/dbname\s*=\s*[^\s;,)]+/i', 'dbname=[REDACTED]', $message);

            // Remove full namespaces and class paths
            $message = preg_replace('/\\\\[A-Za-z\\\\]+\\\\[A-Za-z]+/', '[class]', $message);
            $message = preg_replace('/[A-Za-z\\\\]+\\\\[A-Za-z]+::[A-Za-z]+/', '[method]', $message);

            // Sanitize common database errors
            if (stripos($message, 'SQLSTATE') !== false) {
                $message = 'Database operation failed';
            }
            if (stripos($message, 'duplicate entry') !== false) {
                $message = 'Data already exists';
            }
            if (stripos($message, 'foreign key constraint') !== false) {
                $message = 'Data constraint violation';
            }
            if (stripos($message, 'table') !== false && stripos($message, "doesn't exist") !== false) {
                $message = 'Resource not found';
            }

            // Remove internal configuration details
            $message = preg_replace('/\.env.*/', '[config]', $message);
            $message = preg_replace('/config\/.*\.php/', '[config]', $message);

            // Remove stack trace indicators
            $message = preg_replace('/Stack trace:.*$/s', '', $message);
            $message = preg_replace('/#\d+.*$/m', '', $message);

            // Sanitize common authentication errors
            if (stripos($message, 'token') !== false && stripos($message, 'invalid') !== false) {
                $message = 'Authentication failed';
            }

            // Generic fallback for remaining technical details
            if (strlen($message) > 200) {
                $message = 'An internal error occurred';
            }
        }

        return trim($message);
    }

    /**
     * Log security-relevant exceptions to audit system
     *
     * @param \Throwable $exception The exception to audit
     * @param string|null $userUuid Current user UUID
     * @param array $context Request context
     * @return void
     */
    private static function logToAuditSystem(\Throwable $exception, ?string $userUuid, array $context): void
    {
        try {
            $auditLogger = self::getAuditLogger();

            // Determine if this exception should be audited
            $shouldAudit = false;
            $category = AuditEvent::CATEGORY_SYSTEM;
            $severity = AuditEvent::SEVERITY_ERROR;

            if ($exception instanceof AuthenticationException) {
                $shouldAudit = true;
                $category = AuditEvent::CATEGORY_AUTH;
                $severity = AuditEvent::SEVERITY_WARNING;
            } elseif (
                $exception instanceof \Glueful\Permissions\Exceptions\UnauthorizedException ||
                $exception instanceof \Glueful\Permissions\Exceptions\PermissionException
            ) {
                $shouldAudit = true;
                $category = AuditEvent::CATEGORY_AUTHZ;
                $severity = AuditEvent::SEVERITY_WARNING;
            } elseif ($exception instanceof SecurityException) {
                $shouldAudit = true;
                $category = AuditEvent::CATEGORY_SYSTEM;
                $severity = AuditEvent::SEVERITY_CRITICAL;
            } elseif ($exception instanceof RateLimitExceededException) {
                $shouldAudit = true;
                $category = AuditEvent::CATEGORY_SYSTEM;
                $severity = AuditEvent::SEVERITY_WARNING;
            } elseif ($exception instanceof DatabaseException) {
                $shouldAudit = true;
                $category = AuditEvent::CATEGORY_SYSTEM;
                $severity = AuditEvent::SEVERITY_ERROR;
            }

            if ($shouldAudit) {
                $auditDetails = [
                    'exception_type' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode(),
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine(),
                    'request_context' => $context
                ];

                // Create audit event
                $event = new AuditEvent(
                    $category,
                    'exception_occurred',
                    $severity,
                    $auditDetails
                );

                if ($userUuid) {
                    $event->setActor($userUuid);
                }

                $auditLogger->logAuditEvent($event);
            }
        } catch (\Throwable $auditException) {
            // If audit logging fails, don't throw an exception
            // Just log it to the regular error log
            error_log('Failed to log exception to audit system: ' . $auditException->getMessage());
        }
    }

    /**
     * Output a JSON response using Response class for consistency
     *
     * @param int $statusCode HTTP status code
     * @param string $message Error message
     * @param mixed $data Additional error data
     * @return void
     */
    private static function outputJsonResponse(int $statusCode, string $message, $data = null): void
    {
        if (self::$testMode) {
            // In test mode, capture the response in Glueful format
            self::$testResponse = [
                'success' => $statusCode < 400,
                'message' => $message,
                'code' => $statusCode
            ];

            if ($data !== null) {
                self::$testResponse['data'] = $data;
            }

            return; // Don't output or exit
        }

        // Use Response class for consistent output format
        if ($statusCode >= 400) {
            // Determine appropriate error type based on status code
            $errorType = match ($statusCode) {
                400, 422 => \Glueful\Http\Response::ERROR_VALIDATION,
                401 => \Glueful\Http\Response::ERROR_AUTHENTICATION,
                403 => \Glueful\Http\Response::ERROR_AUTHORIZATION,
                404 => \Glueful\Http\Response::ERROR_NOT_FOUND,
                429 => \Glueful\Http\Response::ERROR_RATE_LIMIT,
                default => \Glueful\Http\Response::ERROR_SERVER
            };

            \Glueful\Http\Response::error($message, $statusCode, $errorType, null, $data)->send();
        } else {
            // Success response (shouldn't happen in exception handler, but for completeness)
            \Glueful\Http\Response::ok($data, $message)->send();
        }
    }
}
