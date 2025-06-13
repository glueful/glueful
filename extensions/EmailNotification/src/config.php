<?php

declare(strict_types=1);

/*
 * Email Notification Extension Configuration
 *
 * Extension-specific configuration for the Email Notification Channel.
 * Core mail settings (SMTP, from address, etc.) are loaded from config/services.php
 * This file contains only extension-specific features and behaviors.
 */

return [
    /**
     * Extension Templates Configuration
     */
    'templates' => [
        'path' => __DIR__ . '/../templates',
        'cache_enabled' => true,
        'default_layout' => 'layout',
    ],

    /**
     * Email Sending Limits (Extension Feature)
     */
    'rate_limit' => [
        'enabled' => env('MAIL_RATE_LIMIT_ENABLED', true),
        'max_per_minute' => env('MAIL_RATE_LIMIT_PER_MINUTE', 10),
        'max_per_hour' => env('MAIL_RATE_LIMIT_PER_HOUR', 100),
        'max_per_day' => env('MAIL_RATE_LIMIT_PER_DAY', 1000),
    ],

    /**
     * Queue Integration (Extension Feature)
     */
    'queue' => [
        'enabled' => env('MAIL_QUEUE_ENABLED', true),
        'connection' => env('MAIL_QUEUE_CONNECTION', 'default'),
        'queue' => env('MAIL_QUEUE_NAME', 'emails'),
        'retry_after' => env('MAIL_QUEUE_RETRY_AFTER', 90),
        'max_attempts' => env('MAIL_QUEUE_MAX_ATTEMPTS', 3),
    ],

    /**
     * Event Handling Configuration (Extension Feature)
     */
    'events' => [
        'enabled' => env('MAIL_EVENTS_ENABLED', true),
        'listeners' => [
            'Glueful\\Extensions\\EmailNotification\\Listeners\\EmailNotificationListener'
        ],
        'fire_events' => [
            'email.sending' => true,
            'email.sent' => true,
            'email.failed' => true,
        ],
    ],

    /**
     * Retry Configuration for Failed Deliveries (Extension Feature)
     */
    'retry' => [
        'enabled' => env('MAIL_RETRY_ENABLED', true),
        'max_attempts' => env('MAIL_RETRY_MAX_ATTEMPTS', 3),
        'delay' => env('MAIL_RETRY_DELAY', 300), // seconds (5 minutes)
        'backoff' => env('MAIL_RETRY_BACKOFF', 'exponential'), // linear, exponential
        'jitter' => env('MAIL_RETRY_JITTER', true),
    ],

    /**
     * Monitoring and Analytics (Extension Feature)
     */
    'monitoring' => [
        'enabled' => env('MAIL_MONITORING_ENABLED', true),
        'track_opens' => env('MAIL_TRACK_OPENS', false),
        'track_clicks' => env('MAIL_TRACK_CLICKS', false),
        'bounce_handling' => env('MAIL_BOUNCE_HANDLING', true),
    ],

    /**
     * Debug and Development (Extension Feature)
     */
    'debug' => [
        'enabled' => env('MAIL_DEBUG', false),
        'log_all_emails' => env('MAIL_LOG_ALL', false),
        'preview_mode' => env('MAIL_PREVIEW_MODE', false),
        'test_email' => env('MAIL_TEST_EMAIL', null),
    ],

    /**
     * Security Features (Extension Feature)
     */
    'security' => [
        'verify_ssl' => env('MAIL_VERIFY_SSL', true),
        'allowed_domains' => env('MAIL_ALLOWED_DOMAINS', null), // comma-separated
        'blocked_domains' => env('MAIL_BLOCKED_DOMAINS', null), // comma-separated
        'content_scanning' => env('MAIL_CONTENT_SCANNING', false),
    ],

    /**
     * Performance Optimizations (Extension Feature)
     */
    'performance' => [
        'connection_pooling' => env('MAIL_CONNECTION_POOLING', false),
        'batch_sending' => env('MAIL_BATCH_SENDING', true),
        'batch_size' => env('MAIL_BATCH_SIZE', 50),
        'concurrent_connections' => env('MAIL_CONCURRENT_CONNECTIONS', 3),
    ],
];
