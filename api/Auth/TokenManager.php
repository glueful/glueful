<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheEngine;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;
use Glueful\Http\RequestContext;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;

/**
 * Token Management System
 *
 * Handles all aspects of authentication tokens:
 * - Token generation and validation
 * - Token-session mapping
 * - Token refresh operations
 * - Token fingerprinting and security
 * - Token invalidation and cleanup
 *
 * Security Features:
 * - Token pair management (access + refresh)
 * - Token fingerprinting
 * - Expiration control
 * - Revocation tracking
 */
class TokenManager
{
    private const TOKEN_PREFIX = 'token:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private static ?int $ttl = null;

    /**
     * Initialize token manager
     *
     * Sets up caching and loads configuration.
     */
    public static function initialize(): void
    {
        if (!defined('CACHE_ENGINE')) {
            define('CACHE_ENGINE', true);
        }

        CacheEngine::initialize('glueful:', 'redis');

        // Cast the config value to int
        self::$ttl = (int)config('session.access_token_lifetime', self::DEFAULT_TTL);
    }

   /**
 * Generate token pair with custom lifetimes
 *
 * Creates access and refresh tokens for authentication.
 *
 * @param array $userData User data to encode in tokens
 * @param int $accessTokenLifetime Access token lifetime in seconds
 * @param int $refreshTokenLifetime Refresh token lifetime in seconds
 * @return array Token pair with access_token and refresh_token
 */
    public static function generateTokenPair(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        self::initialize();

        // Use provided lifetimes or defaults
        $accessTokenLifetime = $accessTokenLifetime ?? self::$ttl;
        $refreshTokenLifetime = $refreshTokenLifetime ??
        config('session.refresh_token_lifetime', 30 * 24 * 3600); // Default 30 days

        // Add remember-me indicator to token payload if applicable
        $tokenPayload = $userData;
        if (isset($userData['remember_me']) && $userData['remember_me']) {
            $tokenPayload['persistent'] = true;
        }

        $accessToken = JWTService::generate($tokenPayload, $accessTokenLifetime);
        $refreshToken = bin2hex(random_bytes(32)); // 64 character random string

        // Skip audit logging for token generation - login success is sufficient

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTokenLifetime
        ];
    }
    /**
     * Store token-session mapping
     *
     * Creates mapping between token and session ID.
     *
     * @param string $token Authentication token
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public static function mapTokenToSession(string $token, string $sessionId): bool
    {
        self::initialize();

        // Skip audit logging for token mapping - this is internal implementation detail

        return CacheEngine::set(
            self::TOKEN_PREFIX . $token,
            $sessionId,
            self::$ttl
        );
    }

    /**
     * Get session ID from token
     *
     * Retrieves the session ID associated with a token.
     *
     * @param string $token Authentication token
     * @return string|null Session ID or null if not found
     */
    public static function getSessionIdFromToken(string $token): ?string
    {
        self::initialize();
        return CacheEngine::get(self::TOKEN_PREFIX . $token);
    }

    /**
     * Remove token mapping
     *
     * Deletes the token-session mapping.
     *
     * @param string $token Authentication token
     * @return bool Success status
     */
    public static function removeTokenMapping(string $token, ?RequestContext $requestContext = null): bool
    {
        self::initialize();
        $requestContext = $requestContext ?? RequestContext::fromGlobals();

        // Log token mapping removal
        $auditLogger = AuditLogger::getInstance();
        $sessionId = self::getSessionIdFromToken($token);
        $auditLogger->audit(
            AuditEvent::CATEGORY_AUTH,
            'token_mapping_removed',
            AuditEvent::SEVERITY_INFO,
            [
                'session_id' => $sessionId,
                'ip_address' => $requestContext->getClientIp(),
            ]
        );

        return CacheEngine::delete(self::TOKEN_PREFIX . $token);
    }

    /**
     * Validate access token
     *
     * Checks if token is valid, not expired, and not revoked.
     * Uses the appropriate authentication provider based on token type.
     *
     * @param string $token Access token
     * @param string|null $provider Optional provider name to use for validation
     * @return bool Validity status
     */
    public static function validateAccessToken(
        string $token,
        ?string $provider = null,
        ?RequestContext $requestContext = null
    ): bool {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();

        // Get the authentication manager instance
        $authManager = self::getAuthManager();

        $isValid = false;

        // If provider is explicitly specified, use it
        if ($provider && $authManager) {
            $authProvider = $authManager->getProvider($provider);
            if ($authProvider) {
                $isValid = $authProvider->validateToken($token) && !self::isTokenRevoked($token);
            }
        } elseif ($authManager) {
            // We need to loop through the available providers
            $providers = self::getAvailableProviders($authManager);
            foreach ($providers as $authProvider) {
                if ($authProvider->canHandleToken($token)) {
                    $isValid = $authProvider->validateToken($token) && !self::isTokenRevoked($token);
                    break;
                }
            }
        } else {
            $isValid = JWTService::verify($token) && !self::isTokenRevoked($token);
        }

        // Log token validation attempt
        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_AUTH,
            $isValid ? 'token_validated' : 'token_validation_failed',
            $isValid ? AuditEvent::SEVERITY_INFO : AuditEvent::SEVERITY_WARNING,
            [
                'provider' => $provider ?? 'auto-detect',
                'is_valid' => $isValid,
                'is_revoked' => self::isTokenRevoked($token),
                'ip_address' => $requestContext->getClientIp(),
            ]
        );

        return $isValid;
    }

    /**
     * Refresh authentication tokens
     *
     * Generates new token pair using refresh token.
     * Supports multiple authentication providers.
     *
     * @param string $refreshToken Current refresh token
     * @param string|null $provider Optional provider name to use
     * @return array|null New token pair or null if invalid
     */
    public static function refreshTokens(
        string $refreshToken,
        ?string $provider = null,
        ?RequestContext $requestContext = null
    ): ?array {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();
        // Get session data from refresh token
        $sessionData = self::getSessionFromRefreshToken($refreshToken);

        // Log refresh token attempt
        $auditLogger = AuditLogger::getInstance();
        $isValid = $sessionData !== null;

        if (!$isValid) {
            // Log invalid refresh token attempt
            $auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_refresh_failed',
                AuditEvent::SEVERITY_WARNING,
                [
                    'reason' => 'invalid_refresh_token',
                    'provider' => $provider ?? 'auto-detect',
                    'ip_address' => $requestContext->getClientIp(),
                ]
            );
            return null;
        }

        // Get the authentication manager instance
        $authManager = self::getAuthManager();
        $tokens = null;

        // If provider is explicitly specified, use it
        if ($provider && $authManager) {
            $authProvider = $authManager->getProvider($provider);
            if ($authProvider) {
                $tokens = $authProvider->refreshTokens($refreshToken, $sessionData);
            }
        }

        // If no explicit provider but we have stored provider in session
        if (!$tokens) {
            $connection = new Connection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
            $result = $queryBuilder->select('auth_sessions', ['provider'])
                ->where(['refresh_token' => $refreshToken])
                ->get();

            if (!empty($result) && isset($result[0]['provider']) && $result[0]['provider'] !== 'jwt') {
                $storedProvider = $result[0]['provider'];
                if ($authManager) {
                    $authProvider = $authManager->getProvider($storedProvider);
                    if ($authProvider) {
                        $tokens = $authProvider->refreshTokens($refreshToken, $sessionData);
                    }
                }
            }
        }

        // Default to standard JWT token generation for backward compatibility
        if (!$tokens) {
            // Get remember_me status from the session to determine token lifetime
            $connection = new Connection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
            $sessionResult = $queryBuilder->select('auth_sessions', ['remember_me'])
                ->where(['refresh_token' => $refreshToken])
                ->get();
            $rememberMe = !empty($sessionResult[0]['remember_me']);
            // Set appropriate token lifetime based on remember_me
            $accessTokenLifetime = $rememberMe
                ? (int)config('session.remember_expiration', 30 * 24 * 3600)  // 30 days
                : (int)config('session.access_token_lifetime', 3600);         // 1 hour
            $refreshTokenLifetime = $rememberMe
                ? (int)config('session.remember_expiration', 60 * 24 * 3600)  // 60 days for refresh
                : (int)config('session.refresh_token_lifetime', 7 * 24 * 3600); // 7 days
            $tokens = self::generateTokenPair($sessionData, $accessTokenLifetime, $refreshTokenLifetime);
        }

        // Log successful token refresh
        $auditLogger->audit(
            AuditEvent::CATEGORY_AUTH,
            'token_refreshed',
            AuditEvent::SEVERITY_INFO,
            [
                'user_id' => $sessionData['uuid'] ?? null,
                'provider' => $provider ?? $result[0]['provider'] ?? 'jwt',
                'ip_address' => $requestContext->getClientIp(),
            ]
        );

        return $tokens;
    }

    /**
     * Get session from refresh token
     *
     * Retrieves session data using refresh token.
     *
     * @param string $refreshToken Refresh token
     * @return array|null Session data or null if invalid
     */
    private static function getSessionFromRefreshToken(string $refreshToken): ?array
    {
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        $result = $queryBuilder->select('auth_sessions', ['user_uuid', 'access_token', 'created_at'])
            ->where(['refresh_token' => $refreshToken, 'status' => 'active'])
            ->get();

        if (empty($result)) {
            return null;
        }

        // Return basic session data with user UUID
        // The calling method will handle fetching full user data
        // Note: We exclude access_token to prevent token wrapping
        return [
            'uuid' => $result[0]['user_uuid'],
            'created_at' => $result[0]['created_at']
        ];
    }

     /**
     * Normalize user data to ensure consistent structure across all providers
     *
     * Ensures all user objects contain required fields with proper defaults.
     * Fetches missing profile data from database if needed.
     *
     * @param array $user Raw user data from authentication provider
     * @param string|null $provider Provider name (jwt, admin, ldap, saml, etc.)
     * @return array Normalized user data with consistent structure
     */
    private static function normalizeUserData(array $user, ?string $provider = null): array
    {
        // Start with the existing user data
        $normalizedUser = $user;

        // Ensure critical fields exist
        if (!isset($normalizedUser['uuid'])) {
            // Cannot normalize without UUID
            return $user;
        }

        // Set default values for commonly missing fields
        $normalizedUser['remember_me'] = $normalizedUser['remember_me'] ?? false;
        $normalizedUser['provider'] = $provider ?? 'jwt';

        // Ensure profile data exists
        if (!isset($normalizedUser['profile']) || empty($normalizedUser['profile'])) {
            // Try to fetch profile data from database
            $userRepository = new \Glueful\Repository\UserRepository();
            $profileData = $userRepository->getProfile($normalizedUser['uuid']);

            // Create profile structure with null-safe access
            $normalizedUser['profile'] = [
                'first_name' => $profileData['first_name'] ?? null,
                'last_name' => $profileData['last_name'] ?? null,
                'photo_uuid' => $profileData['photo_uuid'] ?? null,
                'photo_url' => $profileData['photo_url'] ?? null
            ];
        }

        // Don't include roles in the login response - fetch via separate endpoint
        // This follows OAuth/OIDC best practices for minimal token responses

        // Ensure consistent timestamp fields
        if (isset($normalizedUser['last_login_at']) && !isset($normalizedUser['last_login'])) {
            $normalizedUser['last_login'] = $normalizedUser['last_login_at'];
        }

        // Ensure last_login_date is set (used in JWT token claims)
        if (!isset($normalizedUser['last_login_date'])) {
            $normalizedUser['last_login_date'] = $normalizedUser['last_login'] ?? date('Y-m-d H:i:s');
        }

        return $normalizedUser;
    }

     /**
     * Create user session with provider support
     *
     * Handles user authentication and session creation with support for different authentication providers.
     *
     * @param array $user User data
     * @param string|null $provider Optional authentication provider to use
     * @return array Session data or error
     */
    public static function createUserSession(array $user, ?string $provider = null): array
    {
        // Add validation to ensure we have valid user data
        if (empty($user) || !isset($user['uuid'])) {
            return [];  // Return empty array that will be caught as failure
        }

        // Normalize user data to ensure consistent structure
        $user = self::normalizeUserData($user, $provider);

        // Adjust token lifetime based on remember-me preference
        $accessTokenLifetime = $user['remember_me']
            ? (int)config('session.remember_expiration', 30 * 24 * 3600) // 30 days
            : (int)config('session.access_token_lifetime', 3600);          // 1 hour

        $refreshTokenLifetime = $user['remember_me']
            ? (int)config('session.remember_expiration', 60 * 24 * 3600) // 60 days
            : (int)config('session.refresh_token_lifetime', 7 * 24 * 3600); // 7 days

        // Use authentication provider if specified and available
        $authManager = self::getAuthManager();
        if ($provider && $authManager) {
            $authProvider = $authManager->getProvider($provider);
            if ($authProvider) {
                $tokens = $authProvider->generateTokens($user, $accessTokenLifetime, $refreshTokenLifetime);
            } else {
                // Fall back to default token generation
                $tokens = self::generateTokenPair($user, $accessTokenLifetime, $refreshTokenLifetime);
            }
        } else {
            // Default to JWT tokens for backward compatibility
            $tokens = self::generateTokenPair($user, $accessTokenLifetime, $refreshTokenLifetime);
        }

        // Store session using TokenStorageService for unified database/cache management
        $user['refresh_token'] = $tokens['refresh_token'];
        $user['session_id'] = Utils::generateNanoID(); // Generate session ID once
        $user['provider'] = $provider ?? 'jwt';
        $user['remember_me'] = $user['remember_me'] ?? false;

        $tokenStorage = new TokenStorageService();
        $tokenStorage->storeSession($user, $tokens);

        // Also store in cache for quick lookup
        SessionCacheManager::storeSession(
            $user, // userData array
            $tokens['access_token'], // token string
            $provider ?? 'jwt', // provider
            $tokens['expires_in'] // ttl
        );

        unset($user['refresh_token']);

        // Build OIDC-compliant user object
        $oidcUser = [
            'id' => $user['uuid'],
            'email' => $user['email'] ?? null,
            'email_verified' => !empty($user['email_verified_at']),
            'username' => $user['username'] ?? null,
            'locale' => $user['locale'] ?? 'en-US',
            'updated_at' => isset($user['updated_at']) ? strtotime($user['updated_at']) : time()
        ];

        // Add name fields if profile exists
        if (isset($user['profile'])) {
            $firstName = $user['profile']['first_name'] ?? '';
            $lastName = $user['profile']['last_name'] ?? '';

            if ($firstName || $lastName) {
                $oidcUser['name'] = trim($firstName . ' ' . $lastName);
                $oidcUser['given_name'] = $firstName ?: null;
                $oidcUser['family_name'] = $lastName ?: null;
            }

            if (!empty($user['profile']['photo_url'])) {
                $oidcUser['picture'] = $user['profile']['photo_url'];
            }
        }

        // Return OAuth 2.0 compliant response structure
        return [
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenLifetime,
            'refresh_token' => $tokens['refresh_token'],
            'user' => $oidcUser
        ];
    }

    /**
     * Store session in database
     *
     * Persists session for refresh token operations.
     * Supports storing the authentication provider used.
     *
     * @param string $userUuid User identifier
     * @param array $tokens Token data
     * @param int|null $refreshTokenLifetime Optional refresh token lifetime
     * @return int Number of rows affected
     */
    public static function storeSession(
        string $userUuid,
        array $tokens,
        ?int $refreshTokenLifetime = null,
        ?RequestContext $requestContext = null
    ): int {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $uuid = Utils::generateNanoID();

        // Use provided refresh token lifetime or fall back to config
        $refreshTokenLifetime = $refreshTokenLifetime ??
            (int)config('session.refresh_token_lifetime', 7 * 24 * 3600);

        $result = $queryBuilder->insert('auth_sessions', [
            'uuid' => $uuid,
            'user_uuid' => $userUuid,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_fingerprint' => $tokens['token_fingerprint'],
            'access_expires_at' => date('Y-m-d H:i:s', time() + (int)config('session.access_token_lifetime', 3600)),
            'refresh_expires_at' => date('Y-m-d H:i:s', time() + $refreshTokenLifetime),
            'status' => 'active',
            'ip_address' => $requestContext->getClientIp(),
            'user_agent' => $requestContext->getUserAgent(),
            'last_token_refresh' => date('Y-m-d H:i:s'),
            'provider' => $tokens['provider'] ?? 'jwt', // Store the provider used
        ]);

        // Skip session creation audit log as login success is already logged
        // This reduces duplicate audit entries during login

        return $result;
    }

    /**
     * Revoke session
     *
     * Invalidates session tokens.
     *
     * @param string $token Access token to revoke
     * @return int Number of rows affected
     */
    public static function revokeSession(string $token, ?RequestContext $requestContext = null): int
    {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Get session details before revocation for audit logging
        $sessionDetails = $queryBuilder->select('auth_sessions', ['user_uuid', 'provider', 'uuid'])
            ->where(['access_token' => $token])
            ->get();

        $result = $queryBuilder->update(
            'auth_sessions',
            ['status' => 'revoked'],
            ['access_token' => $token]
        );

        // Log token revocation event
        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_AUTH,
            'token_revoked',
            AuditEvent::SEVERITY_INFO,
            [
                'user_id' => !empty($sessionDetails) ? $sessionDetails[0]['user_uuid'] : null,
                'session_id' => !empty($sessionDetails) ? $sessionDetails[0]['uuid'] : null,
                'provider' => !empty($sessionDetails) ? $sessionDetails[0]['provider'] : 'jwt',
                'ip_address' => $requestContext->getClientIp(),
                'result' => $result > 0 ? 'success' : 'no_changes',
            ]
        );

        return $result;
    }

    /**
     * Check if token is revoked
     *
     * Verifies token against revocation list.
     *
     * @param string $token Authentication token
     * @return bool True if revoked
     */
    public static function isTokenRevoked(string $token): bool
    {
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        $result = $queryBuilder->select('auth_sessions', ['status'])
            ->where(['access_token' => $token])
            ->get();

        return !empty($result) && $result[0]['status'] === "revoked";
    }

    /**
     * Generate token fingerprint
     *
     * Creates unique identifier for token security.
     *
     * @param string $token Authentication token
     * @return string Fingerprint hash
     */
    public static function generateTokenFingerprint(string $token): string
    {
        return hash('sha256', $token . config('session.fingerprint_salt', ''));
    }

    /**
     * Extract authentication token from HTTP request
     *
     * Attempts to locate and extract the bearer token from multiple possible locations
     * in the request headers, following a fallback chain:
     *
     * 1. Standard Authorization header
     * 2. Apache specific REDIRECT_HTTP_AUTHORIZATION header
     * 3. Custom Authorization header from getallheaders()
     * 4. Apache request headers
     * 5. Query parameter 'token' as last resort
     *
     * Supported header formats:
     * - "Authorization: Bearer <token>"
     * - "Authorization: BEARER <token>"
     * - "Authorization: bearer <token>"
     *
     * @return string|null The extracted token or null if not found
     *
     * @example
     * ```php
     * $token = TokenManager::extractTokenFromRequest();
     * if ($token) {
     *     $userData = TokenManager::validateAccessToken($token);
     * }
     * ```
     */
    public static function extractTokenFromRequest(?RequestContext $requestContext = null): ?string
    {
        $requestContext = $requestContext ?? RequestContext::fromGlobals();
        $authorization_header = $requestContext->getAuthorizationHeader();

        // Fallback to getallheaders() (case-insensitive)
        if (!$authorization_header && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $authorization_header = $value;
                    break;
                }
            }
        }

        // Fallback to apache_request_headers() (case-insensitive)
        if (!$authorization_header && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $authorization_header = $value;
                    break;
                }
            }
        }

        // Extract Bearer token using preg_match (handles extra spaces)
        if ($authorization_header && preg_match('/Bearer\s+(.+)/i', $authorization_header, $matches)) {
            return trim($matches[1]);
        }

        // Last fallback: Check query parameter `token`
        return $requestContext->getQueryParam('token');
    }

    /**
     * Check if a token is compatible with a specific provider
     *
     * Determines if a token can be handled by a specific authentication provider.
     * Useful for routing authentication requests to the correct provider.
     *
     * @param string $token The token to check
     * @param string $providerName The name of the provider to check against
     * @return bool True if the provider can handle this token
     */
    public static function isTokenCompatibleWithProvider(string $token, string $providerName): bool
    {
        $authManager = self::getAuthManager();
        if (!$authManager) {
            // If no authentication manager is active, only jwt tokens are supported
            return $providerName === 'jwt';
        }

        $provider = $authManager->getProvider($providerName);
        if (!$provider) {
            return false;
        }

        return $provider->canHandleToken($token);
    }

    /**
     * Get the AuthenticationManager instance
     *
     * Helper method to safely retrieve the AuthenticationManager instance.
     *
     * @return AuthenticationManager|null
     */
    private static function getAuthManager(): ?AuthenticationManager
    {
        // Check if the AuthenticationService class exists and has a static getInstance method
        if (class_exists('\\Glueful\\Auth\\AuthenticationService')) {
            try {
                $authService = call_user_func(['\\Glueful\\Auth\\AuthenticationService', 'getInstance']);
                if ($authService && method_exists($authService, 'getAuthManager')) {
                    return $authService->getAuthManager();
                }
            } catch (\Throwable $e) {
                // Silently fail and return null
            }
        }

        // Try direct instantiation of AuthenticationManager if the service is not available
        try {
            return new AuthenticationManager();
        } catch (\Throwable $e) {
            // Silently fail
            return null;
        }
    }

    /**
     * Get all available authentication providers
     *
     * Helper method to retrieve all registered providers from the AuthenticationManager.
     *
     * @param AuthenticationManager $authManager The authentication manager instance
     * @return array Array of AuthenticationProviderInterface instances
     */
    private static function getAvailableProviders(AuthenticationManager $authManager): array
    {
        $providers = [];

        // Try to call a method to get all providers if it exists
        if (method_exists($authManager, 'getProviders')) {
            try {
                return $authManager->getProviders();
            } catch (\Throwable $e) {
                // Silently fail and continue with fallback
            }
        }

        // Fallback: try to get known providers individually
        foreach (['jwt', 'api_key', 'oauth', 'saml'] as $providerName) {
            try {
                $provider = $authManager->getProvider($providerName);
                if ($provider) {
                    $providers[] = $provider;
                }
            } catch (\Throwable $e) {
                // Skip this provider
            }
        }

        return $providers;
    }
}
