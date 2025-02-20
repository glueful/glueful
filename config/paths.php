<?php

/**
 * Application Path Configuration
 * 
 * Defines system paths and URLs for various components.
 * All filesystem paths are absolute from project root.
 */
return [
    // Core application paths
    'base' => dirname(__DIR__),                        // Project root directory
    
    // API related paths
    'api_base_directory' => dirname(__DIR__) . '/api/',        // API root directory
    'api_base_url' => env('API_BASE_URL', 'http://localhost/glueful/api/'), // API base URL
    'api_library' => dirname(__DIR__) . '/api/api-library/',   // API library classes
    'api_extensions' => dirname(__DIR__) . '/api/api-extensions/', // API extensions
    'api_docs' => dirname(__DIR__) . '/docs/',                 // API documentation
    
    // Content delivery paths
    'cdn' => env('CDN_BASE_URL', 'http://localhost/cdn/'),    // CDN base URL
    'domain' => env('WEBSITE_DOMAIN', 'http://localhost/glueful/'), // Website domain
    'uploads' => dirname(__DIR__) . '/cdn/',                   // File upload directory
    
    // System paths
    'logs' => dirname(__DIR__) . '/logs/',                     // Log files directory
    'cache' => dirname(__DIR__) . '/cache/',                   // Cache directory
    'json_definitions' => dirname(__DIR__) . '/api/api-json-definitions/', // API definitions
    'project_extensions' => dirname(__DIR__) . '/extensions/', // Project extensions
];
