<?php

return [
    // System-level configuration
    'paths' => [
        'extensions_dir' =>  dirname(__DIR__) . '/extensions',
        'cache_dir' =>  dirname(__DIR__) . '/storage/cache/extensions'
    ],

    // Default settings (used if extensions.json is missing)
    'defaults' => [
        'enabled' => ['EmailNotification'], // Core extensions always enabled
    ],

    // Security settings
    'security' => [
        'allow_remote_installation' => env('ALLOW_REMOTE_EXTENSIONS', false),
        'verify_signatures' => env('VERIFY_EXTENSION_SIGNATURES', true),
    ],

    // Extensions.json location (can be customized)
    'config_file' =>  dirname(__DIR__) . '/extensions/extensions.json',

    // Environment mappings
    'environments' => [
        'local' => 'development',
        'dev' => 'development',
        'staging' => 'staging',
        'prod' => 'production',
    ]
];
