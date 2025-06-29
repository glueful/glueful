<?php

/**
 * Logging Configuration
 *
 * Framework vs Application logging boundaries:
 * - Framework logs: HTTP protocol, exceptions, lifecycle, performance
 * - Application logs: Business logic, user actions, custom events
 */

declare(strict_types=1);

return [
    // Framework-level logging configuration
    'framework' => [
        'enabled' => env('FRAMEWORK_LOGGING_ENABLED', true),
        'level' => env('FRAMEWORK_LOG_LEVEL', 'info'),
        'channel' => env('FRAMEWORK_LOG_CHANNEL', 'framework'),

        // Feature-specific toggles
        'log_exceptions' => env('LOG_FRAMEWORK_EXCEPTIONS', true),
        'log_deprecations' => env('LOG_FRAMEWORK_DEPRECATIONS', true),
        'log_lifecycle' => env('LOG_FRAMEWORK_LIFECYCLE', true),
        'log_protocol_errors' => env('LOG_FRAMEWORK_PROTOCOL_ERRORS', true),

        // Performance monitoring (optional framework features)
        'slow_requests' => [
            'enabled' => env('LOG_SLOW_REQUESTS', true),
            'threshold_ms' => env('SLOW_REQUEST_THRESHOLD', 1000),
            'log_level' => 'warning'
        ],

        'slow_queries' => [
            'enabled' => env('LOG_SLOW_QUERIES', true),
            'threshold_ms' => env('SLOW_QUERY_THRESHOLD', 200),
            'log_level' => 'warning'
        ],

        'http_client' => [
            'log_failures' => env('LOG_HTTP_CLIENT_FAILURES', true),
            'log_level' => 'error',
            'slow_threshold_ms' => env('HTTP_CLIENT_SLOW_THRESHOLD', 5000)
        ]
    ],

    // Application-level logging (developers configure)
    'application' => [
        'default_channel' => env('LOG_CHANNEL', 'app'),
        'level' => env('LOG_LEVEL', match (env('APP_ENV')) {
            'production' => 'error',
            'staging' => 'warning',
            default => 'debug'
        }),
        'log_to_file' => env('LOG_TO_FILE', true),
        'log_to_db' => env('LOG_TO_DB', true),
        'database_logging' => env('LOG_TO_DB', true), // Alias for backward compatibility
    ],

    // File paths and rotation settings
    'paths' => [
        'log_directory' => env('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/'),
        'api_log_file' => env('API_LOG_FILE', 'api_debug_') . date('Y-m-d') . '.log',
    ],

    'rotation' => [
        'days' => env('LOG_ROTATION_DAYS', 30),
        'strategy' => env('LOG_ROTATION_STRATEGY', 'daily'), // daily, weekly, monthly, size
        'max_size' => env('LOG_MAX_SIZE', '100M'), // For size-based rotation
    ],

    // Channels configuration
    'channels' => [
        'framework' => [
            'driver' => 'daily',
            'path' => env('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/') . 'framework.log',
            'level' => env('FRAMEWORK_LOG_LEVEL', 'info'),
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'app' => [
            'driver' => 'daily',
            'path' => env('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/') . 'app.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'api' => [
            'driver' => 'daily',
            'path' => env('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/') . 'api.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'error' => [
            'driver' => 'daily',
            'path' => env('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/') . 'error.log',
            'level' => 'error',
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'debug' => [
            'driver' => 'daily',
            'path' => env('LOG_FILE_PATH', dirname(__DIR__) . '/storage/logs/') . 'debug.log',
            'level' => 'debug',
            'days' => env('LOG_ROTATION_DAYS', 30),
        ]
    ]
];
