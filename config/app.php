<?php

/**
 * Application Configuration
 *
 * Core application settings, paths, performance, and pagination configurations.
 * Values can be overridden using environment variables.
 */

return [
    // Application Environment (development, staging, production)
    'env' => env('APP_ENV', 'development'),

    // Smart environment-aware debug default (false in production, true otherwise)
    'debug' => (bool) env('APP_DEBUG', env('APP_ENV') !== 'production'),

    // Smart environment-aware API documentation (disabled in production for security)
    'api_docs_enabled' => env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),

    // Smart environment-aware development mode
    'dev_mode' => env('DEV_MODE', env('APP_ENV') === 'development'),

    // Smart environment-aware HTTPS enforcement
    'force_https' => env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    // API Information
    'name' => env('APP_NAME', 'Glueful'),
    'version_full' => env('API_VERSION_FULL', '1.0.0'),
    'api_version' => env('API_VERSION', 'v1'),

    // API Versioning Configuration
    'versioning' => [
        'strategy' => env('API_VERSION_STRATEGY', 'url'), // url, header, both
        'current' => env('API_VERSION', 'v1'),
        'supported' => explode(',', env('API_SUPPORTED_VERSIONS', 'v1')),
        'default' => env('API_DEFAULT_VERSION', 'v1'),
    ],

    // Application Paths
    'paths' => [
        'base' => dirname(__DIR__),
        'api_base_directory' => dirname(__DIR__) . '/api/',
        'api_docs' => dirname(__DIR__) . '/docs/',
        'api_docs_url' => env('BASE_URL', 'http://localhost') . '/docs',
        'cdn' => env('BASE_URL', 'http://localhost') . '/storage/cdn/',
        'domain' => env('BASE_URL', 'http://localhost'),
        'api_base_url' => env('API_BASE_URL', 'http://localhost/api/'),
        'uploads' => dirname(__DIR__) . '/storage/cdn/',
        'logs' => dirname(__DIR__) . '/storage/logs/',
        'cache' => dirname(__DIR__) . '/storage/cache/',
        'backups' => dirname(__DIR__) . '/storage/backups/',
        'storage' => dirname(__DIR__) . '/storage/',
        'json_definitions' => dirname(__DIR__) . '/api/api-json-definitions/',
        'project_extensions' => dirname(__DIR__) . '/extensions/',
        'archives' => dirname(__DIR__) . '/storage/archives/',
        'migrations' => dirname(__DIR__) . '/database/migrations',
    ],

    // Pagination Settings
    'pagination' => [
        'enabled' => env('PAGINATION_ENABLED', true),
        'default_size' => env('PAGINATION_DEFAULT_SIZE', 25),
        'max_size' => env('PAGINATION_MAX_SIZE', 100),
        'list_limit' => env('PAGINATION_LIST_LIMIT', 1000),
    ],

    // Performance Settings
    'performance' => [
        'memory' => [
            'monitoring' => [
                'enabled' => env('MEMORY_MONITORING_ENABLED', true),
                'alert_threshold' => env('MEMORY_ALERT_THRESHOLD', 0.8),
                'critical_threshold' => env('MEMORY_CRITICAL_THRESHOLD', 0.9),
                'log_level' => env('MEMORY_LOG_LEVEL', 'warning'),
                'sample_rate' => env('MEMORY_SAMPLE_RATE', 0.01)
            ],
            'limits' => [
                'query_cache' => env('MEMORY_LIMIT_QUERY_CACHE', 1000),
                'object_pool' => env('MEMORY_LIMIT_OBJECT_POOL', 500),
                'result_limit' => env('MEMORY_LIMIT_RESULTS', 10000)
            ],
            'gc' => [
                'auto_trigger' => env('MEMORY_AUTO_GC', true),
                'threshold' => env('MEMORY_GC_THRESHOLD', 0.85)
            ]
        ]
    ],

    // Logging Configuration
    'logging' => [
        'log_channel' => env('LOG_CHANNEL', 'app'),
        // Smart environment-aware log level (error in production, debug in development)
        'log_level' => env('LOG_LEVEL', match (env('APP_ENV')) {
            'production' => 'error',
            'staging' => 'warning',
            default => 'debug'
        }),
        'log_to_file' => env('LOG_TO_FILE', true),
        'log_to_db' => env('LOG_TO_DB', true),
        'log_file_path' => dirname(__DIR__) . '/storage/logs/',
        'api_log_file' => env('API_LOG_FILE', 'api_debug_') . date('Y-m-d') . '.log',
        'log_rotation_days' => env('LOG_ROTATION_DAYS', 30),
        // Audit logging configuration
        'audit' => [
            // Minimum audit level to log (1=CRITICAL, 2=IMPORTANT, 3=INFO, 4=DEBUG)
            'minimum_level' => env('AUDIT_MINIMUM_LEVEL', match (env('APP_ENV')) {
                'production' => 2,  // IMPORTANT and above in production
                'staging' => 3,     // INFO and above in staging
                default => 4        // All events in development
            }),
            // Enable batch audit logging for performance
            'batch_enabled' => env('AUDIT_BATCH_ENABLED', true),
            'batch_size' => env('AUDIT_BATCH_SIZE', match (env('APP_ENV')) {
                'production' => 200,  // Larger batches in production for max performance
                'staging' => 100,     // Medium batches in staging
                default => 50         // Smaller batches in development for faster feedback
            }),
            'batch_timeout' => env('AUDIT_BATCH_TIMEOUT', match (env('APP_ENV')) {
                'production' => 10,   // Longer timeout in production for larger batches
                'staging' => 7,       // Medium timeout in staging
                default => 5          // Quick timeout in development
            }),
            // High-frequency event specific batching
            'auth_event_batch_size' => env('AUDIT_AUTH_BATCH_SIZE', 100),
            'auth_event_batch_timeout' => env('AUDIT_AUTH_BATCH_TIMEOUT', 3), // Quick flush for security events
            'resource_access_batch_size' => env('AUDIT_RESOURCE_BATCH_SIZE', 300),
            'resource_access_batch_timeout' => env('AUDIT_RESOURCE_BATCH_TIMEOUT', 15),
            // Skip audit logging for certain paths
            'skip_paths' => [
                '/health',
                '/metrics',
                '/favicon.ico',
                '/robots.txt',
                '/ping',
                '/status',
            ],
            // Skip audit logging for certain user agents
            'skip_user_agents' => [
                'UptimeRobot',
                'Pingdom',
                'StatusCake',
                'NewRelic',
                'Datadog',
            ],
            // Async processing for non-critical events
            'async_processing' => [
                'enabled' => env('AUDIT_ASYNC_ENABLED', true),
                'queue_name' => env('AUDIT_QUEUE_NAME', 'audit'),
                'categories' => ['resource_access', 'info', 'debug'], // Use async for these categories
            ],
        ],
    ],
];
