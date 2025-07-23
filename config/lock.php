<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Lock Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default lock store that will be used
    | by the framework. This store will be used unless another is
    | explicitly specified when creating locks.
    |
    | Supported: "file", "redis", "database"
    |
    */

    'default' => env('LOCK_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Lock Store Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure the lock stores for your application.
    | Each store has its own configuration options.
    |
    */

    'stores' => [

        'file' => [
            'driver' => 'file',
            'path' => env('LOCK_FILE_PATH', 'framework/locks'),
            'prefix' => 'lock_',
            'extension' => '.lock',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('LOCK_REDIS_CONNECTION', 'default'),
            'prefix' => env('LOCK_REDIS_PREFIX', 'glueful_lock_'),
            'ttl' => 300, // 5 minutes default TTL
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('LOCK_DB_CONNECTION', null),
            'table' => env('LOCK_DB_TABLE', 'locks'),
            'id_col' => 'key_id',
            'token_col' => 'token',
            'expiration_col' => 'expiration',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Lock Key Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be added to all lock keys to avoid collisions
    | with other applications using the same storage backend.
    |
    */

    'prefix' => env('LOCK_PREFIX', 'glueful_lock_'),

    /*
    |--------------------------------------------------------------------------
    | Default Lock TTL
    |--------------------------------------------------------------------------
    |
    | The default time-to-live for locks in seconds. This will be used
    | when no specific TTL is provided when creating a lock.
    |
    */

    'ttl' => env('LOCK_TTL', 300), // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Lock Retry Configuration
    |--------------------------------------------------------------------------
    |
    | These options control the retry behavior when attempting to acquire
    | locks that are already held by another process.
    |
    */

    'retry' => [
        'times' => env('LOCK_RETRY_TIMES', 10),
        'delay' => env('LOCK_RETRY_DELAY', 100), // milliseconds
        'max_wait' => env('LOCK_MAX_WAIT', 10), // seconds
    ],

];
