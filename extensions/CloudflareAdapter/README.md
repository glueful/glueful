# Cloudflare CDN Adapter Extension for Glueful

This extension provides integration with Cloudflare's CDN services for the Edge Caching architecture in Glueful v0.27.0+. It serves as a reference implementation for the CDN adapter extension system.

## Installation

1. Copy the extension files to your `extensions/CloudflareAdapter` directory
2. Enable the extension in `config/extensions.php`:

```php
return [
    'enabled' => [
        // other extensions...
        'CloudflareAdapter',
    ],
];
```

3. Configure your Cloudflare credentials in your environment variables:

```
CLOUDFLARE_API_TOKEN=your_api_token
CLOUDFLARE_ZONE_ID=your_zone_id
CLOUDFLARE_ENABLED=true
CLOUDFLARE_CACHE_TTL=3600
```

## Configuration

Configure the extension by editing `extensions/CloudflareAdapter/config.php`:

```php
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
        // Add more route-specific rules as needed
    ]
];
```

## Features

- **Cache-Control Headers**: Automatically adds appropriate cache headers to responses
- **Custom TTL by Route**: Configure different cache durations for each route
- **Cache Tags**: Use Cloudflare's cache tags for granular cache invalidation
- **Cache Purging**: API endpoints for purging URLs, tags, or all cache
- **Intelligent Vary Headers**: Configure which request headers should affect caching
- **Simple Integration**: Works automatically with the Edge Cache middleware

## API Endpoints

This extension integrates with the Glueful API to provide the following endpoints:

### Test Connection

```
GET /api/extensions/CloudflareAdapter?action=test_connection
```

Tests the connection to the Cloudflare API and returns the status.

### Purge Cache

```
POST /api/extensions/CloudflareAdapter?action=purge_cache
```

Parameters:
- `purge_all=true` (query param): Purge all cache
- OR `url=https://example.com/path` (body param): Purge specific URL
- OR `tag=products` (body param): Purge by cache tag

## Creating Your Own CDN Adapter

This extension serves as a reference implementation for the CDN adapter system. To create an adapter for another CDN provider:

1. Create a new extension following this same pattern
2. Implement the `CDNAdapterInterface` interface
3. Extend the `AbstractCDNAdapter` class to inherit common functionality
4. Register your adapter in the extension's `registerServices` method

## Requirements

- Glueful v0.27.0 or higher
- PHP 8.1 or higher
- Cloudflare account with API access

## License

This extension is covered by the same license as Glueful core.

This extension is licensed under the same license as the Glueful framework.

## Author

Glueful team <>

## Support

For support, please open an issue on the GitHub repository or contact the author directly.