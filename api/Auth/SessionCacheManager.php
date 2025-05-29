<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheEngine;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;

/**
 * Session Cache Management System
 *
 * Manages cached user session data:
 * - Session data storage and retrieval
 * - Session expiration handling
 * - Session data structure
 * - Multi-provider authentication support
 *
 * This class focuses purely on session data management,
 * delegating all token operations to TokenManager.
 */
class SessionCacheManager
{
    private const SESSION_PREFIX = 'session:';
    private const PROVIDER_INDEX_PREFIX = 'provider:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private static ?int $ttl = null;
    private static ?array $providerConfigs = null;

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

        // Load provider-specific configurations
        self::$providerConfigs = config('security.authentication_providers', []);
    }

    /**
     * Store new session
     *
     * Creates and stores session data in cache.
     * Supports multiple authentication providers.
     *
     * @param array $userData User and permission data
     * @param string $token Access token for the session
     * @param string|null $provider Authentication provider (jwt, apikey, etc.)
     * @param int|null $ttl Custom time-to-live in seconds
     * @return bool Success status
     */
    public static function storeSession(
        array $userData,
        string $token,
        ?string $provider = 'jwt',
        ?int $ttl = null
    ): bool {
        self::initialize();
        $sessionId = self::generateSessionId();

        // Use custom TTL if provided, or provider-specific TTL if available
        $sessionTtl = $ttl ?? self::getProviderTtl($provider);

        $sessionData = [
            'id' => $sessionId,
            'token' => $token,
            'user' => $userData,
            'created_at' => time(),
            'last_activity' => time(),
            'provider' => $provider ?? 'jwt' // Store the provider used
        ];

        // Store session data
        $success = CacheEngine::set(
            self::SESSION_PREFIX . $sessionId,
            $sessionData,
            $sessionTtl
        );

        if ($success) {
            // Index this session by provider for easier management
            self::indexSessionByProvider($provider ?? 'jwt', $sessionId, $sessionTtl);

            // Have TokenManager map the token to this session
            $mapped = TokenManager::mapTokenToSession($token, $sessionId);

            // Skip audit logging here - session creation is already logged in TokenManager

            return $mapped;
        }

        return false;
    }

    /**
     * Index session by provider type
     *
     * Creates a secondary index of sessions organized by provider.
     * Useful for provider-specific session operations.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @param string $sessionId Session identifier
     * @param int $ttl Time-to-live in seconds
     * @return bool Success status
     */
    private static function indexSessionByProvider(string $provider, string $sessionId, int $ttl): bool
    {
        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessions = CacheEngine::get($indexKey) ?? [];

        // Add session to the provider's index
        $sessions[] = $sessionId;

        // Remove any duplicates
        $sessions = array_unique($sessions);

        return CacheEngine::set($indexKey, $sessions, $ttl);
    }

    /**
     * Get sessions by provider
     *
     * Retrieves all sessions for a specific authentication provider.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return array Array of session data
     */
    public static function getSessionsByProvider(string $provider): array
    {
        self::initialize();

        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessionIds = CacheEngine::get($indexKey) ?? [];

        $sessions = [];
        foreach ($sessionIds as $sessionId) {
            $session = CacheEngine::get(self::SESSION_PREFIX . $sessionId);
            if ($session) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    /**
     * Get session by token
     *
     * Retrieves and refreshes session data.
     *
     * @param string $token Authentication token
     * @param string|null $provider Optional provider hint
     * @return array|null Session data or null if invalid
     */
    public static function getSession(string $token, ?string $provider = null): ?array
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

        // If provider is specified, validate it matches the session's provider
        if ($provider && isset($session['provider']) && $session['provider'] !== $provider) {
            return null;
        }

        // Get the TTL for this provider type
        $ttl = self::getProviderTtl($session['provider'] ?? 'jwt');

        // Update last activity
        $session['last_activity'] = time();
        CacheEngine::set(
            self::SESSION_PREFIX . $sessionId,
            $session,
            $ttl
        );

        return $session;
    }

    /**
     * Get provider-specific TTL value
     *
     * Returns the correct TTL value based on provider type and configuration.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return int Time-to-live in seconds
     */
    private static function getProviderTtl(string $provider): int
    {
        if (isset(self::$providerConfigs[$provider]['session_ttl'])) {
            return (int)self::$providerConfigs[$provider]['session_ttl'];
        }

        return self::$ttl;
    }

    /**
     * Remove session
     *
     * Deletes session data from cache and provider index.
     *
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public static function removeSession(string $sessionId): bool
    {
        self::initialize();

        // Get session to find its provider
        $session = CacheEngine::get(self::SESSION_PREFIX . $sessionId);

        // Remove from provider index if provider information is available
        if ($session && isset($session['provider'])) {
            self::removeSessionFromProviderIndex($session['provider'], $sessionId);
        }

        return CacheEngine::delete(self::SESSION_PREFIX . $sessionId);
    }

    /**
     * Remove session from provider index
     *
     * Removes a session ID from a provider's index list.
     *
     * @param string $provider Provider name
     * @param string $sessionId Session ID to remove
     * @return bool Success status
     */
    private static function removeSessionFromProviderIndex(string $provider, string $sessionId): bool
    {
        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessions = CacheEngine::get($indexKey) ?? [];

        // Remove session from the index
        $sessions = array_diff($sessions, [$sessionId]);

        // Get the TTL for this provider type
        $ttl = self::getProviderTtl($provider);

        return CacheEngine::set($indexKey, $sessions, $ttl);
    }

    /**
     * Destroy session by token
     *
     * Removes both session data and token mapping.
     *
     * @param string $token Authentication token
     * @param string|null $provider Optional provider hint
     * @return bool Success status
     */
    public static function destroySession(string $token, ?string $provider = null): bool
    {
        self::initialize();

        // Get session ID from token
        $sessionId = TokenManager::getSessionIdFromToken($token);
        if (!$sessionId) {
            return false;
        }

        // Get session to find its provider
        $session = CacheEngine::get(self::SESSION_PREFIX . $sessionId);
        if ($session && $provider && isset($session['provider']) && $session['provider'] !== $provider) {
            // If provider is specified and doesn't match, don't destroy the session
            return false;
        }

        // Store user ID for audit logging before removing the session
        $userId = null;
        $sessionProvider = null;
        if ($session) {
            if (isset($session['user']['uuid'])) {
                $userId = $session['user']['uuid'];
            }
            $sessionProvider = $session['provider'] ?? 'jwt';
        }

        // Remove session data
        $sessionRemoved = self::removeSession($sessionId);

        // Remove token mapping
        $mappingRemoved = TokenManager::removeTokenMapping($token);

        // Have TokenManager revoke the token
        TokenManager::revokeSession($token);

        // Log session destruction in the audit log
        try {
            if ($sessionRemoved && $mappingRemoved) {
                $auditLogger = AuditLogger::getInstance();
                $auditLogger->authEvent(
                    'session_destroyed',
                    $userId,
                    [
                        'session_id' => $sessionId,
                        'provider' => $sessionProvider,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'reason' => 'explicit_logout'
                    ],
                    AuditEvent::SEVERITY_INFO
                );
            }
        } catch (\Throwable $e) {
            // Silently handle audit logging errors to ensure session destruction isn't affected
        }

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
     * @param string|null $provider Provider name (optional)
     * @return bool Success status
     */
    public static function updateSession(
        string $oldToken,
        array $newData,
        string $newToken,
        ?string $provider = null
    ): bool {
        self::initialize();

        // Get session ID from old token
        $sessionId = TokenManager::getSessionIdFromToken($oldToken);
        if (!$sessionId) {
            return false;
        }

        // Get current session to determine provider
        $currentSession = CacheEngine::get(self::SESSION_PREFIX . $sessionId);
        $sessionProvider = $provider ?? ($currentSession['provider'] ?? 'jwt');

        // Make sure provider is set in updated data
        $newData['provider'] = $sessionProvider;

        // Get the TTL for this provider type
        $ttl = self::getProviderTtl($sessionProvider);

        // Remove old token mapping
        TokenManager::removeTokenMapping($oldToken);

        // Store new session data
        $success = CacheEngine::set(
            self::SESSION_PREFIX . $sessionId,
            $newData,
            $ttl
        );

        if ($success) {
            // Map new token to existing session
            $mapped = TokenManager::mapTokenToSession($newToken, $sessionId);

            // Log session update in the audit log
            try {
                if ($mapped) {
                    $userId = $newData['user']['uuid'] ?? null;
                    $auditLogger = AuditLogger::getInstance();
                    $auditLogger->authEvent(
                        'session_updated',
                        $userId,
                        [
                            'session_id' => $sessionId,
                            'provider' => $sessionProvider,
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'token_refreshed' => true
                        ],
                        AuditEvent::SEVERITY_INFO
                    );
                }
            } catch (\Throwable $e) {
                // Silently handle audit logging errors to ensure session update isn't affected
            }

            return $mapped;
        }

        return false;
    }

    /**
     * Get current session
     *
     * Retrieves session for current request.
     *
     * @param string|null $provider Optional provider hint
     * @return array|null Session data or null if not authenticated
     */
    public static function getCurrentSession(?string $provider = null): ?array
    {
        $token = TokenManager::extractTokenFromRequest();
        if (!$token) {
            return null;
        }

        return self::getSession($token, $provider);
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

    /**
     * Invalidate all sessions for a provider
     *
     * Removes all sessions associated with a specific authentication provider.
     * Useful for security events or when changing provider configuration.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return bool Success status
     */
    public static function invalidateProviderSessions(string $provider): bool
    {
        self::initialize();

        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessionIds = CacheEngine::get($indexKey) ?? [];
        // Store count of sessions for audit logging
        $sessionCount = count($sessionIds);

        $success = true;
        foreach ($sessionIds as $sessionId) {
            $session = CacheEngine::get(self::SESSION_PREFIX . $sessionId);
            if ($session && isset($session['token'])) {
                // Use destroySession to properly clean up token mappings as well
                $result = self::destroySession($session['token'], $provider);
                $success = $success && $result;
            }
        }

        // Clear the provider index
        CacheEngine::delete($indexKey);

        // Log provider sessions invalidation in the audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            // Try to get current user for actor
            $userRepository = new \Glueful\Repository\UserRepository();
            $userId = null;
            if (function_exists('getCurrentUserId')) {
                $userId = $userRepository->getCurrentUser()['uuid'] ?? null;
            }

            $auditLogger->authEvent(
                'provider_sessions_invalidated',
                $userId,
                [
                    'provider' => $provider,
                    'sessions_count' => $sessionCount,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ],
                AuditEvent::SEVERITY_WARNING // Higher severity as this is a bulk operation
            );
        } catch (\Throwable $e) {
            // Silently handle audit logging errors to ensure invalidation isn't affected
        }

        return $success;
    }
}
