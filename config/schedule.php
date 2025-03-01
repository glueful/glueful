<?php

return [
    // Core system jobs
    'jobs' => [
        [
            'name' => 'session-cleanup',
            'schedule' => '0 0 * * *',  // Daily at midnight
            'handler_class' => 'Glueful\\Cron\\SessionCleaner',
            'parameters' => [],
            'description' => 'Cleans up expired access and refresh tokens from the database.',
            'enabled' => true,
            'persistence' => false,
        ],
        [
            'name' => 'backup',
            'parameters' => [],
            'enabled' => env('DB_BACKUP_ENABLED', false), // Enable auto-backups
            'handler_class' =>'', 
            'schedule' => '0 0 * * *',                    // Backup schedule (cron)
            'path' => dirname(__DIR__) . '/storage/backups', // Backup storage path
            'compress' => true,                           // Compress backups
            'retention_days' => 7,  
            'description' => '',                          // Backup retention period
            'persistence' => false,                   // Backup retention period
        ],
    ]
];