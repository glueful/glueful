<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\CloudflareAdapter\CloudflareCDNAdapter;
use Glueful\Cache\CDN\CDNAdapterInterface;

/**
 * CloudflareAdapter Extension
 *
 * Provides integration with Cloudflare CDN for Edge Caching functionality.
 * This extension serves as a reference implementation for the CDN adapter pattern.
 *
 * @description Cloudflare CDN adapter for EdgeCache service
 * @version 1.0.0
 */
class CloudflareAdapter extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];

    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::$config = require __DIR__ . '/config.php';
        }
    }

    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        // Register the Cloudflare CDN adapter
        self::registerCDNAdapter();
    }

    /**
     * Register all CDN adapters provided by this extension
     *
     * @return array Associative array of provider names to adapter class names
     */
    public static function registerCDNAdapters(): array
    {
        return [
            'cloudflare' => CloudflareCDNAdapter::class
        ];
    }

    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // No middleware needed for this extension
    }

    /**
     * Process extension requests
     *
     * @param array $queryParams GET parameters
     * @param array $bodyParams POST parameters
     * @return array Response data
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        // If testing the connection, try to connect to Cloudflare
        if (isset($queryParams['action']) && $queryParams['action'] === 'test_connection') {
            return self::testConnection();
        }

        // If purging cache, handle that
        if (isset($queryParams['action']) && $queryParams['action'] === 'purge_cache') {
            return self::purgeCache($queryParams, $bodyParams);
        }

        // Default response
        return [
            'success' => true,
            'data' => [
                'extension' => 'CloudflareAdapter',
                'message' => 'Cloudflare CDN adapter is installed and ready for use',
                'provider' => 'cloudflare'
            ]
        ];
    }

    /**
     * Register this adapter with the CDN Adapter Manager
     */
    private static function registerCDNAdapter(): void
    {
        // Note: No need to manually register the adapter here
        // The CDNAdapterManager trait will discover the adapter
        // through the registerCDNAdapters() method
    }

    /**
     * Test the connection to Cloudflare API
     *
     * @return array Test result
     */
    private static function testConnection(): array
    {
        $config = self::$config['provider_config'] ?? [];

        if (empty($config['api_token']) || empty($config['zone_id'])) {
            return [
                'success' => false,
                'message' => 'Cloudflare adapter not configured: missing api_token or zone_id'
            ];
        }

        $adapter = new CloudflareCDNAdapter($config);
        $status = $adapter->getStatus();

        if ($status['status'] === 'connected') {
            return [
                'success' => true,
                'message' => 'Successfully connected to Cloudflare API',
                'data' => $status
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to connect to Cloudflare API',
            'data' => $status
        ];
    }

    /**
     * Purge cache based on specified parameters
     *
     * @param array $queryParams Query parameters
     * @param array $bodyParams Body parameters
     * @return array Purge result
     */
    private static function purgeCache(array $queryParams, array $bodyParams): array
    {
        $config = self::$config['provider_config'] ?? [];
        $adapter = new CloudflareCDNAdapter($config);

        // Purge all cache
        if (isset($queryParams['purge_all']) && filter_var($queryParams['purge_all'], FILTER_VALIDATE_BOOLEAN)) {
            $success = $adapter->purgeAll();
            return [
                'success' => $success,
                'message' => $success ? 'Successfully purged all cache' : 'Failed to purge all cache'
            ];
        }

        // Purge by URL
        if (!empty($bodyParams['url'])) {
            $success = $adapter->purgeUrl($bodyParams['url']);
            return [
                'success' => $success,
                'message' => $success ? 'Successfully purged URL from cache' : 'Failed to purge URL from cache',
                'url' => $bodyParams['url']
            ];
        }

        // Purge by tag
        if (!empty($bodyParams['tag'])) {
            $success = $adapter->purgeByTag($bodyParams['tag']);
            return [
                'success' => $success,
                'message' => $success ? 'Successfully purged tag from cache' : 'Failed to purge tag from cache',
                'tag' => $bodyParams['tag']
            ];
        }

        return [
            'success' => false,
            'message' => 'No purge parameters specified'
        ];
    }

    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'CloudflareAdapter',
            'description' => 'Cloudflare CDN adapter for EdgeCache service',
            'version' => '1.0.0',
            'author' => 'Glueful team',
            'type' => 'optional',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.1.0',
                'extensions' => []
            ]
        ];
    }

    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];

        // Check if configuration exists
        if (!file_exists(__DIR__ . '/config.php')) {
            $healthy = false;
            $issues[] = 'Configuration file missing: Create a config.php file in the extension directory';
        } else {
            // Check if configuration is valid
            $config = require __DIR__ . '/config.php';

            if (empty($config['provider_config']['api_token'])) {
                $healthy = false;
                $issues[] = 'Missing Cloudflare API token in configuration';
            }

            if (empty($config['provider_config']['zone_id'])) {
                $healthy = false;
                $issues[] = 'Missing Cloudflare Zone ID in configuration';
            }
        }

        // Check if all required classes exist
        if (!class_exists(CloudflareCDNAdapter::class)) {
            $healthy = false;
            $issues[] = 'CloudflareCDNAdapter class not found';
        }

        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }

    /**
     * Get extension dependencies
     */
    public static function getDependencies(): array
    {
        return [];
    }
}
