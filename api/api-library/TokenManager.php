<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

/**
 * Token Management System
 * 
 * Handles JWT token generation, validation, and refresh operations.
 * Manages both access tokens and refresh tokens for user sessions.
 */
class TokenManager 
{
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

            $definition = [
                'table' => [
                    'name' => 'auth_sessions',
                    'alias' => 'as'
                ],
                'fields' => ['status'],
                'conditions' => [
                    'access_token' => ':token',
                    'token_fingerprint' => ':fingerprint',
                    'status' => 'active',
                    'access_expires_at > NOW()' => null
                ]
            ];

            $params = [
                ':token' => $token,
                ':fingerprint' => self::generateTokenFingerprint($token)
            ];

            $query = MySQLQueryBuilder::prepare(QueryAction::SELECT, $definition, $params);
            $db = Utils::getMySQLConnection();
            $stmt = $db->prepare($query['sql']);
            $stmt->execute($query['params']);

            return $stmt->fetch(\PDO::FETCH_COLUMN) === 'active';
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
            $definition = [
                'table' => [
                    'name' => 'auth_sessions',
                    'alias' => 'as'
                ],
                'fields' => ['user_uuid', 'refresh_token', 'status'],
                'conditions' => [
                    'refresh_token' => ':token',
                    'status' => 'active',
                    'refresh_expires_at > NOW()' => null
                ]
            ];

            $params = [':token' => $refreshToken];
            
            $query = MySQLQueryBuilder::prepare(QueryAction::SELECT, $definition, $params);
            $db = Utils::getMySQLConnection();
            $stmt = $db->prepare($query['sql']);
            $stmt->execute($query['params']);

            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
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
                'table' => [
                    'name' => 'auth_sessions'
                ],
                'fields' => [
                    'uuid' => Utils::generateNanoID(12),
                    'user_uuid' => $userUuid,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'],
                    'token_fingerprint' => $tokenData['token_fingerprint'],
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'access_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL :access_lifetime SECOND)'],
                    'refresh_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL :refresh_lifetime SECOND)']
                ]
            ];

            $params = [
                ':access_lifetime' => config('session.access_token_lifetime'),
                ':refresh_lifetime' => config('session.refresh_token_lifetime')
            ];

            $query = MySQLQueryBuilder::prepare(QueryAction::INSERT, $definition, $params);
            $db = Utils::getMySQLConnection();
            $stmt = $db->prepare($query['sql']);
            return $stmt->execute($query['params']);
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
            $definition = [
                'table' => [
                    'name' => 'auth_sessions'
                ],
                'fields' => [
                    'status' => 'revoked'
                ],
                'conditions' => [
                    'access_token' => ':token'
                ]
            ];

            $params = [':token' => $token];

            $query = MySQLQueryBuilder::prepare(QueryAction::UPDATE, $definition, $params);
            $db = Utils::getMySQLConnection();
            $stmt = $db->prepare($query['sql']);
            return $stmt->execute($query['params']);
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
                'table' => [
                    'name' => 'auth_sessions'
                ],
                'fields' => [
                    'access_token' => $newTokens['access_token'],
                    'refresh_token' => $newTokens['refresh_token'],
                    'token_fingerprint' => self::generateTokenFingerprint($newTokens['access_token']),
                    'last_token_refresh' => ['EXPR' => 'NOW()'],
                    'access_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL :access_lifetime SECOND)'],
                    'refresh_expires_at' => ['EXPR' => 'DATE_ADD(NOW(), INTERVAL :refresh_lifetime SECOND)']
                ],
                'conditions' => [
                    'refresh_token' => ':old_refresh_token',
                    'status' => 'active'
                ]
            ];

            $params = [
                ':access_lifetime' => config('session.access_token_lifetime'),
                ':refresh_lifetime' => config('session.refresh_token_lifetime'),
                ':old_refresh_token' => $oldRefreshToken
            ];

            $query = MySQLQueryBuilder::prepare(QueryAction::UPDATE, $definition, $params);
            $db = Utils::getMySQLConnection();
            $stmt = $db->prepare($query['sql']);
            return $stmt->execute($query['params']);
        } catch (\Exception $e) {
            error_log("Failed to update session tokens: " . $e->getMessage());
            return false;
        }
    }
}
