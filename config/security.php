<?php

/**
 * Security Configuration
 *
 * Defines security levels and authentication settings.
 * Controls permission checks, session validation, and rate limiting.
 */

return [
    // Security level definitions
    'levels' => [
        'flexible' => 1,    // Basic token validation only
        'moderate' => 2,    // Token + IP address validation
        'strict' => 3,      // Token + IP + User Agent validation
    ],

    // Default security level for new sessions
    'default_level' => env('DEFAULT_SECURITY_LEVEL', 1),  // Use flexible by default

    // Permission system settings
    'enabled_permissions' => env('ENABLE_PERMISSIONS', true),  // Enable role-based access control
    'nanoid_length' => env('NANOID_LENGTH', 12),  // Default length for NanoID generation

    // Adaptive Rate Limiting settings
    'rate_limiter' => [
        'enable_adaptive' => env('ENABLE_ADAPTIVE_RATE_LIMITING', true),
        'enable_distributed' => env('ENABLE_DISTRIBUTED_RATE_LIMITING', false),
        'enable_ml' => env('ENABLE_ML_RATE_LIMITING', false),
        'default_behavior_score' => 0.25,  // Default behavior score for new clients
        'sync_interval' => 30,   // Distributed rate limiter sync interval in seconds
        'rule_update_interval' => 3600,  // How often to refresh rules from storage (seconds)
        'behavior_ttl' => 86400,  // How long to keep behavior profiles (seconds)
        'anomaly_ttl' => 604800,  // How long to keep anomaly scores (seconds)

        // Default rate limits
        'defaults' => [
            'ip' => [
                'max_attempts' => env('IP_RATE_LIMIT_MAX', 60),
                'window_seconds' => env('IP_RATE_LIMIT_WINDOW', 60)
            ],
            'user' => [
                'max_attempts' => env('USER_RATE_LIMIT_MAX', 1000),
                'window_seconds' => env('USER_RATE_LIMIT_WINDOW', 3600)
            ],
            'endpoint' => [
                'max_attempts' => env('ENDPOINT_RATE_LIMIT_MAX', 30),
                'window_seconds' => env('ENDPOINT_RATE_LIMIT_WINDOW', 60)
            ]
        ]
    ]
];
