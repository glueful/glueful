<?php
return [
    'base' => env('../'),
    'api_base_directory' => '../api/',
    'api_base_url' => env('API_BASE_URL', 'http://localhost/mapi/api/'),
    'cdn' => env('CDN_BASE_URL', 'http://localhost/cdn/'),
    'domain' => env('WEBSITE_DOMAIN', 'http://localhost/mapi/'),
    'uploads' => '../cdn/',
    'logs' =>'../logs/',
    'cache' => '../cache/',
    'json_definitions' => '../api/api-json-definitions/',
    'api_library' => '../api/api-library/',
    'api_extensions' => '../api/api-extensions/',
    'project_extensions' => '../extensions/',
    'api_docs' => '../docs/',

];
