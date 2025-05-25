<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Not Found Exception
 *
 * Handles 404 errors for missing resources.
 * Provides standardized error response for resource lookup failures.
 */
class NotFoundException extends ApiException
{
    /**
     * Constructor
     *
     * Creates a new not found exception with standard 404 code.
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (defaults to 404)
     * @param array|null $details Additional error details
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Resource not found',
        int $statusCode = 404,
        array|null $details = null,
        \Throwable|null $previous = null
    ) {
        // If the message doesn't contain "not found", append it (for resource names)
        if (!str_contains($message, 'not found') && $message !== 'Resource not found') {
            $message = $message . ' not found';
        }
        parent::__construct($message, $statusCode, $details, $previous);
    }
}
