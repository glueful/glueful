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

        'mailers' => [
            // Generic SMTP (works with any provider)
            'smtp' => [
                'transport' => 'smtp',
                'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),
                'port' => env('MAIL_PORT', 2525),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'username' => env('MAIL_USERNAME'),
                'password' => env('MAIL_PASSWORD'),
                'timeout' => 30,
                'auth_mode' => null,
            ],

            // Brevo (Sendinblue) - Provider Bridge
            'brevo' => [
                'transport' => env('BREVO_TRANSPORT', 'brevo+api'), // brevo+api or brevo+smtp
                'key' => env('BREVO_API_KEY'),
                'username' => env('MAIL_USERNAME'),  // Reuse existing SMTP credentials
                'password' => env('MAIL_PASSWORD'),  // Reuse existing SMTP credentials
                'dsn' => env('BREVO_DSN'), // Override: brevo+api://KEY@default or brevo+smtp://USER:PASS@default
            ],

            // SendGrid - Provider Bridge
            'sendgrid' => [
                'transport' => 'sendgrid+api',
                'key' => env('SENDGRID_API_KEY'),
                'dsn' => env('SENDGRID_DSN'), // Override: sendgrid+api://KEY@default
            ],

            // Mailgun - Provider Bridge
            'mailgun' => [
                'transport' => 'mailgun+api',
                'domain' => env('MAILGUN_DOMAIN'),
                'key' => env('MAILGUN_SECRET'),
                'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
                'region' => env('MAILGUN_REGION', 'us'),
                'dsn' => env('MAILGUN_DSN'), // Override: mailgun+api://KEY@DOMAIN
            ],

            // Amazon SES - Provider Bridge
            'ses' => [
                'transport' => 'ses+api',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'dsn' => env('SES_DSN'), // Override: ses+api://KEY:SECRET@default?region=REGION
            ],

            // Postmark - Provider Bridge
            'postmark' => [
                'transport' => 'postmark+api',
                'token' => env('POSTMARK_TOKEN'),
                'dsn' => env('POSTMARK_DSN'), // Override: postmark+api://TOKEN@default
            ],

            // Development/Testing
            'log' => [
                'transport' => 'log',
                'channel' => env('MAIL_LOG_CHANNEL', 'mail'),
            ],

            'null' => [
                'transport' => 'null',
            ],

            'array' => [
                'transport' => 'array',
            ],
        ],

        'from' => [
            'address' => env('MAIL_FROM', 'noreply@glueful.com'),
            'name' => env('MAIL_FROM_NAME', 'Glueful'),
        ],

        'failover' => [
            'mailers' => explode(',', env('MAIL_FAILOVER_MAILERS', '')),
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
            // Primary template directory (extension's templates by default)
            'path' => env('MAIL_TEMPLATES_PATH', dirname(__DIR__) . '/extensions/EmailNotification/src/Templates/html'),

            // Additional custom template directories (checked in order)
            'custom_paths' => [
                // Framework's mail templates (if any)
                dirname(__DIR__) . '/resources/mail',
                // User can add more paths via config
            ],

            // Template caching for performance
            'cache_enabled' => env('MAIL_TEMPLATE_CACHE', true),
            'cache_path' => env('MAIL_TEMPLATE_CACHE_PATH', dirname(__DIR__) . '/storage/cache/mail-templates'),

            // Layout and partials
            'default_layout' => env('MAIL_DEFAULT_LAYOUT', 'layout'),
            'partials_directory' => 'partials',

            // Template file extension
            'extension' => '.html',

            // Custom template mappings
            'mappings' => [
                // Framework can define its own mappings
                // 'password_reset' => 'auth/password-reset',
                // 'user_welcome' => 'onboarding/welcome',
            ],

            // Variables available to all templates
            'global_variables' => [
                'app_name' => env('APP_NAME', 'Glueful Application'),
                'app_url' => env('BASE_URL', 'https://example.com'),
                'support_email' => env('MAIL_SUPPORT_EMAIL', 'support@example.com'),
                'logo_url' => env('MAIL_LOGO_URL', 'https://brand.glueful.com/logo.png'),
                'current_year' => date('Y'),
            ],
        ],

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
