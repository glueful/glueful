<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class SessionManager {
    private const SESSION_PREFIX = 'session:';
    private const TOKEN_PREFIX = 'token:';
    private const DEFAULT_TTL = 3600; // 1 hour - default value
    private const REDIS_HASH_KEY = 'mapi_sessions';
    private static $ttl; // Variable to hold configurable TTL
    public static function initialize(): void
    {
        CacheEngine::initialize('mapi:', 'redis');
        self::$ttl = config('services.jwt.default_expiration', self::DEFAULT_TTL);
    }

    public static function start(array $userData, string $token): void 
    {
        self::initialize();
        $sessionId = self::generateSessionId();
        
        $sessionData = [
            'id' => $sessionId,
            'token' => $token, // Use the provided JWT token
            'user' => $userData,
            'created_at' => time(),
            'last_activity' => time()
        ];

        $redis = CacheEngine::getInstance();
        if ($redis instanceof \Redis) {
            // Store session data with JWT token
            $redis->hSet(
                self::REDIS_HASH_KEY, 
                $sessionId, 
                serialize($sessionData)
            );
            $redis->expire(self::REDIS_HASH_KEY, self::$ttl);
            
            // Map JWT token to session ID
            $redis->setEx(
                self::TOKEN_PREFIX . $token,
                self::$ttl,
                $sessionId
            );
        } else {
            // Fallback to regular CacheEngine methods
            CacheEngine::set(self::SESSION_PREFIX . $sessionId, $sessionData, self::$ttl);
            CacheEngine::set(self::TOKEN_PREFIX . $token, $sessionId, self::$ttl);
        }
    }

    public static function get(string $token): ?array 
    {
        self::initialize();
        $redis = CacheEngine::getInstance();
        
        if ($redis instanceof \Redis) {
            $sessionId = $redis->get(self::TOKEN_PREFIX . $token);
            if (!$sessionId) {
                return null;
            }

            $sessionData = $redis->hGet(self::REDIS_HASH_KEY, $sessionId);
            if (!$sessionData) {
                return null;
            }

            $session = unserialize($sessionData);
            if ($session) {
                // Update last activity
                $session['last_activity'] = time();
                $redis->hSet(
                    self::REDIS_HASH_KEY,
                    $sessionId,
                    serialize($session)
                );
                // Refresh token expiry
                $redis->expire(self::TOKEN_PREFIX . $token, self::DEFAULT_TTL);
                return $session;
            }
        }

        // Fallback to regular CacheEngine get
        return self::getFallback($token);
    }

    private static function getFallback(string $token): ?array
    {
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
            self::DEFAULT_TTL
        );

        return $session;
    }

    public static function destroy(string $token): bool 
    {
        self::initialize();
        $redis = CacheEngine::getInstance();
        
        if ($redis instanceof \Redis) {
            $sessionId = $redis->get(self::TOKEN_PREFIX . $token);
            if ($sessionId) {
                $redis->hDel(self::REDIS_HASH_KEY, $sessionId);
                $redis->del(self::TOKEN_PREFIX . $token);
                return true;
            }
            return false;
        }

        return CacheEngine::delete(self::TOKEN_PREFIX . $token);
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
            self::DEFAULT_TTL
        );

        if ($success) {
            // Store token reference
            return CacheEngine::set(
                self::TOKEN_PREFIX . $newToken, 
                $sessionId, 
                self::DEFAULT_TTL
            );
        }

        return false;
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
