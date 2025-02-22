<?php

/**
 * Application Configuration
 * 
 * Core application settings and environment-specific configurations.
 * Values can be overridden using environment variables.
 */
return [
    // Application Environment (development, staging, production)
    'env' => env('APP_ENV', 'development'),
    
    // Debug Mode Settings
    'debug' => env('APP_DEBUG', true),           // Enable detailed error messages
    'dev_mode' => env('DEV_MODE', false),        // Enable development features
    
    // API Information
    'version' => env('API_VERSION', '1.0.0'),    // API version number
    'title' => env('API_TITLE', 'Glueful Documentation'),
    
    // Documentation Settings
    'docs_enabled' => env('API_DOCS_ENABLED', true),  // Enable API documentation
    
    // Query Limits
    'list_limit' => env('LIST_LIMIT', 200),      // Maximum items per list query
    
    // Logging Configuration
    'logging'=> [
        'log_channel' => env('LOG_CHANNEL', 'app'), // Default log channel
        'log_level' => env('LOG_LEVEL', 'debug'),  // Minimum log level
        'log_to_file' => env('LOG_TO_FILE',true),    // Enable/Disable file logging
        'log_to_db' => env('LOG_TO_DB',true),      // Enable/Disable database logging
        'log_file_path' => dirname(__DIR__) . '/logs/', // Log file path
        'api_log_file' => env('API_LOG_FILE', 'api_debug_') . date('Y-m-d') . '.log',
        'log_rotation_days' => 30, // Automatically delete logs older than 30 days
    ],
    
    // API Settings
    'api_version' => env('API_VERSION', '1.0.0'),    // API version for routing
    'rest_mode' => env('REST_MODE', true),           // Enable REST API mode
    
    // Status Constants
    'active_status' => env('ACTIVE_STATUS', 'active'),
    'deleted_status' => env('DELETED_STATUS', 'deleted'),
    
    // Audit Settings
    'enable_audit' => env('ENABLE_AUDIT', false),    // Enable action auditing
];
