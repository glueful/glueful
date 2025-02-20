<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\Cache\CacheEngine;

class SessionManager 
{
    private const SESSION_PREFIX = 'session:';
    private const TOKEN_PREFIX = 'token:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private static ?int $ttl = null;

    public static function initialize(): void
    {
        if (!defined('CACHE_ENGINE')) {
            define('CACHE_ENGINE', true);
        }
        
        CacheEngine::initialize('glueful:', 'redis');
        
        // Cast the config value to int
        self::$ttl = (int)config('session.access_token_lifetime', self::DEFAULT_TTL);
    }

    public static function start(array $userData, string $token): void 
    {
        self::initialize();
        $sessionId = self::generateSessionId();
        
        $sessionData = [
            'id' => $sessionId,
            'token' => $token,
            'user' => $userData,
            'created_at' => time(),
            'last_activity' => time()
        ];

        // Store session data
        CacheEngine::set(
            self::SESSION_PREFIX . $sessionId, 
            $sessionData, 
            self::$ttl
        );

        // Map token to session ID
        CacheEngine::set(
            self::TOKEN_PREFIX . $token,
            $sessionId,
            self::$ttl
        );

        // Store session in database if refresh token exists
        if (isset($userData['refresh_token'])) {
            TokenManager::storeSession(
                $userData['user_uuid'],
                [
                    'access_token' => $token,
                    'refresh_token' => $userData['refresh_token'],
                    'token_fingerprint' => TokenManager::generateTokenFingerprint($token)
                ]
            );
        }
    }

    public static function get(string $token): ?array 
    {
        self::initialize();
        
        if (!TokenManager::validateAccessToken($token)) {
            return null;
        }

        $sessionId = CacheEngine::get(self::TOKEN_PREFIX . $token);
        if (!$sessionId) {
            return null;
        }

        $session = CacheEngine::get(self::SESSION_PREFIX . $sessionId);
        if (!$session) {
            return null;
        }

        // Update last activity
        $session['last_activity'] = time();
        CacheEngine::set(
            self::SESSION_PREFIX . $sessionId,
            $session,
            self::$ttl
        );

        // Refresh token expiry
        CacheEngine::expire(self::TOKEN_PREFIX . $token, self::$ttl);

        return $session;
    }

    public static function destroy(string $token): bool 
    {
        self::initialize();
        
        // Revoke session in database
        TokenManager::revokeSession($token);
        
        // Remove from cache
        $sessionId = CacheEngine::get(self::TOKEN_PREFIX . $token);
        if ($sessionId) {
            CacheEngine::delete(self::SESSION_PREFIX . $sessionId);
            CacheEngine::delete(self::TOKEN_PREFIX . $token);
            return true;
        }

        return false;
    }

    public static function update(string $oldToken, array $newData, string $newToken): bool
    {
        // Remove old token
        self::destroy($oldToken);
        
        // Store new session data with new token
        $sessionId = self::generateSessionId();
        
        // Store session data in cache
        $success = CacheEngine::set(
            self::SESSION_PREFIX . $sessionId, 
            $newData, 
            self::$ttl
        );

        if ($success) {
            // Store token reference
            return CacheEngine::set(
                self::TOKEN_PREFIX . $newToken, 
                $sessionId, 
                self::$ttl
            );
        }

        return false;
    }

    public static function getCurrentSession(): ?array 
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token)) {
            return null;
        }

        return self::get($token);
    }

    private static function generateSessionId(): string 
    {
        return bin2hex(random_bytes(16));
    }

    private static function generateToken(): string 
    {
        return bin2hex(random_bytes(32));
    }
}
