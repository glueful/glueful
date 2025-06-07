<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Security Exception
 *
 * Exception thrown when security-related validation fails.
 * Used for content type validation, user agent requirements,
 * and other security policy violations.
 */
class SecurityException extends ApiException
{
    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default 400 Bad Request)
     * @param array|null $data Additional error data
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        array|null $data = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $data, $previous);
    }

    /**
     * Create exception for invalid content type
     *
     * @param string $received Received content type
     * @param array $expected Array of expected content types
     * @return self
     */
    public static function invalidContentType(string $received, array $expected): self
    {
        return new self(
            'Invalid content type provided',
            415,
            [
                'content_type_error' => true,
                'received' => $received,
                'expected' => $expected
            ]
        );
    }

    /**
     * Create exception for suspicious activity
     *
     * @param string $activity Description of suspicious activity
     * @param array $context Additional context data
     * @return self
     */
    public static function suspiciousActivity(string $activity, array $context = []): self
    {
        return new self(
            'Suspicious activity detected',
            403,
            array_merge([
                'security_violation' => true,
                'activity' => $activity
            ], $context)
        );
    }

    /**
     * Create exception for rate limit abuse
     *
     * @param string $endpoint Endpoint being abused
     * @param int $attempts Number of attempts
     * @return self
     */
    public static function rateLimitAbuse(string $endpoint, int $attempts): self
    {
        return new self(
            'Rate limit abuse detected',
            429,
            [
                'rate_limit_abuse' => true,
                'endpoint' => $endpoint,
                'attempts' => $attempts
            ]
        );
    }
}
