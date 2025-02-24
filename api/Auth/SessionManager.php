<?php
declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheEngine;

/**
 * Session Management System
 * 
 * Handles user session lifecycle with distributed caching:
 * - Session creation and storage
 * - Token-based authentication
 * - Session retrieval and validation
 * - Automatic session expiration
 * - Token refresh management
 * 
 * Security Features:
 * - Token fingerprinting
 * - Session invalidation
 * - Distributed session storage
 * - Automatic cleanup
 * 
 * Storage Architecture:
 * - Redis for active sessions
 * - Database for refresh tokens
 * - Distributed cache for scaling
 * - Token-session mapping
 */
class SessionManager 
{
    private const SESSION_PREFIX = 'session:';
    private const TOKEN_PREFIX = 'token:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private static ?int $ttl = null;

    /**
     * Initialize session management system
     * 
     * Sets up required components:
     * - Cache engine configuration
     * - Session lifetime settings
     * - Redis connection
     * - Prefix management
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
     * Create new user session
     * 
     * Stores session data across multiple systems:
     * - Redis cache for active session
     * - Token-session mapping
     * - Database for refresh tokens
     * 
     * Data Storage:
     * - Session metadata
     * - User information
     * - Token mappings
     * - Timing information
     * 
     * @param array $userData User information and permissions
     * @param string $token Authentication token
     * @throws \RuntimeException If session creation fails
     */
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

    /**
     * Retrieve active session
     * 
     * Validates and returns session data:
     * - Verifies token validity
     * - Updates activity timestamp
     * - Refreshes expiration
     * - Returns session data
     * 
     * Security:
     * - Token validation
     * - Session existence check
     * - Activity tracking
     * 
     * @param string $token Authentication token
     * @return array|null Session data or null if invalid
     */
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

    /**
     * Terminate user session
     * 
     * Comprehensive session cleanup:
     * - Revokes database tokens
     * - Removes cache entries
     * - Cleans up mappings
     * - Updates tracking
     * 
     * Cleanup Steps:
     * 1. Revoke refresh tokens
     * 2. Remove session data
     * 3. Remove token mapping
     * 4. Clear related cache
     * 
     * @param string $token Authentication token
     * @return bool True if session was destroyed
     */
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

    /**
     * Update existing session
     * 
     * Handles session refresh operations:
     * - Replaces old session data
     * - Updates token mappings
     * - Refreshes expiration
     * - Maintains continuity
     * 
     * @param string $oldToken Current token
     * @param array $newData Updated session data
     * @param string $newToken New authentication token
     * @return bool Success status
     */
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

    /**
     * Get current request's session
     * 
     * Retrieves session from Authorization header:
     * - Extracts bearer token
     * - Validates session
     * - Returns current data
     * 
     * Usage:
     * ```php
     * $session = SessionManager::getCurrentSession();
     * if ($session) {
     *     $userId = $session['user']['id'];
     * }
     * ```
     * 
     * @return array|null Current session or null if unauthorized
     */
    public static function getCurrentSession(): ?array 
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        if (empty($token)) {
            return null;
        }

        return self::get($token);
    }

    /**
     * Generate unique session identifier
     * 
     * Creates cryptographically secure session ID:
     * - 16 bytes of entropy
     * - Hex encoded
     * - Collision resistant
     * 
     * @return string 32-character session ID
     */
    private static function generateSessionId(): string 
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate authentication token
     * 
     * Creates secure token for session:
     * - 32 bytes of entropy
     * - Hex encoded
     * - Cryptographically secure
     * 
     * @return string 64-character token
     */
    private static function generateToken(): string 
    {
        return bin2hex(random_bytes(32));
    }
}
