<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth;

use Glueful\Auth\AuthenticationProviderInterface;
use Glueful\Container;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\OAuthServer;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth Authentication Provider
 *
 * Implements authentication provider interface for OAuth 2.0 tokens.
 *
 * @package Glueful\Auth
 */
class OAuthAuthenticationProvider implements AuthenticationProviderInterface
{
    /**
     * @var string|null Current error message
     */
    private ?string $error = null;

    /**
     * @var OAuthServer OAuth server instance
     */
    private OAuthServer $oauthServer;

    /**
     * @var QueryBuilder Database query builder instance
     */
    private QueryBuilder $queryBuilder;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize database connection and query builder
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Create OAuth server with required dependencies
        $this->oauthServer = new OAuthServer();
    }
    /**
     * Authenticate a request
     *
     * Validates the authentication credentials in the request and
     * returns user information if authentication is successful.
     *
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    /**
     * Authenticate a request
     *
     * Validates the authentication credentials in the request and
     * returns user information if authentication is successful.
     *
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticate(Request $request): ?array
    {
        // Check for bearer token in Authorization header
        $authHeader = $request->headers->get('Authorization');
        $token = null;

        if ($authHeader && strpos(strtolower($authHeader), 'bearer ') === 0) {
            $token = substr($authHeader, 7); // Remove "Bearer " from the header
        }

        // Check query parameter if header not found
        if (!$token) {
            $token = $request->query->get('access_token');
        }

        // Check form parameter if still not found
        if (!$token) {
            $token = $request->request->get('access_token');
        }

        if (!$token) {
            $this->error = 'No access token provided';
            return null;
        }

        // Validate the token
        $tokenInfo = $this->oauthServer->validateToken($token);

        if (!$tokenInfo) {
            $this->error = 'Invalid or expired token';
            return null;
        }

        // Get user data if token is associated with a user
        if (empty($tokenInfo['user_id'])) {
            // Client credentials flow - no user associated
            return [
                'id' => null,
                'client_id' => $tokenInfo['client_id'],
                'scopes' => $tokenInfo['scopes'],
                'type' => 'client',
                'expires_at' => $tokenInfo['expires_at']
            ];
        }

        // Get user data from database using QueryBuilder
        $users = $this->queryBuilder->select('users', ['id', 'username', 'email', 'role'])
            ->where(['id' => $tokenInfo['user_id']])
            ->limit(1)
            ->get();

        if (empty($users)) {
            $this->error = 'User not found';
            return null;
        }

        $user = $users[0];

        // Return user data with token details
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'client_id' => $tokenInfo['client_id'],
            'scopes' => $tokenInfo['scopes'],
            'type' => 'user',
            'expires_at' => $tokenInfo['expires_at']
        ];
    }

    /**
     * Check if a user has admin privileges
     *
     * Determines if the authenticated user has admin permissions.
     *
     * @param array $userData User data from successful authentication
     * @return bool True if user has admin privileges, false otherwise
     */
    public function isAdmin(array $userData): bool
    {
        // Check if user has admin role
        if (isset($userData['role']) && $userData['role'] === 'admin') {
            return true;
        }

        // Check if user has admin scope
        if (isset($userData['scopes']) && in_array('admin', $userData['scopes'])) {
            return true;
        }

        return false;
    }

    /**
     * Get the current authentication error, if any
     *
     * @return string|null The authentication error message or null if no error
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Validate a token
     *
     * Checks if a token is valid according to this provider's rules.
     *
     * @param string $token The token to validate
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(string $token): bool
    {
        $tokenInfo = $this->oauthServer->validateToken($token);
        return $tokenInfo !== null;
    }

    /**
     * Check if this provider can handle a given token
     *
     * Determines if the token format is compatible with this provider.
     *
     * @param string $token The token to check
     * @return bool True if this provider can validate this token
     */
    public function canHandleToken(string $token): bool
    {
        // Since OAuth tokens are generally opaque, we need to try validation
        // But we can do a basic check for common OAuth token formats
        return preg_match('/^[a-zA-Z0-9\-_]+$/', $token) === 1;
    }

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
    ): array {
        // Get client ID from user data or use default client
        $clientId = $userData['client_id'] ?? $this->getDefaultClientId();

        // Process password grant with user credentials
        $tokenData = [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'username' => $userData['username'] ?? $userData['email'],
            'password' => $userData['password'] ?? '',
            'scope' => $userData['scope'] ?? 'basic profile'
        ];

        // Issue token through OAuth server
        try {
            $tokenResponse = $this->oauthServer->issueToken($tokenData);

            return [
                'access_token' => $tokenResponse['access_token'],
                'refresh_token' => $tokenResponse['refresh_token'] ?? null,
                'expires_in' => $tokenResponse['expires_in'],
                'token_type' => $tokenResponse['token_type'],
                'scope' => $tokenResponse['scope'] ?? null
            ];
        } catch (\Exception $e) {
            $this->error = $e->getMessage();

            return [
                'access_token' => null,
                'refresh_token' => null,
                'error' => $this->error
            ];
        }
    }

    /**
     * Refresh authentication tokens
     *
     * Generates new token pair using refresh token.
     *
     * @param string $refreshToken Current refresh token
     * @param array $sessionData Session data associated with the refresh token
     * @return array|null New token pair or null if invalid
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        // Get client ID from session data or use default client
        $clientId = $sessionData['client_id'] ?? $this->getDefaultClientId();

        // Process refresh token grant
        $tokenData = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken
        ];

        try {
            $tokenResponse = $this->oauthServer->issueToken($tokenData);

            return [
                'access_token' => $tokenResponse['access_token'],
                'refresh_token' => $tokenResponse['refresh_token'] ?? $refreshToken,
                'expires_in' => $tokenResponse['expires_in'],
                'token_type' => $tokenResponse['token_type'],
                'scope' => $tokenResponse['scope'] ?? null
            ];
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return null;
        }
    }

    /**
     * Get default client ID for system-generated tokens
     *
     * @return string Default client ID
     */
    private function getDefaultClientId(): string
    {
        $clients = $this->queryBuilder->select('oauth_clients', ['id'])
            ->where(['is_default' => 1])
            ->limit(1)
            ->get();

        if (empty($clients)) {
            // If no default client exists, use a placeholder
            // In a production environment, this should be properly handled
            return 'default_client';
        }

        return $clients[0]['id'];
    }
}
