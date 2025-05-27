<?php

/**
 * Security Lockdown Configuration
 *
 * Configuration for emergency security lockdown system
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Emergency Lockdown Settings
    |--------------------------------------------------------------------------
    |
    | Configure the behavior of the emergency security lockdown system.
    | These settings control how the system responds to security threats.
    |
    */

    'enabled' => env('LOCKDOWN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Lockdown Duration
    |--------------------------------------------------------------------------
    |
    | Default duration for lockdowns if not specified in the command.
    | Supports: m (minutes), h (hours), d (days)
    |
    */

    'default_duration' => env('LOCKDOWN_DEFAULT_DURATION', '1h'),

    /*
    |--------------------------------------------------------------------------
    | Default Severity Level
    |--------------------------------------------------------------------------
    |
    | Default severity level for lockdowns if not specified.
    | Options: low, medium, high, critical
    |
    */

    'default_severity' => env('LOCKDOWN_DEFAULT_SEVERITY', 'high'),

    /*
    |--------------------------------------------------------------------------
    | Administrator Alerts
    |--------------------------------------------------------------------------
    |
    | Email addresses and webhooks to notify when lockdown is activated.
    |
    */

    'admin_emails' => [
        env('ADMIN_EMAIL_1'),
        env('ADMIN_EMAIL_2'),
        env('ADMIN_EMAIL_3'),
    ],

    'alert_webhook_url' => env('LOCKDOWN_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Endpoints During Lockdown
    |--------------------------------------------------------------------------
    |
    | Endpoints that should remain accessible during any lockdown.
    | These are essential for system operation and monitoring.
    |
    */

    'always_allowed_endpoints' => [
        '/health',
        '/status',
        '/lockdown-status',
        '/api/auth/login',
        '/api/health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity-Based Endpoint Restrictions
    |--------------------------------------------------------------------------
    |
    | Define which endpoints to disable based on lockdown severity.
    | Higher severity levels include restrictions from lower levels.
    |
    */

    'endpoint_restrictions' => [
        'low' => [
            '/api/admin/delete',
            '/api/admin/reset',
            '/api/extensions/install',
        ],

        'medium' => [
            '/api/admin/*',
            '/api/users/create',
            '/api/files/upload',
        ],

        'high' => [
            '/api/admin/*',
            '/api/users/create',
            '/api/files/upload',
            '/api/extensions/*',
            '/api/config/*',
        ],

        'critical' => [
            '*', // Disable everything except always_allowed_endpoints
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Blocking Thresholds
    |--------------------------------------------------------------------------
    |
    | Failed authentication attempt thresholds for automatic IP blocking
    | based on lockdown severity.
    |
    */

    'ip_blocking' => [
        'enabled' => env('LOCKDOWN_IP_BLOCKING', true),

        'thresholds' => [
            'critical' => 3,   // Block after 3 failed attempts
            'high' => 5,        // Block after 5 failed attempts
            'medium' => 10,     // Block after 10 failed attempts
            'low' => 20,        // Block after 20 failed attempts
        ],

        'time_windows' => [
            'critical' => 300,  // 5 minutes
            'high' => 900,      // 15 minutes
            'medium' => 1800,   // 30 minutes
            'low' => 3600,      // 1 hour
        ],

        'block_duration' => env('LOCKDOWN_IP_BLOCK_DURATION', 86400), // 24 hours

        'whitelist' => [
            '127.0.0.1',
            '::1',
            // Add your trusted IPs here
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enhanced Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for enhanced logging during lockdown.
    |
    */

    'enhanced_logging' => [
        'enabled' => env('LOCKDOWN_ENHANCED_LOGGING', true),
        'log_level' => env('LOCKDOWN_LOG_LEVEL', 'debug'),
        'log_requests' => env('LOCKDOWN_LOG_REQUESTS', true),
        'log_responses' => env('LOCKDOWN_LOG_RESPONSES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Actions
    |--------------------------------------------------------------------------
    |
    | Configure which actions are taken automatically during lockdown.
    |
    */

    'automatic_actions' => [
        'revoke_tokens' => true,
        'enable_maintenance_mode' => true,
        'disable_registrations' => true,
        'block_suspicious_ips' => true,
        'enable_enhanced_logging' => true,
        'send_admin_alerts' => true,

        // Critical severity only
        'force_password_resets' => [
            'enabled' => env('LOCKDOWN_FORCE_PASSWORD_RESETS', false),
            'severity_required' => 'critical',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for maintenance mode during lockdown.
    |
    */

    'maintenance_mode' => [
        'default_message' => 'System temporarily unavailable due to security maintenance',
        'show_end_time' => env('LOCKDOWN_SHOW_END_TIME', true),
        'retry_after_header' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Recovery
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic lockdown recovery.
    |
    */

    'auto_recovery' => [
        'enabled' => env('LOCKDOWN_AUTO_RECOVERY', true),
        'cleanup_expired_blocks' => true,
        'cleanup_expired_lockdowns' => true,
        'max_lockdown_duration' => env('LOCKDOWN_MAX_DURATION', 86400), // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Metrics
    |--------------------------------------------------------------------------
    |
    | Configuration for lockdown monitoring and metrics collection.
    |
    */

    'monitoring' => [
        'track_blocked_requests' => true,
        'track_allowed_requests' => true,
        'metrics_retention_days' => env('LOCKDOWN_METRICS_RETENTION', 30),
    ],
];
