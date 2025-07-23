<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    |
    | This option controls the default queue connection that will be used
    | by the queue system. You may change this value to any of the
    | connection configurations defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. A driver configuration example is provided
    | for each backend that is supported by the queue system.
    |
    */

    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'queue_jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
            'failed_table' => 'queue_failed_jobs',
        ],


        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', 0),
            'timeout' => env('REDIS_TIMEOUT', 5),
            'persistent' => env('REDIS_PERSISTENT', false),
            'prefix' => env('REDIS_QUEUE_PREFIX', 'glueful:queue:'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
            'job_expiration' => 3600,
        ],

        'sync' => [
            'driver' => 'sync',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database/table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database'),
        'database' => 'default',
        'table' => 'queue_failed_jobs',
        'max_retries' => 5,
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Batching
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of job batching which allows you
    | to execute a group of related jobs and track their progress as a unit.
    | Completed batches can be automatically cleaned up after a period.
    |
    */

    'batching' => [
        'database' => 'default',
        'table' => 'queue_batches',
        'cleanup_after_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring and Alerting
    |--------------------------------------------------------------------------
    |
    | These options control monitoring and alerting for the queue system.
    | You can define alert rules that will trigger when certain conditions
    | are met, helping you maintain queue health and performance.
    |
    */

    'monitoring' => [
        'enabled' => env('QUEUE_MONITORING_ENABLED', true),
        'metrics_retention_days' => 30,
        'worker_heartbeat_timeout' => 120, // seconds
        'alert_rules' => [
            [
                'name' => 'high_failure_rate',
                'queue' => '*',
                'condition' => 'failure_rate_above',
                'threshold' => 0.1, // 10%
                'period' => '1hour',
                'severity' => 'warning',
                'cooldown' => 900, // 15 minutes
                'enabled' => env('QUEUE_ALERT_FAILURE_RATE', true),
            ],
            [
                'name' => 'queue_size_critical',
                'queue' => '*',
                'condition' => 'queue_size_above',
                'threshold' => 1000,
                'period' => '5minutes',
                'severity' => 'critical',
                'cooldown' => 300, // 5 minutes
                'enabled' => env('QUEUE_ALERT_SIZE', true),
            ],
            [
                'name' => 'no_workers_running',
                'queue' => '*',
                'condition' => 'active_workers_below',
                'threshold' => 1,
                'period' => '1minute',
                'severity' => 'critical',
                'cooldown' => 600, // 10 minutes
                'enabled' => env('QUEUE_ALERT_NO_WORKERS', true),
            ],
            [
                'name' => 'slow_job_processing',
                'queue' => '*',
                'condition' => 'avg_processing_time_above',
                'threshold' => 300, // 5 minutes
                'period' => '15minutes',
                'severity' => 'warning',
                'cooldown' => 1800, // 30 minutes
                'enabled' => env('QUEUE_ALERT_SLOW_JOBS', false),
            ],
        ],
        'notification_channels' => [
            'email' => [
                'enabled' => env('QUEUE_ALERT_EMAIL', false),
                'to' => env('QUEUE_ALERT_EMAIL_TO', 'admin@example.com'),
                'from' => env('QUEUE_ALERT_EMAIL_FROM', 'queue@example.com'),
            ],
            'webhook' => [
                'enabled' => env('QUEUE_ALERT_WEBHOOK', false),
                'url' => env('QUEUE_ALERT_WEBHOOK_URL'),
                'secret' => env('QUEUE_ALERT_WEBHOOK_SECRET'),
            ],
            'log' => [
                'enabled' => env('QUEUE_ALERT_LOG', true),
                'level' => 'warning',
                'channel' => 'queue',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Management
    |--------------------------------------------------------------------------
    |
    | These options control worker behavior including auto-scaling, resource
    | limits, and performance tuning. Auto-scaling can automatically adjust
    | the number of workers based on queue size and system load.
    |
    */

    'workers' => [
        /*
        |----------------------------------------------------------------------
        | Process Management (Symfony Process)
        |----------------------------------------------------------------------
        | Modern multi-worker process management using Symfony Process.
        | This is the new default for queue worker management.
        */
        'process' => [
            'enabled' => env('QUEUE_PROCESS_ENABLED', true), // Default to enabled
            'default_workers' => env('QUEUE_DEFAULT_WORKERS', 2),
            'max_workers_global' => env('QUEUE_MAX_WORKERS_GLOBAL', 50),
            'max_workers_per_queue' => env('QUEUE_MAX_WORKERS', 10),
            'restart_delay' => env('QUEUE_RESTART_DELAY', 5),
            'health_check_interval' => env('QUEUE_HEALTH_CHECK_INTERVAL', 30),
            'worker_timeout' => env('QUEUE_WORKER_TIMEOUT', 300),
            'graceful_shutdown_timeout' => env('QUEUE_GRACEFUL_SHUTDOWN_TIMEOUT', 30),
            'heartbeat_interval' => env('QUEUE_HEARTBEAT_INTERVAL', 15),
            'max_restarts_per_hour' => env('QUEUE_MAX_RESTARTS_PER_HOUR', 10),
        ],

        /*
        |----------------------------------------------------------------------
        | Auto-scaling Configuration
        |----------------------------------------------------------------------
        | Intelligent scaling based on queue load, schedule, and resources.
        */
        'auto_scaling' => [
            'enabled' => env('QUEUE_AUTO_SCALING', false),
            'check_interval' => env('QUEUE_SCALE_CHECK_INTERVAL', 60),
            'scale_up_threshold' => env('QUEUE_SCALE_UP_THRESHOLD', 100),
            'scale_down_threshold' => env('QUEUE_SCALE_DOWN_THRESHOLD', 10),
            'scale_up_step' => env('QUEUE_SCALE_UP_STEP', 2),
            'scale_down_step' => env('QUEUE_SCALE_DOWN_STEP', 1),
            'cooldown_period' => env('QUEUE_SCALE_COOLDOWN', 300), // 5 minutes
        ],

        /*
        |----------------------------------------------------------------------
        | Queue-Specific Worker Configuration
        |----------------------------------------------------------------------
        | Per-queue worker settings for fine-tuned control.
        */
        'queues' => [
            'default' => [
                'workers' => env('DEFAULT_QUEUE_WORKERS', 2),
                'max_workers' => env('DEFAULT_QUEUE_MAX_WORKERS', 5),
                'priority' => 1,
                'memory_limit' => env('DEFAULT_QUEUE_MEMORY', 128), // MB
                'timeout' => env('DEFAULT_QUEUE_TIMEOUT', 60), // seconds
                'max_jobs' => env('DEFAULT_QUEUE_MAX_JOBS', 1000),
                'auto_scale' => true,
            ],
            'high' => [
                'workers' => env('HIGH_QUEUE_WORKERS', 3),
                'max_workers' => env('HIGH_QUEUE_MAX_WORKERS', 8),
                'priority' => 10,
                'memory_limit' => env('HIGH_QUEUE_MEMORY', 256), // MB
                'timeout' => env('HIGH_QUEUE_TIMEOUT', 30), // seconds
                'max_jobs' => env('HIGH_QUEUE_MAX_JOBS', 500),
                'auto_scale' => true,
            ],
            'emails' => [
                'workers' => env('EMAIL_QUEUE_WORKERS', 2),
                'max_workers' => env('EMAIL_QUEUE_MAX_WORKERS', 4),
                'priority' => 5,
                'memory_limit' => env('EMAIL_QUEUE_MEMORY', 64), // MB
                'timeout' => env('EMAIL_QUEUE_TIMEOUT', 120), // seconds
                'max_jobs' => env('EMAIL_QUEUE_MAX_JOBS', 2000),
                'auto_scale' => false,
            ],
            'reports' => [
                'workers' => env('REPORTS_QUEUE_WORKERS', 1),
                'max_workers' => env('REPORTS_QUEUE_MAX_WORKERS', 2),
                'priority' => 2,
                'memory_limit' => env('REPORTS_QUEUE_MEMORY', 512), // MB
                'timeout' => env('REPORTS_QUEUE_TIMEOUT', 600), // 10 minutes
                'max_jobs' => env('REPORTS_QUEUE_MAX_JOBS', 50),
                'auto_scale' => false,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Resource Monitoring & Limits
        |----------------------------------------------------------------------
        | System resource monitoring for scaling decisions.
        */
        'resource_limits' => [
            'memory_limit' => env('QUEUE_WORKER_MEMORY_LIMIT', '512M'),
            'time_limit' => env('QUEUE_WORKER_TIME_LIMIT', 3600), // 1 hour
            'job_timeout' => env('QUEUE_JOB_TIMEOUT', 300), // 5 minutes
            'max_jobs_per_worker' => env('QUEUE_MAX_JOBS_PER_WORKER', 1000),
            'worker_memory_mb' => env('QUEUE_WORKER_MEMORY_MB', 128),
            'worker_cpu_percent' => env('QUEUE_WORKER_CPU_PERCENT', 10),
        ],

        /*
        |----------------------------------------------------------------------
        | Resource Monitoring Thresholds
        |----------------------------------------------------------------------
        | Thresholds for resource-aware scaling decisions.
        */
        'resource_thresholds' => [
            'memory' => [
                'warning' => env('QUEUE_MEMORY_WARNING', 75),
                'critical' => env('QUEUE_MEMORY_CRITICAL', 90),
                'scale_limit' => env('QUEUE_MEMORY_SCALE_LIMIT', 85),
            ],
            'cpu' => [
                'warning' => env('QUEUE_CPU_WARNING', 70),
                'critical' => env('QUEUE_CPU_CRITICAL', 90),
                'scale_limit' => env('QUEUE_CPU_SCALE_LIMIT', 80),
            ],
            'disk' => [
                'warning' => env('QUEUE_DISK_WARNING', 80),
                'critical' => env('QUEUE_DISK_CRITICAL', 95),
                'scale_limit' => env('QUEUE_DISK_SCALE_LIMIT', 90),
            ],
            'load' => [
                'warning' => env('QUEUE_LOAD_WARNING', 2.0),
                'critical' => env('QUEUE_LOAD_CRITICAL', 4.0),
                'scale_limit' => env('QUEUE_LOAD_SCALE_LIMIT', 3.0),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Performance Settings
        |----------------------------------------------------------------------
        | Worker performance and behavior configuration.
        */
        'performance' => [
            'sleep_seconds' => env('QUEUE_WORKER_SLEEP', 3),
            'max_tries' => env('QUEUE_MAX_TRIES', 3),
            'backoff_strategy' => env('QUEUE_BACKOFF_STRATEGY', 'exponential'), // linear, exponential, fixed
            'backoff_base' => env('QUEUE_BACKOFF_BASE', 2),
            'max_backoff' => env('QUEUE_MAX_BACKOFF', 3600), // 1 hour
        ],

        /*
        |----------------------------------------------------------------------
        | Legacy Supervisor Support
        |----------------------------------------------------------------------
        | Legacy supervisor configuration (use process management instead).
        */
        'supervisor' => [
            'enabled' => env('QUEUE_SUPERVISOR_ENABLED', false),
            'config_path' => env('QUEUE_SUPERVISOR_CONFIG', '/etc/supervisor/conf.d/'),
            'restart_threshold' => 10, // restart worker after N failures
            'restart_cooldown' => 60, // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Performance Optimization
    |--------------------------------------------------------------------------
    |
    | These settings help optimize queue performance including connection
    | pooling, job prioritization, and resource allocation strategies.
    |
    */

    'performance' => [
        'connection_pooling' => [
            'enabled' => env('QUEUE_CONNECTION_POOLING', true),
            'min_connections' => 1,
            'max_connections' => 10,
            'idle_timeout' => 300, // 5 minutes
        ],
        'job_priority' => [
            'enabled' => env('QUEUE_PRIORITY_ENABLED', true),
            'default_priority' => 0,
            'high_priority_threshold' => 100,
            'low_priority_threshold' => -100,
        ],
        'batch_processing' => [
            'enabled' => env('QUEUE_BATCH_PROCESSING', true),
            'batch_size' => env('QUEUE_BATCH_SIZE', 100),
            'batch_timeout' => env('QUEUE_BATCH_TIMEOUT', 30), // seconds
        ],
        'compression' => [
            'enabled' => env('QUEUE_COMPRESSION', false),
            'algorithm' => 'gzip', // gzip, bzip2, lz4
            'level' => 6, // compression level 1-9
            'min_size' => 1024, // compress payloads larger than 1KB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | These options control security features including payload encryption,
    | authentication, and access control for queue operations.
    |
    */

    'security' => [
        'encryption' => [
            'enabled' => env('QUEUE_ENCRYPTION', false),
            'key' => env('QUEUE_ENCRYPTION_KEY'),
            'cipher' => 'AES-256-CBC',
        ],
        'authentication' => [
            'enabled' => env('QUEUE_AUTH_ENABLED', false),
            'driver' => 'token', // token, jwt, session
            'token_header' => 'X-Queue-Auth-Token',
            'token' => env('QUEUE_AUTH_TOKEN'),
        ],
        'rate_limiting' => [
            'enabled' => env('QUEUE_RATE_LIMITING', false),
            'max_jobs_per_minute' => 1000,
            'max_jobs_per_hour' => 50000,
            'burst_allowance' => 100,
        ],
        'ip_whitelist' => [
            'enabled' => env('QUEUE_IP_WHITELIST', false),
            'allowed_ips' => explode(',', env('QUEUE_ALLOWED_IPS', '127.0.0.1,::1')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development and Debugging
    |--------------------------------------------------------------------------
    |
    | These options are useful during development and debugging to help
    | troubleshoot queue issues and optimize performance.
    |
    */

    'development' => [
        'debug' => env('QUEUE_DEBUG', false),
        'log_level' => env('QUEUE_LOG_LEVEL', 'info'), // debug, info, warning, error
        'query_logging' => env('QUEUE_QUERY_LOGGING', false),
        'profiling' => [
            'enabled' => env('QUEUE_PROFILING', false),
            'slow_job_threshold' => 10, // seconds
            'memory_profiling' => env('QUEUE_MEMORY_PROFILING', false),
        ],
        'testing' => [
            'fake_mode' => env('QUEUE_FAKE_MODE', false),
            'delay_simulation' => env('QUEUE_DELAY_SIMULATION', false),
            'failure_simulation' => env('QUEUE_FAILURE_SIMULATION', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for queue system plugins and extensions.
    |
    */

    'plugins' => [
        'discovery' => [
            'enabled' => env('QUEUE_PLUGIN_DISCOVERY', true),
            'paths' => [
                __DIR__ . '/../api/Queue/Plugins',
                __DIR__ . '/../plugins/queue',
            ],
            'auto_register' => env('QUEUE_AUTO_REGISTER_PLUGINS', true),
        ],
        'validation' => [
            'strict_mode' => env('QUEUE_STRICT_VALIDATION', true),
            'validate_on_load' => env('QUEUE_VALIDATE_ON_LOAD', true),
        ],
    ],
];
