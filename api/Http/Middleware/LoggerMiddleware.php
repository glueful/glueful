<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Glueful\Logging\LogManager;

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

    /** @var string Log channel to use */
    private string $channel;

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
        $this->channel = $channel;
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

        // Log the incoming request
        $this->logRequest($request, $requestId);

        try {
            // Process the request through the middleware pipeline
            $response = $handler->handle($request);

            // Calculate the request processing time
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log the response
            $this->logResponse($request, $response, $requestId, $processingTime);

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
}
