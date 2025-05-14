<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

use Exception;

/**
 * HTTP Exception
 *
 * Exception class for HTTP client errors.
 * Used by the HTTP client to represent network errors, cURL issues,
 * unexpected response formats, and other HTTP-related failures.
 */
class HttpException extends ApiException
{
    /** @var mixed|null The HTTP response body if available */
    private mixed $responseBody = null;

    /** @var array|null HTTP response headers if available */
    private array|null $responseHeaders = null;

    /** @var string|null The request URL that caused the exception */
    private string|null $requestUrl = null;

    /** @var string|null The HTTP method used in the request */
    private string|null $requestMethod = null;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code or curl error code
     * @param array|null $data Additional error data
     * @param mixed|null $responseBody Response body if available
     * @param array|null $responseHeaders Response headers if available
     * @param string|null $requestUrl The request URL that failed
     * @param string|null $requestMethod The HTTP method used in the request
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        array|null $data = null,
        mixed $responseBody = null,
        array|null $responseHeaders = null,
        string|null $requestUrl = null,
        string|null $requestMethod = null
    ) {
        parent::__construct($message, $statusCode, $data);

        $this->responseBody = $responseBody;
        $this->responseHeaders = $responseHeaders;
        $this->requestUrl = $requestUrl;
        $this->requestMethod = $requestMethod;
    }

    /**
     * Get the HTTP response body
     *
     * @return mixed|null
     */
    public function getResponseBody(): mixed
    {
        return $this->responseBody;
    }

    /**
     * Get the HTTP response headers
     *
     * @return array|null
     */
    public function getResponseHeaders(): array|null
    {
        return $this->responseHeaders;
    }

    /**
     * Get the request URL that failed
     *
     * @return string|null
     */
    public function getRequestUrl(): string|null
    {
        return $this->requestUrl;
    }

    /**
     * Get the HTTP method used in the request
     *
     * @return string|null
     */
    public function getRequestMethod(): string|null
    {
        return $this->requestMethod;
    }

    /**
     * Create an HttpException from a response
     *
     * @param \Glueful\Http\HttpResponse $response
     * @param string|null $message Custom error message or null to use status code
     * @param string|null $requestUrl The request URL that failed
     * @param string|null $requestMethod The HTTP method used in the request
     * @return self
     */
    public static function fromResponse(
        \Glueful\Http\HttpResponse $response,
        ?string $message = null,
        ?string $requestUrl = null,
        ?string $requestMethod = null
    ): self {
        // Use provided message or create one based on status code
        $message = $message ?? "HTTP request failed with status code {$response->getStatusCode()}";

        // Try to get JSON error details from response
        $responseData = null;
        try {
            if (
                isset($response->getHeaders()['Content-Type']) &&
                strpos($response->getHeaders()['Content-Type'][0] ?? '', 'application/json') !== false
            ) {
                $responseData = $response->json();
            }
        } catch (\Exception $e) {
            // If JSON parsing fails, just use the raw body
        }

        return new self(
            $message,
            $response->getStatusCode(),
            is_array($responseData) ? $responseData : null,
            $response->getBody(),
            $response->getHeaders(),
            $requestUrl,
            $requestMethod
        );
    }

    /**
     * Create an HttpException from a cURL error
     *
     * @param int $errorCode cURL error code
     * @param string $errorMessage cURL error message
     * @param string|null $requestUrl The request URL that failed
     * @param string|null $requestMethod The HTTP method used in the request
     * @return self
     */
    public static function fromCurlError(
        int $errorCode,
        string $errorMessage,
        ?string $requestUrl = null,
        ?string $requestMethod = null
    ): self {
        return new self(
            "cURL error ($errorCode): $errorMessage",
            $errorCode,
            ['curl_error' => true],
            null,
            null,
            $requestUrl,
            $requestMethod
        );
    }
}
