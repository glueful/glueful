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
        ]
    ]
];