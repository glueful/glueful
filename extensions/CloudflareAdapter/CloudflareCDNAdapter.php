<?php

namespace Glueful\Extensions\CloudflareAdapter;

use Glueful\Cache\CDN\AbstractCDNAdapter;

/**
 * Cloudflare CDN Adapter
 *
 * Provides integration with Cloudflare's CDN services for the Edge Cache system.
 */
class CloudflareCDNAdapter extends AbstractCDNAdapter
{
    /**
     * Base API URL for Cloudflare
     */
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * Zone ID for the Cloudflare account
     */
    private string $zoneId;

    /**
     * API token for the Cloudflare account
     */
    private string $apiToken;

    /**
     * Constructor
     *
     * @param array $config Configuration for the Cloudflare CDN adapter
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->zoneId = $config['zone_id'] ?? '';
        $this->apiToken = $config['api_token'] ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function purgeUrl(string $url): bool
    {
        // Ensure zone ID and API token are set
        if (empty($this->zoneId) || empty($this->apiToken)) {
            error_log('Cloudflare adapter not configured correctly: missing zone_id or api_token');
            return false;
        }

        // Prepare API request
        $endpoint = self::API_BASE_URL . "/zones/{$this->zoneId}/purge_cache";
        $data = [
            'files' => [$url]
        ];

        // Call Cloudflare API
        $result = $this->makeApiRequest($endpoint, 'POST', $data);

        return $result && isset($result['success']) && $result['success'] === true;
    }

    /**
     * {@inheritdoc}
     */
    public function purgeByTag(string $tag): bool
    {
        // Ensure zone ID and API token are set
        if (empty($this->zoneId) || empty($this->apiToken)) {
            error_log('Cloudflare adapter not configured correctly: missing zone_id or api_token');
            return false;
        }

        // Prepare API request
        $endpoint = self::API_BASE_URL . "/zones/{$this->zoneId}/purge_cache";
        $data = [
            'tags' => [$tag]
        ];

        // Call Cloudflare API
        $result = $this->makeApiRequest($endpoint, 'POST', $data);

        return $result && isset($result['success']) && $result['success'] === true;
    }

    /**
     * {@inheritdoc}
     */
    public function purgeAll(): bool
    {
        // Ensure zone ID and API token are set
        if (empty($this->zoneId) || empty($this->apiToken)) {
            error_log('Cloudflare adapter not configured correctly: missing zone_id or api_token');
            return false;
        }

        // Prepare API request
        $endpoint = self::API_BASE_URL . "/zones/{$this->zoneId}/purge_cache";
        $data = [
            'purge_everything' => true
        ];

        // Call Cloudflare API
        $result = $this->makeApiRequest($endpoint, 'POST', $data);

        return $result && isset($result['success']) && $result['success'] === true;
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        $baseStats = parent::getStats();

        // Ensure zone ID and API token are set
        if (empty($this->zoneId) || empty($this->apiToken)) {
            $baseStats['status'] = 'not_configured';
            return $baseStats;
        }

        // Try to get analytics from Cloudflare
        try {
            // Get basic stats for the last 24 hours
            $endpoint = self::API_BASE_URL . "/zones/{$this->zoneId}/analytics/dashboard";
            $queryParams = [
                'since' => '-1440', // Last 24 hours (in minutes)
                'until' => '0',     // Now
                'continuous' => 'false'
            ];

            $result = $this->makeApiRequest($endpoint, 'GET', null, $queryParams);

            if ($result && isset($result['success']) && $result['success'] === true) {
                $totals = $result['result']['totals'] ?? [];

                return array_merge($baseStats, [
                    'status' => 'connected',
                    'cache_hit_ratio' => $totals['cacheThroughputRatio'] ?? null,
                    'edge_requests' => $totals['requests'] ?? null,
                    'bandwidth' => $totals['bandwidth'] ?? null,
                    'threats' => $totals['threats'] ?? null,
                    'period' => '24h' // Period for these statistics
                ]);
            }
        } catch (\Exception $e) {
            error_log('Error fetching Cloudflare statistics: ' . $e->getMessage());
        }

        $baseStats['status'] = 'error';
        return $baseStats;
    }

    /**
     * {@inheritdoc}
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        $headers = parent::generateCacheHeaders($route, $contentType);

        // Add Cloudflare-specific headers
        $routeConfig = $this->getRouteConfig($route);

        // Add cache tags if configured
        if (!empty($routeConfig['tags'])) {
            $headers['Cache-Tag'] = implode(',', $routeConfig['tags']);
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return 'cloudflare';
    }

    /**
     * Make an API request to Cloudflare
     *
     * @param string $endpoint API endpoint URL
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array|null $data Request payload data
     * @param array $queryParams Query parameters to append to the URL
     * @return array|false Response data or false on error
     */
    private function makeApiRequest(
        string $endpoint,
        string $method = 'GET',
        ?array $data = null,
        array $queryParams = []
    ): array|false {
        // Add query parameters if provided
        if (!empty($queryParams)) {
            $endpoint .= '?' . http_build_query($queryParams);
        }

        // Initialize cURL
        $ch = curl_init($endpoint);

        // Set common options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // Set headers
        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Set method and data if needed
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        // Execute the request
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // Handle errors
        if ($response === false) {
            error_log('Cloudflare API request failed: ' . $error);
            return false;
        }

        // Parse response
        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = isset($result['errors']) && !empty($result['errors'])
                ? $result['errors'][0]['message'] ?? 'Unknown error'
                : 'HTTP error ' . $httpCode;

            error_log('Cloudflare API error: ' . $errorMessage);
            return false;
        }

        return $result;
    }
}
