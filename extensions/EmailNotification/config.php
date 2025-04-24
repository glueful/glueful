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
    'host' => getenv('MAIL_HOST') ?: 'smtp.example.com',
    'port' => getenv('MAIL_PORT') ?: 587,
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls', // tls, ssl, or null
    'smtp_auth' => true,
    
    /**
     * From Address Configuration
     */
    'from' => [
        'address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@example.com',
        'name' => getenv('MAIL_FROM_NAME') ?: 'Notification System',
    ],
    
    /**
     * Reply-To Address Configuration (optional)
     */
    'reply_to' => [
        'address' => getenv('MAIL_REPLY_TO_ADDRESS') ?: '',
        'name' => getenv('MAIL_REPLY_TO_NAME') ?: '',
    ],
    
    /**
     * Application Name (used in templates)
     */
    'app_name' => getenv('APP_NAME') ?: 'Glueful Application',
    
    /**
     * Default Email Templates Path
     * If set, email templates will be loaded from this directory
     */
    'templates_path' => null,
    
    /**
     * Email Queue Configuration
     */
    'queue' => [
        'enabled' => false,
        'connection' => 'default',
        'queue' => 'emails',
        'retry_after' => 90, // seconds
    ],
    
    /**
     * Email Sending Limits
     */
    'rate_limit' => [
        'enabled' => false,
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
];