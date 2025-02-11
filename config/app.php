<?php
return [
    'env' => env('APP_ENV', 'development'),
    'debug' => env('APP_DEBUG', true),
    'dev_mode' => env('DEV_MODE', false),
    'version' => env('API_VERSION', '1.0.0'),
    'title' => env('API_TITLE', 'MAPI Documentation'),
    'docs_enabled' => env('API_DOCS_ENABLED', true),
    'list_limit' => env('LIST_LIMIT', 200),
    'debug_logging' => env('API_DEBUG_LOGGING', true),
    'api_log_file' => env('API_LOG_FILE', 'api_debug_') . date('Y-m-d') . '.log',
    'api_version' => env('API_VERSION', '1.0.0'),
    'rest_mode' => env('REST_MODE', true),
    'active_status' => env('ACTIVE_STATUS', 'active'),
    'deleted_status' => env('DELETED_STATUS', 'deleted'),
];
