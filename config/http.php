<?php

/**
 * HTTP Client Configuration
 *
 * This file contains configuration for Symfony HttpClient and related services.
 * All settings can be overridden via environment variables.
 */

declare(strict_types=1);

return [
    /**
     * Default HTTP client configuration
     */
    'default' => [
        'timeout' => env('HTTP_TIMEOUT', 30),
        'max_duration' => env('HTTP_MAX_DURATION', 60),
        'max_redirects' => env('HTTP_MAX_REDIRECTS', 3),
        'http_version' => env('HTTP_VERSION', '2.0'),
        'verify_ssl' => env('HTTP_VERIFY_SSL', true),
        'user_agent' => env('HTTP_USER_AGENT', 'Glueful/1.0'),
        'default_headers' => [
            'Accept' => 'application/json',
        ],
    ],

    /**
     * Retry mechanism configuration
     */
    'retry' => [
        'enabled' => env('HTTP_RETRY_ENABLED', true),
        'max_retries' => env('HTTP_MAX_RETRIES', 3),
        'delay_ms' => env('HTTP_RETRY_DELAY_MS', 1000),
        'multiplier' => env('HTTP_RETRY_MULTIPLIER', 2),
        'max_delay_ms' => env('HTTP_RETRY_MAX_DELAY_MS', 30000),
        'status_codes' => [423, 425, 429, 500, 502, 503, 504, 507, 510],
    ],

    /**
     * Pre-configured scoped clients for common use cases
     */
    'scoped_clients' => [
        'oauth' => [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]
        ],
        'webhook' => [
            'timeout' => 5,
            'max_redirects' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Glueful-Webhook/1.0',
            ]
        ],
        'extension_registry' => [
            'base_uri' => env('EXTENSION_REGISTRY_URL', 'https://registry.glueful.com'),
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Glueful-Extension-Manager/1.0'
            ]
        ]
    ],

    /**
     * HTTP client logging configuration
     */
    'logging' => [
        'enabled' => env('HTTP_LOGGING_ENABLED', false),
        'log_requests' => env('HTTP_LOG_REQUESTS', true),
        'log_responses' => env('HTTP_LOG_RESPONSES', true),
        'log_body' => env('HTTP_LOG_BODY', false),
    ],
];
