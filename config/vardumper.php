<?php

declare(strict_types=1);

/**
 * VarDumper Configuration
 *
 * Configuration options for Symfony VarDumper integration.
 * Only applies in development environment.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enable VarDumper
    |--------------------------------------------------------------------------
    |
    | Whether to enable VarDumper in development environment.
    | Automatically disabled in production regardless of this setting.
    |
    */
    'enabled' => env('VARDUMPER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for VarDumper server mode (optional).
    | Allows dumping to a separate server process.
    |
    */
    'server' => [
        'enabled' => env('VARDUMPER_SERVER_ENABLED', false),
        'host' => env('VARDUMPER_SERVER_HOST', 'tcp://127.0.0.1:9912'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloner Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the VarCloner component.
    |
    */
    'cloner' => [
        'max_items' => env('VARDUMPER_MAX_ITEMS', 2500),
        'max_string' => env('VARDUMPER_MAX_STRING', -1),
        'min_depth' => env('VARDUMPER_MIN_DEPTH', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dumper Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for CLI and HTML dumpers.
    |
    */
    'dumpers' => [
        'cli' => [
            'colors' => env('VARDUMPER_CLI_COLORS', true),
            'max_string_width' => env('VARDUMPER_CLI_MAX_STRING_WIDTH', 0),
        ],
        'html' => [
            'theme' => env('VARDUMPER_HTML_THEME', 'dark'),
            'file_link_format' => env('VARDUMPER_FILE_LINK_FORMAT', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where dumps should be output.
    |
    */
    'output' => [
        'stream' => env('VARDUMPER_OUTPUT_STREAM', 'php://output'),
        'charset' => env('VARDUMPER_CHARSET', 'UTF-8'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Configuration
    |--------------------------------------------------------------------------
    |
    | Configure contextual information to include with dumps.
    |
    */
    'context' => [
        'include_request_info' => env('VARDUMPER_INCLUDE_REQUEST', true),
        'include_trace' => env('VARDUMPER_INCLUDE_TRACE', false),
        'trace_limit' => env('VARDUMPER_TRACE_LIMIT', 10),
    ],
];