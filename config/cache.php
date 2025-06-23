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
            'database' => env('REDIS_DB', 0),
            'timeout' => env('REDIS_TIMEOUT', 2.5),
            'retry_interval' => 100,
            'read_timeout' => 2.5,
        ],

        // Memcached configuration
        'memcached' => [
            'driver' => 'memcached',
            'host' => env('MEMCACHED_HOST', '127.0.0.1'),
            'port' => env('MEMCACHED_PORT', 11211),
            'weight' => 100,
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                'username' => env('MEMCACHED_USERNAME'),
                'password' => env('MEMCACHED_PASSWORD'),
            ],
        ],

        // File cache configuration
        'file' => [
            'driver' => 'file',
            'path' => env('CACHE_FILE_PATH', dirname(__DIR__) . '/storage/cache/'),
        ],
    ],

    // Global cache settings
    'ttl' => env('CACHE_TTL', 3600),
    'lock_ttl' => env('CACHE_LOCK_TTL', 60),

    // Cache stampede protection settings
    'stampede_protection' => [
        'enabled' => env('CACHE_STAMPEDE_PROTECTION', false),
        'lock_ttl' => env('CACHE_STAMPEDE_LOCK_TTL', 60),
        'max_wait_time' => env('CACHE_STAMPEDE_MAX_WAIT', 30),
        'retry_interval' => env('CACHE_STAMPEDE_RETRY_INTERVAL', 100000), // microseconds
        'early_expiration' => [
            'enabled' => env('CACHE_EARLY_EXPIRATION', false),
            'threshold' => env('CACHE_EARLY_EXPIRATION_THRESHOLD', 0.8), // 80% of TTL
        ],
    ],

    // Cache tag settings
    'enable_tags' => env('CACHE_TAGS', true),
    'tags_store' => 'redis',

    // Edge caching configuration
    'edge' => [
        'enabled' => env('EDGE_CACHE_ENABLED', false),
        'provider' => env('EDGE_CACHE_PROVIDER', 'cloudflare'),
        'default_ttl' => env('EDGE_CACHE_TTL', 3600),
        'rules' => [
            // Route-specific cache rules can be defined here
        ],
    ],

    // Distributed caching configuration
    'distributed' => [
        'enabled' => env('DISTRIBUTED_CACHE_ENABLED', false),
        'strategy' => env('CACHE_REPLICATION_STRATEGY', 'consistent-hashing'),
        'replicas' => env('CACHE_REPLICAS', 2),
        'failover' => [
            'enabled' => env('CACHE_FAILOVER_ENABLED', true),
            'timeout' => env('CACHE_FAILOVER_TIMEOUT', 5),
            'retry_after' => env('CACHE_FAILOVER_RETRY', 30),
        ],
        'nodes' => [
            [
                'id' => 'cache-01',
                'driver' => 'redis',
                'host' => env('REDIS_HOST_1', '127.0.0.1'),
                'port' => env('REDIS_PORT_1', 6379),
                'password' => env('REDIS_PASSWORD_1', null),
                'weight' => 1
            ],
            [
                'id' => 'cache-02',
                'driver' => 'redis',
                'host' => env('REDIS_HOST_2', '127.0.0.1'),
                'port' => env('REDIS_PORT_2', 6380),
                'password' => env('REDIS_PASSWORD_2', null),
                'weight' => 1
            ],
        ]
    ]
];
