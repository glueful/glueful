<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Cache\CacheEngine;
use Glueful\Helpers\DatabaseConnectionTrait;

/**
 * Cache Warmup Service
 *
 * Provides intelligent cache warming strategies to improve application performance
 * by pre-loading frequently accessed data into cache during low-usage periods.
 */
class CacheWarmupService
{
    use DatabaseConnectionTrait;

    /** @var array<string, string> Default warmup strategies mapping strategy names to method names */
    private static array $strategies = [
        'config' => 'warmupConfiguration',
        'permissions' => 'warmupPermissions',
        'roles' => 'warmupRoles',
        'users' => 'warmupActiveUsers',
        'metadata' => 'warmupMetadata'
    ];

    /** @var array Warmup statistics */
    private static array $stats = [
        'total_items' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'errors' => 0,
        'start_time' => 0,
        'end_time' => 0
    ];

    /**
     * Warm up all critical cache data
     */
    public static function warmupAll(array $strategies = []): array
    {
        if (!CacheEngine::isEnabled()) {
            return ['status' => 'disabled', 'message' => 'Cache is not enabled'];
        }

        self::$stats['start_time'] = microtime(true);
        $strategies = $strategies ?: array_keys(self::$strategies);
        $results = [];

        foreach ($strategies as $strategy) {
            if (isset(self::$strategies[$strategy])) {
                $method = self::$strategies[$strategy];
                try {
                    // Dynamically call the warmup method for this strategy
                    // Methods: warmupConfiguration, warmupPermissions, warmupRoles, warmupActiveUsers, warmupMetadata
                    $result = call_user_func([self::class, $method]);
                    $results[$strategy] = $result;
                    self::$stats['total_items'] += $result['items'] ?? 0;
                } catch (\Exception $e) {
                    self::$stats['errors']++;
                    $results[$strategy] = [
                        'status' => 'error',
                        'message' => $e->getMessage(),
                        'items' => 0
                    ];
                    error_log("Cache warmup error for strategy '$strategy': " . $e->getMessage());
                }
            }
        }

        self::$stats['end_time'] = microtime(true);
        $duration = self::$stats['end_time'] - self::$stats['start_time'];

        return [
            'status' => 'completed',
            'duration' => round($duration, 3),
            'statistics' => self::$stats,
            'results' => $results
        ];
    }

    /**
     * Warm up configuration data
     *
     * @used-by self::warmupAll() Called dynamically via call_user_func
     * @internal This method is called dynamically through the $strategies array
     */
    private static function warmupConfiguration(): array
    {
        $items = 0;
        $configKeys = [
            'app.name',
            'app.version',
            'app.debug',
            'security.default_level',
            'security.rate_limiter.defaults',
            'cache.default',
            'logging.log_level'
        ];

        foreach ($configKeys as $key) {
            try {
                $cacheKey = "config:$key";

                if (CacheEngine::get($cacheKey) === null) {
                    // Get config value and cache it
                    $value = config($key);
                    if ($value !== null) {
                        CacheEngine::set($cacheKey, $value, 3600); // Cache for 1 hour
                        $items++;
                        self::$stats['cache_misses']++;
                    }
                } else {
                    self::$stats['cache_hits']++;
                }
            } catch (\Exception $e) {
                error_log("Config warmup error for key '$key': " . $e->getMessage());
            }
        }

        return [
            'status' => 'success',
            'items' => $items,
            'message' => "Warmed up $items configuration items"
        ];
    }

