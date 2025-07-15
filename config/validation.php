<?php

declare(strict_types=1);

/**
 * Validation Configuration
 *
 * Configuration for the Glueful validation system including performance
 * optimizations, caching, and extension settings.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Validation Cache
    |--------------------------------------------------------------------------
    |
    | Cache compiled validation constraints for better performance in production.
    | This significantly reduces the overhead of reflection and constraint
    | compilation on each request.
    |
    */
    'cache_dir' => dirname(__DIR__) . '/storage/validation/cache',
    'enable_cache' => env('VALIDATION_CACHE_ENABLED', env('APP_ENV') === 'production'),
    'cache_ttl' => env('VALIDATION_CACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configure performance-related options for the validation system.
    |
    */
    'enable_compilation' => env('VALIDATION_COMPILATION_ENABLED', env('APP_ENV') === 'production'),
    'lazy_loading' => env('VALIDATION_LAZY_LOADING', true),
    'constraint_caching' => env('VALIDATION_CONSTRAINT_CACHING', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Settings
    |--------------------------------------------------------------------------
    |
    | Debug mode provides detailed error information and disables caching
    | for development environments.
    |
    */
    'debug' => env('APP_DEBUG', false),
    'enable_profiling' => env('VALIDATION_PROFILING', false),
    'log_validation_errors' => env('VALIDATION_LOG_ERRORS', true),

    /*
    |--------------------------------------------------------------------------
    | Extension Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for extension-provided validation constraints.
    |
    */
    'extension_constraints' => [
        'auto_discovery' => env('VALIDATION_EXTENSION_AUTO_DISCOVERY', true),
        'cache_extension_constraints' => env('VALIDATION_CACHE_EXTENSION_CONSTRAINTS', true),
        'validate_extension_constraints' => env('VALIDATION_VALIDATE_EXTENSION_CONSTRAINTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Groups
    |--------------------------------------------------------------------------
    |
    | Default validation groups and their priorities.
    |
    */
    'default_groups' => ['Default'],
    'group_priorities' => [
        'CREATE' => 100,
        'UPDATE' => 90,
        'DELETE' => 80,
        'Default' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Validation
    |--------------------------------------------------------------------------
    |
    | Settings for database-based validation constraints.
    |
    */
    'database_validation' => [
        'connection' => env('VALIDATION_DB_CONNECTION', 'default'),
        'cache_queries' => env('VALIDATION_CACHE_DB_QUERIES', true),
        'query_timeout' => env('VALIDATION_DB_QUERY_TIMEOUT', 5), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitization Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for data sanitization during validation.
    |
    */
    'sanitization' => [
        'enabled' => env('VALIDATION_SANITIZATION_ENABLED', true),
        'cache_sanitized_data' => env('VALIDATION_CACHE_SANITIZED_DATA', false),
        'strip_tags_by_default' => env('VALIDATION_STRIP_TAGS_DEFAULT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Message Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for validation error messages and translation.
    |
    */
    'error_messages' => [
        'localization' => env('VALIDATION_LOCALIZATION', 'en'),
        'custom_message_path' => dirname(__DIR__) . '/resources/lang/validation',
        'include_field_names' => env('VALIDATION_INCLUDE_FIELD_NAMES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory and Performance Limits
    |--------------------------------------------------------------------------
    |
    | Configure memory usage and performance limits for validation operations.
    |
    */
    'limits' => [
        'max_validation_depth' => env('VALIDATION_MAX_DEPTH', 10),
        'max_constraints_per_class' => env('VALIDATION_MAX_CONSTRAINTS_PER_CLASS', 100),
        'memory_limit' => env('VALIDATION_MEMORY_LIMIT', '128M'),
        'execution_time_limit' => env('VALIDATION_EXECUTION_TIME_LIMIT', 30), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Metrics
    |--------------------------------------------------------------------------
    |
    | Enable monitoring and metrics collection for validation operations.
    |
    */
    'monitoring' => [
        'enabled' => env('VALIDATION_MONITORING_ENABLED', false),
        'collect_metrics' => env('VALIDATION_COLLECT_METRICS', false),
        'slow_validation_threshold' => env('VALIDATION_SLOW_THRESHOLD', 0.1), // seconds
    ],
];