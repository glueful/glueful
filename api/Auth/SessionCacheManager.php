<?php
declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheEngine;

/**
 * Session Cache Management System
 * 
 * Manages cached user session data:
 * - Session data storage and retrieval
 * - Session expiration handling
 * - Session data structure
 * 
 * This class focuses purely on session data management,
 * delegating all token operations to TokenManager.
 */
class SessionCacheManager 
{
    private const SESSION_PREFIX = 'session:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private static ?int $ttl = null;

    /**
     * Initialize session cache manager
     * 
     * Sets up cache connections and configuration.
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
     * Store new session
     * 
     * Creates and stores session data in cache.
     * 
     * @param array $userData User and permission data
     * @param string $token Access token for the session
     * @return bool Success status
     */
    public static function storeSession(array $userData, string $token): bool
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
        $success = CacheEngine::set(
            self::SESSION_PREFIX . $sessionId, 
            $sessionData, 
            self::$ttl
        );
        
        if ($success) {
            // Have TokenManager map the token to this session
            return TokenManager::mapTokenToSession($token, $sessionId);
        }
        
        return false;
    }

    /**
     * Get session by token
     * 
     * Retrieves and refreshes session data.
     * 
     * @param string $token Authentication token
     * @return array|null Session data or null if invalid
     */
    public static function getSession(string $token): ?array
    {
        self::initialize();
        
        $sessionId = TokenManager::getSessionIdFromToken($token);
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

        return $session;
    }

    /**
     * Remove session
     * 
     * Deletes session data from cache.
     * 
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public static function removeSession(string $sessionId): bool
    {
        self::initialize();
        return CacheEngine::delete(self::SESSION_PREFIX . $sessionId);
    }

    /**
     * Destroy session by token
     * 
     * Removes both session data and token mapping.
     * 
     * @param string $token Authentication token
     * @return bool Success status
     */
    public static function destroySession(string $token): bool
    {
        self::initialize();
        
        // Get session ID from token
        $sessionId = TokenManager::getSessionIdFromToken($token);
        if (!$sessionId) {
            return false;
        }
        
        // Remove session data
        $sessionRemoved = self::removeSession($sessionId);
        
        // Remove token mapping
        $mappingRemoved = TokenManager::removeTokenMapping($token);
        
        // Have TokenManager revoke the token
        TokenManager::revokeSession($token);
        
        return $sessionRemoved && $mappingRemoved;
    }

    /**
     * Update session data
     * 
     * Updates session with new data and token.
     * 
     * @param string $oldToken Current token
     * @param array $newData Updated session data
     * @param string $newToken New authentication token
     * @return bool Success status
     */
    public static function updateSession(string $oldToken, array $newData, string $newToken): bool
    {
        self::initialize();
        
        // Get session ID from old token
        $sessionId = TokenManager::getSessionIdFromToken($oldToken);
        if (!$sessionId) {
            return false;
        }
        
        // Remove old token mapping
        TokenManager::removeTokenMapping($oldToken);
        
        // Store new session data
        $success = CacheEngine::set(
            self::SESSION_PREFIX . $sessionId, 
            $newData, 
            self::$ttl
        );
        
        if ($success) {
            // Map new token to existing session
            return TokenManager::mapTokenToSession($newToken, $sessionId);
        }
        
        return false;
    }

    /**
     * Get current session
     * 
     * Retrieves session for current request.
     * 
     * @return array|null Session data or null if not authenticated
     */
    public static function getCurrentSession(): ?array
    {
        $token = TokenManager::extractTokenFromRequest();
        if (!$token) {
            return null;
        }
        
        return self::getSession($token);
    }

    /**
     * Generate unique session identifier
     * 
     * @return string Session ID
     */
    private static function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
