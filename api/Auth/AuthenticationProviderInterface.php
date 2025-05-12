<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Authentication Provider Interface
 *
 * Defines the contract for authentication providers.
 * This abstraction allows for multiple authentication strategies
 * to be used interchangeably throughout the application.
 *
 * Benefits:
 * - Separation of concerns between routing and authentication
 * - Support for multiple authentication methods (JWT, OAuth, API keys, etc.)
 * - Simplified testing with mock implementations
 * - Flexible authentication strategies based on context
 */
interface AuthenticationProviderInterface
{
    /**
     * Authenticate a request
     *
     * Validates the authentication credentials in the request and
     * returns user information if authentication is successful.
     *
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticate(Request $request): ?array;

    /**
     * Check if a user has admin privileges
     *
     * Determines if the authenticated user has admin permissions.
     *
     * @param array $userData User data from successful authentication
     * @return bool True if user has admin privileges, false otherwise
     */
    public function isAdmin(array $userData): bool;

    /**
     * Get the current authentication error, if any
     *
     * @return string|null The authentication error message or null if no error
     */
    public function getError(): ?string;

    /**
     * Validate a token
     *
     * Checks if a token is valid according to this provider's rules.
     *
     * @param string $token The token to validate
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(string $token): bool;

    /**
     * Check if this provider can handle a given token
     *
     * Determines if the token format is compatible with this provider.
     *
     * @param string $token The token to check
     * @return bool True if this provider can validate this token
     */
    public function canHandleToken(string $token): bool;

    /**
     * Generate authentication tokens
     *
     * Creates access and refresh tokens for a user.
     *
     * @param array $userData User data to encode in tokens
     * @param int|null $accessTokenLifetime Access token lifetime in seconds
     * @param int|null $refreshTokenLifetime Refresh token lifetime in seconds
     * @return array Token pair with access_token and refresh_token
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array;

    /**
     * Refresh authentication tokens
     *
     * Generates new token pair using refresh token.
     *
     * @param string $refreshToken Current refresh token
     * @param array $sessionData Session data associated with the refresh token
     * @return array|null New token pair or null if invalid
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array;
}
