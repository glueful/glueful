<?php
declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheEngine;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

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
    private static ?CacheEngine $cache = null;

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
    int $accessTokenLifetime = null,
    int $refreshTokenLifetime = null
): array
{
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
    public static function removeTokenMapping(string $token): bool
    {
        self::initialize();
        return CacheEngine::delete(self::TOKEN_PREFIX . $token);
    }

    /**
     * Validate access token
     * 
     * Checks if token is valid, not expired, and not revoked.
     * 
     * @param string $token Access token
     * @return bool Validity status
     */
    public static function validateAccessToken(string $token): bool
    {
        return JWTService::verify($token) && !self::isTokenRevoked($token);
    }

    /**
     * Refresh authentication tokens
     * 
     * Generates new token pair using refresh token.
     * 
     * @param string $refreshToken Current refresh token
     * @return array|null New token pair or null if invalid
     */
    public static function refreshTokens(string $refreshToken): ?array
    {
        // Get session data from refresh token
        $sessionData = self::getSessionFromRefreshToken($refreshToken);
        if (!$sessionData) {
            return null;
        }
        
        // Generate new token pair
        return self::generateTokenPair($sessionData);
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
        
        return json_decode($result[0]['user_uuid'], true);
    }

     /**
     * Create user session
     * 
     * Handles user authentication and session creation.
     * 
     * @param string $function Authentication function
     * @param string $action Auth action type
     * @param array $param Credentials and options
     * @return array Session data or error
     */
    public static function createUserSession(array $user): array 
    {      
            // Add validation to ensure we have valid user data
            if (empty($user) || !isset($user['uuid'])) {
                return [];  // Return empty array that will be caught as failure
            }
            // Adjust token lifetime based on remember-me preference
            $accessTokenLifetime = $user['remember_me'] 
                ? config('session.remember_expiration', 30 * 24 * 3600) // 30 days
                : config('session.access_token_lifetime', 3600);          // 1 hour
                
            $refreshTokenLifetime = $user['remember_me'] 
                ? config('session.remember_expiration', 60 * 24 * 3600) // 60 days
                : config('session.refresh_token_lifetime', 7 * 24 * 3600); // 7 days

           // Generate token pair

           $tokens = TokenManager::generateTokenPair($user, $accessTokenLifetime, $refreshTokenLifetime);

            // Store session
            $user['refresh_token'] = $tokens['refresh_token'];

            SessionCacheManager::storeSession($user, $tokens['access_token']);


            self::storeSession(
                $user['uuid'],
                [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_fingerprint' => TokenManager::generateTokenFingerprint($tokens['access_token']),
                    'remember_me' => $user['remember_me']
                ],
                $refreshTokenLifetime
            );

            return [
                'tokens' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'expires_in' => $accessTokenLifetime,
                    'token_type' => 'Bearer'
                ],
                'user' => $user
            ];
       
    }

    /**
     * Store session in database
     * 
     * Persists session for refresh token operations.
     * 
     * @param string $userUuid User identifier
     * @param array $tokens Token data
     * @return bool Success status
     */
    public static function storeSession(string $userUuid, array $tokens): int
    {
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $uuid = Utils::generateNanoID(12);
        return $queryBuilder->insert('auth_sessions', [
            'uuid'=> $uuid,
            'user_uuid' => $userUuid,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_fingerprint' => $tokens['token_fingerprint'],
            'access_expires_at' => date('Y-m-d H:i:s', time() + (int)config('session.access_token_lifetime', 3600)),
            'refresh_expires_at' => date('Y-m-d H:i:s', time() + (int)config('session.refresh_token_lifetime', 7 * 24 * 3600)),
            'status' => 'active',
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'last_token_refresh' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Revoke session
     * 
     * Invalidates session tokens.
     * 
     * @param string $token Access token to revoke
     * @return bool Success status
     */
    public static function revokeSession(string $token): int
    {
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        return $queryBuilder->upsert('auth_sessions',
            ['status' => 'revoked'],
            ['access_token' => $token]
        );
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
            
        return !empty($result) && (int)$result[0]['status'] === "revoked";
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
     * Extract token from request
     * 
     * Gets token from various request sources.
     * 
     * @return string|null Authentication token or null if not found
     */
    public static function extractTokenFromRequest(): ?string
    {
        $headers = getallheaders();
        $token = null;
        
        // Check Authorization header
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                $token = substr($auth, 7);
            }
        }
        
        // Check query parameter if no header
        if (!$token && isset($_GET['token'])) {
            $token = $_GET['token'];
        }

        return $token;
    }
}
