<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Permission Cache Interface
 *
 * Contract for permission caching implementations.
 * Permission checks can be expensive, especially with complex
 * providers, so caching is crucial for performance.
 *
 * This interface allows different caching strategies:
 * - In-memory caching for request lifetime
 * - Redis/Memcached for distributed caching
 * - Database caching for persistent storage
 * - Multi-layer caching for optimal performance
 *
 * @package Glueful\Interfaces\Permission
 */
interface PermissionCacheInterface
{
    /**
     * Initialize the cache system
     *
     * Set up cache connections, configurations, and any
     * necessary resources for caching operations.
     *
     * @param array $config Cache configuration
     * @return void
     * @throws \Exception If cache initialization fails
     */
    public function initialize(array $config = []): void;

    /**
     * Get cached permissions for a user
     *
     * Retrieve all cached permissions for a specific user.
     * Should return null if no cache exists or cache is expired.
     *
     * @param string $userUuid User UUID to get cached permissions for
     * @return array|null Cached permissions or null if not found
     */
    public function getUserPermissions(string $userUuid): ?array;

    /**
     * Cache permissions for a user
     *
     * Store all permissions for a user in the cache.
     * Should include appropriate TTL (Time To Live) handling.
     *
     * @param string $userUuid User UUID to cache permissions for
     * @param array $permissions User's permissions to cache
     * @param int $ttl Time to live in seconds (0 = no expiration)
     * @return bool True if caching successful
     */
    public function setUserPermissions(string $userUuid, array $permissions, int $ttl = 3600): bool;

    /**
     * Get cached permission check result
     *
     * Retrieve a cached result for a specific permission check.
     * Key should be generated from user, permission, resource, and context.
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array $context Permission context
     * @return bool|null Cached result or null if not found
     */
    public function getPermissionCheck(string $userUuid, string $permission, string $resource, array $context = []): ?bool;

    /**
     * Cache permission check result
     *
     * Store the result of a permission check for future use.
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param bool $result Permission check result
     * @param array $context Permission context
     * @param int $ttl Time to live in seconds
     * @return bool True if caching successful
     */
    public function setPermissionCheck(string $userUuid, string $permission, string $resource, bool $result, array $context = [], int $ttl = 1800): bool;

    /**
     * Invalidate all cached data for a user
     *
     * Remove all cached permissions and permission checks for a user.
     * Should be called when user permissions change.
     *
     * @param string $userUuid User UUID to invalidate
     * @return bool True if invalidation successful
     */
    public function invalidateUser(string $userUuid): bool;

    /**
     * Invalidate cached data for a specific permission
     *
     * Remove cached data for a specific permission across all users.
     * Useful when permission definitions change.
     *
     * @param string $permission Permission name to invalidate
     * @param string $resource Optional resource filter
     * @return bool True if invalidation successful
     */
    public function invalidatePermission(string $permission, string $resource = ''): bool;

    /**
     * Invalidate all cached permission data
     *
     * Clear all permission caches across the system.
     * Use with caution as this will impact performance.
     *
     * @return bool True if invalidation successful
     */
    public function invalidateAll(): bool;

    /**
     * Warm up cache for a user
     *
     * Pre-populate cache with user's permissions to improve
     * performance of subsequent permission checks.
     *
     * @param string $userUuid User UUID to warm up
     * @param callable $permissionLoader Function to load permissions
     * @return bool True if warmup successful
     */
    public function warmupUser(string $userUuid, callable $permissionLoader): bool;

    /**
     * Get cache statistics
     *
     * Return information about cache usage, hit rates, and performance.
     * Useful for monitoring and optimization.
     *
     * @return array Cache statistics
     */
    public function getStats(): array;

    /**
     * Check if cache is available and functional
     *
     * Perform a health check of the caching system.
     *
     * @return bool True if cache is working
     */
    public function isHealthy(): bool;

    /**
     * Set cache TTL (Time To Live) for different cache types
     *
     * Configure default expiration times for different types of cached data.
     *
     * @param array $ttlConfig Array of cache type => TTL mappings
     * @return void
     */
    public function configureTTL(array $ttlConfig): void;

    /**
     * Generate cache key for permission check
     *
     * Create a consistent cache key for permission checks.
     * Should include all relevant parameters to avoid collisions.
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array $context Permission context
     * @return string Cache key
     */
    public function generateKey(string $userUuid, string $permission, string $resource, array $context = []): string;

    /**
     * Batch invalidate multiple users
     *
     * Efficiently invalidate cache for multiple users at once.
     * Useful for bulk operations or role changes.
     *
     * @param array $userUuids Array of user UUIDs to invalidate
     * @return bool True if batch invalidation successful
     */
    public function batchInvalidateUsers(array $userUuids): bool;

    /**
     * Get cache configuration
     *
     * Return current cache configuration for debugging.
     *
     * @return array Cache configuration
     */
    public function getConfig(): array;
}
