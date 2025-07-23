# Glueful Caching System

This comprehensive guide covers Glueful's enterprise-grade caching system, which provides multi-driver support, distributed caching capabilities, advanced features like cache stampede protection, and comprehensive management tools for optimal performance in production environments.

## Table of Contents

1. [Overview](#overview)
2. [Cache Architecture](#cache-architecture)
3. [Cache Drivers](#cache-drivers)
4. [CacheStore Interface](#cachestore-interface)
5. [CacheFactory and Configuration](#cachefactory-and-configuration)
6. [Distributed Caching](#distributed-caching)
7. [Cache Tagging System](#cache-tagging-system)
8. [Cache Helper Utilities](#cache-helper-utilities)
9. [Cache Stampede Protection](#cache-stampede-protection)
10. [Edge Caching and CDN Integration](#edge-caching-and-cdn-integration)
11. [CLI Commands](#cli-commands)
12. [Configuration](#configuration)
13. [Usage Examples](#usage-examples)
14. [Production Optimization](#production-optimization)

## Overview

Glueful's caching system provides a unified, high-performance caching layer that supports multiple storage backends, distributed operations, and advanced features for enterprise applications. The system is built on PSR-16 standards with extensive additional capabilities.

### Key Features

- **Multi-Driver Support**: Redis, Memcached, and file-based caching with automatic fallback
- **PSR-16 Compliant**: Standard caching interface with advanced extensions
- **Distributed Caching**: Multi-node coordination with consistent hashing and health monitoring
- **Cache Tagging**: Grouped invalidation and organized cache management
- **Stampede Protection**: Advanced locking mechanisms to prevent cache stampedes
- **Edge Caching**: CDN integration for global content distribution
- **Comprehensive CLI Tools**: Complete cache management via command line
- **Production-Ready**: Health monitoring, automatic failover, and performance optimization

### Architecture Components

1. **CacheStore Interface**: PSR-16 compliant with advanced operations
2. **Cache Drivers**: Redis, Memcached, and File implementations
3. **CacheFactory**: Driver instantiation with connection management
4. **DistributedCacheService**: Multi-node cache coordination
5. **CacheTaggingService**: Grouped cache invalidation
6. **CacheHelper**: Utilities and stampede protection
7. **EdgeCacheService**: CDN and edge caching integration

## Cache Architecture

### Core Interface

```php
interface CacheStore extends \Psr\SimpleCache\CacheInterface
{
    // PSR-16 methods (inherited)
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function getMultiple(iterable $keys, mixed $default = null): iterable;
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;
    public function deleteMultiple(iterable $keys): bool;
    public function has(string $key): bool;
    
    // Advanced operations
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool;
    public function increment(string $key, int $value = 1): int;
    public function decrement(string $key, int $value = 1): int;
    public function ttl(string $key): int;
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
    
    // Pattern operations
    public function deletePattern(string $pattern): bool;
    public function getKeys(string $pattern = '*'): array;
    public function getKeyCount(string $pattern = '*'): int;
    
    // Sorted sets (Redis-style)
    public function zadd(string $key, array $scoreValues): bool;
    public function zrange(string $key, int $start, int $stop): array;
    public function zremrangebyscore(string $key, string $min, string $max): int;
    
    // Tagging
    public function addTags(string $key, array $tags): bool;
    public function invalidateTags(array $tags): bool;
    
    // Introspection
    public function getStats(): array;
    public function getCapabilities(): array;
}
```

### Driver Capabilities Matrix

| Feature | Redis | Memcached | File |
|---------|--------|-----------|------|
| PSR-16 Compliance | ✅ | ✅ | ✅ |
| Atomic Operations | ✅ | ✅ | ✅ (via locking) |
| Pattern Deletion | ✅ | ❌ | ✅ |
| Sorted Sets | ✅ (native) | ✅ (emulated) | ✅ (file-based) |
| Key Enumeration | ✅ | ❌ | ✅ |
| TTL Inspection | ✅ | ❌ (limited) | ✅ |
| Bulk Operations | ✅ | ✅ | ✅ |
| Distributed Support | ✅ | ✅ | ❌ |
| Stampede Protection | ✅ | ✅ | ✅ |

## Cache Drivers

### Redis Driver

The Redis driver provides full functionality with native Redis features:

```php
use Glueful\Cache\Drivers\RedisCacheDriver;
use Redis;

// Create Redis instance
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('password'); // if required
$redis->select(0); // database selection

// Create driver
$cache = new RedisCacheDriver($redis);

// Advanced Redis operations
$cache->setNx('unique_key', 'value', 3600); // Set if not exists
$cache->increment('counter', 1); // Atomic increment
$cache->zadd('scores', [100 => 'user1', 95 => 'user2']); // Sorted sets
$cache->deletePattern('user:*'); // Pattern deletion
```

### Memcached Driver

The Memcached driver provides PSR-16 compliance with emulated advanced features:

```php
use Glueful\Cache\Drivers\MemcachedCacheDriver;
use Memcached;

// Create Memcached instance
$memcached = new Memcached();
$memcached->addServer('127.0.0.1', 11211);

// Create driver
$cache = new MemcachedCacheDriver($memcached);

// Basic operations
$cache->set('key', 'value', 3600);
$cache->get('key');
$cache->setNx('unique_key', 'value', 3600); // Emulated via add()

// Bulk operations
$cache->setMultiple(['key1' => 'value1', 'key2' => 'value2'], 3600);
$values = $cache->getMultiple(['key1', 'key2']);
```

### File Cache Driver

File-based caching with metadata tracking and atomic operations:

```php
use Glueful\Cache\Drivers\FileCacheDriver;
use Glueful\Services\FileManager;
use Glueful\Services\FileFinder;

// Create driver with file services
$fileManager = container()->get(FileManager::class);
$fileFinder = container()->get(FileFinder::class);
$cache = new FileCacheDriver('/path/to/cache', $fileManager, $fileFinder);

// File operations with atomic locking
$cache->set('key', 'value', 3600); // Creates key.cache and key.meta files
$cache->deletePattern('user:*'); // Pattern-based deletion
```

## CacheStore Interface

### Basic Operations

```php
use Glueful\Cache\CacheFactory;

// Create cache instance
$cache = CacheFactory::create();

// Basic PSR-16 operations
$cache->set('user:123', $userData, 3600);
$user = $cache->get('user:123');
$cache->delete('user:123');
$cache->clear(); // Clear all cache

// Bulk operations
$cache->setMultiple([
    'user:123' => $userData,
    'user:456' => $otherUserData
], 3600);

$users = $cache->getMultiple(['user:123', 'user:456']);
$cache->deleteMultiple(['user:123', 'user:456']);
```

### Advanced Operations

```php
// Atomic operations
$cache->setNx('lock:process', 1, 60); // Set only if not exists
$newValue = $cache->increment('page_views', 1);
$newValue = $cache->decrement('available_slots', 1);

// TTL management
$remainingTime = $cache->ttl('user:123');
$cache->expire('user:123', 7200); // Extend TTL

// Pattern operations (Redis and File drivers)
$cache->deletePattern('user:*'); // Delete all user keys
$userKeys = $cache->getKeys('user:*'); // Get matching keys
$count = $cache->getKeyCount('session:*'); // Count matching keys

// Sorted sets
$cache->zadd('leaderboard', [100 => 'player1', 95 => 'player2']);
$topPlayers = $cache->zrange('leaderboard', 0, 9); // Top 10
$removed = $cache->zremrangebyscore('leaderboard', 0, 50); // Remove low scores
```

### Remember Pattern

```php
// Simple remember pattern
$userData = $cache->remember('user:123', function() {
    return $this->userRepository->find(123);
}, 3600);

// Remember pattern with early expiration
$configData = $cache->remember('app_config', function() {
    return $this->configRepository->getAll();
}, 7200);
```

## CacheFactory and Configuration

### Factory Usage

```php
use Glueful\Cache\CacheFactory;

// Create with default configuration
$cache = CacheFactory::create();

// Create with specific driver
$redisCache = CacheFactory::create('redis');
$memcachedCache = CacheFactory::create('memcached');
$fileCache = CacheFactory::create('file');

// Automatic fallback to file cache if Redis/Memcached unavailable
$cache = CacheFactory::create(); // Tries Redis first, falls back to file
```

### Connection Management

```php
// Redis connection with authentication
$cache = CacheFactory::create('redis');
// Automatically handles:
// - Connection timeout
// - Authentication
// - Database selection
// - Health checks (ping)

// Memcached connection testing
$cache = CacheFactory::create('memcached');
// Automatically handles:
// - Server addition
// - Connection testing
// - Error handling
```

## Distributed Caching

### Basic Distributed Setup

```php
use Glueful\Cache\DistributedCacheService;

// Configuration
$config = [
    'nodes' => [
        ['host' => '192.168.1.10', 'port' => 6379, 'weight' => 100],
        ['host' => '192.168.1.11', 'port' => 6379, 'weight' => 100],
        ['host' => '192.168.1.12', 'port' => 6379, 'weight' => 50]
    ],
    'replication' => 'consistent-hashing',
    'failover' => true,
    'health' => [
        'check_interval' => 30,
        'timeout' => 5,
        'max_failures' => 3
    ]
];

// Create distributed cache
$distributedCache = new DistributedCacheService($primaryCache, $config);

// Use like normal cache - operations are distributed automatically
$distributedCache->set('user:123', $userData, 3600);
$userData = $distributedCache->get('user:123');
```

### Replication Strategies

```php
// Consistent hashing (default)
$distributedCache->setReplicationStrategy('consistent-hashing');

// Round-robin distribution
$distributedCache->setReplicationStrategy('round-robin');

// Custom strategy configuration
$config['strategies'] = [
    'consistent-hashing' => [
        'virtual_nodes' => 150,
        'hash_function' => 'md5'
    ],
    'round-robin' => [
        'sticky_sessions' => true
    ]
];
```

### Health Monitoring and Failover

```php
// Enable automatic failover
$distributedCache->setFailoverEnabled(true);

// Check failover status
if ($distributedCache->isFailoverEnabled()) {
    // Failover is active - unhealthy nodes are automatically excluded
}

// Get node manager for advanced operations
$nodeManager = $distributedCache->getNodeManager();
$healthyNodes = $nodeManager->getHealthyNodes();
$allNodes = $nodeManager->getAllNodes();
```

## Cache Tagging System

### Basic Tagging

```php
use Glueful\Cache\CacheTaggingService;

// Enable tagging
CacheTaggingService::enable();

// Tag cache entries
CacheTaggingService::tagCache('user:123', ['users', 'user_data']);
CacheTaggingService::tagCache('user:123:permissions', ['users', 'permissions']);
CacheTaggingService::tagCache('role:admin', ['roles', 'permissions']);

// Get tags for a key
$tags = CacheTaggingService::getKeyTags('user:123');

// Get keys for a tag
$userKeys = CacheTaggingService::getTaggedKeys('users');
```

### Tag-based Invalidation

```php
// Invalidate by single tag
$result = CacheTaggingService::invalidateByTag('users');
/*
[
    'status' => 'completed',
    'tag' => 'users',
    'invalidated' => ['user:123', 'user:456'],
    'failed' => [],
    'success_count' => 2,
    'failure_count' => 0
]
*/

// Invalidate by multiple tags
$result = CacheTaggingService::invalidateByTags(['users', 'permissions']);

// Invalidate by predefined category
$result = CacheTaggingService::invalidateRelated('config');
```

### Predefined Tag Categories

```php
// Built-in categories
$categories = CacheTaggingService::getPredefinedTags();
/*
[
    'config' => ['app_config', 'database_config', 'cache_config'],
    'permissions' => ['user_permissions', 'role_permissions', 'permission_definitions'],
    'roles' => ['user_roles', 'role_definitions', 'role_hierarchy'],
    'users' => ['user_data', 'user_sessions', 'user_profiles'],
    'auth' => ['jwt_tokens', 'session_data', 'auth_cache'],
    'api' => ['api_routes', 'api_definitions', 'api_metadata'],
    'files' => ['file_metadata', 'upload_cache', 'image_cache'],
    'notifications' => ['notification_templates', 'notification_preferences', 'notification_queue']
]
*/

// Add custom category
CacheTaggingService::addPredefinedTag('products', ['product_data', 'product_images', 'product_cache']);

// Invalidate entire category
CacheTaggingService::invalidateRelated('users');
```

### Tag Management

```php
// Get tagging statistics
$stats = CacheTaggingService::getTagStats();
/*
[
    'total_tags' => 12,
    'total_tagged_keys' => 45,
    'tags' => [
        'users' => ['key_count' => 15, 'keys' => ['user:123', 'user:456', ...]],
        'permissions' => ['key_count' => 8, 'keys' => ['perm:admin', ...]]
    ]
]
*/

// Cleanup orphaned tags and keys
$cleanup = CacheTaggingService::cleanup();
/*
[
    'status' => 'completed',
    'cleaned_keys' => ['user:789'], // Keys that no longer exist in cache
    'cleaned_tags' => ['temp_tag'], // Tags with no associated keys
    'key_count' => 1,
    'tag_count' => 1
]
*/
```

## Cache Helper Utilities

### Safe Cache Operations

```php
use Glueful\Helpers\CacheHelper;

// Create cache instance with graceful fallback
$cache = CacheHelper::createCacheInstance(); // Returns null if cache unavailable

// Check cache health
if (CacheHelper::isCacheHealthy($cache)) {
    // Cache is available and responding
}

// Safe cache operations with fallback
$result = CacheHelper::safeExecute(
    $cache,
    fn($cache) => $cache->get('user:123'),
    $defaultUserData // Fallback if cache operation fails
);
```

### Key Management

```php
// Automatic key prefixing
$key = CacheHelper::key('user:123'); // Adds configured prefix
$keys = CacheHelper::keys(['user:123', 'user:456']); // Prefix multiple keys

// Specialized key generators
$userKey = CacheHelper::userKey('123', 'profile'); // user:123:profile
$sessionKey = CacheHelper::sessionKey('abc123', 'data'); // session:data:abc123
$rateLimitKey = CacheHelper::rateLimitKey('192.168.1.1', 'api'); // rate_limit:api:192.168.1.1
$permissionKey = CacheHelper::permissionKey('user123', 'admin'); // permissions:user123:admin
$configKey = CacheHelper::configKey('database'); // config:database

// Remove prefix from keys
$baseKey = CacheHelper::unprefix('prefix:user:123'); // user:123
```

## Cache Stampede Protection

### Basic Stampede Protection

```php
use Glueful\Helpers\CacheHelper;

// Simple remember with stampede protection
$userData = CacheHelper::remember(
    $cache,
    'expensive:user:123',
    function() {
        // This expensive operation will only run once
        // even if multiple processes request it simultaneously
        return $this->expensiveUserDataCalculation(123);
    },
    3600, // TTL
    true  // Enable stampede protection
);
```

### Advanced Stampede Protection

```php
// Advanced stampede protection with custom settings
$userData = CacheHelper::rememberWithStampedeProtection(
    $cache,
    'expensive:calculation',
    function() {
        return $this->performExpensiveCalculation();
    },
    3600,   // Cache TTL
    60,     // Lock TTL
    30,     // Max wait time for lock
    100000  // Retry interval in microseconds (0.1s)
);
```

### Early Expiration with Background Refresh

```php
// Early expiration detection with background refresh
$configData = CacheHelper::rememberWithEarlyExpiration(
    $cache,
    'app:config',
    function() {
        return $this->loadConfiguration();
    },
    3600,  // Cache TTL (1 hour)
    0.8,   // Refresh at 80% of TTL (48 minutes)
    60     // Lock TTL for background refresh
);

// When cache is at 80% of its TTL:
// 1. Return current cached value immediately
// 2. Trigger background refresh asynchronously
// 3. Next request gets refreshed value
```

### Configuration-based Stampede Protection

```php
// Configure stampede protection globally
// config/cache.php
return [
    'stampede_protection' => [
        'enabled' => true,
        'lock_ttl' => 60,
        'max_wait_time' => 30,
        'retry_interval' => 100000,
        'early_expiration' => [
            'enabled' => true,
            'threshold' => 0.8 // Refresh at 80% of TTL
        ]
    ]
];

// Use with automatic configuration
$result = CacheHelper::remember($cache, $key, $callback, $ttl);
// Automatically uses configured stampede protection settings
```

## Edge Caching and CDN Integration

### Edge Cache Service

```php
use Glueful\Cache\EdgeCacheService;

// Initialize edge cache service
$edgeCache = new EdgeCacheService();

// Generate cache headers for edge caching
$headers = $edgeCache->generateCacheHeaders([
    'max_age' => 3600,
    'public' => true,
    'vary' => ['Accept-Encoding', 'Accept-Language']
]);

// Set response headers
foreach ($headers as $header => $value) {
    header("{$header}: {$value}");
}
```

### CDN Integration

```php
// Purge CDN cache by URL
$edgeCache->purgeUrl('https://example.com/api/data');

// Purge by tags (if CDN supports it)
$edgeCache->purgeByTags(['users', 'api_data']);

// Purge all cache
$edgeCache->purgeAll();

// CDN provider configuration (via extensions)
$edgeCache->setProvider('cloudflare', [
    'api_token' => 'your_token',
    'zone_id' => 'zone_id'
]);
```

## CLI Commands

### Basic Cache Commands

```bash
# Clear all cache
php glueful cache:clear

# Clear cache by tag
php glueful cache:clear --tag=users

# Clear cache with confirmation
php glueful cache:clear --force

# Get cache status
php glueful cache:status

# Get comprehensive cache statistics
php glueful cache:status --detailed
```

### Advanced Cache Operations

```bash
# Get cache value
php glueful cache:get user:123

# Set cache value
php glueful cache:set user:123 "User Data" --ttl=3600

# Delete cache key
php glueful cache:delete user:123

# Get TTL for key
php glueful cache:ttl user:123

# Set expiration for key
php glueful cache:expire user:123 7200

# Purge cache by pattern
php glueful cache:purge "user:*"
```

### Cache Warmup

```bash
# Warm up cache with predefined strategies
php glueful cache:warmup

# Warm specific categories
php glueful cache:warmup --category=config
php glueful cache:warmup --category=permissions

# Background warmup
php glueful cache:warmup --background
```

## Configuration

### Main Cache Configuration

```php
// config/cache.php
return [
    'default' => env('CACHE_DRIVER', 'redis'),
    'prefix' => env('CACHE_PREFIX', 'glueful_'),
    
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DB', 0),
            'timeout' => env('REDIS_TIMEOUT', 2.5),
            'options' => [
                'serializer' => 'igbinary', // or 'php', 'json'
                'compression' => 'lz4'      // or 'gzip', 'zstd'
            ]
        ],
        
        'memcached' => [
            'driver' => 'memcached',
            'host' => env('MEMCACHED_HOST', '127.0.0.1'),
            'port' => env('MEMCACHED_PORT', 11211),
            'options' => [
                'compression' => true,
                'binary_protocol' => true,
                'no_block' => true
            ]
        ],
        
        'file' => [
            'driver' => 'file',
            'path' => env('CACHE_FILE_PATH', storage_path('cache')),
            'hash_levels' => 2, // Directory structure depth
            'cleanup_frequency' => 3600 // Cleanup every hour
        ]
    ],
    
    'fallback_to_file' => env('CACHE_FALLBACK_FILE', true),
    
    'stampede_protection' => [
        'enabled' => env('CACHE_STAMPEDE_PROTECTION', true),
        'lock_ttl' => 60,
        'max_wait_time' => 30,
        'retry_interval' => 100000,
        'early_expiration' => [
            'enabled' => true,
            'threshold' => 0.8
        ]
    ],
    
    'tagging' => [
        'enabled' => env('CACHE_TAGGING_ENABLED', true),
        'storage' => 'cache', // Store tag mappings in cache
        'cleanup_frequency' => 3600
    ]
];
```

### Distributed Cache Configuration

```php
// config/distributed_cache.php
return [
    'enabled' => env('DISTRIBUTED_CACHE_ENABLED', false),
    
    'nodes' => [
        [
            'host' => env('CACHE_NODE1_HOST', '192.168.1.10'),
            'port' => env('CACHE_NODE1_PORT', 6379),
            'weight' => 100,
            'role' => 'primary'
        ],
        [
            'host' => env('CACHE_NODE2_HOST', '192.168.1.11'),
            'port' => env('CACHE_NODE2_PORT', 6379),
            'weight' => 100,
            'role' => 'replica'
        ],
        [
            'host' => env('CACHE_NODE3_HOST', '192.168.1.12'),
            'port' => env('CACHE_NODE3_PORT', 6379),
            'weight' => 50,
            'role' => 'replica'
        ]
    ],
    
    'replication' => 'consistent-hashing',
    'failover' => true,
    
    'strategies' => [
        'consistent-hashing' => [
            'virtual_nodes' => 150,
            'hash_function' => 'md5'
        ],
        'round-robin' => [
            'sticky_sessions' => true
        ]
    ],
    
    'health' => [
        'enabled' => true,
        'check_interval' => 30,
        'timeout' => 5,
        'max_failures' => 3,
        'recovery_time' => 300
    ]
];
```

### Environment Variables

```env
# Cache Configuration
CACHE_DRIVER=redis
CACHE_PREFIX=glueful_
CACHE_FALLBACK_FILE=true

# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_TIMEOUT=2.5

# Memcached Configuration
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211

# File Cache Configuration
CACHE_FILE_PATH=/var/cache/glueful

# Stampede Protection
CACHE_STAMPEDE_PROTECTION=true

# Tagging
CACHE_TAGGING_ENABLED=true

# Distributed Cache
DISTRIBUTED_CACHE_ENABLED=false
```

## Usage Examples

### Service Integration

```php
class UserService
{
    private CacheStore $cache;
    
    public function __construct(CacheStore $cache)
    {
        $this->cache = $cache;
    }
    
    public function getUser(int $userId): ?User
    {
        $cacheKey = CacheHelper::userKey((string)$userId, 'data');
        
        return CacheHelper::remember(
            $this->cache,
            $cacheKey,
            function() use ($userId) {
                return $this->userRepository->find($userId);
            },
            3600 // 1 hour TTL
        );
    }
    
    public function updateUser(int $userId, array $data): User
    {
        $user = $this->userRepository->update($userId, $data);
        
        // Invalidate related cache
        CacheTaggingService::invalidateByTags(['users', 'user_data']);
        
        // Update cache with new data
        $cacheKey = CacheHelper::userKey((string)$userId, 'data');
        $this->cache->set($cacheKey, $user, 3600);
        
        return $user;
    }
}
```

### Configuration Service with Caching

```php
class ConfigurationService
{
    private CacheStore $cache;
    
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = CacheHelper::configKey($key);
        
        return CacheHelper::rememberWithEarlyExpiration(
            $this->cache,
            $cacheKey,
            function() use ($key, $default) {
                return $this->configRepository->get($key, $default);
            },
            7200, // 2 hour TTL
            0.9   // Refresh at 90% of TTL
        );
    }
    
    public function invalidateConfig(): void
    {
        CacheTaggingService::invalidateRelated('config');
    }
}
```

### API Response Caching

```php
class ApiController
{
    private CacheStore $cache;
    private EdgeCacheService $edgeCache;
    
    public function getProducts(Request $request): Response
    {
        $page = $request->get('page', 1);
        $cacheKey = "api:products:page:{$page}";
        
        // Cache API response
        $products = CacheHelper::remember(
            $this->cache,
            $cacheKey,
            function() use ($page) {
                return $this->productService->getPaginated($page);
            },
            1800 // 30 minutes
        );
        
        // Set edge cache headers
        $headers = $this->edgeCache->generateCacheHeaders([
            'max_age' => 1800,
            'public' => true,
            'vary' => ['Accept', 'Accept-Language']
        ]);
        
        return response()->json($products)->withHeaders($headers);
    }
}
```

### Background Cache Warming

```php
class CacheWarmupJob
{
    private CacheStore $cache;
    
    public function execute(): void
    {
        // Warm critical configuration
        $this->warmConfiguration();
        
        // Warm user permissions
        $this->warmPermissions();
        
        // Warm frequently accessed data
        $this->warmPopularContent();
    }
    
    private function warmConfiguration(): void
    {
        $configs = ['database', 'cache', 'mail', 'app'];
        
        foreach ($configs as $config) {
            $cacheKey = CacheHelper::configKey($config);
            
            CacheHelper::remember(
                $this->cache,
                $cacheKey,
                fn() => config($config),
                7200
            );
            
            // Tag for easy invalidation
            CacheTaggingService::tagCache($cacheKey, ['config', 'app_config']);
        }
    }
    
    private function warmPermissions(): void
    {
        $activeUsers = $this->userRepository->getActiveUsers();
        
        foreach ($activeUsers as $user) {
            $cacheKey = CacheHelper::permissionKey($user->uuid);
            
            CacheHelper::remember(
                $this->cache,
                $cacheKey,
                fn() => $this->permissionService->getUserPermissions($user->uuid),
                3600
            );
            
            CacheTaggingService::tagCache($cacheKey, ['permissions', 'user_permissions']);
        }
    }
}
```

## Production Optimization

### High-Performance Configuration

```php
// config/cache.php - Production optimizations
return [
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST'),
            'port' => env('REDIS_PORT'),
            'password' => env('REDIS_PASSWORD'),
            'database' => 0,
            'timeout' => 1.0, // Faster timeout for production
            'options' => [
                'serializer' => 'igbinary', // Faster serialization
                'compression' => 'lz4',     // Fast compression
                'persistent' => true,       // Persistent connections
                'tcp_keepalive' => 1       // Keep connections alive
            ],
            'pool' => [
                'min_connections' => 5,
                'max_connections' => 20,
                'idle_timeout' => 300
            ]
        ]
    ],
    
    'stampede_protection' => [
        'enabled' => true,
        'lock_ttl' => 30,        // Shorter locks in production
        'max_wait_time' => 10,   // Less waiting time
        'early_expiration' => [
            'enabled' => true,
            'threshold' => 0.85  // Earlier refresh
        ]
    ]
];
```

### Monitoring and Alerting

```php
class CacheMonitoringService
{
    private CacheStore $cache;
    
    public function getHealthMetrics(): array
    {
        $stats = $this->cache->getStats();
        
        return [
            'hit_ratio' => $this->calculateHitRatio($stats),
            'memory_usage' => $stats['memory_usage'] ?? 0,
            'connection_count' => $stats['connections'] ?? 0,
            'operations_per_second' => $stats['ops_per_sec'] ?? 0,
            'avg_response_time' => $this->measureResponseTime()
        ];
    }
    
    public function checkAlerts(): array
    {
        $metrics = $this->getHealthMetrics();
        $alerts = [];
        
        if ($metrics['hit_ratio'] < 0.85) {
            $alerts[] = 'Cache hit ratio below 85%';
        }
        
        if ($metrics['memory_usage'] > 0.9) {
            $alerts[] = 'Cache memory usage above 90%';
        }
        
        if ($metrics['avg_response_time'] > 10) {
            $alerts[] = 'Cache response time above 10ms';
        }
        
        return $alerts;
    }
    
    private function measureResponseTime(): float
    {
        $start = microtime(true);
        $this->cache->get('health_check_' . uniqid());
        return (microtime(true) - $start) * 1000; // ms
    }
}
```

### Cache Partitioning Strategy

```php
class CachePartitioningService
{
    private array $caches = [];
    
    public function __construct()
    {
        // Partition cache by data type for better performance
        $this->caches = [
            'users' => CacheFactory::create('redis'), // User data on Redis
            'sessions' => CacheFactory::create('redis'), // Session data on Redis
            'static' => CacheFactory::create('file'),    // Static content on file
            'temp' => CacheFactory::create('memcached')  // Temporary data on Memcached
        ];
    }
    
    public function getCache(string $partition): CacheStore
    {
        return $this->caches[$partition] ?? $this->caches['users'];
    }
    
    public function set(string $partition, string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->getCache($partition)->set($key, $value, $ttl);
    }
    
    public function get(string $partition, string $key, mixed $default = null): mixed
    {
        return $this->getCache($partition)->get($key, $default);
    }
}
```

## Summary

Glueful's caching system provides enterprise-grade caching capabilities with:

- **Multi-Driver Support**: Redis, Memcached, and file-based caching with seamless fallback
- **Advanced Features**: Stampede protection, cache tagging, distributed caching, and edge integration
- **Production-Ready**: Health monitoring, automatic failover, and performance optimization
- **Developer-Friendly**: Comprehensive CLI tools, helper utilities, and extensive configuration options
- **PSR-16 Compliant**: Standard interface with powerful extensions for enterprise needs

The system is designed to scale from simple applications to high-traffic, distributed environments while maintaining optimal performance and reliability.