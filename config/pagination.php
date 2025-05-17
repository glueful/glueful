<?php

/**
 * Pagination Configuration
 *
 * Defines global pagination settings for API result sets.
 * Controls default behavior and limits for paginated responses.
 */
return [
    // Enable/disable pagination globally
    'enabled' => env('PAGINATION_ENABLED', true),       // Master switch for pagination

    // Default number of items per page
    'default_size' => env('PAGINATION_DEFAULT_SIZE', 25), // Items shown if not specified

    // Maximum allowed items per page
    'max_size' => env('PAGINATION_MAX_SIZE', 100),     // Upper limit for page size

    'list_limit'=> env('PAGINATION_LIST_LIMIT', 1000), // Maximum number of items in a list
];