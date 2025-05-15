<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories\AccessTokenRepository;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories\ClientRepository;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories\RefreshTokenRepository;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories\ScopeRepository;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories\AuthCodeRepository;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories\UserRepository;

/**
 * OAuth Server
 *
 * Handles OAuth 2.0 token issuance, validation, and management.
 */
class OAuthServer
{
    /**
     * @var QueryBuilder Database query builder instance
     */
    private QueryBuilder $queryBuilder;

    /**
     * @var array Repository instances
     */
    private array $repositories = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize database connection and query builder
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Initialize repositories
        $this->initializeRepositories();
    }

    /**
     * Initialize OAuth repositories
     */
    private function initializeRepositories(): void
    {
        // Initialize all required repositories
        $this->repositories = [
            'client' => new ClientRepository(),
            'accessToken' => new AccessTokenRepository(),
            'refreshToken' => new RefreshTokenRepository(),
            'scope' => new ScopeRepository(),
            'authCode' => new AuthCodeRepository(),
            'user' => new UserRepository()
        ];
    }

    /**
     * Issue a new token
     *
     * @param array $request Token request data
     * @return array Token response
     * @throws \Exception If token cannot be issued
     */
    public function issueToken(array $request): array
    {
        $grantType = $request['grant_type'] ?? null;

        if (!$grantType) {
            throw new \Exception('Grant type is required');
        }

        // Validate client ID
        $clientId = $request['client_id'] ?? null;
        if (!$clientId) {
            throw new \Exception('Client ID is required');
        }

        // Get client from repository
        $clientRepo = $this->repositories['client'];
        $client = $clientRepo->getClientEntity($clientId, $grantType, $request['client_secret'] ?? null);

        if (!$client) {
            throw new \Exception('Invalid client');
        }

        // Process based on grant type
        switch ($grantType) {
            case 'password':
                return $this->handlePasswordGrant($request, $client);

            case 'refresh_token':
                return $this->handleRefreshTokenGrant($request, $client);

            case 'client_credentials':
                return $this->handleClientCredentialsGrant($request, $client);

            case 'authorization_code':
                return $this->handleAuthorizationCodeGrant($request, $client);

            default:
                throw new \Exception('Unsupported grant type: ' . $grantType);
        }
    }

    /**
     * Validate an access token
     *
     * @param string $token Access token to validate
     * @return array|null Token information if valid, null otherwise
     */
    public function validateToken(string $token): ?array
    {
        // Query the database for the token
        $tokens = $this->queryBuilder->select(
            'oauth_access_tokens',
            ['id', 'user_id', 'client_id', 'scopes', 'revoked', 'expires_at']
        )
            ->where(['id' => $token])
            ->limit(1)
            ->get();

        if (empty($tokens)) {
            return null;
        }

        $tokenData = $tokens[0];

        // Check if token is revoked
        if ((bool)$tokenData['revoked']) {
            return null;
        }

        // Check if token is expired
        $expiresAt = strtotime($tokenData['expires_at']);
        if ($expiresAt < time()) {
            return null;
        }

        // Parse scopes from JSON
        $scopes = json_decode($tokenData['scopes'], true) ?? [];

        // Return token information
        return [
            'token_id' => $tokenData['id'],
            'user_id' => $tokenData['user_id'],
            'client_id' => $tokenData['client_id'],
            'scopes' => $scopes,
            'expires_at' => $tokenData['expires_at']
        ];
    }

    /**
     * Revoke an access token
     *
     * @param string $token Access token to revoke
     * @return bool True if token was revoked, false otherwise
     */
    public function revokeToken(string $token): bool
    {
        // Update token status to revoked
        $updated = $this->queryBuilder->update(
            'oauth_access_tokens',
            ['revoked' => true],
            ['id' => $token]
        );

        // Also revoke any refresh tokens associated with this access token
        if ($updated) {
            $this->queryBuilder->update(
                'oauth_refresh_tokens',
                ['revoked' => true],
                ['access_token_id' => $token]
            );
        }

        return $updated > 0;
    }

    /**
     * Handle password grant type
     *
     * @param array $request Request data
     * @param object $client Client entity
     * @return array Token response
     * @throws \Exception If authentication fails
     */
    private function handlePasswordGrant(array $request, $client): array
    {
        // Validate username and password
        $username = $request['username'] ?? null;
        $password = $request['password'] ?? null;

        if (!$username || !$password) {
            throw new \Exception('Username and password are required');
        }

        // Authenticate user
        $user = $this->repositories['user']->getUserEntityByUserCredentials(
            $username,
            $password,
            'password',
            $client
        );

        if (!$user) {
            throw new \Exception('Invalid username or password');
        }

        // Process requested scopes
        $requestedScopes = $this->processScopeRequest($request, $client);

        // Generate access token
        $accessToken = $this->repositories['accessToken']->getNewToken(
            $client,
            $requestedScopes,
            $user->getIdentifier()
        );
        $accessToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID

        // Set expiration time (1 hour by default)
        $accessToken->setExpiryDateTime(
            (new \DateTimeImmutable())->add(new \DateInterval('PT1H'))
        );

        // Persist access token
        $this->repositories['accessToken']->persistNewAccessToken($accessToken);

        // Generate refresh token if requested
        $refreshToken = null;
        if (strpos(($request['scope'] ?? ''), 'offline_access') !== false) {
            $refreshToken = $this->repositories['refreshToken']->getNewRefreshToken();
            $refreshToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID
            $refreshToken->setAccessToken($accessToken);

            // Set expiration time (30 days by default)
            $refreshToken->setExpiryDateTime(
                (new \DateTimeImmutable())->add(new \DateInterval('P30D'))
            );

            // Persist refresh token
            $this->repositories['refreshToken']->persistNewRefreshToken($refreshToken);
        }

        // Format and return response
        return $this->formatTokenResponse($accessToken, $refreshToken);
    }

    /**
     * Handle refresh token grant type
     *
     * @param array $request Request data
     * @param object $client Client entity
     * @return array Token response
     * @throws \Exception If token refresh fails
     */
    private function handleRefreshTokenGrant(array $request, $client): array
    {
        // Validate refresh token
        $refreshTokenId = $request['refresh_token'] ?? null;

        if (!$refreshTokenId) {
            throw new \Exception('Refresh token is required');
        }

        // Check if refresh token is valid
        $refreshTokens = $this->queryBuilder->select(
            'oauth_refresh_tokens',
            ['id', 'access_token_id', 'revoked', 'expires_at']
        )
            ->where(['id' => $refreshTokenId])
            ->limit(1)
            ->get();

        if (empty($refreshTokens) || (bool)$refreshTokens[0]['revoked']) {
            throw new \Exception('Invalid refresh token');
        }

        // Check if token is expired
        $expiresAt = strtotime($refreshTokens[0]['expires_at']);
        if ($expiresAt < time()) {
            throw new \Exception('Refresh token has expired');
        }

        // Get the access token
        $accessTokenId = $refreshTokens[0]['access_token_id'];
        $accessTokens = $this->queryBuilder->select(
            'oauth_access_tokens',
            ['id', 'user_id', 'client_id', 'scopes']
        )
            ->where(['id' => $accessTokenId])
            ->limit(1)
            ->get();

        if (empty($accessTokens)) {
            throw new \Exception('Original access token not found');
        }

        $oldAccessToken = $accessTokens[0];

        // Verify the client matches
        if ($oldAccessToken['client_id'] !== $client->getIdentifier()) {
            throw new \Exception('Refresh token was not issued to this client');
        }

        // Revoke old tokens
        $this->revokeToken($accessTokenId);

        // Create new access token
        $accessToken = $this->repositories['accessToken']->getNewToken(
            $client,
            json_decode($oldAccessToken['scopes'], true) ?? [],
            $oldAccessToken['user_id']
        );
        $accessToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID

        // Set expiration time (1 hour by default)
        $accessToken->setExpiryDateTime(
            (new \DateTimeImmutable())->add(new \DateInterval('PT1H'))
        );

        // Persist access token
        $this->repositories['accessToken']->persistNewAccessToken($accessToken);

        // Generate new refresh token
        $refreshToken = $this->repositories['refreshToken']->getNewRefreshToken();
        $refreshToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID
        $refreshToken->setAccessToken($accessToken);

        // Set expiration time (30 days by default)
        $refreshToken->setExpiryDateTime(
            (new \DateTimeImmutable())->add(new \DateInterval('P30D'))
        );

        // Persist refresh token
        $this->repositories['refreshToken']->persistNewRefreshToken($refreshToken);

        // Format and return response
        return $this->formatTokenResponse($accessToken, $refreshToken);
    }

    /**
     * Handle client credentials grant type
     *
     * @param array $request Request data
     * @param object $client Client entity
     * @return array Token response
     */
    private function handleClientCredentialsGrant(array $request, $client): array
    {
        // Process requested scopes
        $requestedScopes = $this->processScopeRequest($request, $client);

        // Generate access token (no user ID for client credentials)
        $accessToken = $this->repositories['accessToken']->getNewToken($client, $requestedScopes, null);
        $accessToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID

        // Set expiration time (1 hour by default)
        $accessToken->setExpiryDateTime(
            (new \DateTimeImmutable())->add(new \DateInterval('PT1H'))
        );

        // Persist access token
        $this->repositories['accessToken']->persistNewAccessToken($accessToken);

        // Format and return response (no refresh token for client credentials)
        return $this->formatTokenResponse($accessToken, null);
    }

    /**
     * Handle authorization code grant type
     *
     * @param array $request Request data
     * @param object $client Client entity
     * @return array Token response
     * @throws \Exception If code is invalid
     */
    private function handleAuthorizationCodeGrant(array $request, $client): array
    {
        // Validate authorization code
        $code = $request['code'] ?? null;

        if (!$code) {
            throw new \Exception('Authorization code is required');
        }

        // Validate redirect URI
        $redirectUri = $request['redirect_uri'] ?? null;

        // Check if code is valid
        $authCodes = $this->queryBuilder->select(
            'oauth_auth_codes',
            ['id', 'user_id', 'client_id', 'scopes', 'revoked', 'expires_at']
        )
            ->where(['id' => $code])
            ->limit(1)
            ->get();

        if (empty($authCodes) || (bool)$authCodes[0]['revoked']) {
            throw new \Exception('Invalid authorization code');
        }

        // Check if code is expired
        $expiresAt = strtotime($authCodes[0]['expires_at']);
        if ($expiresAt < time()) {
            throw new \Exception('Authorization code has expired');
        }

        // Verify the client matches
        if ($authCodes[0]['client_id'] !== $client->getIdentifier()) {
            throw new \Exception('Authorization code was not issued to this client');
        }

        // Revoke the authorization code
        $this->queryBuilder->update(
            'oauth_auth_codes',
            ['revoked' => true],
            ['id' => $code]
        );

        // Create new access token
        $accessToken = $this->repositories['accessToken']->getNewToken(
            $client,
            json_decode($authCodes[0]['scopes'], true) ?? [],
            $authCodes[0]['user_id']
        );
        $accessToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID

        // Set expiration time (1 hour by default)
        $accessToken->setExpiryDateTime(
            (new \DateTimeImmutable())->add(new \DateInterval('PT1H'))
        );

        // Persist access token
        $this->repositories['accessToken']->persistNewAccessToken($accessToken);

        // Generate refresh token if offline_access was requested
        $scopes = json_decode($authCodes[0]['scopes'], true) ?? [];
        $refreshToken = null;

        if (in_array('offline_access', $scopes)) {
            $refreshToken = $this->repositories['refreshToken']->getNewRefreshToken();
            $refreshToken->setIdentifier(bin2hex(random_bytes(20))); // Generate token ID
            $refreshToken->setAccessToken($accessToken);

            // Set expiration time (30 days by default)
            $refreshToken->setExpiryDateTime(
                (new \DateTimeImmutable())->add(new \DateInterval('P30D'))
            );

            // Persist refresh token
            $this->repositories['refreshToken']->persistNewRefreshToken($refreshToken);
        }

        // Format and return response
        return $this->formatTokenResponse($accessToken, $refreshToken);
    }

    /**
     * Process requested scopes
     *
     * @param array $request Request data
     * @param object $client Client entity
     * @return array Approved scope entities
     */
    private function processScopeRequest(array $request, $client): array
    {
        $requestedScopeStr = $request['scope'] ?? '';
        $requestedScopeIds = !empty($requestedScopeStr) ? explode(' ', $requestedScopeStr) : [];

        $requestedScopes = [];
        foreach ($requestedScopeIds as $scopeId) {
            $scope = $this->repositories['scope']->getScopeEntityByIdentifier($scopeId);
            if ($scope) {
                $requestedScopes[] = $scope;
            }
        }

        // If no scopes requested, use default scope
        if (empty($requestedScopes)) {
            $defaultScope = $this->repositories['scope']->getScopeEntityByIdentifier('basic');
            if ($defaultScope) {
                $requestedScopes[] = $defaultScope;
            }
        }

        // Finalize scopes (checks if they're allowed for this client/user)
        return $this->repositories['scope']->finalizeScopes(
            $requestedScopes,
            $request['grant_type'],
            $client
        );
    }

    /**
     * Format standard OAuth2 token response
     *
     * @param object $accessToken Access token entity
     * @param object|null $refreshToken Refresh token entity or null
     * @return array Formatted token response
     */
    private function formatTokenResponse($accessToken, $refreshToken = null): array
    {
        $response = [
            'access_token' => $accessToken->getIdentifier(),
            'token_type' => 'Bearer',
            'expires_in' => $accessToken->getExpiryDateTime()->getTimestamp() - time()
        ];

        // Add refresh token if available
        if ($refreshToken) {
            $response['refresh_token'] = $refreshToken->getIdentifier();
        }

        // Add scopes if available
        $scopes = [];
        foreach ($accessToken->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }

        if (!empty($scopes)) {
            $response['scope'] = implode(' ', $scopes);
        }

        return $response;
    }
    /**
     * Get the client repository instance
     *
     * @return ClientRepository The client repository instance
     */
    public function getClientRepository(): Repositories\ClientRepository
    {
        return $this->repositories['client'];
    }
    /**
     * Get the access token repository instance
     *
     * @return AccessTokenRepository The access token repository instance
     */
    public function getAccessTokenRepository(): Repositories\AccessTokenRepository
    {
        return $this->repositories['accessToken'];
    }
    /**
     * Get the refresh token repository instance
     *
     * @return RefreshTokenRepository The refresh token repository instance
     */
    public function getRefreshTokenRepository(): Repositories\RefreshTokenRepository
    {
        return $this->repositories['refreshToken'];
    }

    /**
     * Get the authorization code repository instance
     *
     * @return AuthCodeRepository The authorization code repository instance
     */
    public function getAuthCodeRepository(): Repositories\AuthCodeRepository
    {
        return $this->repositories['authCode'];
    }
    /**
     * Get the user repository instance
     *
     * @return UserRepository The user repository instance
     */
    public function getUserRepository(): Repositories\UserRepository
    {
        return $this->repositories['user'];
    }
    /**
     * Get the scope repository instance
     *
     * @return ScopeRepository The scope repository instance
     */
    public function getScopeRepository(): Repositories\ScopeRepository
    {
        return $this->repositories['scope'];
    }
    /**
     * Creates an authorization code for the OAuth flow
     *
     * @param string $clientId The client ID
     * @param string $userId The user ID
     * @param string $redirectUri The redirect URI
     * @param array $scopes The requested scopes
     * @param string|null $codeChallenge PKCE code challenge
     * @param string $codeChallengeMethod PKCE code challenge method
     * @return string The generated authorization code
     */
    public function createAuthorizationCode(
        string $clientId,
        string $userId,
        string $redirectUri,
        array $scopes = [],
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'plain'
    ): string {
        // Generate a random code
        $code = bin2hex(random_bytes(32));

        // Store the authorization code
        $this->getAuthCodeRepository()->createAuthorizationCode(
            $code,
            $clientId,
            $userId,
            $redirectUri,
            $scopes,
            time() + 600, // 10 minute expiration
            $codeChallenge,
            $codeChallengeMethod
        );

        return $code;
    }
}
