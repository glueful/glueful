<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Interfaces\Permission\PermissionCacheInterface;
use Glueful\Cache\CacheStore;

/**
 * Permission Cache
 *
 * Default implementation of permission caching using the
 * framework's built-in cache system. Provides multi-layer
 * caching with configurable TTL and efficient invalidation.
 *
 * @package Glueful\Permissions
 */
class PermissionCache implements PermissionCacheInterface
{
    /** @var array Cache configuration */
    private array $config;

    /** @var CacheStore|null Cache driver service */
    private ?CacheStore $cache;

    /** @var array In-memory cache for request lifetime */
    private array $memoryCache = [];

    /** @var array TTL configuration for different cache types */
    private array $ttlConfig = [
        'user_permissions' => 3600, // 1 hour
        'permission_checks' => 1800, // 30 minutes
        'provider_data' => 7200,     // 2 hours
    ];

    /** @var string Cache key prefix */
    private string $keyPrefix = 'glueful:permissions:';

    /** @var bool Whether cache is enabled */
    private bool $enabled = true;

    /**
     * Constructor
     *
     * @param CacheStore|null $cache Cache driver service
     */
    public function __construct(?CacheStore $cache = null)
    {
        $this->cache = $cache;
        $this->initialize();
    }

    /**
     * Initialize the cache system
     *
     * @param array $config Cache configuration
     * @return void
     * @throws \Exception If cache initialization fails
     */
    public function initialize(array $config = []): void
    {
        $this->config = array_merge([
            'enabled' => true,
            'memory_cache' => true,
            'distributed_cache' => true,
            'key_prefix' => 'glueful:permissions:',
            'default_ttl' => 3600,
        ], $config);

        $this->enabled = $this->config['enabled'];
        $this->keyPrefix = $this->config['key_prefix'];

        // Update TTL configuration if provided
        if (isset($config['ttl'])) {
            $this->ttlConfig = array_merge($this->ttlConfig, $config['ttl']);
        }

        // Set distributed cache based on cache availability
        $this->config['distributed_cache'] = $this->cache !== null;
    }

    /**
     * Get cached permissions for a user
     *
     * @param string $userUuid User UUID to get cached permissions for
     * @return array|null Cached permissions or null if not found
     */
    public function getUserPermissions(string $userUuid): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateUserPermissionsKey($userUuid);

        // Check memory cache first
        if ($this->config['memory_cache'] && isset($this->memoryCache[$key])) {
            return $this->memoryCache[$key];
        }

