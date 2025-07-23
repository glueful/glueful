<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

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
     * @param int $statusCode HTTP status code (defaults to 401)
     * @param array|null $data Additional error data
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Authentication failed',
        int $statusCode = 401,
        array|null $data = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $data, $previous);
    }

    /**
     * Create exception for invalid credentials
     *
     * @param string $method Authentication method used
     * @return self
     */
    public static function invalidCredentials(string $method = 'credentials'): self
    {
        return new self(
            "Invalid $method provided",
            401,
            ['auth_method' => $method, 'invalid_credentials' => true]
        );
    }

    /**
     * Create exception for expired token
     *
     * @param string $tokenType Type of token (JWT, API key, etc.)
     * @return self
     */
    public static function tokenExpired(string $tokenType = 'token'): self
    {
        return new self(
            "$tokenType has expired",
            401,
            ['token_type' => $tokenType, 'token_expired' => true]
        );
    }

    /**
     * Create exception for missing authentication
     *
     * @return self
     */
    public static function missingAuthentication(): self
    {
        return new self(
            'Authentication required',
            401,
            ['missing_authentication' => true]
        );
    }
}
