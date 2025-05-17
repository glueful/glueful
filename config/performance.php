<?php

return [
    'memory' => [
        'monitoring' => [
            'enabled' => env('MEMORY_MONITORING_ENABLED', true),
            'alert_threshold' => env('MEMORY_ALERT_THRESHOLD', 0.8),
            'critical_threshold' => env('MEMORY_CRITICAL_THRESHOLD', 0.9),
            'log_level' => env('MEMORY_LOG_LEVEL', 'warning'),
            'sample_rate' => env('MEMORY_SAMPLE_RATE', 0.01)
        ],
        'limits' => [
            'query_cache' => env('MEMORY_LIMIT_QUERY_CACHE', 1000),
            'object_pool' => env('MEMORY_LIMIT_OBJECT_POOL', 500),
            'result_limit' => env('MEMORY_LIMIT_RESULTS', 10000)
        ],
        'gc' => [
            'auto_trigger' => env('MEMORY_AUTO_GC', true),
            'threshold' => env('MEMORY_GC_THRESHOLD', 0.85)
        ]
    ]
];
