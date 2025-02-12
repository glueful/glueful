<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "memcached", "redis"
    |
    */
    'default' => env('CACHE_DRIVER', 'memcached'),

    /*
    |--------------------------------------------------------------------------
    | Cache Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store, there might be other applications 
    | utilizing the same cache. So, we'll specify a value to get prefixed
    | to all our keys so we can avoid collisions.
    |
    */
    'prefix' => env('CACHE_PREFIX', 'mapi_'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers.
    |
    */
    'stores' => [
        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_CACHE_DB', 1),
            'read_write_timeout' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, Memcached, or Redis cache stores there might
    | be other applications using the same cache. So, we'll specify a
    | value to get prefixed to all our keys so we can avoid collisions.
    |
    */
    'key_prefix' => env('CACHE_KEY_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | Default time in seconds that items will remain in cache
    |
    */
    'ttl' => env('CACHE_TTL', 3600),
];
