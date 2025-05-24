<?php

/**
 * Database Configuration
 *
 * Defines database connections and settings with enhanced security.
 * Supports multiple database engines and connections.
 */

return [
    // Default database engine (mysql, sqlite, pgsql)
    'engine' => env('DB_ENGINE', 'mysql'),

    // MySQL database connection
    'mysql' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'db' => env('DB_DATABASE', 'glueful'),
        'user' => env('DB_USERNAME', 'root'),
        'pass' => env('DB_PASSWORD', ''),  // Should be strong in production
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => env('DB_PREFIX', ''),
        'strict' => true,
        'engine' => 'InnoDB',
        'role' => env('DB_MYSQL_ROLE', 'primary'),
        // Production security settings
        'ssl' => [
            'enabled' => env('DB_SSL_ENABLED', env('APP_ENV') === 'production'),
            'ca_cert' => env('DB_SSL_CA_CERT'),
            'client_cert' => env('DB_SSL_CLIENT_CERT'),
            'client_key' => env('DB_SSL_CLIENT_KEY'),
            'verify_cert' => env('DB_SSL_VERIFY_CERT', true),
        ],
        'options' => [
            'timeout' => env('DB_TIMEOUT', 30),
            'charset' => 'utf8mb4',
        ],
    ],

    // PostgreSQL configuration
    'pgsql' => [
        'driver' => env('DB_PGSQL_DRIVER', 'pgsql'),
        'host' => env('DB_PGSQL_HOST', '127.0.0.1'),
        'port' => env('DB_PGSQL_PORT', 5432),
        'db' => env('DB_PGSQL_DATABASE', 'glueful'),
        'user' => env('DB_PGSQL_USERNAME', 'postgres'),
        'pass' => env('DB_PGSQL_PASSWORD', ''),
        'schema' => env('DB_PGSQL_SCHEMA', 'public'),
        'charset' => 'utf8',
        'prefix' => env('DB_PGSQL_PREFIX', ''),
        'sslmode' => env('DB_PGSQL_SSL_MODE', env('APP_ENV') === 'production' ? 'require' : 'prefer'),
        'timezone' => env('DB_PGSQL_TIMEZONE', 'UTC'),
        'role' => env('DB_PGSQL_ROLE', ''),
    ],

    // SQLite configuration
    'sqlite' => [
        'primary' => env('DB_SQLITE_DATABASE', dirname(__DIR__) . '/database/primary.sqlite'),
        'testing' => env('DB_SQLITE_TESTING', dirname(__DIR__) . '/database/testing.sqlite'),
        'role' => env('DB_SQLITE_ROLE', 'backup')
    ],

    // Connection pooling settings
    'pool' => [
        'max_connections' => env('DB_POOL_MAX_CONNECTIONS', 20),
        'min_connections' => env('DB_POOL_MIN_CONNECTIONS', 5),
        'acquire_timeout' => env('DB_POOL_ACQUIRE_TIMEOUT', 30),
        'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 300),
    ],

    // Query logging options
    'logging' => [
        'enabled' => env('DB_LOGGING', env('APP_ENV') !== 'production'),
        'slow_threshold' => env('DB_SLOW_MS', 100),
        'log_path' => dirname(__DIR__) . '/storage/logs/query.log',
    ],

    // Migration settings
    'migrations' => [
        'table' => 'migrations',
        'path' => dirname(__DIR__) . '/database/migrations',
    ],

    // Query cache settings
    'query_cache' => [
        'enabled' => env('QUERY_CACHE_ENABLED', true),
        'default_ttl' => env('QUERY_CACHE_TTL', 3600),
        'store' => env('QUERY_CACHE_STORE', 'redis'),
        'auto_invalidate' => env('QUERY_CACHE_AUTO_INVALIDATE', true),
        'exclude_tables' => [
            'migrations',
            'jobs',
            'failed_jobs',
            'sessions'
        ],
        'exclude_patterns' => [
            '/^UPDATE/i',
            '/^INSERT/i',
            '/^DELETE/i',
            '/FOR UPDATE$/'
        ]
    ],
];
