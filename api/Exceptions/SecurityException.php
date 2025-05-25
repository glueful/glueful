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
     */
    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
    }
}