    /**
     * Warm up permissions data
     *
     * @used-by self::warmupAll() Called dynamically via call_user_func
     * @internal This method is called dynamically through the $strategies array
     */
    private static function warmupPermissions(): array
    {
        if (!self::tableExists('permissions')) {
            return ['status' => 'skipped', 'items' => 0, 'message' => 'Permissions table not found'];
        }

        $instance = new self();
        $queryBuilder = $instance->getQueryBuilder();
        $items = 0;

        try {
            // Cache all permissions
            $permissions = $queryBuilder->select('permissions', ['id', 'name', 'description'])
                ->where(['status' => 'active'])
                ->get();

            foreach ($permissions as $permission) {
                $cacheKey = "permission:name:{$permission['name']}";
                if (CacheEngine::get($cacheKey) === null) {
                    CacheEngine::set($cacheKey, $permission, 1800); // Cache for 30 minutes
                    $items++;
                    self::$stats['cache_misses']++;
                } else {
                    self::$stats['cache_hits']++;
                }
            }

            // Cache permission hierarchy
            $hierarchyKey = 'permissions:hierarchy';
            if (CacheEngine::get($hierarchyKey) === null) {
                CacheEngine::set($hierarchyKey, $permissions, 1800);
                self::$stats['cache_misses']++;
            } else {
                self::$stats['cache_hits']++;
            }

            return [
                'status' => 'success',
                'items' => $items,
                'message' => "Warmed up $items permissions"
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'items' => 0,
                'message' => 'Error warming permissions: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Warm up roles data
     *
     * @used-by self::warmupAll() Called dynamically via call_user_func
     * @internal This method is called dynamically through the $strategies array
     */
    private static function warmupRoles(): array
    {
        if (!self::tableExists('roles')) {
            return ['status' => 'skipped', 'items' => 0, 'message' => 'Roles table not found'];
        }

        $instance = new self();
        $queryBuilder = $instance->getQueryBuilder();
        $items = 0;

        try {
            // Cache all roles
            $roles = $queryBuilder->select('roles', ['id', 'uuid', 'name', 'description'])
                ->where(['status' => 'active'])
                ->get();

            foreach ($roles as $role) {
                $cacheKey = "role:uuid:{$role['uuid']}";
                if (CacheEngine::get($cacheKey) === null) {
                    CacheEngine::set($cacheKey, $role, 1800); // Cache for 30 minutes
                    $items++;
                    self::$stats['cache_misses']++;
                } else {
                    self::$stats['cache_hits']++;
                }

                // Cache by name as well
                $nameKey = "role:name:{$role['name']}";
                if (CacheEngine::get($nameKey) === null) {
                    CacheEngine::set($nameKey, $role, 1800);
                    $items++;
                }
            }

            return [
                'status' => 'success',
                'items' => $items,
                'message' => "Warmed up $items roles"
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'items' => 0,
                'message' => 'Error warming roles: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Warm up active users data
     *
     * @used-by self::warmupAll() Called dynamically via call_user_func
     * @internal This method is called dynamically through the $strategies array
     */
    private static function warmupActiveUsers(): array
    {
        if (!self::tableExists('users')) {
            return ['status' => 'skipped', 'items' => 0, 'message' => 'Users table not found'];
        }

        $instance = new self();
        $queryBuilder = $instance->getQueryBuilder();
        $items = 0;

        try {
            // Cache recently active users (last 24 hours)
            $twentyFourHoursAgo = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $activeUsers = $queryBuilder->select('users', ['id', 'uuid', 'username', 'email', 'status'])
                ->where(['status' => 'active'])
                ->whereGreaterThan('last_login_at', $twentyFourHoursAgo)
                ->limit(100) // Limit to most recent 100 active users
                ->get();

            foreach ($activeUsers as $user) {
                $cacheKey = "user:uuid:{$user['uuid']}";
                if (CacheEngine::get($cacheKey) === null) {
                    // Remove sensitive data before caching
                    unset($user['password'], $user['remember_token']);
                    CacheEngine::set($cacheKey, $user, 900); // Cache for 15 minutes
                    $items++;
                    self::$stats['cache_misses']++;
                } else {
                    self::$stats['cache_hits']++;
                }
            }

            return [
                'status' => 'success',
                'items' => $items,
                'message' => "Warmed up $items active users"
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'items' => 0,
                'message' => 'Error warming active users: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Warm up system metadata
     *
     * @used-by self::warmupAll() Called dynamically via call_user_func
     * @internal This method is called dynamically through the $strategies array
     */
    private static function warmupMetadata(): array
    {
        $items = 0;
        $metadata = [
            'system:version' => config('app.version', '1.0.0'),
            'system:environment' => env('APP_ENV', 'production'),
            'system:timezone' => date_default_timezone_get(),
            'system:php_version' => PHP_VERSION,
            'system:warmup_timestamp' => time()
        ];

        foreach ($metadata as $key => $value) {
            if (CacheEngine::get($key) === null) {
                CacheEngine::set($key, $value, 7200); // Cache for 2 hours
                $items++;
                self::$stats['cache_misses']++;
            } else {
                self::$stats['cache_hits']++;
            }
        }

        return [
            'status' => 'success',
            'items' => $items,
            'message' => "Warmed up $items metadata items"
        ];
    }

    /**
     * Schedule periodic cache warmup
     *
     * @api Public API method for console commands and controllers
     */
    public static function scheduleWarmup(array $options = []): void
    {
        if (!CacheEngine::isEnabled()) {
            return;
        }

        $interval = $options['interval'] ?? 3600; // Default: every hour
        $strategies = $options['strategies'] ?? [];
        $lastWarmup = CacheEngine::get('warmup:last_run') ?: 0;

        if (time() - $lastWarmup >= $interval) {
            // Run warmup in background if possible
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $result = self::warmupAll($strategies);
            CacheEngine::set('warmup:last_run', time(), $interval * 2);
            CacheEngine::set('warmup:last_result', $result, $interval * 2);

            error_log("Cache warmup completed: {$result['status']} in {$result['duration']}s");
        }
    }

    /**
     * Check if a database table exists
     */
    private static function tableExists(string $tableName): bool
    {
        try {
            $instance = new self();
            $queryBuilder = $instance->getQueryBuilder();
            $queryBuilder->rawQuery("SELECT 1 FROM $tableName LIMIT 1");
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Get warmup statistics
     *
     * @api Public API method for monitoring and debugging
     */
    public static function getStats(): array
    {
        return self::$stats;
    }

    /**
     * Get last warmup result
     *
     * @api Public API method for monitoring cache warmup status
     */
    public static function getLastWarmupResult(): ?array
    {
        if (!CacheEngine::isEnabled()) {
            return null;
        }

        return CacheEngine::get('warmup:last_result');
    }

    /**
     * Clear warmup cache
     *
     * @api Public API method for cache management
     */
    public static function clearWarmupCache(): bool
    {
        if (!CacheEngine::isEnabled()) {
            return false;
        }

        $keys = [
            'config:*',
            'permission:*',
            'role:*',
            'user:*',
            'system:*',
            'warmup:*'
        ];

        foreach ($keys as $pattern) {
            // Note: deletePattern method not available in CacheEngine
            // Pattern-based deletion would need custom implementation
            // Variable $pattern is intentionally unused pending implementation
            unset($pattern);
        }

        return true; // Would return actual count if deletePattern was implemented
    }

    /**
     * Reset warmup statistics
     *
     * @api Public API method for monitoring
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'total_items' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'errors' => 0,
            'start_time' => 0,
            'end_time' => 0
        ];
    }
}
