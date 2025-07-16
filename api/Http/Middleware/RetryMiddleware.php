<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Retry Middleware
 *
 * Provides automatic retry functionality for HTTP requests using Symfony's
 * RetryableHttpClient with configurable retry strategies.
 */
class RetryMiddleware
{
    /**
     * Create a retryable HTTP client with the specified configuration
     */
    public static function create(HttpClientInterface $client, array $config = []): RetryableHttpClient
    {
        $strategy = new GenericRetryStrategy(
            statusCodes: $config['status_codes'] ?? [423, 425, 429, 500, 502, 503, 504, 507, 510],
            delayMs: $config['delay_ms'] ?? 1000,
            multiplier: $config['multiplier'] ?? 2.0,
            maxDelayMs: $config['max_delay_ms'] ?? 30000,
            jitter: $config['jitter'] ?? 0.1
        );

        return new RetryableHttpClient(
            $client,
            $strategy,
            maxRetries: $config['max_retries'] ?? 3
        );
    }

    /**
     * Create a retry strategy for API calls
     */
    public static function createApiRetryStrategy(): GenericRetryStrategy
    {
        return new GenericRetryStrategy(
            statusCodes: [429, 500, 502, 503, 504],
            delayMs: 1000,
            multiplier: 2.0,
            maxDelayMs: 30000,
            jitter: 0.1
        );
    }

    /**
     * Create a retry strategy for webhook deliveries
     */
    public static function createWebhookRetryStrategy(): GenericRetryStrategy
    {
        return new GenericRetryStrategy(
            statusCodes: [500, 502, 503, 504],
            delayMs: 2000,
            multiplier: 1.5,
            maxDelayMs: 15000,
            jitter: 0.2
        );
    }

    /**
     * Create a retry strategy for external service calls
     */
    public static function createExternalServiceRetryStrategy(): GenericRetryStrategy
    {
        return new GenericRetryStrategy(
            statusCodes: [423, 425, 429, 500, 502, 503, 504, 507, 510],
            delayMs: 500,
            multiplier: 2.0,
            maxDelayMs: 20000,
            jitter: 0.15
        );
    }

    /**
     * Create a conservative retry strategy for payment gateways
     */
    public static function createPaymentRetryStrategy(): GenericRetryStrategy
    {
        return new GenericRetryStrategy(
            statusCodes: [500, 502, 503, 504], // No 4xx retries for payments
            delayMs: 3000,
            multiplier: 1.2,
            maxDelayMs: 10000,
            jitter: 0.05
        );
    }

    /**
     * Create a retry configuration from array
     */
    public static function createFromConfig(array $retryConfig): array
    {
        return [
            'status_codes' => $retryConfig['status_codes'] ?? [423, 425, 429, 500, 502, 503, 504, 507, 510],
            'delay_ms' => $retryConfig['delay_ms'] ?? 1000,
            'multiplier' => $retryConfig['multiplier'] ?? 2.0,
            'max_delay_ms' => $retryConfig['max_delay_ms'] ?? 30000,
            'max_retries' => $retryConfig['max_retries'] ?? 3,
            'jitter' => $retryConfig['jitter'] ?? 0.1,
        ];
    }

    /**
     * Validate retry configuration
     */
    public static function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['max_retries']) && ($config['max_retries'] < 0 || $config['max_retries'] > 10)) {
            $errors[] = 'max_retries must be between 0 and 10';
        }

        if (isset($config['delay_ms']) && ($config['delay_ms'] < 0 || $config['delay_ms'] > 60000)) {
            $errors[] = 'delay_ms must be between 0 and 60000';
        }

        if (isset($config['multiplier']) && ($config['multiplier'] < 1.0 || $config['multiplier'] > 5.0)) {
            $errors[] = 'multiplier must be between 1.0 and 5.0';
        }

        if (isset($config['max_delay_ms']) && ($config['max_delay_ms'] < 1000 || $config['max_delay_ms'] > 300000)) {
            $errors[] = 'max_delay_ms must be between 1000 and 300000';
        }

        if (isset($config['jitter']) && ($config['jitter'] < 0.0 || $config['jitter'] > 1.0)) {
            $errors[] = 'jitter must be between 0.0 and 1.0';
        }

        return $errors;
    }
}
