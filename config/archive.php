<?php

/**
 * Archive Configuration
 *
 * Configuration settings for the data archiving system including
 * storage paths, compression settings, retention policies, and
 * table-specific archiving rules.
 *
 * @package Glueful\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Archive Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where archives are stored and how they are processed.
    | Storage path should be outside the web root for security.
    |
    */
    'storage' => [
        'path' => dirname(__DIR__) . '/storage/archives/',
        'temp_path' => dirname(__DIR__) . '/storage/archives/temp/',
        'max_archive_size' => (int) (env('ARCHIVE_MAX_SIZE') ?? 1073741824), // 1GB default
        'cleanup_temp_files' => true,
        'cleanup_temp_after_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression and Encryption
    |--------------------------------------------------------------------------
    |
    | Configure compression and encryption settings for archived data.
    | Available compression: gzip, bzip2, none
    |
    */
    'compression' => [
        'algorithm' => env('ARCHIVE_COMPRESSION') ?? 'gzip',
        'level' => (int) (env('ARCHIVE_COMPRESSION_LEVEL') ?? 9), // 1-9 for gzip
    ],

    'encryption' => [
        'enabled' => !empty(env('ARCHIVE_ENCRYPTION_KEY')),
        'key' => env('ARCHIVE_ENCRYPTION_KEY') ?? null,
        'algorithm' => 'AES-256-GCM',
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how archives are processed including chunk sizes and
    | verification settings.
    |
    */
    'processing' => [
        'chunk_size' => (int) (env('ARCHIVE_CHUNK_SIZE') ?? 10000),
        'memory_limit' => env('ARCHIVE_MEMORY_LIMIT') ?? '512M',
        'max_execution_time' => (int) (env('ARCHIVE_MAX_EXECUTION_TIME') ?? 3600), // 1 hour
        'verify_checksums' => filter_var(
            env('ARCHIVE_VERIFY_CHECKSUMS') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'auto_cleanup_failed' => filter_var(
            env('ARCHIVE_AUTO_CLEANUP_FAILED') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Policies
    |--------------------------------------------------------------------------
    |
    | Define retention policies for different table types. Each policy
    | specifies when data should be archived based on age and row count.
    |
    */
    'retention_policies' => [
        'audit_logs' => [
            'archive_after_days' => (int) (env('ARCHIVE_AUDIT_LOGS_DAYS') ?? 90),
            'threshold_rows' => (int) (env('ARCHIVE_AUDIT_LOGS_ROWS') ?? 100000),
            'auto_archive' => filter_var(
                env('ARCHIVE_AUDIT_LOGS_AUTO') ?? 'true',
                FILTER_VALIDATE_BOOLEAN
            ),
            'date_column' => 'created_at',
            'priority' => 'high',
            'compliance_period_years' => 7,
        ],

        'api_metrics' => [
            'archive_after_days' => (int) (env('ARCHIVE_API_METRICS_DAYS') ?? 30),
            'threshold_rows' => (int) (env('ARCHIVE_API_METRICS_ROWS') ?? 500000),
            'auto_archive' => filter_var(
                env('ARCHIVE_API_METRICS_AUTO') ?? 'true',
                FILTER_VALIDATE_BOOLEAN
            ),
            'date_column' => 'created_at',
            'priority' => 'medium',
            'compliance_period_years' => 2,
        ],

        'api_metrics_daily' => [
            'archive_after_days' => (int) (env('ARCHIVE_DAILY_METRICS_DAYS') ?? 365),
            'threshold_rows' => (int) (env('ARCHIVE_DAILY_METRICS_ROWS') ?? 100000),
            'auto_archive' => filter_var(
                env('ARCHIVE_DAILY_METRICS_AUTO') ?? 'true',
                FILTER_VALIDATE_BOOLEAN
            ),
            'date_column' => 'metric_date',
            'priority' => 'low',
            'compliance_period_years' => 2,
        ],

        'api_rate_limits' => [
            'archive_after_days' => (int) (env('ARCHIVE_RATE_LIMITS_DAYS') ?? 7),
            'threshold_rows' => (int) (env('ARCHIVE_RATE_LIMITS_ROWS') ?? 50000),
            'auto_archive' => filter_var(
                env('ARCHIVE_RATE_LIMITS_AUTO') ?? 'true',
                FILTER_VALIDATE_BOOLEAN
            ),
            'date_column' => 'created_at',
            'priority' => 'medium',
            'compliance_period_years' => 1,
        ],

        'notifications' => [
            'archive_after_days' => (int) (env('ARCHIVE_NOTIFICATIONS_DAYS') ?? 180),
            'threshold_rows' => (int) (env('ARCHIVE_NOTIFICATIONS_ROWS') ?? 50000),
            'auto_archive' => filter_var(
                env('ARCHIVE_NOTIFICATIONS_AUTO') ?? 'false',
                FILTER_VALIDATE_BOOLEAN
            ),
            'date_column' => 'created_at',
            'priority' => 'low',
            'compliance_period_years' => 1,
        ],

        'auth_sessions' => [
            'archive_after_days' => (int) (env('ARCHIVE_SESSIONS_DAYS') ?? 30),
            'threshold_rows' => (int) (env('ARCHIVE_SESSIONS_ROWS') ?? 100000),
            'auto_archive' => filter_var(
                env('ARCHIVE_SESSIONS_AUTO') ?? 'true',
                FILTER_VALIDATE_BOOLEAN
            ),
            'date_column' => 'created_at',
            'priority' => 'low',
            'compliance_period_years' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search and Indexing
    |--------------------------------------------------------------------------
    |
    | Configure search indexing for archived data to enable fast lookups
    | across compressed archives.
    |
    */
    'search' => [
        'enable_indexing' => filter_var(
            env('ARCHIVE_ENABLE_SEARCH_INDEX') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'index_fields' => [
            'user_uuid',
            'user_id',
            'ip_address',
            'endpoint',
            'action',
            'status',
            'method',
            'notification_type',
        ],
        'max_search_results' => (int) (env('ARCHIVE_MAX_SEARCH_RESULTS') ?? 1000),
        'search_timeout_seconds' => (int) (env('ARCHIVE_SEARCH_TIMEOUT') ?? 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Alerts
    |--------------------------------------------------------------------------
    |
    | Configure monitoring thresholds and alert settings for the
    | archive system health and performance.
    |
    */
    'monitoring' => [
        'enable_health_checks' => filter_var(
            env('ARCHIVE_ENABLE_HEALTH_CHECKS') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'max_failed_archives' => (int) (env('ARCHIVE_MAX_FAILED') ?? 5),
        'alert_on_corruption' => filter_var(
            env('ARCHIVE_ALERT_CORRUPTION') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'alert_on_disk_space' => filter_var(
            env('ARCHIVE_ALERT_DISK_SPACE') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'disk_space_threshold_percent' => (int) (env('ARCHIVE_DISK_THRESHOLD') ?? 85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Jobs
    |--------------------------------------------------------------------------
    |
    | Configure automatic archiving schedules and maintenance tasks.
    |
    */
    'schedule' => [
        'auto_archive_enabled' => filter_var(
            env('ARCHIVE_AUTO_ENABLED') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'archive_time' => env('ARCHIVE_SCHEDULE_TIME') ?? '02:00', // 2 AM
        'verify_time' => env('ARCHIVE_VERIFY_TIME') ?? '03:00', // 3 AM
        'cleanup_time' => env('ARCHIVE_CLEANUP_TIME') ?? '04:00', // 4 AM
        'max_archives_per_run' => (int) (env('ARCHIVE_MAX_PER_RUN') ?? 10),
        'archive_frequency' => env('ARCHIVE_FREQUENCY') ?? 'weekly', // daily, weekly, monthly
        'max_concurrent_archives' => (int) (env('ARCHIVE_MAX_CONCURRENT') ?? 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup and Recovery
    |--------------------------------------------------------------------------
    |
    | Configure backup settings for archive metadata and recovery options.
    |
    */
    'backup' => [
        'backup_metadata' => filter_var(
            env('ARCHIVE_BACKUP_METADATA') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'metadata_backup_path' => dirname(__DIR__) . '/storage/backups/archive_metadata/',
        'restore_verification' => filter_var(
            env('ARCHIVE_RESTORE_VERIFY') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Compatibility
    |--------------------------------------------------------------------------
    |
    | Maintain backward compatibility with existing archive configurations.
    |
    */
    'integrity' => [
        'verify_after_archive' => filter_var(
            env('ARCHIVE_VERIFY_CHECKSUMS') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        ),
        'checksum_algorithm' => 'sha256',
        'test_restore_sample' => (float) (env('ARCHIVE_TEST_RESTORE_SAMPLE') ?? 0.1), // Test restore 10% of archives
    ],
];
