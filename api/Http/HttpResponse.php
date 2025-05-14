<?php

declare(strict_types=1);

namespace Glueful\Http;

/**
 * HTTP Response
 *
 * Represents an HTTP response received from an external API.
 */
class HttpResponse
{
    /**
     * @var int HTTP status code
     */
    private int $statusCode;

    /**
     * @var array Response headers
     */
    private array $headers;

    /**
     * @var string Response body
     */
    private string $body;

    /**
     * Create a new HttpResponse instance
     *
     * @param int $statusCode HTTP status code
     * @param array $headers Response headers
     * @param string $body Response body
     */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * Get the response status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get all response headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific response header
     *
     * @param string $name Header name (case-insensitive)
     * @param mixed $default Default value if header doesn't exist
     * @return mixed Header value or default
     */
    public function getHeader(string $name, $default = null)
    {
        $name = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get the response body
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get response body contents
     *
     * @return string
     */
    public function getContents(): string
    {
        return $this->body;
    }

    /**
     * Parse the response body as JSON
     *
     * @param bool $assoc When true, returns array instead of object
     * @param int $depth Recursion depth
     * @param int $options JSON decode options
     * @return mixed
     * @throws \JsonException
     */
    public function json(bool $assoc = true, int $depth = 512, int $options = 0)
    {
        return json_decode($this->body, $assoc, $depth, $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Check if the response was successful
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if the response indicates a client error
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if the response indicates a server error
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}
