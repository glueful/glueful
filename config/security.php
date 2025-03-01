<?php

/**
 * Security Configuration
 * 
 * Defines security levels and authentication settings.
 * Controls permission checks and session validation.
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
];
