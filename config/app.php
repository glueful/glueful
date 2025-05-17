<?php

use Glueful\Helpers\Config;
/**
 * Application Configuration
 *
 * Core application settings and environment-specific configurations.
 * Values can be overridden using environment variables.
 */
return [
    // Application Environment (development, staging, production)
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),

    // API Information
    'version' => env('API_VERSION', '1.0.0'),    // API version number
    'name' => env('APP_NAME', 'Glueful'),

    // Query Limits
    'list_limit' => 200,      // Maximum items per list query

    // Logging Configuration
    'logging'=> [
        'log_channel' => env('LOG_CHANNEL', 'app'), // Default log channel
        'log_level' => env('LOG_LEVEL', 'debug'),  // Minimum log level
        'log_to_file' => env('LOG_TO_FILE',true),    // Enable/Disable file logging
        'log_to_db' => env('LOG_TO_DB',true),      // Enable/Disable database logging
        'log_file_path' => dirname(__DIR__) . '/storage/logs/', // Log file path
        'api_log_file' => env('API_LOG_FILE', 'api_debug_') . date('Y-m-d') . '.log',
        'log_rotation_days' => 30, // Automatically delete logs older than 30 days
    ],
];
