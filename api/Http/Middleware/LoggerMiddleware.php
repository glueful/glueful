<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Glueful\Logging\LogManager;
use Glueful\Exceptions\HttpProtocolException;

/**
 * Logger Middleware
 *
 * PSR-15 compatible middleware that logs API requests and responses.
 * Provides detailed request tracking with customizable logging levels.
 *
 * Features:
 * - Request logging with URL, method, and parameters
 * - Response time tracking
 * - Error logging for failed requests
 * - Customizable log channels and levels
 * - Request ID tracking for correlation
 */
class LoggerMiddleware implements MiddlewareInterface
{
    /** @var LogManager Logger instance */
    private LogManager $logger;

    /** @var string Log level for normal requests */
    private string $level;

    /**
     * Create a new logger middleware
     *
     * @param string $channel Log channel to use
     * @param string $level Log level for normal requests
     */
    public function __construct(string $channel = 'api', string $level = 'info')
    {
        $this->logger = new LogManager($channel);
        $this->level = $level;
    }

    /**
     * Process the request through the logger middleware
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Generate a unique request ID for correlation
        $requestId = uniqid('req-');
        $request->attributes->set('request_id', $requestId);

        // Record the start time
        $startTime = microtime(true);

        // NEW: Add contextual logging helper to request
        $request->attributes->set('logger_context', [
            'request_id' => $requestId,
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod()
        ]);

        // NEW: Add contextual logger method to request for easy use by other components
        $request->attributes->set('contextual_logger', function () use ($request) {
            $context = $request->attributes->get('logger_context', []);

            // Add user context if available (after authentication middleware)
            $user = $request->attributes->get('user');
            if ($user) {
                $context['user_id'] = $user->uuid ?? $user->id ?? null;
            }

            // Return a wrapper object that automatically includes context
            return new class ($this->logger, $context) {
                private LogManager $logger;
                private array $context;

                public function __construct(LogManager $logger, array $context)
                {
                    $this->logger = $logger;
                    $this->context = $context;
                }

                public function info($message, array $extraContext = []): void
                {
                    $this->logger->info($message, array_merge($this->context, $extraContext));
                }

                public function error($message, array $extraContext = []): void
                {
                    $this->logger->error($message, array_merge($this->context, $extraContext));
                }

                public function warning($message, array $extraContext = []): void
                {
                    $this->logger->warning($message, array_merge($this->context, $extraContext));
                }

                public function debug($message, array $extraContext = []): void
                {
                    $this->logger->debug($message, array_merge($this->context, $extraContext));
                }

                public function log($level, $message, array $extraContext = []): void
                {
                    $this->logger->log($level, $message, array_merge($this->context, $extraContext));
                }
            };
        });

        try {
            // NEW: Validate HTTP protocol (framework concern)
            $this->validateHttpProtocol($request);

            // Log the incoming request
            $this->logRequest($request, $requestId);

            // Process the request through the middleware pipeline
            $response = $handler->handle($request);

            // Calculate the request processing time
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log the response
            $this->logResponse($request, $response, $requestId, $processingTime);

            // NEW: Check for slow requests (framework concern)
            $this->checkSlowRequest($request, $response, $processingTime);

            // Add the request ID to the response for correlation
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (\Throwable $exception) {
            // Calculate the request processing time
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log the error
            $this->logError($request, $exception, $requestId, $processingTime);

            // Re-throw the exception for the global exception handler
            throw $exception;
        }
    }

    /**
     * Log the incoming request
     *
     * @param Request $request The incoming request
     * @param string $requestId The unique request ID
     */
    private function logRequest(Request $request, string $requestId): void
    {
        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => $request->getPathInfo(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ];

        // Add query parameters if present
        if (count($request->query->all()) > 0) {
            $context['query_params'] = $this->sanitizeParameters($request->query->all());
        }

        // Log the request
        $this->logger->{$this->level}('API request started', $context);
    }

