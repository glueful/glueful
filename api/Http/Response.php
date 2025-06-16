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
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_UNPROCESSABLE_ENTITY = 422;
    public const HTTP_TOO_MANY_REQUESTS = 429;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    // Error type constants for standardized error handling
    public const ERROR_VALIDATION = 'VALIDATION_ERROR';
    public const ERROR_AUTHENTICATION = 'AUTHENTICATION_ERROR';
    public const ERROR_AUTHORIZATION = 'AUTHORIZATION_ERROR';
    public const ERROR_NOT_FOUND = 'NOT_FOUND_ERROR';
    public const ERROR_RATE_LIMIT = 'RATE_LIMIT_ERROR';
    public const ERROR_SERVER = 'SERVER_ERROR';
    public const ERROR_SECURITY = 'SECURITY_ERROR';

    private int $statusCode;
    private mixed $data;
    private ?string $message;
    private bool $success;
    private ?array $errorDetails;

    public function __construct(
        mixed $data = null,
        int $statusCode = self::HTTP_OK,
        ?string $message = null,
        bool $success = true,
        ?array $errorDetails = null
    ) {
        $this->data = $data ?? []; // Ensure data is at least an empty array
        $this->statusCode = $statusCode;
        $this->message = $message ?? ''; // Avoid null values in response
        $this->success = $success;
        $this->errorDetails = $errorDetails;
    }

    /**
     * Send HTTP Response
     *
     * @param string|null $key Optional key to extract from data as the response data
     * @return mixed Response data
     */
    public function send(?string $key = null): mixed
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');

        // Initialize response with base fields
        $response = [
            'success' => $this->success,
            'message' => $this->message,
            'code' => $this->statusCode,
        ];

        // Add error details for failed responses
        if (!$this->success && $this->errorDetails) {
            $response['error'] = $this->errorDetails;
        }

        // If a specific key is provided, use that to extract data
        if ($key !== null && is_array($this->data) && isset($this->data[$key])) {
            // Create a new response array with keys in specific order
            $orderedResponse = $response;
            $orderedResponse['data'] = $this->data[$key];
            $response = $orderedResponse;
        } elseif (is_array($this->data) && isset($this->data['data'])) {
            // Create a new response array with keys in specific order
            $orderedResponse = $response;
            $orderedResponse['data'] = $this->data['data'];

            // Add columns right after data if available
            if (isset($this->data['columns'])) {
                $orderedResponse['columns'] = $this->data['columns'];
            }

            // Add pagination fields after columns
            $paginationFields = ['current_page', 'per_page', 'total', 'last_page', 'has_more', 'from', 'to'];
            foreach ($paginationFields as $field) {
                if (isset($this->data[$field])) {
                    $orderedResponse[$field] = $this->data[$field];
                }
            }

            $response = $orderedResponse;
        } else {
            // Regular data response
            $response['data'] = $this->data;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        exit;
    }

    public static function ok(mixed $data = null, ?string $message = null): self
    {
        return new self($data, self::HTTP_OK, $message);
    }

    public static function created(mixed $data = null, ?string $message = null): self
    {
        return new self($data, self::HTTP_CREATED, $message);
    }

    public static function error(
        string $message,
        int $statusCode = self::HTTP_BAD_REQUEST,
        ?string $errorType = null,
        ?string $errorCode = null,
        mixed $details = null,
        ?string $requestId = null
    ): self {
        $errorDetails = [
            'type' => $errorType ?? self::getErrorTypeFromStatus($statusCode),
            'code' => $errorCode,
            'details' => $details,
            'timestamp' => date('c'),
            'request_id' => $requestId ?? self::generateRequestId()
        ];

        // Remove null values from error details
        $errorDetails = array_filter($errorDetails, fn($value) => $value !== null);

        return new self(null, $statusCode, $message, false, $errorDetails);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self([], self::HTTP_NOT_FOUND, $message, false);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, self::HTTP_UNAUTHORIZED, self::ERROR_AUTHENTICATION);
    }

    public static function forbidden(mixed $data = null, string $message = 'Forbidden'): self
    {
        return new self($data, self::HTTP_FORBIDDEN, $message, false);
    }

    /**
     * Get error type based on HTTP status code
     *
     * @param int $statusCode HTTP status code
     * @return string Error type constant
     */
    private static function getErrorTypeFromStatus(int $statusCode): string
    {
        return match ($statusCode) {
            400, 422 => self::ERROR_VALIDATION,
            401 => self::ERROR_AUTHENTICATION,
            403 => self::ERROR_AUTHORIZATION,
            404 => self::ERROR_NOT_FOUND,
            429 => self::ERROR_RATE_LIMIT,
            413, 415 => self::ERROR_SECURITY,
            default => self::ERROR_SERVER
        };
    }

    /**
     * Generate a unique request ID for error tracking
     *
     * @return string Unique request identifier
     */
    private static function generateRequestId(): string
    {
        return 'req_' . bin2hex(random_bytes(6));
    }
}
