<?php

/**
 * Cache Configuration
 *
 * Defines caching settings and driver configurations.
 * Supports Redis and Memcached with fallback options.
 */

return [
    // Default cache driver (redis, memcached, file)
    'default' => env('CACHE_DRIVER', 'redis'),

    // Global cache prefix for key namespacing
    'prefix' => env('CACHE_PREFIX', 'glueful:'),

    // Enable file-based fallback if primary cache fails
    'fallback_to_file' => env('CACHE_FALLBACK', true),

    // Stores configuration
    'stores' => [
        // Redis configuration
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', 0),        // Redis database index
            'timeout' => env('REDIS_TIMEOUT', 2.5),  // Connection timeout in seconds
            'retry_interval' => 100,                 // Retry interval in milliseconds
            'read_timeout' => 2.5,                   // Read timeout in seconds
        ],

        // Memcached configuration
        'memcached' => [
            'driver' => 'memcached',
            'host' => env('MEMCACHED_HOST', '127.0.0.1'),
            'port' => env('MEMCACHED_PORT', 11211),
            'weight' => 100,                         // Server weight for consistent hashing
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [                             // SASL authentication
                'username' => env('MEMCACHED_USERNAME'),
                'password' => env('MEMCACHED_PASSWORD'),
            ],
        ],

        // File cache configuration
        'file' => [
            'driver' => 'file',
            'path' => env('CACHE_FILE_PATH', config('paths.cache', dirname(__DIR__) . '/storage/cache/')),
        ],
    ],

    // Global cache settings
    'ttl' => env('CACHE_TTL', 3600),           // Default TTL in seconds
    'lock_ttl' => env('CACHE_LOCK_TTL', 60),   // Lock timeout in seconds

    // Cache tag settings
    'enable_tags' => env('CACHE_TAGS', true),  // Enable cache tags support
    'tags_store' => 'redis',                  // Store for cache tags

    // Edge caching configuration
    'edge' => [
        'enabled' => env('EDGE_CACHE_ENABLED', false),
        'provider' => env('EDGE_CACHE_PROVIDER', 'cloudflare'),
        'default_ttl' => env('EDGE_CACHE_TTL', 3600), // 1 hour
        'rules' => [
            // Route-specific cache rules can be defined here
            // or managed by extensions
        ],
    ],
];
