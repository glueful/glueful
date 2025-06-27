<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Cache\CacheStore;
use Glueful\Security\SecureSerializer;

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
    private const PERMISSION_CACHE_PREFIX = 'user_permissions:';
    private const USER_SESSION_INDEX_PREFIX = 'user_sessions:';
    private const DEFAULT_TTL = 3600; // 1 hour
    private const PERMISSIONS_TTL = 1800; // 30 minutes

    /** @var CacheStore Cache driver service */
    private CacheStore $cache;

    /** @var int Session TTL */
    private int $ttl;

    /** @var array Provider configurations */
    private array $providerConfigs;

    /** @var object|null Permission service */
    private ?object $permissionService = null;

    /** @var object|null Queue service */
    private ?object $queueService = null;

    /**
     * Constructor
     *
     * @param CacheStore $cache Cache driver service
     */
    public function __construct(CacheStore $cache)
    {
        $this->cache = $cache;
        $this->ttl = (int)config('session.access_token_lifetime', self::DEFAULT_TTL);
        $this->providerConfigs = config('security.authentication_providers', []);
        $this->initializeServices();
    }

    /**
     * Initialize services via dependency injection
     */
    private function initializeServices(): void
    {
        try {
            // Try to get services from DI container using the container() helper
            if (function_exists('container')) {
                $container = container();

                // Try to resolve queue service
                if ($container->has('queue.service')) {
                    $this->queueService = $container->get('queue.service');
                } elseif ($container->has('QueueService')) {
                    $this->queueService = $container->get('QueueService');
                }
            }

            // Initialize PermissionManager's provider for efficient permission loading
            if (class_exists('Glueful\\Permissions\\PermissionManager')) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $this->permissionService = $manager->getProvider();
                }
            }
        } catch (\Throwable $e) {
            // Container not available or services not found - this is expected during early initialization
            // Will fall back to container-based repositories for permissions
        }
    }

    /**
     * Set permission service (for testing or manual DI)
     */
    public function setPermissionService(?object $service): void
    {
        $this->permissionService = $service;
    }

    /**
     * Set queue service (for testing or manual DI)
     */
    public function setQueueService(?object $service): void
    {
        $this->queueService = $service;
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
    public function storeSession(
        array $userData,
        string $token,
        ?string $provider = 'jwt',
        ?int $ttl = null
    ): bool {

        $sessionId = $this->generateSessionId();

    // Use custom TTL if provided, or provider-specific TTL if available
        $sessionTtl = $ttl ?? $this->getProviderTtl($provider);

    // Pre-load permissions if RBAC extension is available
        $enhancedUserData = $this->enhanceUserDataWithPermissions($userData);

        $sessionData = [
            'id' => $sessionId,
            'token' => $token,
            'user' => $enhancedUserData,
            'created_at' => time(),
            'last_activity' => time(),
            'provider' => $provider ?? 'jwt', // Store the provider used
            'permissions_loaded_at' => time() // Track when permissions were cached
        ];

    // Store session data
        $success = $this->cache->set(
            self::SESSION_PREFIX . $sessionId,
            $sessionData,
            $sessionTtl
        );

        if ($success) {
            // Index this session by provider for easier management
            $this->indexSessionByProvider($provider ?? 'jwt', $sessionId, $sessionTtl);

            // Index session by user UUID for quick lookup
            if (isset($enhancedUserData['uuid'])) {
                $this->indexSessionByUser($enhancedUserData['uuid'], $sessionId, $sessionTtl);
            }

            // Have TokenManager map the token to this session
            $mapped = TokenManager::mapTokenToSession($token, $sessionId);

            // Cache permissions separately for faster access
            if (isset($enhancedUserData['permissions']) && isset($enhancedUserData['uuid'])) {
                $this->cacheUserPermissions($enhancedUserData['uuid'], $enhancedUserData['permissions']);
            }

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
    private function indexSessionByProvider(string $provider, string $sessionId, int $ttl): bool
    {
        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Add session to the provider's index
        $sessions[] = $sessionId;

        // Remove any duplicates
        $sessions = array_unique($sessions);

        return $this->cache->set($indexKey, $sessions, $ttl);
    }

    /**
     * Index session by user UUID
     *
     * Creates a secondary index of sessions organized by user.
     * Enables efficient lookup of all sessions for a specific user.
     *
     * @param string $userUuid User UUID
     * @param string $sessionId Session identifier
     * @param int $ttl Time-to-live in seconds
     * @return bool Success status
     */
    private function indexSessionByUser(string $userUuid, string $sessionId, int $ttl): bool
    {
        $indexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Add session to the user's index
        $sessions[] = $sessionId;

        // Remove any duplicates
        $sessions = array_unique($sessions);

        return $this->cache->set($indexKey, $sessions, $ttl);
    }

    /**
     * Remove session from user index
     *
     * Removes a session ID from a user's index list.
     *
     * @param string $userUuid User UUID
     * @param string $sessionId Session ID to remove
     * @return bool Success status
     */
    private function removeSessionFromUserIndex(string $userUuid, string $sessionId): bool
    {
        $indexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Remove session from the index
        $sessions = array_diff($sessions, [$sessionId]);

        // If no sessions left, delete the index entirely
        if (empty($sessions)) {
            return $this->cache->delete($indexKey);
        }

        // Use default TTL for user session index
        return $this->cache->set($indexKey, $sessions, self::DEFAULT_TTL);
    }

    /**
     * Get sessions by provider
     *
     * Retrieves all sessions for a specific authentication provider.
     *
     * @param string $provider Provider name (jwt, apikey, etc.)
     * @return array Array of session data
     */
    public function getSessionsByProvider(string $provider): array
    {


        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessionIds = $this->cache->get($indexKey) ?? [];

        if (empty($sessionIds)) {
            return [];
        }

        // Batch retrieve sessions to avoid N+1 cache calls
        return $this->batchGetSessions($sessionIds);
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
    public function getSession(string $token, ?string $provider = null): ?array
    {


        $sessionId = TokenManager::getSessionIdFromToken($token);
        if (!$sessionId) {
            return null;
        }

        $session = $this->cache->get(self::SESSION_PREFIX . $sessionId);
        if (!$session) {
            return null;
        }

        // If provider is specified, validate it matches the session's provider
        if ($provider && isset($session['provider']) && $session['provider'] !== $provider) {
            return null;
        }

        // Get the TTL for this provider type
        $ttl = $this->getProviderTtl($session['provider'] ?? 'jwt');

        // Update last activity
        $session['last_activity'] = time();
        $this->cache->set(
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
    private function getProviderTtl(string $provider): int
    {
        if (isset($this->providerConfigs[$provider]['session_ttl'])) {
            return (int)$this->providerConfigs[$provider]['session_ttl'];
        }

        return $this->ttl;
    }

    /**
     * Remove session
     *
     * Deletes session data from cache and provider index.
     *
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public function removeSession(string $sessionId): bool
    {


        // Get session to find its provider
        $session = $this->cache->get(self::SESSION_PREFIX . $sessionId);

        // Remove from provider index if provider information is available
        if ($session && isset($session['provider'])) {
            $this->removeSessionFromProviderIndex($session['provider'], $sessionId);
        }

        // Remove from user index if user information is available
        if ($session && isset($session['user']['uuid'])) {
            $this->removeSessionFromUserIndex($session['user']['uuid'], $sessionId);
        }

        return $this->cache->delete(self::SESSION_PREFIX . $sessionId);
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
    private function removeSessionFromProviderIndex(string $provider, string $sessionId): bool
    {
        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessions = $this->cache->get($indexKey) ?? [];

        // Remove session from the index
        $sessions = array_diff($sessions, [$sessionId]);

        // Get the TTL for this provider type
        $ttl = $this->getProviderTtl($provider);

        return $this->cache->set($indexKey, $sessions, $ttl);
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
    public function destroySession(string $token, ?string $provider = null): bool
    {


        // Get session ID from token
        $sessionId = TokenManager::getSessionIdFromToken($token);
        if (!$sessionId) {
            return false;
        }

        // Get session to find its provider
        $session = $this->cache->get(self::SESSION_PREFIX . $sessionId);
        if ($session && $provider && isset($session['provider']) && $session['provider'] !== $provider) {
            // If provider is specified and doesn't match, don't destroy the session
            return false;
        }


        // Remove session data
        $sessionRemoved = $this->removeSession($sessionId);

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
     * @param string|null $provider Provider name (optional)
     * @return bool Success status
     */
    public function updateSession(
        string $oldToken,
        array $newData,
        string $newToken,
        ?string $provider = null
    ): bool {


        // Get session ID from old token
        $sessionId = TokenManager::getSessionIdFromToken($oldToken);
        if (!$sessionId) {
            return false;
        }

        // Get current session to determine provider
        $currentSession = $this->cache->get(self::SESSION_PREFIX . $sessionId);
        $sessionProvider = $provider ?? ($currentSession['provider'] ?? 'jwt');

        // Make sure provider is set in updated data
        $newData['provider'] = $sessionProvider;

        // Get the TTL for this provider type
        $ttl = $this->getProviderTtl($sessionProvider);

        // Remove old token mapping
        TokenManager::removeTokenMapping($oldToken);

        // Store new session data
        $success = $this->cache->set(
            self::SESSION_PREFIX . $sessionId,
            $newData,
            $ttl
        );

        if ($success) {
            // Map new token to existing session
            $mapped = TokenManager::mapTokenToSession($newToken, $sessionId);


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
    public function getCurrentSession(?string $provider = null): ?array
    {
        $token = TokenManager::extractTokenFromRequest();
        if (!$token) {
            return null;
        }

        return $this->getSession($token, $provider);
    }

    /**
     * Get session with permission validation
     *
     * Retrieves session data and checks if permissions need refresh.
     *
     * @param string $token Authentication token
     * @param string|null $provider Optional provider hint
     * @return array|null Session data or null if invalid
     */
    public function getSessionWithValidPermissions(string $token, ?string $provider = null): ?array
    {
        $session = $this->getSession($token, $provider);

        if (!$session) {
            return null;
        }

        // Check if permissions need refresh
        $permissionsAge = time() - ($session['permissions_loaded_at'] ?? 0);

        if ($permissionsAge > self::PERMISSIONS_TTL) {
            // Queue background permission refresh for next request
            $userUuid = $session['user']['uuid'] ?? null;
            if ($userUuid) {
                $this->queuePermissionRefresh($userUuid, $token);
            }
        }

        return $session;
    }

    /**
     * Generate unique session identifier
     *
     * @return string Session ID
     */
    private function generateSessionId(): string
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
    public function invalidateProviderSessions(string $provider): bool
    {


        $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
        $sessionIds = $this->cache->get($indexKey) ?? [];

        $success = true;
        foreach ($sessionIds as $sessionId) {
            $session = $this->cache->get(self::SESSION_PREFIX . $sessionId);
            if ($session && isset($session['token'])) {
                // Use destroySession to properly clean up token mappings as well
                $result = $this->destroySession($session['token'], $provider);
                $success = $success && $result;
            }
        }

        // Clear the provider index
        $this->cache->delete($indexKey);


        return $success;
    }

    /**
     * Enhance user data with permissions and roles
     *
     * Pre-loads user permissions and roles using DI-injected permission service.
     *
     * @param array $userData Base user data
     * @return array Enhanced user data with permissions
     */
    private function enhanceUserDataWithPermissions(array $userData): array
    {
        $userUuid = $userData['uuid'] ?? null;

        if (!$userUuid) {
            return $userData;
        }

        try {
            $permissions = $this->loadUserPermissions($userUuid);
            $roles = $this->loadUserRoles($userUuid);

            // Ensure we have arrays (defensive programming)
            $permissions = is_array($permissions) ? $permissions : [];
            $roles = is_array($roles) ? $roles : [];

            return array_merge($userData, [
            'permissions' => $permissions,
            'roles' => $roles,
            'permission_hash' => hash('xxh3', json_encode(array_merge($permissions, $roles)))
            ]);
        } catch (\Throwable $e) {
            // Log error but don't fail session creation
            error_log("Failed to load permissions for user 1 {$userUuid}: " . $e->getMessage());
            return array_merge($userData, [
            'permissions' => [],
            'roles' => [],
            'permission_hash' => null
            ]);
        }
    }

    /**
     * Load user permissions using RBAC permission provider
     *
     * @param string $userUuid User UUID
     * @return array User permissions grouped by resource
     */
    private function loadUserPermissions(string $userUuid): array
    {
        try {
            // 1. Use initialized permission service (PermissionManager's provider)
            if ($this->permissionService && method_exists($this->permissionService, 'getUserPermissions')) {
                return $this->permissionService->getUserPermissions($userUuid);
            }

            // 2. Fallback to direct RBAC repository access if service not available
            if (function_exists('container')) {
                try {
                    $container = container();
                    if ($container->has('rbac.repository.user_permission')) {
                        $userPermRepo = $container->get('rbac.repository.user_permission');
                        if (method_exists($userPermRepo, 'getUserPermissions')) {
                            return $userPermRepo->getUserPermissions($userUuid);
                        }
                    }
                } catch (\Throwable $e) {
                    // Container not available, return empty permissions
                }
            }

            return [];
        } catch (\Throwable $e) {
            error_log("Failed to load permissions for user {$userUuid}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load user roles using RBAC role service
     *
     * @param string $userUuid User UUID
     * @return array User roles with hierarchy information
     */
    private function loadUserRoles(string $userUuid): array
    {
        try {
            // 1. Use DI-injected RBAC service first (most efficient)
            if ($this->permissionService && method_exists($this->permissionService, 'getUserRoles')) {
                return $this->permissionService->getUserRoles($userUuid);
            }

            // 2. Try RBAC role service directly
            if (function_exists('container')) {
                try {
                    $container = container();
                    if ($container->has('rbac.role_service')) {
                        $roleService = $container->get('rbac.role_service');
                        if (method_exists($roleService, 'getUserRoles')) {
                            return $roleService->getUserRoles($userUuid);
                        }
                    }
                } catch (\Throwable $e) {
                    // Container not available, continue to fallbacks
                }
            }

            // 3. Try RBAC role repository
            if (function_exists('container')) {
                try {
                    $container = container();
                    if ($container->has('rbac.repository.user_role')) {
                        $userRoleRepo = $container->get('rbac.repository.user_role');
                        if (method_exists($userRoleRepo, 'getUserRoles')) {
                            return $userRoleRepo->getUserRoles($userUuid);
                        }
                    }
                } catch (\Throwable $e) {
                    // Container not available, continue to fallbacks
                }
            }

            // 4. Try PermissionManager provider (only if it's an RBAC provider with getUserRoles)
            if (class_exists('Glueful\\Permissions\\PermissionManager')) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $provider = $manager->getProvider();
                    // Only call getUserRoles if the provider actually has this method
                    if ($provider && method_exists($provider, 'getUserRoles')) {
                        $result = call_user_func([$provider, 'getUserRoles'], $userUuid);
                        return is_array($result) ? $result : [];
                    }
                }
            }

            // 5. Graceful fallback - return empty array if no role system available
            return [];
        } catch (\Throwable $e) {
            error_log("Failed to load roles for user {$userUuid}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Cache user permissions separately for faster access
     *
     * @param string $userUuid User UUID
     * @param array $permissions User permissions
     * @return bool Success status
     */
    private function cacheUserPermissions(string $userUuid, array $permissions): bool
    {
        try {
            $cacheKey = self::PERMISSION_CACHE_PREFIX . $userUuid;
            return $this->cache->set($cacheKey, $permissions, self::PERMISSIONS_TTL);
        } catch (\Throwable $e) {
            error_log("Failed to cache permissions for user {$userUuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cached user permissions
     *
     * @param string $userUuid User UUID
     * @return array|null Cached permissions or null if not found
     */
    public function getCachedUserPermissions(string $userUuid): ?array
    {
        try {
            $cacheKey = self::PERMISSION_CACHE_PREFIX . $userUuid;
            return $this->cache->get($cacheKey);
        } catch (\Throwable $e) {
            error_log("Failed to get cached permissions for user {$userUuid}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Invalidate cached user permissions across all systems
     *
     * Integrates with RBAC permission provider invalidation.
     *
     * @param string $userUuid User UUID
     * @return bool Success status
     */
    public function invalidateUserPermissions(string $userUuid): bool
    {
        try {
            // 1. Invalidate session-level permission cache
            $cacheKey = self::PERMISSION_CACHE_PREFIX . $userUuid;
            $success = $this->cache->delete($cacheKey);

            // 2. Invalidate RBAC permission provider cache if available
            if ($this->permissionService && method_exists($this->permissionService, 'invalidateUserCache')) {
                $this->permissionService->invalidateUserCache($userUuid);
            }

            // 3. Fallback: invalidate via PermissionManager
            if (class_exists('Glueful\\Permissions\\PermissionManager')) {
                $manager = \Glueful\Permissions\PermissionManager::getInstance();
                if ($manager->hasActiveProvider()) {
                    $provider = $manager->getProvider();
                    if ($provider && method_exists($provider, 'invalidateUserCache')) {
                        $provider->invalidateUserCache($userUuid);
                    }
                }
            }

            // 4. Invalidate session permission patterns
            $patterns = [
            "session:*:user:{$userUuid}:permissions",
            "rbac:check:{$userUuid}:*",
            "permissions:user:{$userUuid}*"
            ];

            foreach ($patterns as $pattern) {
                $this->cache->deletePattern($pattern);
            }

            // 5. Clean up user session index (optional - sessions will auto-cleanup)
            $userIndexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
            $this->cache->delete($userIndexKey);

            return $success;
        } catch (\Throwable $e) {
            error_log("Failed to invalidate permissions for user {$userUuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue permission refresh for background processing
     *
     * @param string $userUuid User UUID
     * @param string $token Session token
     * @return void
     */
    private function queuePermissionRefresh(string $userUuid, string $token): void
    {
        try {
            // Use DI-injected queue service first
            if ($this->queueService && method_exists($this->queueService, 'dispatch')) {
                $this->queueService->dispatch('RefreshUserPermissionsJob', [
                'user_uuid' => $userUuid,
                'token' => $token,
                'queued_at' => time()
                ]);
                return;
            }

            // Fallback: mark for refresh on next session access
            $refreshKey = "permission_refresh:{$userUuid}";
            $this->cache->set($refreshKey, $token, 300); // 5 minutes
        } catch (\Throwable $e) {
            error_log("Failed to queue permission refresh for user {$userUuid}: " . $e->getMessage());
        }
    }

    /**
     * Refresh user permissions in their active session
     *
     * @param string $userUuid User UUID
     * @param string $token Session token
     * @return bool Success status
     */
    public function refreshUserPermissionsInSession(string $userUuid, string $token): bool
    {
        try {
            // Get current session
            $session = $this->getSession($token);
            if (!$session) {
                return false;
            }

            // Load fresh permissions
            $permissions = $this->loadUserPermissions($userUuid);
            $roles = $this->loadUserRoles($userUuid);

            // Update session data
            $session['user']['permissions'] = $permissions;
            $session['user']['roles'] = $roles;
            $session['user']['permission_hash'] = hash('xxh3', json_encode(array_merge($permissions, $roles)));
            $session['permissions_loaded_at'] = time();

            // Get session ID and update
            $sessionId = TokenManager::getSessionIdFromToken($token);
            if ($sessionId) {
                $ttl = $this->getProviderTtl($session['provider'] ?? 'jwt');
                $success = $this->cache->set(self::SESSION_PREFIX . $sessionId, $session, $ttl);

                if ($success) {
                    // Update separate permission cache
                    $this->cacheUserPermissions($userUuid, $permissions);
                }

                return $success;
            }

            return false;
        } catch (\Throwable $e) {
            error_log("Failed to refresh permissions in session for user {$userUuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Refresh permissions for all active sessions of a user
     *
     * @param string $userUuid User UUID
     * @return int Number of sessions updated
     */
    public function refreshPermissionsForAllUserSessions(string $userUuid): int
    {
        $updatedSessions = 0;

        try {
            // Find all sessions for the user (this is a simplified approach)
            // In a real implementation, you might want to maintain a user-to-sessions index
            $sessions = $this->findUserSessions($userUuid);

            foreach ($sessions as $session) {
                if (isset($session['token'])) {
                    $success = $this->refreshUserPermissionsInSession($userUuid, $session['token']);
                    if ($success) {
                        $updatedSessions++;
                    }
                }
            }

            // Also invalidate the separate permission cache
            $this->invalidateUserPermissions($userUuid);
        } catch (\Throwable $e) {
            error_log("Failed to refresh permissions for all sessions of user {$userUuid}: " . $e->getMessage());
        }

        return $updatedSessions;
    }

    /**
     * Find all active sessions for a user
     *
     * Uses the user session index for efficient lookup.
     *
     * @param string $userUuid User UUID
     * @return array Array of session data
     */
    private function findUserSessions(string $userUuid): array
    {
        try {
            // Get session IDs from user index
            $indexKey = self::USER_SESSION_INDEX_PREFIX . $userUuid;
            $sessionIds = $this->cache->get($indexKey) ?? [];

            // Use batch get to retrieve all sessions at once
            $sessions = $this->batchGetSessions($sessionIds);
            $validSessions = [];

            // Verify sessions belong to this user and clean up invalid entries
            foreach ($sessions as $i => $session) {
                if (isset($session['user']['uuid']) && $session['user']['uuid'] === $userUuid) {
                    $validSessions[] = $session;
                } else {
                    // Clean up invalid index entry if we can map it back to sessionId
                    if (isset($sessionIds[$i])) {
                        $this->removeSessionFromUserIndex($userUuid, $sessionIds[$i]);
                    }
                }
            }

            $sessions = $validSessions;

            return $sessions;
        } catch (\Throwable $e) {
            error_log("Failed to find sessions for user {$userUuid}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all active sessions for a user (public interface)
     *
     * @param string $userUuid User UUID
     * @return array Array of session data
     */
    public function getUserSessions(string $userUuid): array
    {
        return $this->findUserSessions($userUuid);
    }

    /**
     * Get count of active sessions for a user
     *
     * @param string $userUuid User UUID
     * @return int Number of active sessions
     */
    public function getUserSessionCount(string $userUuid): int
    {
        return count($this->findUserSessions($userUuid));
    }

    /**
     * Terminate all sessions for a user
     *
     * @param string $userUuid User UUID
     * @return int Number of sessions terminated
     */
    public function terminateAllUserSessions(string $userUuid): int
    {
        $sessions = $this->findUserSessions($userUuid);
        $terminated = 0;

        foreach ($sessions as $session) {
            if (isset($session['token'])) {
                $success = $this->destroySession($session['token']);
                if ($success) {
                    $terminated++;
                }
            }
        }

        return $terminated;
    }

    /**
     * Check if session permissions are valid and fresh
     *
     * Uses RBAC-style cache validation logic.
     *
     * @param array $session Session data
     * @return bool True if permissions are valid
     */
    private function areSessionPermissionsValid(array $session): bool
    {
        // Check if permissions exist
        if (!isset($session['user']['permissions']) || !isset($session['permissions_loaded_at'])) {
            return false;
        }

        // Check TTL
        $age = time() - $session['permissions_loaded_at'];
        if ($age > self::PERMISSIONS_TTL) {
            return false;
        }

        // Validate permission hash if available (integrity check)
        if (isset($session['user']['permission_hash'])) {
            $currentHash = hash('xxh3', json_encode(array_merge(
                $session['user']['permissions'] ?? [],
                $session['user']['roles'] ?? []
            )));

            if ($currentHash !== $session['user']['permission_hash']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get optimized session with smart permission caching
     *
     * Uses RBAC-style optimization patterns for maximum performance.
     *
     * @param string $token Authentication token
     * @param array $context Additional context for permission loading (reserved for future use)
     * @return array|null Session data with optimized permissions
     */
    public function getOptimizedSession(string $token, array $context = []): ?array
    {
        // Note: $context parameter reserved for future context-aware permission loading
        unset($context); // Acknowledge unused parameter until context-aware loading is implemented
        $session = $this->getSession($token);

        if (!$session) {
            return null;
        }

        // Check if permissions are valid
        if ($this->areSessionPermissionsValid($session)) {
            // Permissions are fresh, return as-is
            return $session;
        }

        // Permissions need refresh - load fresh data
        $userUuid = $session['user']['uuid'] ?? null;
        if ($userUuid) {
            try {
                $permissions = $this->loadUserPermissions($userUuid);
                $roles = $this->loadUserRoles($userUuid);

                // Update session with fresh permissions
                $session['user']['permissions'] = $permissions;
                $session['user']['roles'] = $roles;
                $session['user']['permission_hash'] = hash('xxh3', json_encode(array_merge($permissions, $roles)));
                $session['permissions_loaded_at'] = time();

                // Store updated session
                $sessionId = TokenManager::getSessionIdFromToken($token);
                if ($sessionId) {
                    $ttl = $this->getProviderTtl($session['provider'] ?? 'jwt');
                    $this->cache->set(self::SESSION_PREFIX . $sessionId, $session, $ttl);

                    // Also update separate permission cache
                    $this->cacheUserPermissions($userUuid, $permissions);
                }
            } catch (\Throwable $e) {
                error_log("Failed to refresh permissions for optimized session: " . $e->getMessage());
                // Return session with potentially stale permissions rather than failing
            }
        }

        return $session;
    }

    /**
     * Batch load permissions for multiple users
     *
     * Optimizes permission loading for bulk operations.
     *
     * @param array $userUuids Array of user UUIDs
     * @return array Associative array of userUuid => permissions
     */
    public function batchLoadUserPermissions(array $userUuids): array
    {
        if (empty($userUuids)) {
            return [];
        }

        $results = [];
        $missing = [];

        // Check cache for all users first
        foreach ($userUuids as $userUuid) {
            $cached = $this->getCachedUserPermissions($userUuid);
            if ($cached !== null) {
                $results[$userUuid] = $cached;
            } else {
                $missing[] = $userUuid;
            }
        }

        // Load missing permissions
        if (!empty($missing)) {
            try {
                // Try batch loading if RBAC provider supports it
                if ($this->permissionService && method_exists($this->permissionService, 'batchGetUserPermissions')) {
                    $batchResults = $this->permissionService->batchGetUserPermissions($missing);
                    foreach ($batchResults as $userUuid => $permissions) {
                        $results[$userUuid] = $permissions;
                        $this->cacheUserPermissions($userUuid, $permissions);
                    }
                } else {
                    // Fallback to individual loading
                    foreach ($missing as $userUuid) {
                        $permissions = $this->loadUserPermissions($userUuid);
                        $results[$userUuid] = $permissions;
                        $this->cacheUserPermissions($userUuid, $permissions);
                    }
                }
            } catch (\Throwable $e) {
                error_log("Failed to batch load permissions: " . $e->getMessage());
                // Fill missing with empty arrays
                foreach ($missing as $userUuid) {
                    $results[$userUuid] = [];
                }
            }
        }

        return $results;
    }

    /**
     * Batch retrieve sessions to avoid N+1 cache calls
     *
     * @param array $sessionIds Array of session IDs
     * @return array Array of valid sessions
     */
    private function batchGetSessions(array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        // Prepare cache keys
        $cacheKeys = array_map(fn($id) => self::SESSION_PREFIX . $id, $sessionIds);

        // Use the batch get operation from CacheStore
        $cachedSessions = $this->cache->mget($cacheKeys);

        // Return only valid sessions (filter out null/false values)
        return array_values(array_filter($cachedSessions));
    }

    /**
     * Create session query builder for advanced filtering
     *
     * @return SessionQueryBuilder Query builder instance
     */
    public function sessionQuery(): SessionQueryBuilder
    {
        return new SessionQueryBuilder(__CLASS__);
    }

    /**
     * Get sessions by provider for query builder (public access for SessionQueryBuilder)
     *
     * @param string $provider Provider name
     * @return array Sessions for the provider
     */
    public function getSessionsByProviderForQuery(string $provider): array
    {
        try {
            $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
            $sessionIds = $this->cache->get($indexKey) ?? [];

            return $this->batchGetSessions($sessionIds);
        } catch (\Throwable $e) {
            error_log("Failed to get sessions for provider {$provider}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get provider TTL (public access for SessionTransaction)
     *
     * @param string $provider Provider name
     * @return int TTL in seconds
     */
    public function getProviderTtlPublic(string $provider): int
    {
        return $this->getProviderTtl($provider);
    }

    /**
     * Remove session from provider index (public access for SessionTransaction)
     *
     * @param string $provider Provider name
     * @param string $sessionId Session ID
     * @return bool Success status
     */
    public function removeSessionFromProviderIndexPublic(string $provider, string $sessionId): bool
    {
        try {
            $indexKey = self::PROVIDER_INDEX_PREFIX . $provider;
            $sessionIds = $this->cache->get($indexKey) ?? [];

            $sessionIds = array_filter($sessionIds, fn($id) => $id !== $sessionId);

            return $this->cache->set($indexKey, array_values($sessionIds), self::DEFAULT_TTL);
        } catch (\Throwable $e) {
            error_log("Failed to remove session from provider index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Index session by provider (public access for SessionTransaction)
     *
     * @param string $provider Provider name
     * @param string $sessionId Session ID
     * @param int $ttl TTL in seconds
     * @return bool Success status
     */
    public function indexSessionByProviderPublic(string $provider, string $sessionId, int $ttl): bool
    {
        return $this->indexSessionByProvider($provider, $sessionId, $ttl);
    }

    /**
     * Create bulk session transaction
     *
     * @return SessionTransaction Transaction instance
     */
    public function transaction(): SessionTransaction
    {
        $transaction = new SessionTransaction();
        $transaction->begin();
        return $transaction;
    }

    /**
     * Invalidate sessions matching criteria
     *
     * @param array $criteria Selection criteria
     * @return int Number of sessions invalidated
     */
    public function invalidateSessionsWhere(array $criteria): int
    {
        $transaction = $this->transaction();

        try {
            $count = $transaction->invalidateSessionsWhere($criteria);
            $transaction->commit();
            return $count;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * Update sessions matching criteria
     *
     * @param array $criteria Selection criteria
     * @param array $updates Updates to apply
     * @return int Number of sessions updated
     */
    public function updateSessionsWhere(array $criteria, array $updates): int
    {
        $transaction = $this->transaction();

        try {
            $count = $transaction->updateSessionsWhere($criteria, $updates);
            $transaction->commit();
            return $count;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * Create bulk sessions
     *
     * @param array $sessionsData Array of session data
     * @return array Array of created session IDs
     */
    public function createBulkSessions(array $sessionsData): array
    {
        $transaction = $this->transaction();

        try {
            $sessionIds = $transaction->createSessions($sessionsData);
            $transaction->commit();
            return $sessionIds;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * Migrate sessions between providers
     *
     * @param string $fromProvider Source provider
     * @param string $toProvider Target provider
     * @return int Number of sessions migrated
     */
    public function migrateSessions(string $fromProvider, string $toProvider): int
    {
        $transaction = $this->transaction();

        try {
            $count = $transaction->migrateSessions($fromProvider, $toProvider);
            $transaction->commit();
            return $count;
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }
}
