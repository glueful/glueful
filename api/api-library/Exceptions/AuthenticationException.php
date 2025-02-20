<?php
declare(strict_types=1);

namespace Glueful\Api\Exceptions;

/**
 * Authentication Exception
 * 
 * Handles authentication failures in the API.
 * Returns standard 401 Unauthorized responses.
 */
class AuthenticationException extends ApiException
{
    /**
     * Constructor
     * 
     * Creates a new authentication exception with 401 status code.
     * 
     * @param string $message Custom error message
     */
    public function __construct(string $message = 'Authentication failed')
    {
        parent::__construct($message, 401);
    }
}