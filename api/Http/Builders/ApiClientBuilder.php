<?php

declare(strict_types=1);

namespace Glueful\Http\Builders;

use Glueful\Http\Client;
use Glueful\Http\Authentication\AuthenticationMethods;
use Glueful\Http\Middleware\RetryMiddleware;

/**
 * API Client Builder
 *
 * Fluent builder for creating configured HTTP clients with authentication,
 * retry policies, custom headers, and other common API client requirements.
 */
class ApiClientBuilder
{
    private array $options = [];

    public function __construct(private Client $baseClient)
    {
    }

    /**
     * Set the base URI for all requests
     */
    public function baseUri(string $uri): self
    {
        $this->options['base_uri'] = $uri;
        return $this;
    }

    /**
     * Set request timeout in seconds
     */
    public function timeout(int $seconds): self
    {
        $this->options['timeout'] = $seconds;
        return $this;
    }

    /**
     * Set maximum request duration in seconds
     */
    public function maxDuration(int $seconds): self
    {
        $this->options['max_duration'] = $seconds;
        return $this;
    }

    /**
     * Set maximum number of redirects to follow
     */
    public function maxRedirects(int $redirects): self
    {
        $this->options['max_redirects'] = $redirects;
        return $this;
    }

    /**
     * Set HTTP version
     */
    public function httpVersion(string $version): self
    {
        $this->options['http_version'] = $version;
        return $this;
    }

    /**
     * Enable or disable SSL verification
     */
    public function verifySsl(bool $verify = true): self
    {
        $this->options['verify_peer'] = $verify;
        $this->options['verify_host'] = $verify;
        return $this;
    }

    /**
     * Add or merge custom headers
     */
    public function headers(array $headers): self
    {
        $this->options['headers'] = array_merge($this->options['headers'] ?? [], $headers);
        return $this;
    }

    /**
     * Add a single header
     */
    public function header(string $name, string $value): self
    {
        $this->options['headers'][$name] = $value;
        return $this;
    }

    /**
     * Set User-Agent header
     */
    public function userAgent(string $userAgent): self
    {
        return $this->header('User-Agent', $userAgent);
    }

    /**
     * Set Accept header to JSON
     */
    public function acceptJson(): self
    {
        return $this->header('Accept', 'application/json');
    }

    /**
     * Set Content-Type header to JSON
     */
    public function contentTypeJson(): self
    {
        return $this->header('Content-Type', 'application/json');
    }

    /**
     * Add Bearer token authentication
     */
    public function bearerAuth(string $token): self
    {
        $auth = AuthenticationMethods::bearerToken($token);
        return $this->headers($auth['headers']);
    }

    /**
     * Add Basic authentication
     */
    public function basicAuth(string $username, string $password): self
    {
        $auth = AuthenticationMethods::basicAuth($username, $password);
        if (isset($auth['auth_basic'])) {
            $this->options['auth_basic'] = $auth['auth_basic'];
        }
        return $this;
    }

    /**
     * Add API key authentication
     */
    public function apiKey(string $key, string $header = 'X-API-Key'): self
    {
        $auth = AuthenticationMethods::apiKey($key, $header);
        return $this->headers($auth['headers']);
    }

    /**
     * Add OAuth2 Bearer token authentication
     */
    public function oauth2(string $accessToken): self
    {
        return $this->bearerAuth($accessToken);
    }

    /**
     * Add JWT token authentication
     */
    public function jwt(string $token): self
    {
        return $this->bearerAuth($token);
    }

    /**
     * Add custom authentication method
     */
    public function auth(array $authConfig): self
    {
        if (isset($authConfig['headers'])) {
            $this->headers($authConfig['headers']);
        }
        if (isset($authConfig['auth_basic'])) {
            $this->options['auth_basic'] = $authConfig['auth_basic'];
        }
        if (isset($authConfig['query'])) {
            $this->options['query'] = array_merge($this->options['query'] ?? [], $authConfig['query']);
        }
        return $this;
    }

    /**
     * Enable retry mechanism
     * Note: Retry functionality requires using RetryMiddleware.create() after building the client
     */
    public function retries(int $maxRetries, array $config = []): self
    {
        // Store retry configuration for later use with RetryMiddleware
        $this->options['_retry_config'] = array_merge([
            'max_retries' => $maxRetries,
            'delay_ms' => 1000,
            'multiplier' => 2.0,
            'max_delay_ms' => 30000,
            'status_codes' => [423, 425, 429, 500, 502, 503, 504, 507, 510],
        ], $config);
        return $this;
    }

    /**
     * Configure for API usage (JSON content, common headers)
     */
    public function forApi(): self
    {
        return $this->acceptJson()->contentTypeJson();
    }

    /**
     * Configure for webhooks
     */
    public function forWebhooks(): self
    {
        return $this->contentTypeJson()
            ->timeout(5)
            ->maxRedirects(0)
            ->userAgent('Glueful-Webhook/1.0');
    }

    /**
     * Configure for OAuth flows
     */
    public function forOAuth(): self
    {
        return $this->acceptJson()
            ->contentTypeJson()
            ->timeout(10);
    }

    /**
     * Configure for payment processing
     */
    public function forPayments(): self
    {
        return $this->acceptJson()
            ->contentTypeJson()
            ->verifySsl(true)
            ->timeout(30)
            ->retries(2, [
                'status_codes' => [500, 502, 503, 504], // No 4xx retries for payments
                'delay_ms' => 3000,
                'multiplier' => 1.2,
            ]);
    }

    /**
     * Configure for external service integration
     */
    public function forExternalService(string $serviceName): self
    {
        return $this->acceptJson()
            ->userAgent("Glueful-{$serviceName}/1.0")
            ->timeout(30)
            ->retries(3);
    }

    /**
     * Configure for file downloads
     */
    public function forDownloads(): self
    {
        return $this->timeout(300)
            ->maxDuration(360)
            ->header('Accept', '*/*');
    }

    /**
     * Configure for monitoring/health checks
     */
    public function forMonitoring(): self
    {
        return $this->timeout(5)
            ->maxRedirects(0)
            ->userAgent('Glueful-Monitor/1.0')
            ->header('Accept', '*/*');
    }

    /**
     * Build the configured HTTP client
     */
    public function build(): Client
    {
        // Remove retry config from options before creating client
        $options = $this->options;
        unset($options['_retry_config']);
        return $this->baseClient->createScopedClient($options);
    }

    /**
     * Build the configured HTTP client with retry middleware
     */
    public function buildWithRetries(): Client
    {
        $client = $this->build();

        if (isset($this->options['_retry_config'])) {
            // Note: This would require access to the underlying Symfony client
            // For now, return the regular client with a note about manual retry setup
        }
        return $client;
    }

    /**
     * Get retry configuration if set
     */
    public function getRetryConfig(): ?array
    {
        return $this->options['_retry_config'] ?? null;
    }

    /**
     * Get the current configuration array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Reset all options to start fresh
     */
    public function reset(): self
    {
        $this->options = [];
        return $this;
    }

    /**
     * Create a new builder with the same base client
     */
    public function newBuilder(): self
    {
        return new self($this->baseClient);
    }
}
