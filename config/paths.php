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
    'api_extensions' => dirname(__DIR__) . '/api/api-extensions/', // API extensions
    'api_docs' => dirname(__DIR__) . '/docs/',                 // API documentation
    
    // Content delivery paths
    'cdn' => env('API_BASE_URL'). '/storage/cdn/',    // CDN base URL
    'domain' => env('API_BASE_URL', 'http://localhost/'), // Website domain
    'api_base_url' => env('API_BASE_URL'), // API base URL
    'uploads' => dirname(__DIR__) . '/storage/cdn/',                   // File upload directory
    
    // System paths
    'logs' => dirname(__DIR__) . '/storage/logs/',                     // Log files directory
    'cache' => dirname(__DIR__) . '/storage/cache/',                   // Cache directory
    'json_definitions' => dirname(__DIR__) . '/api/api-json-definitions/', // API definitions
    'project_extensions' => dirname(__DIR__) . '/extensions/', // Project extensions
];
