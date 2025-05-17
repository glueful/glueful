<?php

/**
 * Cloudflare CDN adapter configuration
 */

return [
    // Provider-specific configuration
    'provider_config' => [
        // Cloudflare API token with appropriate permissions
        // (Required: Cache Purge permission for your zone)
        'api_token' => env('CLOUDFLARE_API_TOKEN', ''),

        // Cloudflare Zone ID for your domain
        'zone_id' => env('CLOUDFLARE_ZONE_ID', ''),

        // Default time-to-live for cached content in seconds
        'default_ttl' => env('CLOUDFLARE_CACHE_TTL', 3600), // Default: 1 hour

        // Is this adapter enabled?
        'enabled' => env('CLOUDFLARE_ENABLED', false),
    ],

    // Route-specific cache rules
    'rules' => [
        // Example: route-specific rules
        'api.products.index' => [
            'ttl' => 600, // 10 minutes
            'tags' => ['products', 'catalog'],
            'vary_by' => ['Accept-Language', 'Accept']
        ],

        // Example: wildcard pattern rules
        'api.products.*' => [
            'ttl' => 300, // 5 minutes
            'tags' => ['products'],
            'vary_by' => ['Accept-Language', 'Accept']
        ],

        'api.categories.*' => [
            'ttl' => 1800, // 30 minutes
            'tags' => ['categories'],
            'vary_by' => ['Accept-Language']
        ]
    ]
];
