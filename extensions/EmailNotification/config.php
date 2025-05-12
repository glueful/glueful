<?php

declare(strict_types=1);

/**
 * Email Notification Channel Configuration
 *
 * Default configuration for the Email Notification Channel.
 * These settings can be overridden in the application's config files.
 */

return [
    /**
     * SMTP Server Configuration
     */
    'host' => env('MAIL_HOST') ?: 'smtp.example.com',
    'port' => env('MAIL_PORT') ?: 587,
    'username' => env('MAIL_USERNAME') ?: '',
    'password' => env('MAIL_PASSWORD') ?: '',
    'encryption' => env('MAIL_ENCRYPTION') ?: 'tls', // tls, ssl, or null
    'smtp_auth' => true,

    /**
     * From Address Configuration
     */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS') ?: 'noreply@example.com',
        'name' => env('MAIL_FROM_NAME') ?: 'Notification System',
    ],

    /**
     * Reply-To Address Configuration (optional)
     */
    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS') ?: '',
        'name' => env('MAIL_REPLY_TO_NAME') ?: '',
    ],

    /**
     * Application Name (used in templates)
     */
    'app_name' => env('APP_NAME') ?: 'Glueful Application',

    /**
     * Default Email Templates Path
     * If set, email templates will be loaded from this directory
     */
    'templates_path' => null,

    /**
     * Email Queue Configuration
     */
    'queue' => [
        'enabled' => true,
        'connection' => 'default',
        'queue' => 'emails',
        'retry_after' => 90, // seconds
    ],

    /**
     * Email Sending Limits
     */
    'rate_limit' => [
        'enabled' => true,
        'max_per_minute' => 10,
        'max_per_hour' => 100,
    ],

    /**
     * Debug Mode
     * When enabled, emails are not actually sent but logged instead
     */
    'debug' => false,

    /**
     * Logging Configuration
     */
    'logging' => [
        'enabled' => true,
        'channel' => 'email',
    ],

    /**
     * Event Handling Configuration
     */
    'events' => [
        'enabled' => true,
        'listeners' => [
            'Glueful\\Extensions\\EmailNotification\\Listeners\\EmailNotificationListener'
        ]
    ],

    /**
     * Retry Configuration for Failed Deliveries
     */
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay' => 300, // seconds (5 minutes)
        'backoff' => 'exponential', // linear, exponential
    ],
];
