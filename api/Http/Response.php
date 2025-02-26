<?php

namespace Glueful\Http;

/**
 * API Response Handler
 * 
 * Provides standardized HTTP response formatting for the API.
 * Includes common HTTP status codes and response building methods.
 */
class Response
{
    /**
     * HTTP Status Code Constants
     * 
     * Standard HTTP status codes used in API responses
     */
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    /**
     * @var int HTTP status code for the response
     */
    private int $statusCode;

    /**
     * @var mixed Response payload data
     */
    private mixed $data;

    /**
     * @var string|null Optional response message
     */
    private ?string $message;

    /**
     * @var bool Whether the request was successful
     */
    private bool $success;

    /**
     * Constructor
     * 
     * @param mixed $data Response payload
     * @param int $statusCode HTTP status code
     * @param string|null $message Optional response message
     * @param bool $success Request success status
     */
    public function __construct(
        mixed $data = null,
        int $statusCode = self::HTTP_OK,
        ?string $message = null,
        bool $success = true
    ) {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->message = $message;
        $this->success = $success;
    }

    /**
     * Send HTTP Response
     * 
     * Sets HTTP status code, headers, and returns formatted response array.
     * 
     * @return array{success: bool, message: ?string, data: mixed, code: int}
     */
    public function send(): array
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');

        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'code' => $this->statusCode
        ];
    }

    /**
     * Create successful response
     * 
     * @param mixed $data Response payload
     * @param string|null $message Optional success message
     * @return self
     */
    public static function ok(mixed $data = null, ?string $message = null): self
    {
        return new self($data, self::HTTP_OK, $message);
    }

    /**
     * Create resource created response
     * 
     * @param mixed $data Created resource data
     * @param string|null $message Optional creation message
     * @return self
     */
    public static function created(mixed $data = null, ?string $message = null): self
    {
        return new self($data, self::HTTP_CREATED, $message);
    }

    /**
     * Create error response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP error status code
     * @return self
     */
    public static function error(string $message, int $statusCode = self::HTTP_BAD_REQUEST, mixed $error = null): self
    {
        return new self($error, $statusCode, $message, false);
    }

    /**
     * Create not found response
     * 
     * @param string $message Not found message
     * @return self
     */
    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self(null, self::HTTP_NOT_FOUND, $message, false);
    }

    /**
     * Create unauthorized response
     * 
     * @param string $message Unauthorized message
     * @return self
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(null, self::HTTP_UNAUTHORIZED, $message, false);
    }
}
