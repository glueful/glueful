<?php
return [
    'base' => dirname(__DIR__),
    'api_base_directory' => dirname(__DIR__) . '/api/',
    'api_base_url' => env('API_BASE_URL', 'http://localhost/glueful/api/'),
    'cdn' => env('CDN_BASE_URL', 'http://localhost/cdn/'),
    'domain' => env('WEBSITE_DOMAIN', 'http://localhost/glueful/'),
    'uploads' => dirname(__DIR__) . '/cdn/',
    'logs' => dirname(__DIR__) . '/logs/',
    'cache' => dirname(__DIR__) . '/cache/',
    'json_definitions' => dirname(__DIR__) . '/api/api-json-definitions/',
    'api_library' => dirname(__DIR__) . '/api/api-library/',
    'api_extensions' => dirname(__DIR__) . '/api/api-extensions/',
    'project_extensions' => dirname(__DIR__) . '/extensions/',
    'api_docs' => dirname(__DIR__) . '/docs/',

];
