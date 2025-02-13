<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class TokenManager {

    public static function generateTokenPair(array $userData): array {
        $accessToken = JWTService::generate($userData, config('session.access_token_lifetime'));
        $refreshToken = self::generateRefreshToken();
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken
        ];
    }

    public static function generateTokenFingerprint(string $token): string {
        // Generate a unique fingerprint based on token and server-specific salt
        $salt = config('session.token_salt', '');
        return hash('sha256', $token . $salt, true); // Returns binary hash
    }

    public static function validateAccessToken(string $token): bool {
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

    public static function validateRefreshToken(string $refreshToken): ?array {
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

    public static function refreshTokens(string $refreshToken): ?array {
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

    public static function storeSession(string $userUuid, array $tokenData): bool {
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

    public static function revokeSession(string $token): bool {
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

    private static function generateRefreshToken(): string {
        return bin2hex(random_bytes(32));
    }

    private static function updateSessionTokens(string $oldRefreshToken, array $newTokens): bool {
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