        // Check distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $cached = $this->cache->get($key);
                if ($cached !== null) {
                    // Store in memory cache for faster subsequent access
                    if ($this->config['memory_cache']) {
                        $this->memoryCache[$key] = $cached;
                    }
                    return $cached;
                }
            } catch (\Exception $e) {
                // Cache error - continue without cache
            }
        }

        return null;
    }

    /**
     * Cache permissions for a user
     *
     * @param string $userUuid User UUID to cache permissions for
     * @param array $permissions User's permissions to cache
     * @param int $ttl Time to live in seconds (0 = use default)
     * @return bool True if caching successful
     */
    public function setUserPermissions(string $userUuid, array $permissions, int $ttl = 0): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->generateUserPermissionsKey($userUuid);
        $actualTtl = $ttl > 0 ? $ttl : $this->ttlConfig['user_permissions'];

        $success = true;

        // Store in memory cache
        if ($this->config['memory_cache']) {
            $this->memoryCache[$key] = $permissions;
        }

        // Store in distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $this->cache->set($key, $permissions, $actualTtl);
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get cached permission check result
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array $context Permission context
     * @return bool|null Cached result or null if not found
     */
    public function getPermissionCheck(
        string $userUuid,
        string $permission,
        string $resource,
        array $context = []
    ): ?bool {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateKey($userUuid, $permission, $resource, $context);

        // Check memory cache first
        if ($this->config['memory_cache'] && array_key_exists($key, $this->memoryCache)) {
            return $this->memoryCache[$key];
        }

        // Check distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $cached = $this->cache->get($key);
                if ($cached !== null) {
                    // Store in memory cache
                    if ($this->config['memory_cache']) {
                        $this->memoryCache[$key] = $cached;
                    }
                    return $cached;
                }
            } catch (\Exception $e) {
                // Cache error - continue without cache
            }
        }

        return null;
    }

    /**
     * Cache permission check result
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param bool $result Permission check result
     * @param array $context Permission context
     * @param int $ttl Time to live in seconds
     * @return bool True if caching successful
     */
    public function setPermissionCheck(
        string $userUuid,
        string $permission,
        string $resource,
        bool $result,
        array $context = [],
        int $ttl = 0
    ): bool {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->generateKey($userUuid, $permission, $resource, $context);
        $actualTtl = $ttl > 0 ? $ttl : $this->ttlConfig['permission_checks'];

        $success = true;

        // Store in memory cache
        if ($this->config['memory_cache']) {
            $this->memoryCache[$key] = $result;
        }

        // Store in distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $this->cache->set($key, $result, $actualTtl);
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Invalidate all cached data for a user
     *
     * @param string $userUuid User UUID to invalidate
     * @return bool True if invalidation successful
     */
    public function invalidateUser(string $userUuid): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $success = true;

        // Clear from memory cache
        if ($this->config['memory_cache']) {
            $userPrefix = $this->keyPrefix . 'user:' . $userUuid;
            foreach (array_keys($this->memoryCache) as $key) {
                if (strpos($key, $userPrefix) === 0) {
                    unset($this->memoryCache[$key]);
                }
            }
        }

        // Clear from distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                // Clear user permissions cache
                $userPermissionsKey = $this->generateUserPermissionsKey($userUuid);
                $this->cache->delete($userPermissionsKey);

                // Clear permission check caches for this user using advanced cache features
                $pattern = $this->keyPrefix . 'check:' . $userUuid . ':*';
                $this->cache->deletePattern($pattern);
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Invalidate cached data for a specific permission
     *
     * @param string $permission Permission name to invalidate
     * @param string $resource Optional resource filter
     * @return bool True if invalidation successful
     */
    public function invalidatePermission(string $permission, string $resource = ''): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $success = true;

        // Clear from memory cache
        if ($this->config['memory_cache']) {
            $searchPattern = ':' . $permission . ':';
            if (!empty($resource)) {
                $searchPattern .= $resource . ':';
            }

            foreach (array_keys($this->memoryCache) as $key) {
                if (strpos($key, $searchPattern) !== false) {
                    unset($this->memoryCache[$key]);
                }
            }
        }

        // Clear from distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $pattern = $this->keyPrefix . 'check:*:' . $permission;
                if (!empty($resource)) {
                    $pattern .= ':' . $resource;
                }
                $pattern .= ':*';
                $this->cache->deletePattern($pattern);
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Invalidate all cached permission data
     *
     * @return bool True if invalidation successful
     */
    public function invalidateAll(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $success = true;

        // Clear memory cache
        if ($this->config['memory_cache']) {
            $this->memoryCache = [];
        }

        // Clear distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $pattern = $this->keyPrefix . '*';
                $this->cache->deletePattern($pattern);
            } catch (\Exception $e) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Warm up cache for a user
     *
     * @param string $userUuid User UUID to warm up
     * @param callable $permissionLoader Function to load permissions
     * @return bool True if warmup successful
     */
    public function warmupUser(string $userUuid, callable $permissionLoader): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $permissions = $permissionLoader($userUuid);
            return $this->setUserPermissions($userUuid, $permissions);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        $stats = [
            'enabled' => $this->enabled,
            'memory_cache_enabled' => $this->config['memory_cache'],
            'distributed_cache_enabled' => $this->config['distributed_cache'],
            'memory_cache_size' => count($this->memoryCache),
            'key_prefix' => $this->keyPrefix,
            'ttl_config' => $this->ttlConfig,
        ];

        // Add distributed cache stats if available
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $stats['distributed_cache_stats'] = $this->cache->getStats();
            } catch (\Exception $e) {
                $stats['distributed_cache_error'] = $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * Check if cache is available and functional
     *
     * @return bool True if cache is working
     */
    public function isHealthy(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Test memory cache
        if ($this->config['memory_cache']) {
            $testKey = $this->keyPrefix . 'health_check';
            $testValue = 'test_' . time();
            $this->memoryCache[$testKey] = $testValue;

            if (!isset($this->memoryCache[$testKey]) || $this->memoryCache[$testKey] !== $testValue) {
                return false;
            }
            unset($this->memoryCache[$testKey]);
        }

        // Test distributed cache
        if ($this->config['distributed_cache'] && $this->cache) {
            try {
                $testKey = $this->keyPrefix . 'health_check';
                $testValue = 'test_' . time();

                $this->cache->set($testKey, $testValue, 60);
                $retrieved = $this->cache->get($testKey);
                $this->cache->delete($testKey);

                if ($retrieved !== $testValue) {
                    return false;
                }
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Set cache TTL configuration
     *
     * @param array $ttlConfig Array of cache type => TTL mappings
     * @return void
     */
    public function configureTTL(array $ttlConfig): void
    {
        $this->ttlConfig = array_merge($this->ttlConfig, $ttlConfig);
    }

    /**
     * Generate cache key for permission check
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array $context Permission context
     * @return string Cache key
     */
    public function generateKey(string $userUuid, string $permission, string $resource, array $context = []): string
    {
        $contextHash = empty($context) ? 'none' : md5(serialize($context));
        return $this->keyPrefix . 'check:' . $userUuid . ':' . $permission . ':' . $resource . ':' . $contextHash;
    }

    /**
     * Batch invalidate multiple users
     *
     * @param array $userUuids Array of user UUIDs to invalidate
     * @return bool True if batch invalidation successful
     */
    public function batchInvalidateUsers(array $userUuids): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $success = true;
        foreach ($userUuids as $userUuid) {
            if (!$this->invalidateUser($userUuid)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get cache configuration
     *
     * @return array Cache configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Generate cache key for user permissions
     *
     * @param string $userUuid User UUID
     * @return string Cache key
     */
    private function generateUserPermissionsKey(string $userUuid): string
    {
        return $this->keyPrefix . 'user:' . $userUuid . ':permissions';
    }
}
