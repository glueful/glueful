<?php

/**
 * Mail Configuration
 *
 * Email service settings and SMTP configurations.
 * Supports multiple mailer types with failover options.
 */
return [
    // Default mailer configuration
    'default' => env('MAIL_MAILER', 'smtp'),    // smtp, sendmail, or mail

    // Global "From" address settings
    'from' => [
        'address' => env('MAIL_FROM', 'noreply@glueful.com'),
        'name' => env('MAIL_FROM_NAME', 'Glueful'),
    ],

    // SMTP configuration
    'smtp' => [
        'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),     // SMTP server address
        'port' => env('MAIL_PORT', 2525),                   // SMTP port (usually 25, 465, or 587)
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),      // tls or ssl
        'username' => env('MAIL_USERNAME'),                 // SMTP authentication username
        'password' => env('MAIL_PASSWORD'),                 // SMTP authentication password
        'timeout' => 30,                                    // Connection timeout in seconds
        'auth_mode' => null,                               // Optional authentication mode
    ],

    // Email retry settings
    'retry' => [
        'max_attempts' => 3,                    // Maximum retry attempts
        'delay' => 5,                          // Delay between retries in seconds
        'multiplier' => 2,                     // Exponential backoff multiplier
    ],

    // Email queuing options
    'queue' => [
        'enabled' => env('MAIL_QUEUE_ENABLED', false),  // Enable email queuing
        'connection' => 'redis',                        // Queue connection to use
        'queue' => 'emails',                           // Queue name for emails
        'timeout' => 60,                               // Queue job timeout
    ],

    // Email template settings
    'templates' => [
        'path' => dirname(__DIR__) . '/resources/mail', // Email template directory
        'cache' => env('MAIL_TEMPLATE_CACHE', true),   // Enable template caching
    ],

    // Brand identity settings
    'logo_url' => env('MAIL_LOGO_URL', 'https://brand.glueful.com/logo.png'), // Company logo for email templates

    // Debug and logging
    'debug' => env('MAIL_DEBUG', false),              // Enable detailed logging
    'log_channel' => env('MAIL_LOG_CHANNEL', 'mail'), // Logging channel name
];
