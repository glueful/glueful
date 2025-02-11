<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class SessionManager {
    private const SESSION_PREFIX = 'session:';
    private const TOKEN_PREFIX = 'token:';
    private const DEFAULT_TTL = 3600; // 1 hour

    public static function start(array $userData): string 
    {
        $sessionId = self::generateSessionId();
        $token = self::generateToken();
        
        $sessionData = [
            'id' => $sessionId,
            'token' => $token,
            'user' => $userData,
            'created_at' => time(),
            'last_activity' => time()
        ];

        // Store session data in cache
        CacheEngine::set(
            self::SESSION_PREFIX . $sessionId, 
            $sessionData, 
            self::DEFAULT_TTL
        );

        // Store token reference
        CacheEngine::set(
            self::TOKEN_PREFIX . $token, 
            $sessionId, 
            self::DEFAULT_TTL
        );

        return $token;
    }

    public static function get(string $token): ?array 
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
        $sessionId = CacheEngine::get(self::TOKEN_PREFIX . $token);
        if (!$sessionId) {
            return false;
        }

        CacheEngine::delete(self::TOKEN_PREFIX . $token);
        CacheEngine::delete(self::SESSION_PREFIX . $sessionId);
        
        return true;
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
