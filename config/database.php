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

    // Primary database connection
    'primary' => [
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
        'engine' => 'InnoDB',                         // Storage engine
    ],

    // SQLite configuration
    'sqlite' => [
        'primary' => env('DB_SQLITE_DATABASE', dirname(__DIR__) . '/database/primary.sqlite'),
        'testing' => env('DB_SQLITE_TESTING', dirname(__DIR__) . '/database/testing.sqlite'),
    ],

    // Connection pool settings
    'pool' => [
        'min_connections' => env('DB_POOL_MIN', 1),   // Minimum connections
        'max_connections' => env('DB_POOL_MAX', 10),  // Maximum connections
        'idle_timeout' => env('DB_POOL_IDLE', 60),   // Idle timeout in seconds
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

    // Backup configuration
    'backup' => [
        'enabled' => env('DB_BACKUP_ENABLED', false), // Enable auto-backups
        'schedule' => '0 0 * * *',                    // Backup schedule (cron)
        'path' => dirname(__DIR__) . '/storage/backups',            // Backup storage path
        'compress' => true,                           // Compress backups
        'retention_days' => 7,                        // Backup retention period
    ],
];
