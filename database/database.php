<?php

return [
    'default' => getenv('DB_ENV') ?: 'development',
    
    'connections' => [
        'development' => [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'glueful_dev',
            'username' => 'postgres',
            'password' => 'postgres',
            'charset' => 'utf8',
            'prefix' => '',
        ],
        'testing' => [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'glueful_test',
            'username' => 'postgres',
            'password' => 'postgres',
            'charset' => 'utf8',
            'prefix' => '',
        ],
        'production' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_HOST'),
            'database' => getenv('DB_NAME'),
            'username' => getenv('DB_USER'),
            'password' => getenv('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
        ],
    ]
];
