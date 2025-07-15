<?php

/**
 * Event System Configuration
 *
 * Configuration for the Symfony EventDispatcher-based event system.
 */

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Event System Enabled
    |--------------------------------------------------------------------------
    |
    | Whether the event system is enabled. Set to false to disable all event
    | dispatching and listeners for maximum performance when events are not needed.
    |
    */
    'enabled' => env('EVENTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Core Event Listeners
    |--------------------------------------------------------------------------
    |
    | Configuration for core framework event listeners. You can disable
    | specific listeners here for performance optimization.
    |
    */
    'listeners' => [
        'cache_invalidation' => env('EVENTS_CACHE_INVALIDATION', true),
        'security_monitoring' => env('EVENTS_SECURITY_MONITORING', true),
        'performance_monitoring' => env('EVENTS_PERFORMANCE_MONITORING', true),
        'audit_logging' => env('EVENTS_AUDIT_LOGGING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Debugging
    |--------------------------------------------------------------------------
    |
    | Enable detailed event dispatching logs for debugging purposes.
    | Only enable in development as it can impact performance.
    |
    */
    'debug' => env('EVENTS_DEBUG', env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | Event Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Track event dispatching performance and log slow event processing.
    |
    */
    'performance' => [
        'enabled' => env('EVENTS_PERFORMANCE_TRACKING', true),
        'slow_threshold_ms' => env('EVENTS_SLOW_THRESHOLD', 100),
        'memory_threshold_mb' => env('EVENTS_MEMORY_THRESHOLD', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extension Event Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for extension-provided event listeners and subscribers.
    |
    */
    'extensions' => [
        'enabled' => env('EVENTS_EXTENSIONS_ENABLED', true),
        'auto_register' => env('EVENTS_EXTENSIONS_AUTO_REGISTER', true),
    ],
];
