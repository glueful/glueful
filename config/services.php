<?php

/**
 * Services Configuration
 *
 * Consolidated configuration for mail, storage, and extensions.
 * Combines multiple service-related configurations into one file.
 */

return [
    // Mail Configuration
    'mail' => [
        'default' => env('MAIL_MAILER', 'smtp'),
        'from' => [
            'address' => env('MAIL_FROM', 'noreply@glueful.com'),
            'name' => env('MAIL_FROM_NAME', 'Glueful'),
        ],
        'smtp' => [
            'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),
            'port' => env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => 30,
            'auth_mode' => null,
        ],
        'retry' => [
            'max_attempts' => 3,
            'delay' => 5,
            'multiplier' => 2,
        ],
        'queue' => [
            'enabled' => env('MAIL_QUEUE_ENABLED', false),
            'connection' => 'redis',
            'queue' => 'emails',
            'timeout' => 60,
        ],
        'templates' => [
            'path' => dirname(__DIR__) . '/resources/mail',
            'cache' => env('MAIL_TEMPLATE_CACHE', true),
        ],
        'logo_url' => env('MAIL_LOGO_URL', 'https://brand.glueful.com/logo.png'),
        'debug' => env('MAIL_DEBUG', false),
        'log_channel' => env('MAIL_LOG_CHANNEL', 'mail'),
    ],

    // Storage Configuration
    'storage' => [
        'driver' => env('STORAGE_DRIVER', 'local'),
        's3' => [
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'endpoint' => env('S3_ENDPOINT'),
        ],
    ],

    // Extensions Configuration
    'extensions' => [
        'paths' => [
            'extensions_dir' => dirname(__DIR__) . '/extensions',
            'cache_dir' => dirname(__DIR__) . '/storage/cache/extensions'
        ],
        'defaults' => [
            'enabled' => ['EmailNotification'],
        ],
        'security' => [
            'allow_remote_installation' => env('ALLOW_REMOTE_EXTENSIONS', false),
            'verify_signatures' => env('VERIFY_EXTENSION_SIGNATURES', true),
        ],
        'config_file' => dirname(__DIR__) . '/extensions/extensions.json',
        'environments' => [
            'local' => 'development',
            'dev' => 'development',
            'staging' => 'staging',
            'prod' => 'production',
        ]
    ],
];
