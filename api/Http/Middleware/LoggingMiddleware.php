<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Logging Middleware
 *
 * Provides comprehensive HTTP request and response logging with configurable
 * detail levels, security filtering, and performance metrics.
 */
class LoggingMiddleware
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Log an outgoing HTTP request
     */
    public function logRequest(
        string $method,
        string $url,
        array $options = [],
        bool $logBody = false,
        bool $logHeaders = true
    ): void {
        $logData = [
            'type' => 'http_request',
            'method' => $method,
            'url' => $this->sanitizeUrl($url),
            'timestamp' => date('c'),
        ];

        if ($logHeaders && isset($options['headers'])) {
            $logData['headers'] = $this->sanitizeHeaders($options['headers']);
        }

        if ($logBody && isset($options['body'])) {
            $logData['body'] = $this->sanitizeBody($options['body']);
        } elseif ($logBody && isset($options['json'])) {
            $logData['body'] = $this->sanitizeBody(json_encode($options['json']));
        }

        if (isset($options['timeout'])) {
            $logData['timeout'] = $options['timeout'];
        }

        $this->logger->debug('HTTP Request', $logData);
    }

    /**
     * Log an HTTP response
     */
    public function logResponse(
        ResponseInterface $response,
        float $duration,
        bool $logBody = false,
        bool $logHeaders = true
    ): void {
        $statusCode = $response->getStatusCode();
        $logLevel = $this->getLogLevel($statusCode);

        $logData = [
            'type' => 'http_response',
            'status_code' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => date('c'),
        ];

        if ($logHeaders) {
            $logData['headers'] = $this->sanitizeHeaders($response->getHeaders(false));
        }

        if ($logBody) {
            try {
                $content = $response->getContent(false);
                $logData['body'] = $this->sanitizeBody($content);
                $logData['body_size'] = strlen($content);
            } catch (\Exception $e) {
                $logData['body_error'] = 'Failed to read response body: ' . $e->getMessage();
            }
        }

        $this->logger->log($logLevel, 'HTTP Response', $logData);
    }

    /**
     * Log a request failure
     */
    public function logFailure(
        string $method,
        string $url,
        \Exception $exception,
        float $duration
    ): void {
        $this->logger->error('HTTP Request Failed', [
            'type' => 'http_request_failed',
            'method' => $method,
            'url' => $this->sanitizeUrl($url),
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'error_code' => $exception->getCode(),
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Log a slow request
     */
    public function logSlowRequest(
        string $method,
        string $url,
        float $duration,
        float $threshold
    ): void {
        $this->logger->warning('Slow HTTP Request', [
            'type' => 'slow_http_request',
            'method' => $method,
            'url' => $this->sanitizeUrl($url),
            'duration_ms' => round($duration * 1000, 2),
            'threshold_ms' => round($threshold * 1000, 2),
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Log retry attempt
     */
    public function logRetry(
        string $method,
        string $url,
        int $attempt,
        int $maxAttempts,
        string $reason
    ): void {
        $this->logger->info('HTTP Request Retry', [
            'type' => 'http_retry',
            'method' => $method,
            'url' => $this->sanitizeUrl($url),
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'reason' => $reason,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Sanitize URL to remove sensitive information
     */
    private function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (!$parsed) {
            return '[INVALID_URL]';
        }

        // Remove query parameters that might contain sensitive data
        $sensitiveParams = ['api_key', 'access_token', 'password', 'secret', 'token'];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            foreach ($sensitiveParams as $param) {
                if (isset($queryParams[$param])) {
                    $queryParams[$param] = '[REDACTED]';
                }
            }
            $parsed['query'] = http_build_query($queryParams);
        }

        return $this->buildUrl($parsed);
    }

    /**
     * Sanitize headers to remove sensitive information
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'x-api-key',
            'x-auth-token',
            'x-access-token',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ];

        $sanitized = [];
        foreach ($headers as $name => $value) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $sensitiveHeaders)) {
                $sanitized[$name] = '[REDACTED]';
            } else {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize request/response body
     */
    private function sanitizeBody(string $body): string
    {
        // Limit body size for logging
        $maxBodySize = 10000; // 10KB
        if (strlen($body) > $maxBodySize) {
            $body = substr($body, 0, $maxBodySize) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $sensitiveFields = [
                'password', 'secret', 'token', 'api_key', 'access_token',
                'refresh_token', 'client_secret', 'private_key', 'credit_card',
                'ssn', 'social_security_number', 'cvv', 'cvc'
            ];

            $this->sanitizeArray($decoded, $sensitiveFields);
            return json_encode($decoded, JSON_PRETTY_PRINT);
        }

        return $body;
    }

    /**
     * Recursively sanitize array fields
     */
    private function sanitizeArray(array &$array, array $sensitiveFields): void
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->sanitizeArray($value, $sensitiveFields);
            } elseif (in_array(strtolower($key), $sensitiveFields)) {
                $value = '[REDACTED]';
            }
        }
    }

    /**
     * Get appropriate log level based on HTTP status code
     */
    private function getLogLevel(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 500 => 'error',
            $statusCode >= 400 => 'warning',
            $statusCode >= 300 => 'info',
            default => 'debug'
        };
    }

    /**
     * Build URL from parsed components
     */
    private function buildUrl(array $parsed): string
    {
        $url = '';

        if (isset($parsed['scheme'])) {
            $url .= $parsed['scheme'] . '://';
        }

        if (isset($parsed['host'])) {
            $url .= $parsed['host'];
        }

        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }

        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }

        if (isset($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }

        return $url;
    }

    /**
     * Check if logging is enabled for the current environment
     */
    public static function isEnabled(): bool
    {
        return config('http.logging.enabled', false);
    }

    /**
     * Check if request logging is enabled
     */
    public static function shouldLogRequests(): bool
    {
        return config('http.logging.log_requests', true);
    }

    /**
     * Check if response logging is enabled
     */
    public static function shouldLogResponses(): bool
    {
        return config('http.logging.log_responses', true);
    }

    /**
     * Check if body logging is enabled
     */
    public static function shouldLogBodies(): bool
    {
        return config('http.logging.log_body', false);
    }
}
