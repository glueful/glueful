<?php

/**
 * Scheduler Configuration
 *
 * Defines scheduled jobs and cron tasks for the application.
 * Enhanced with better error handling and monitoring.
 */

return [
    // Core system jobs
    'jobs' => [
        [
            'name' => 'session_cleaner',
            'schedule' => '0 0 * * *',  // Daily at midnight
            'handler_class' => 'Glueful\\Cron\\SessionCleaner',
            'parameters' => [],
            'description' => 'Cleans up expired access and refresh tokens from the database.',
            'enabled' => env('SESSION_CLEANER_ENABLED', true),
            'persistence' => false,
            'timeout' => 300,  // 5 minutes
            'retry_attempts' => 3,
        ],
        [
            'name' => 'log_cleanup',
            'schedule' => '0 1 * * *',  // Daily at 1 AM
            'handler_class' => 'Glueful\\Cron\\LogCleaner',
            'parameters' => [
                'retention_days' => env('LOG_RETENTION_DAYS', 30)
            ],
            'description' => 'Clean up old log files based on retention policy',
            'enabled' => env('LOG_CLEANUP_ENABLED', true),
            'persistence' => false,
            'timeout' => 600,  // 10 minutes
            'retry_attempts' => 2,
        ],
        [
            'name' => 'database_backup',
            'schedule' => env('DB_BACKUP_SCHEDULE', '0 2 * * *'),  // Daily at 2 AM
            'handler_class' => 'Glueful\\Cron\\DatabaseBackup',
            'parameters' => [
                'retention_days' => env('BACKUP_RETENTION_DAYS', 7)
            ],
            'enabled' => env('DB_BACKUP_ENABLED', env('APP_ENV') === 'production'),
            'description' => 'Create automated database backups',
            'persistence' => false,
            'timeout' => 1800,  // 30 minutes
            'retry_attempts' => 1,
        ],
        [
            'name' => 'notification_retry_processor',
            'schedule' => '*/10 * * * *',  // Every 10 minutes
            'handler_class' => 'Glueful\\Notifications\\Services\\NotificationRetryService',
            'parameters' => ['limit' => 50],
            'description' => 'Process queued notification retries',
            'enabled' => env('NOTIFICATION_RETRIES_ENABLED', true),
            'persistence' => false,
            'timeout' => 300,  // 5 minutes
            'retry_attempts' => 2,
        ],
        [
            'name' => 'cache_maintenance',
            'schedule' => '0 3 * * *',  // Daily at 3 AM
            'handler_class' => 'Glueful\\Cron\\CacheMaintenance',
            'parameters' => [],
            'description' => 'Perform cache cleanup and optimization',
            'enabled' => env('CACHE_MAINTENANCE_ENABLED', true),
            'persistence' => false,
            'timeout' => 600,  // 10 minutes
            'retry_attempts' => 2,
        ],
        [
            'name' => 'queue_maintenance',
            'schedule' => '*/15 * * * *',  // Every 15 minutes
            'handler_class' => 'Glueful\\Queue\\Jobs\\QueueMaintenance',
            'parameters' => [],
            'description' => 'Perform queue system maintenance and optimization',
            'enabled' => env('QUEUE_MAINTENANCE_ENABLED', true),
            'persistence' => false,
            'timeout' => 300,  // 5 minutes
            'retry_attempts' => 2,
        ],
    ],

    // Global scheduler settings
    'settings' => [
        'enabled' => env('SCHEDULER_ENABLED', true),
        'max_concurrent_jobs' => env('MAX_CONCURRENT_JOBS', 5),
        'default_timeout' => env('DEFAULT_JOB_TIMEOUT', 300),
        'log_execution' => env('LOG_JOB_EXECUTION', true),
        'notification_on_failure' => env('NOTIFY_ON_JOB_FAILURE', env('APP_ENV') === 'production'),
    ],
];
