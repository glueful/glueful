<?php
declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Auth\SessionCacheManager;
use Glueful\Auth\AuthenticationService;
use Glueful\Repository\PermissionRepository;
use Glueful\Cache\CacheEngine;

/**
 * Permission Manager
 * 
 * Provides a simplified, centralized interface for permission checking.
 * Acts as a facade to the underlying permission repository and cache system.
 * 
 * Offers:
 * - Standardized permission checking
 * - Caching for better performance
 * - Debugging mode for troubleshooting
 * - Permission cache invalidation
 */
class PermissionManager
{
    /** @var PermissionRepository Permission repository for database operations */
    private static PermissionRepository $repository;
    
    /** @var bool Whether to enable detailed debug information */
    private static bool $debugMode = false;
    
    /**
     * Initialize the permission manager
     * 
     * Sets up dependencies and configuration
     * 
     * @param bool $debugMode Enable debug mode for detailed permission info
     * @return void
     */
    public static function initialize(bool $debugMode = false): void
    {
        self::$repository = new PermissionRepository();
        self::$debugMode = $debugMode;
        
        // Initialize cache if needed
        CacheEngine::initialize();
    }
    
    /**
     * Check if the current authenticated user has a specific permission
     * 
     * @param string $model The resource model to check (e.g. 'api.users')
     * @param string $permission The permission to check (use Permission class constants)
     * @param string|null $token Optional token (uses current request token if not provided)
     * @return bool Whether the user has the permission
     */
    public static function can(string $model, string $permission, ?string $token = null): bool
    {
        // Get the repository
        if (!isset(self::$repository)) {
            self::initialize();
        }
        
        // Get token from request if not provided
        if ($token === null) {
            $token = AuthenticationService::extractTokenFromRequest();
        }
        
        if (!$token) {
            return false;
        }
        
        // Get user from session
        $session = SessionCacheManager::getSession($token);
        if (!$session || !isset($session['user']['uuid'])) {
            return false;
        }
        
        $userUuid = $session['user']['uuid'];
        
        // Use cached permissions check
        return self::$repository->hasPermission($userUuid, $model, $permission);
    }
    
    /**
     * Check if the current user has permission with detailed debug info
     * 
     * @param string $model The resource model to check
     * @param string $permission The permission to check
     * @param string|null $token Optional token
     * @return array Debug information about the permission check
     */
    public static function debug(string $model, string $permission, ?string $token = null): array
    {
        // Get the repository
        if (!isset(self::$repository)) {
            self::initialize();
        }
        
        // Get token from request if not provided
        if ($token === null) {
            $token = AuthenticationService::extractTokenFromRequest();
        }
        
        if (!$token) {
            return [
                'has_permission' => false,
                'reason' => 'No authentication token provided'
            ];
        }
        
        // Get user from session
        $session = SessionCacheManager::getSession($token);
        if (!$session || !isset($session['user']['uuid'])) {
            return [
                'has_permission' => false,
                'reason' => 'Invalid or expired session'
            ];
        }
        
        $userUuid = $session['user']['uuid'];
        
        // Get detailed permission check
        return self::$repository->hasPermissionDebug($userUuid, $model, $permission);
    }
    
    /**
     * Get all effective permissions for the current user
     * 
     * @param string|null $token Optional token
     * @param bool $useCache Whether to use cached permissions
     * @return array User's effective permissions
     */
    public static function getPermissions(?string $token = null, bool $useCache = true): array
    {
        // Get the repository
        if (!isset(self::$repository)) {
            self::initialize();
        }
        
        // Get token from request if not provided
        if ($token === null) {
            $token = AuthenticationService::extractTokenFromRequest();
        }
        
        if (!$token) {
            return [];
        }
        
        // Get user from session
        $session = SessionCacheManager::getSession($token);
        if (!$session || !isset($session['user']['uuid'])) {
            return [];
        }
        
        $userUuid = $session['user']['uuid'];
        
        // Use cached or direct permission lookup
        if ($useCache) {
            return self::$repository->getCachedEffectivePermissions($userUuid);
        } else {
            return self::$repository->getEffectivePermissions($userUuid);
        }
    }
    
    /**
     * Invalidate permission cache for a user
     * 
     * @param string $userUuid User UUID
     * @return bool Whether the cache was successfully invalidated
     */
    public static function invalidateCache(string $userUuid): bool
    {
        if (!isset(self::$repository)) {
            self::initialize();
        }
        
        return self::$repository->invalidatePermissionCache($userUuid);
    }
    
    /**
     * Set debug mode
     * 
     * @param bool $enable Whether to enable debug mode
     */
    public static function setDebugMode(bool $enable): void
    {
        self::$debugMode = $enable;
    }
    
    /**
     * Get debug mode status
     * 
     * @return bool Whether debug mode is enabled
     */
    public static function isDebugMode(): bool
    {
        return self::$debugMode;
    }
}