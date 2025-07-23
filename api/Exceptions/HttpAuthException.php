<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * HTTP Authentication Exception
 *
 * Exception class for HTTP-level authentication failures.
 * Used by the framework to represent protocol-level auth errors such as
 * missing authorization headers, malformed tokens, and JWT format violations.
 * This is distinct from business authentication logic.
 */
class HttpAuthException extends AuthenticationException
{
    /** @var string|null The authentication scheme that failed */
    private ?string $authScheme = null;

    /** @var string|null Token prefix for logging (without sensitive data) */
    private ?string $tokenPrefix = null;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (typically 401)
     * @param string|null $authScheme The authentication scheme (e.g., 'Bearer', 'Basic')
     * @param string|null $tokenPrefix Safe token prefix for logging
     */
    public function __construct(
        string $message,
        int $statusCode = 401,
        ?string $authScheme = null,
        ?string $tokenPrefix = null
    ) {
        parent::__construct($message, $statusCode);

        $this->authScheme = $authScheme;
        $this->tokenPrefix = $tokenPrefix;
    }

    /**
     * Get the authentication scheme
     *
     * @return string|null
     */
    public function getAuthScheme(): ?string
    {
        return $this->authScheme;
    }

    /**
     * Get the token prefix (safe for logging)
     *
     * @return string|null
     */
    public function getTokenPrefix(): ?string
    {
        return $this->tokenPrefix;
    }

    /**
     * Create exception for missing authorization header
     *
     * @return self
     */
    public static function missingAuthorizationHeader(): self
    {
        return new self(
            'Authorization header required',
            401,
            null,
            null
        );
    }

    /**
     * Create exception for malformed authorization header
     *
     * @param string $headerValue The malformed header value (will be sanitized)
     * @return self
     */
    public static function malformedAuthorizationHeader(string $headerValue): self
    {
        // Extract scheme safely
        $parts = explode(' ', $headerValue, 2);
        $scheme = $parts[0] ?? 'unknown';

        return new self(
            'Malformed authorization header',
            401,
            $scheme,
            null
        );
    }

    /**
     * Create exception for invalid JWT token format
     *
     * @param string $token The invalid token (will be sanitized)
     * @return self
     */
    public static function invalidJwtFormat(string $token): self
    {
        $tokenPrefix = strlen($token) > 10 ? substr($token, 0, 10) : null;

        return new self(
            'Invalid token format',
            401,
            'Bearer',
            $tokenPrefix
        );
    }

    /**
     * Create exception for expired token
     *
     * @param string $tokenType Type of token (defaults to 'Bearer')
     * @param string|null $token The expired token (will be sanitized)
     * @return self
     */
    public static function tokenExpired(string $tokenType = 'Bearer', ?string $token = null): self
    {
        $tokenPrefix = $token && strlen($token) > 10 ? substr($token, 0, 10) : null;

        return new self(
            'Token has expired',
            401,
            $tokenType,
            $tokenPrefix
        );
    }

    /**
     * Create exception for unsupported authentication scheme
     *
     * @param string $scheme The unsupported scheme
     * @return self
     */
    public static function unsupportedScheme(string $scheme): self
    {
        return new self(
            "Unsupported authentication scheme: {$scheme}",
            401,
            $scheme,
            null
        );
    }
}