    /**
     * Log the response
     *
     * @param Request $request The original request
     * @param Response $response The response
     * @param string $requestId The unique request ID
     * @param float $processingTime The request processing time in milliseconds
     */
    private function logResponse(
        Request $request,
        Response $response,
        string $requestId,
        float $processingTime
    ): void {
        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => $request->getPathInfo(),
            'status_code' => $response->getStatusCode(),
            'time_ms' => $processingTime,
        ];

        // Determine the log level based on the response status
        $level = $this->determineLogLevel($response->getStatusCode());

        // Log the response
        $this->logger->{$level}('API request completed', $context);
    }

    /**
     * Log an error that occurred during request processing
     *
     * @param Request $request The original request
     * @param \Throwable $exception The exception that was thrown
     * @param string $requestId The unique request ID
     * @param float $processingTime The request processing time in milliseconds
     */
    private function logError(
        Request $request,
        \Throwable $exception,
        string $requestId,
        float $processingTime
    ): void {
        $context = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => $request->getPathInfo(),
            'time_ms' => $processingTime,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        // Log the error
        $this->logger->error('API request failed', $context);
    }

    /**
     * Determine the appropriate log level based on the response status code
     *
     * @param int $statusCode The HTTP status code
     * @return string The log level to use
     */
    private function determineLogLevel(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warning';
        }

        return $this->level;
    }

    /**
     * Sanitize parameters for logging (remove sensitive data)
     *
     * @param array $parameters The parameters to sanitize
     * @return array The sanitized parameters
     */
    private function sanitizeParameters(array $parameters): array
    {
        $sensitiveFields = ['password', 'password_confirmation', 'token', 'secret', 'key', 'apikey', 'api_key'];

        foreach ($parameters as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields)) {
                $parameters[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $parameters[$key] = $this->sanitizeParameters($value);
            }
        }

        return $parameters;
    }

    /**
     * NEW: Validate HTTP protocol requirements (framework concern)
     *
     * @param Request $request
     * @throws HttpProtocolException
     */
    private function validateHttpProtocol(Request $request): void
    {
        // Check for malformed JSON (framework concern)
        $contentType = $request->headers->get('Content-Type');
        if ($contentType && strpos($contentType, 'application/json') !== false) {
            $content = $request->getContent();
            if (!empty($content) && json_decode($content) === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Malformed JSON request body', [
                    'type' => 'request_error',
                    'path' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'error_code' => 'JSON_PARSE_ERROR',
                    'request_id' => $request->attributes->get('request_id'),
                    'timestamp' => date('c')
                ]);
                throw HttpProtocolException::malformedJson('JSON parse error: ' . json_last_error_msg());
            }
        }

        // Check for required HTTP headers (framework concern)
        $requiredHeaders = config('http.required_headers', []);
        foreach ($requiredHeaders as $header) {
            if (!$request->headers->has($header)) {
                $this->logger->error('Missing required header', [
                    'type' => 'request_error',
                    'path' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'missing_header' => $header,
                    'error_code' => 'MISSING_HEADER',
                    'request_id' => $request->attributes->get('request_id'),
                    'timestamp' => date('c')
                ]);
                throw HttpProtocolException::missingHeader($header);
            }
        }
    }

    /**
     * NEW: Check for slow requests and log performance issues (framework concern)
     *
     * @param Request $request
     * @param Response $response
     * @param float $processingTime
     */
    private function checkSlowRequest(Request $request, Response $response, float $processingTime): void
    {
        $slowThreshold = config('logging.framework.slow_requests.threshold_ms', 1000);

        if ($processingTime > $slowThreshold) {
            $this->logger->warning('Slow request detected', [
                'type' => 'performance',
                'message' => 'Request exceeded performance threshold',
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'status' => $response->getStatusCode(),
                'processing_time_ms' => $processingTime,
                'threshold_ms' => $slowThreshold,
                'request_id' => $request->attributes->get('request_id'),
                'timestamp' => date('c')
            ]);
        }
    }
}
