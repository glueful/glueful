<?php
return [
    'driver' => env('DB_DRIVER', 'mysql'),
    'default_db_connection_index' => env('DEFAULT_DATABASE_CONNECTION_INDEX', 'primary'),
    'primary' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', 3306),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASSWORD', 'root'),
        'db'   => env('DB_DATABASE', 'parp'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'strict'    => true,
    ],
];
