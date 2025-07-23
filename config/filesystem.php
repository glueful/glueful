<?php

/**
 * Filesystem Configuration
 *
 * Configuration for Symfony Filesystem and Finder components.
 * Defines default settings for file operations and security constraints.
 */

return [
    // Enable Symfony filesystem components
    'use_symfony_components' => env('USE_SYMFONY_FILESYSTEM', true),

    // FileManager configuration
    'file_manager' => [
        'default_mode' => 0755,
        'enable_logging' => env('FILE_MANAGER_LOGGING', true),
        'max_path_length' => env('FILE_MAX_PATH_LENGTH', 4096),
        'allowed_extensions' => env('FILE_ALLOWED_EXTENSIONS') ? 
            explode(',', env('FILE_ALLOWED_EXTENSIONS')) : null,
        'forbidden_paths' => [
            '/etc',
            '/usr', 
            '/var/log',
            '/sys',
            '/proc',
            '/bin',
            '/sbin'
        ],
        'atomic_operations' => env('ATOMIC_FILE_OPERATIONS', true),
        'security_checks' => env('FILE_SECURITY_CHECKS', true),
    ],

    // FileFinder configuration
    'file_finder' => [
        'enable_logging' => env('FILE_FINDER_LOGGING', true),
        'default_depth' => env('FILE_FINDER_DEFAULT_DEPTH', null),
        'follow_links' => env('FILE_FINDER_FOLLOW_LINKS', false),
        'ignore_vcs' => env('FILE_FINDER_IGNORE_VCS', true),
        'ignore_dot_files' => env('FILE_FINDER_IGNORE_DOT_FILES', true),
        'max_file_size' => env('FILE_FINDER_MAX_SIZE', null), // bytes
        'performance_monitoring' => env('FILE_FINDER_PERF_MONITORING', false),
    ],

    // Path configurations
    'paths' => [
        'extensions' => env('EXTENSIONS_PATH', __DIR__ . '/../extensions'),
        'routes' => env('ROUTES_PATH', __DIR__ . '/../routes'),
        'migrations' => env('MIGRATIONS_PATH', __DIR__ . '/../database/migrations'),
        'cache' => env('CACHE_PATH', __DIR__ . '/../storage/cache'),
        'logs' => env('LOGS_PATH', __DIR__ . '/../storage/logs'),
        'uploads' => env('UPLOADS_PATH', __DIR__ . '/../storage/uploads'),
        'temp' => env('TEMP_PATH', sys_get_temp_dir()),
    ],

    // Security settings
    'security' => [
        'validate_paths' => env('VALIDATE_FILE_PATHS', true),
        'check_permissions' => env('CHECK_FILE_PERMISSIONS', true),
        'prevent_path_traversal' => env('PREVENT_PATH_TRAVERSAL', true),
        'scan_uploads' => env('SCAN_FILE_UPLOADS', true),
        'max_upload_size' => env('MAX_FILE_UPLOAD_SIZE', 10485760), // 10MB
        'quarantine_suspicious' => env('QUARANTINE_SUSPICIOUS_FILES', true),
    ],

    // Performance settings
    'performance' => [
        'cache_file_stats' => env('CACHE_FILE_STATS', true),
        'batch_operations' => env('BATCH_FILE_OPERATIONS', true),
        'parallel_processing' => env('PARALLEL_FILE_PROCESSING', false),
        'memory_limit_warning' => env('FILE_MEMORY_LIMIT_WARNING', '128M'),
        'operation_timeout' => env('FILE_OPERATION_TIMEOUT', 30), // seconds
    ],

    // Extension-specific settings
    'extensions' => [
        'auto_discovery' => env('AUTO_DISCOVER_EXTENSIONS', true),
        'validate_structure' => env('VALIDATE_EXTENSION_STRUCTURE', true),
        'cache_extension_list' => env('CACHE_EXTENSION_LIST', true),
        'required_files' => ['Extension.php'],
        'optional_files' => ['routes.php', 'config.php', 'README.md'],
    ],

    // Route loading settings
    'routes' => [
        'auto_load' => env('AUTO_LOAD_ROUTES', true),
        'cache_routes' => env('CACHE_ROUTE_FILES', true),
        'validate_syntax' => env('VALIDATE_ROUTE_SYNTAX', true),
        'patterns' => ['*.php'],
        'exclude_patterns' => ['*test*', '*Test*'],
    ],

    // Migration settings
    'migrations' => [
        'naming_pattern' => '/^\d{3}_.*\.php$/',
        'auto_discovery' => env('AUTO_DISCOVER_MIGRATIONS', true),
        'validate_sequence' => env('VALIDATE_MIGRATION_SEQUENCE', true),
        'backup_before_run' => env('BACKUP_BEFORE_MIGRATION', false),
    ],

    // Cache management
    'cache_management' => [
        'auto_cleanup' => env('AUTO_CLEANUP_CACHE', false),
        'cleanup_schedule' => env('CACHE_CLEANUP_SCHEDULE', 'daily'),
        'retention_days' => env('CACHE_RETENTION_DAYS', 7),
        'cleanup_patterns' => ['*.cache', '*.tmp', '*.lock'],
        'preserve_patterns' => ['*.keep', '*.preserve'],
    ],

    // Logging settings
    'logging' => [
        'log_file_operations' => env('LOG_FILE_OPERATIONS', true),
        'log_security_events' => env('LOG_FILE_SECURITY_EVENTS', true),
        'log_performance_metrics' => env('LOG_FILE_PERFORMANCE', false),
        'log_level' => env('FILE_LOG_LEVEL', 'info'),
        'separate_log_files' => env('SEPARATE_FILE_LOGS', false),
    ],
];