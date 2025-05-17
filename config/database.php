<?php

/**
 * Database Configuration
 *
 * Defines database connections and settings.
 * Supports multiple database engines and connections.
 */
return [
    // Default database engine (mysql, sqlite, pgsql)
    'engine' => env('DB_ENGINE', 'mysql'),

    // MySQL database connection
    'mysql' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', '127.0.0.1'),        // Database host
        'port' => env('DB_PORT', 3306),               // Database port
        'db' => env('DB_DATABASE', 'glueful'),        // Database name
        'user' => env('DB_USERNAME', 'root'),         // Database username
        'pass' => env('DB_PASSWORD', ''),             // Database password
        'charset' => 'utf8mb4',                       // Character set
        'collation' => 'utf8mb4_unicode_ci',          // Collation
        'prefix' => env('DB_PREFIX', ''),             // Table prefix
        'strict' => true,                             // Strict mode
        'engine' => 'InnoDB',
        'role' => env('DB_MYSQL_ROLE', 'primary'),    // Role of this database connection
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
        'sslmode' => env('DB_PGSQL_SSL_MODE', 'prefer'),
        'timezone' => env('DB_PGSQL_TIMEZONE', 'UTC'),
        'role' => env('DB_PGSQL_ROLE', ''), // Defines the role of this database connection
    ],

    // SQLite configuration
    'sqlite' => [
        'primary' => env('DB_SQLITE_DATABASE', dirname(__DIR__) . '/database/primary.sqlite'),
        'testing' => env('DB_SQLITE_TESTING', dirname(__DIR__) . '/database/testing.sqlite'),
        'role' => env('DB_SQLITE_ROLE', 'backup') // Defines the role of this database connection
    ],

    // Query logging options
    'logging' => [
        'enabled' => env('DB_LOGGING', false),        // Enable query logging
        'slow_threshold' => env('DB_SLOW_MS', 100),   // Slow query threshold (ms)
        'log_path' => dirname(__DIR__) . '/storage/logs/query.log', // Log file path
    ],

    // Migration settings
    'migrations' => [
        'table' => 'migrations',                      // Migrations table name
        'path' => dirname(__DIR__) . '/database/migrations', // Migrations directory
    ],
];
