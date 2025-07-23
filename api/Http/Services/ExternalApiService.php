<?php

declare(strict_types=1);

namespace Glueful\Http\Services;

use Glueful\Http\Client;
use Glueful\Http\Builders\ApiClientBuilder;
use Psr\Log\LoggerInterface;

/**
 * External API Service
 *
 * Helper service for common external API integration patterns including
 * rate limiting, caching, error handling, and response transformation.
 */
class ExternalApiService
{
    private array $cachedResponses = [];
    private array $rateLimitCounters = [];

    public function __construct(
        private Client $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Make a cached API call
     */
    public function cachedApiCall(
        string $cacheKey,
        string $method,
        string $url,
        array $options = [],
        int $cacheTtl = 300
    ): array {
        // Check cache first
        if (isset($this->cachedResponses[$cacheKey])) {
            $cached = $this->cachedResponses[$cacheKey];
            if (time() - $cached['timestamp'] < $cacheTtl) {
                $this->logger->debug('Returning cached API response', [
                    'cache_key' => $cacheKey,
                    'url' => $url
                ]);
                return $cached['data'];
            }
        }

        // Make API call
        $response = $this->httpClient->request($method, $url, $options);
        $data = $response->toArray();

        // Cache response
        $this->cachedResponses[$cacheKey] = [
            'data' => $data,
            'timestamp' => time()
        ];

        return $data;
    }

    /**
     * Make API call with rate limiting
     */
    public function rateLimitedApiCall(
        string $rateLimitKey,
        int $maxRequests,
        int $windowSeconds,
        string $method,
        string $url,
        array $options = []
    ): array {
        // Check rate limit
        if (!$this->checkRateLimit($rateLimitKey, $maxRequests, $windowSeconds)) {
            throw new \Exception("Rate limit exceeded for key: {$rateLimitKey}");
        }

        // Make API call
        $response = $this->httpClient->request($method, $url, $options);

        // Update rate limit counter
        $this->updateRateLimit($rateLimitKey);

        return $response->toArray();
    }

    /**
     * Make API call with automatic retry and exponential backoff
     */
    public function retryApiCall(
        string $method,
        string $url,
        array $options = [],
        int $maxRetries = 3,
        int $baseDelayMs = 1000
    ): array {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $response = $this->httpClient->request($method, $url, $options);

                if ($response->isSuccessful()) {
                    if ($attempt > 1) {
                        $this->logger->info('API call succeeded after retry', [
                            'url' => $url,
                            'attempt' => $attempt
                        ]);
                    }
                    return $response->toArray();
                }

                // Treat non-successful responses as exceptions for retry logic
                throw new \Exception("HTTP {$response->getStatusCode()} error");
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $delay = $baseDelayMs * pow(2, $attempt - 1);
                    usleep($delay * 1000);

                    $this->logger->warning('API call failed, retrying', [
                        'url' => $url,
                        'attempt' => $attempt,
                        'max_attempts' => $maxRetries,
                        'delay_ms' => $delay,
                        'error' => $e->getMessage()
                    ]);
                }

                $attempt++;
            }
        }

        $this->logger->error('API call failed after all retries', [
            'url' => $url,
            'attempts' => $maxRetries,
            'error' => $lastException->getMessage()
        ]);

        throw $lastException;
    }

    /**
     * Make paginated API calls and collect all results
     */
    public function paginatedApiCall(
        string $baseUrl,
        array $options = [],
        string $pageParam = 'page',
        string $dataKey = 'data',
        int $maxPages = 100
    ): array {
        $allData = [];
        $page = 1;
        $hasMorePages = true;

        while ($hasMorePages && $page <= $maxPages) {
            $options['query'][$pageParam] = $page;

            $response = $this->httpClient->get($baseUrl, $options);
            $data = $response->toArray();

            if (isset($data[$dataKey]) && is_array($data[$dataKey])) {
                $allData = array_merge($allData, $data[$dataKey]);

                // Check if there are more pages
                $hasMorePages = !empty($data[$dataKey]);

                // Some APIs provide pagination metadata
                if (isset($data['has_more'])) {
                    $hasMorePages = $data['has_more'];
                } elseif (isset($data['next'])) {
                    $hasMorePages = !empty($data['next']);
                }
            } else {
                $hasMorePages = false;
            }

            $page++;
        }

        $this->logger->info('Paginated API call completed', [
            'url' => $baseUrl,
            'pages_fetched' => $page - 1,
            'total_items' => count($allData)
        ]);

        return $allData;
    }

    /**
     * Transform API response data
     */
    public function transformApiResponse(array $data, array $mapping): array
    {
        $transformed = [];

        foreach ($mapping as $targetKey => $sourceKey) {
            if (is_callable($sourceKey)) {
                $transformed[$targetKey] = $sourceKey($data);
            } elseif (is_string($sourceKey) && isset($data[$sourceKey])) {
                $transformed[$targetKey] = $data[$sourceKey];
            } elseif (is_array($sourceKey)) {
                // Handle nested mapping
                $nestedData = $data;
                foreach ($sourceKey as $key) {
                    if (isset($nestedData[$key])) {
                        $nestedData = $nestedData[$key];
                    } else {
                        $nestedData = null;
                        break;
                    }
                }
                $transformed[$targetKey] = $nestedData;
            }
        }

        return $transformed;
    }

    /**
     * Check rate limit
     */
    private function checkRateLimit(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();

        if (!isset($this->rateLimitCounters[$key])) {
            $this->rateLimitCounters[$key] = [
                'count' => 0,
                'window_start' => $now
            ];
        }

        $counter = &$this->rateLimitCounters[$key];

        // Reset counter if window has expired
        if ($now - $counter['window_start'] >= $windowSeconds) {
            $counter['count'] = 0;
            $counter['window_start'] = $now;
        }

        return $counter['count'] < $maxRequests;
    }

    /**
     * Update rate limit counter
     */
    private function updateRateLimit(string $key): void
    {
        if (isset($this->rateLimitCounters[$key])) {
            $this->rateLimitCounters[$key]['count']++;
        }
    }

    /**
     * Clear cached responses
     */
    public function clearCache(?string $cacheKey = null): void
    {
        if ($cacheKey === null) {
            $this->cachedResponses = [];
        } else {
            unset($this->cachedResponses[$cacheKey]);
        }
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        return [
            'cached_entries' => count($this->cachedResponses),
            'rate_limit_keys' => count($this->rateLimitCounters),
            'memory_usage' => memory_get_usage(true)
        ];
    }

    /**
     * Create an API client builder for external service
     */
    public function createApiBuilder(string $serviceName): ApiClientBuilder
    {
        $builder = new ApiClientBuilder($this->httpClient);
        return $builder
            ->userAgent("Glueful-{$serviceName}/1.0")
            ->acceptJson()
            ->timeout(30);
    }
}
