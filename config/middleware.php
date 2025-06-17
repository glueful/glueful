<?php

/**
 * Middleware Configuration (Consolidated)
 *
 * Defines the middleware stack without duplicating settings from other config files.
 * References configurations from their dedicated files for DRY principle.
 */

return [
    // Global middleware stack (executed in order)
    'global' => [
        // Security headers - uses config/security.php settings
        [
            'class' => \Glueful\Http\Middleware\SecurityHeadersMiddleware::class,
            'config_ref' => 'security.headers',
        ],

        // Rate limiting - uses config/security.php settings
        [
            'class' => \Glueful\Http\Middleware\RateLimiterMiddleware::class,
            'config_ref' => 'security.rate_limiter.defaults',
            'params' => [
                'max_attempts' => 'ip.max_attempts',
                'window_seconds' => 'ip.window_seconds',
                'key_type' => 'ip',
                'enable_adaptive' => 'enable_adaptive',
                'enable_distributed' => 'enable_distributed',
            ],
        ],

        // CSRF protection - consolidated configuration
        [
            'class' => \Glueful\Http\Middleware\CSRFMiddleware::class,
            'config' => [
                'enabled' => env('CSRF_PROTECTION_ENABLED', true),
                'tokenLifetime' => env('CSRF_TOKEN_LIFETIME', 3600),
                'useDoubleSubmit' => env('CSRF_DOUBLE_SUBMIT', false),
                'exemptRoutes' => [
                    'auth/login',
                    'auth/register',
                    'auth/forgot-password',
                    'auth/reset-password',
                    'auth/verify-email',
                    'auth/verify-otp',
                    'webhooks/*',
                    'public/*',
                    'csrf-token',
                ],
            ],
        ],

        // Memory tracking - uses config/app.php settings
        [
            'class' => \Glueful\Http\Middleware\MemoryTrackingMiddleware::class,
            'config_ref' => 'app.performance.memory.monitoring',
        ],

        // API versioning
        \Glueful\Http\Middleware\ApiVersionMiddleware::class,

        // Request logging
        \Glueful\Http\Middleware\LoggerMiddleware::class,

        // Edge cache - uses config/cache.php settings
        [
            'class' => \Glueful\Http\Middleware\EdgeCacheMiddleware::class,
            'config_ref' => 'cache.edge',
        ],

        // Cache control
        \Glueful\Http\Middleware\CacheControlMiddleware::class,

        // API metrics - should run last
        \Glueful\Http\Middleware\ApiMetricsMiddleware::class,
    ],

    // Conditional middleware (applied to specific routes)
    'conditional' => [
        'auth' => \Glueful\Http\Middleware\AuthenticationMiddleware::class,
        'admin' => \Glueful\Http\Middleware\AdminPermissionMiddleware::class,
        'permission' => \Glueful\Http\Middleware\PermissionMiddleware::class,
        'lockdown' => \Glueful\Http\Middleware\LockdownMiddleware::class,
    ],

    // Middleware loading settings
    'settings' => [
        // Whether to replace manual middleware registration in API.php
        'replace_manual_registration' => env('MIDDLEWARE_USE_CONFIG', true),

        // Whether to validate middleware configuration
        'validate_config' => env('MIDDLEWARE_VALIDATE_CONFIG', env('APP_ENV') !== 'production'),

        // Log middleware registration for debugging
        'log_registration' => env('MIDDLEWARE_LOG_REGISTRATION', env('APP_ENV') === 'development'),
    ],
];
