<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Glueful\Http\Response\Response;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Events\Http\HttpClientFailureEvent;
use Glueful\Events\Event;
use Psr\Log\LoggerInterface;

/**
 * HTTP Client Service
 *
 * Modern HTTP client built on Symfony HttpClient with support for async requests,
 * connection pooling, retry mechanisms, and PSR-18 compliance.
 */
class Client
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Send a GET request
     */
    public function get(string $url, array $options = []): Response
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * Send a POST request
     */
    public function post(string $url, array $options = []): Response
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * Send a PUT request
     */
    public function put(string $url, array $options = []): Response
    {
        return $this->request('PUT', $url, $options);
    }

    /**
     * Send a DELETE request
     */
    public function delete(string $url, array $options = []): Response
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * Send a PATCH request
     */
    public function patch(string $url, array $options = []): Response
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * Send an HTTP request
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        $startTime = microtime(true);

        try {
            // Transform options to Symfony format
            $symfonyOptions = $this->transformOptions($options);

            $response = $this->httpClient->request($method, $url, $symfonyOptions);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log slow requests
            $this->logSlowRequest($method, $url, $duration);

            // Log server errors
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 500) {
                $this->logServerError($method, $url, $statusCode, $duration);

                $exception = new HttpClientException("HTTP $statusCode error from server", $statusCode);
                Event::dispatch(new HttpClientFailureEvent(
                    $method,
                    $url,
                    $exception,
                    'server_error',
                    ['duration_ms' => $duration, 'status_code' => $statusCode]
                ));
            }

            return new Response($response);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('HTTP request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);

            $exception = new HttpClientException($e->getMessage(), $e->getCode());

            Event::dispatch(new HttpClientFailureEvent(
                $method,
                $url,
                $exception,
                'connection_failed',
                ['duration_ms' => $duration]
            ));

            throw $exception;
        }
    }

    /**
     * Create a scoped client with default options
     */
    public function createScopedClient(array $defaultOptions = []): self
    {
        $scopedClient = $this->httpClient->withOptions($defaultOptions);
        return new self($scopedClient, $this->logger);
    }

    /**
     * Send an async request (returns Symfony ResponseInterface)
     */
    public function requestAsync(string $method, string $url, array $options = []): ResponseInterface
    {
        $symfonyOptions = $this->transformOptions($options);
        return $this->httpClient->request($method, $url, $symfonyOptions);
    }

    /**
     * Send multiple requests in batch
     */
    public function requestBatch(array $requests): array
    {
        $responses = [];
        foreach ($requests as $key => $request) {
            $responses[$key] = $this->requestAsync(
                $request['method'],
                $request['url'],
                $request['options'] ?? []
            );
        }
        return $responses;
    }

    /**
     * Transform Glueful options to Symfony HttpClient format
     */
    private function transformOptions(array $options): array
    {
        $symfonyOptions = [];

        // Transform timeout options
        if (isset($options['timeout'])) {
            $symfonyOptions['timeout'] = $options['timeout'];
        }
        if (isset($options['connect_timeout'])) {
            $symfonyOptions['timeout'] = $options['connect_timeout'];
        }

        // Transform headers
        if (isset($options['headers'])) {
            $symfonyOptions['headers'] = $options['headers'];
        }

        // Transform query parameters
        if (isset($options['query'])) {
            $symfonyOptions['query'] = $options['query'];
        }

        // Transform JSON body
        if (isset($options['json'])) {
            $symfonyOptions['json'] = $options['json'];
        }

        // Transform form parameters
        if (isset($options['form_params'])) {
            $symfonyOptions['body'] = http_build_query($options['form_params']);
            $symfonyOptions['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        // Transform raw body
        if (isset($options['body'])) {
            $symfonyOptions['body'] = $options['body'];
        }

        // Transform SSL verification
        if (isset($options['verify'])) {
            $symfonyOptions['verify_peer'] = $options['verify'];
            $symfonyOptions['verify_host'] = $options['verify'];
        }

        // Transform sink (file download)
        if (isset($options['sink'])) {
            $symfonyOptions['buffer'] = false;
            $symfonyOptions['user_data'] = ['sink' => $options['sink']];
        }

        return $symfonyOptions;
    }

    /**
     * Log slow HTTP requests
     */
    private function logSlowRequest(string $method, string $url, float $duration): void
    {
        $slowThreshold = config('http.logging.slow_threshold_ms', 5000);
        if ($duration > $slowThreshold) {
            $this->logger->warning('HTTP client slow request', [
                'type' => 'performance',
                'message' => 'HTTP request exceeded threshold',
                'url' => $url,
                'method' => $method,
                'duration_ms' => $duration,
                'threshold_ms' => $slowThreshold,
                'timestamp' => date('c')
            ]);
        }
    }

    /**
     * Log server errors
     */
    private function logServerError(string $method, string $url, int $statusCode, float $duration): void
    {
        $this->logger->error('HTTP client server error', [
            'type' => 'http_client',
            'message' => 'Server error response received',
            'url' => $url,
            'method' => $method,
            'status' => $statusCode,
            'duration_ms' => $duration,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Set logger for framework infrastructure logging (backward compatibility)
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
}
