<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * HTTP Protocol Exception
 *
 * Exception class for HTTP protocol violations and malformed requests.
 * Used by the framework to represent protocol-level errors such as
 * malformed JSON, missing required headers, and other HTTP standard violations.
 */
class HttpProtocolException extends ApiException
{
    /** @var string|null The error code for categorizing protocol violations */
    private ?string $errorCode = null;

    /** @var array Additional context about the protocol violation */
    private array $protocolContext = [];

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (typically 400)
     * @param string|null $errorCode Specific error code for categorization
     * @param array $protocolContext Additional context about the violation
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        ?string $errorCode = null,
        array $protocolContext = []
    ) {
        parent::__construct($message, $statusCode, ['error_code' => $errorCode]);

        $this->errorCode = $errorCode;
        $this->protocolContext = $protocolContext;
    }

    /**
     * Get the protocol error code
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get additional protocol context
     *
     * @return array
     */
    public function getProtocolContext(): array
    {
        return $this->protocolContext;
    }

    /**
     * Create exception for malformed JSON
     *
     * @param string $jsonError JSON error description
     * @return self
     */
    public static function malformedJson(string $jsonError = 'Invalid JSON format'): self
    {
        return new self(
            'Malformed JSON request body',
            400,
            'JSON_PARSE_ERROR',
            ['json_error' => $jsonError]
        );
    }

    /**
     * Create exception for missing required header
     *
     * @param string $headerName The missing header name
     * @return self
     */
    public static function missingHeader(string $headerName): self
    {
        return new self(
            "Missing required header: {$headerName}",
            400,
            'MISSING_HEADER',
            ['missing_header' => $headerName]
        );
    }

    /**
     * Create exception for invalid content type
     *
     * @param string $expected Expected content type
     * @param string $actual Actual content type
     * @return self
     */
    public static function invalidContentType(string $expected, string $actual): self
    {
        return new self(
            "Invalid content type. Expected {$expected}, got {$actual}",
            400,
            'INVALID_CONTENT_TYPE',
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Create exception for request body too large
     *
     * @param int $maxSize Maximum allowed size
     * @param int $actualSize Actual request size
     * @return self
     */
    public static function requestTooLarge(int $maxSize, int $actualSize): self
    {
        return new self(
            "Request body too large. Maximum {$maxSize} bytes allowed",
            413,
            'REQUEST_TOO_LARGE',
            ['max_size' => $maxSize, 'actual_size' => $actualSize]
        );
    }
}
