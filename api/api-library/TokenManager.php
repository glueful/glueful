<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
/**
 * Token Management System
 * 
 * Handles JWT token generation, validation, and refresh operations.
 * Manages both access tokens and refresh tokens for user sessions.
 */
class TokenManager 
{   
    private static Connection $connection;
    private static QueryBuilder $queryBuilder;

    public function __construct() {
        self::$connection = new Connection();
        self::$queryBuilder = new QueryBuilder(self::$connection->getPDO(), self::$connection->getDriver());
    }
    /**
     * Generate new token pair
     * 
     * Creates both access and refresh tokens for a user session.
     * 
     * @param array $userData User session data
     * @return array{access_token: string, refresh_token: string} Token pair
     */
    public static function generateTokenPair(array $userData): array 
    {
        $accessToken = JWTService::generate($userData, config('session.access_token_lifetime'));
        $refreshToken = self::generateRefreshToken();
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken
        ];
    }

    /**
     * Generate token fingerprint
     * 
     * Creates unique identifier for token validation.
     * 
     * @param string $token JWT token
     * @return string Binary hash fingerprint
     */
    public static function generateTokenFingerprint(string $token): string 
    {
        // Generate a unique fingerprint based on token and server-specific salt
        $salt = config('session.token_salt', '');
        return hash('sha256', $token . $salt, true); // Returns binary hash
    }

    /**
     * Validate access token
     * 
     * Verifies token authenticity and expiration.
     * 
     * @param string $token JWT access token
     * @return bool True if token is valid
     */
    public static function validateAccessToken(string $token): bool 
    {
        try {
            $tokenData = JWTService::decode($token);
            if (!$tokenData) {
                return false;
            }

            // Execute query using QueryBuilder
            $result = self::$queryBuilder->select(
                'auth_sessions',
                ['status'],
                [
                    'access_token' => $token,
                    'token_fingerprint' => self::generateTokenFingerprint($token),
                    'status' => 'active',
                    'access_expires_at > NOW()' => null
                ],
            );

            return !empty($result) && $result[0]['status'] === 'active';
        } catch (\Exception $e) {
            error_log("Token validation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate refresh token
     * 
     * Verifies refresh token validity and retrieves associated data.
     * 
     * @param string $refreshToken Refresh token
     * @return array|null User data if valid, null if invalid
     */
    public static function validateRefreshToken(string $refreshToken): ?array 
    {
        try {
            // Execute query using QueryBuilder
            $result = self::$queryBuilder->select(
                'auth_sessions',
                ['user_uuid', 'refresh_token', 'status'],
                [
                    'refresh_token' =>  $refreshToken,
                    'status' => 'active',
                    'refresh_expires_at > NOW()' => null
                ]
            );

            return !empty($result) ? $result[0] : null;
        } catch (\Exception $e) {
            error_log("Refresh token validation error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Refresh token pair
     * 
     * Generates new token pair using refresh token.
     * 
     * @param string $refreshToken Current refresh token
     * @return array|null New token pair or null if invalid
     */
    public static function refreshTokens(string $refreshToken): ?array 
    {
        $tokenData = self::validateRefreshToken($refreshToken);
        if (!$tokenData) {
            return null;
        }

        // Generate new token pair
        $userSession = SessionManager::get($refreshToken);
        if (!$userSession) {
            return null;
        }

        $newTokens = self::generateTokenPair($userSession);
        
        // Update session with new tokens
        self::updateSessionTokens($refreshToken, $newTokens);

        return $newTokens;
    }

    /**
     * Store session data
     * 
     * Saves token data and session information to database.
     * 
     * @param string $userUuid User identifier
     * @param array $tokenData Token and session data
     * @return bool True if stored successfully
     */
    public static function storeSession(string $userUuid, array $tokenData): bool 
    {
        try {
            $definition = [
                'fields' => [
                    'uuid' => Utils::generateNanoID(12),
                    'user_uuid' => $userUuid,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'],
                    'token_fingerprint' => $tokenData['token_fingerprint'],
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'access_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL'.config('session.access_token_lifetime').' SECOND)'],
                    'refresh_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL '.config('session.refresh_token_lifetime').' SECOND)']
                ]
            ];
            
            // Convert definition fields to data array
            $data = [];
            foreach ($definition['fields'] as $field => $value) {
                if (is_array($value) && isset($value['EXPR'])) {
                    // Handle expressions separately if needed
                    $data[$field] = $value['EXPR'];
                } else {
                    $data[$field] = $value;
                }
            }
            
            // Execute insert using QueryBuilder
            return self::$queryBuilder->insert('auth_sessions', $data) > 0;
        } catch (\Exception $e) {
            error_log("Failed to store session: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Revoke user session
     * 
     * Invalidates access token and associated session.
     * 
     * @param string $token Access token to revoke
     * @return bool True if revoked successfully
     */
    public static function revokeSession(string $token): bool 
    {
        try {
            return self::$queryBuilder->upsert(
                'auth_sessions',
                [array_merge(
                    ['access_token' => $token],
                    ['status' => 'revoked']
                )],
                ['status']
            ) > 0;
        } catch (\Exception $e) {
            error_log("Failed to revoke session: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate refresh token
     * 
     * Creates cryptographically secure refresh token.
     * 
     * @return string Generated refresh token
     */
    private static function generateRefreshToken(): string 
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Update session tokens
     * 
     * Updates stored tokens during refresh operation.
     * 
     * @param string $oldRefreshToken Current refresh token
     * @param array $newTokens New token pair
     * @return bool True if update successful
     */
    private static function updateSessionTokens(string $oldRefreshToken, array $newTokens): bool 
    {
        try {
            $definition = [
                'fields' => [
                    'access_token' => $newTokens['access_token'],
                    'refresh_token' => $newTokens['refresh_token'],
                    'token_fingerprint' => self::generateTokenFingerprint($newTokens['access_token']),
                    'last_token_refresh' => ['EXPR' => 'NOW()'],
                    'access_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL '.config('session.access_token_lifetime').'SECOND)'],
                    'refresh_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL '.config('session.refresh_token_lifetime').' SECOND)']
                ],
                'conditions' => [
                    'refresh_token' => $oldRefreshToken,
                    'status' => 'active'
                ]
            ];

            $data = array_merge(
                ['refresh_token' => $oldRefreshToken],
                array_map(function($value) {
                    return is_array($value) && isset($value['EXPR']) ? 
                        $value['EXPR'] : 
                        $value;
                }, $definition['fields'])
            );
            
            // Execute update using QueryBuilder's upsert
            return self::$queryBuilder->upsert(
                'auth_sessions',
                [$data],
                array_keys($definition['fields'])
            ) > 0;
        } catch (\Exception $e) {
            error_log("Failed to update session tokens: " . $e->getMessage());
            return false;
        }
    }
}
