<?php

declare(strict_types=1);

namespace Glueful\Http\Configuration;

/**
 * HTTP Client Configuration
 *
 * Value object representing HTTP client configuration options.
 * Provides type-safe configuration management and validation.
 */
class HttpClientConfig
{
    public function __construct(
        public int $timeout = 30,
        public int $maxDuration = 60,
        public int $maxRedirects = 3,
        public string $httpVersion = '2.0',
        public bool $verifySsl = true,
        public array $defaultHeaders = [],
        public ?string $baseUri = null,
        public array $retryConfig = []
    ) {
    }

    /**
     * Create configuration from array
     */
    public static function fromArray(array $config): self
    {
        return new self(
            timeout: $config['timeout'] ?? 30,
            maxDuration: $config['max_duration'] ?? 60,
            maxRedirects: $config['max_redirects'] ?? 3,
            httpVersion: $config['http_version'] ?? '2.0',
            verifySsl: $config['verify_ssl'] ?? true,
            defaultHeaders: $config['default_headers'] ?? [],
            baseUri: $config['base_uri'] ?? null,
            retryConfig: $config['retry'] ?? []
        );
    }

    /**
     * Convert configuration to Symfony HttpClient format
     */
    public function toSymfonyOptions(): array
    {
        return [
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
            'max_redirects' => $this->maxRedirects,
            'http_version' => $this->httpVersion,
            'verify_peer' => $this->verifySsl,
            'verify_host' => $this->verifySsl,
            'headers' => $this->defaultHeaders,
            'base_uri' => $this->baseUri,
        ];
    }

    /**
     * Convert to array format
     */
    public function toArray(): array
    {
        return [
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
            'max_redirects' => $this->maxRedirects,
            'http_version' => $this->httpVersion,
            'verify_ssl' => $this->verifySsl,
            'default_headers' => $this->defaultHeaders,
            'base_uri' => $this->baseUri,
            'retry' => $this->retryConfig,
        ];
    }

    /**
     * Create configuration for OAuth clients
     */
    public static function forOAuth(?string $baseUri = null): self
    {
        return new self(
            timeout: 10,
            defaultHeaders: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            baseUri: $baseUri
        );
    }

    /**
     * Create configuration for webhook clients
     */
    public static function forWebhooks(): self
    {
        return new self(
            timeout: 5,
            maxRedirects: 0,
            defaultHeaders: [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Glueful-Webhook/1.0',
            ]
        );
    }

    /**
     * Create configuration for API clients
     */
    public static function forApi(string $baseUri, ?string $apiKey = null): self
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return new self(
            timeout: 30,
            baseUri: $baseUri,
            defaultHeaders: $headers
        );
    }


    /**
     * Validate configuration values
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->timeout <= 0) {
            $errors[] = 'Timeout must be greater than 0';
        }

        if ($this->maxDuration <= 0) {
            $errors[] = 'Max duration must be greater than 0';
        }

        if ($this->maxRedirects < 0) {
            $errors[] = 'Max redirects cannot be negative';
        }

        if (!in_array($this->httpVersion, ['1.0', '1.1', '2.0'])) {
            $errors[] = 'HTTP version must be 1.0, 1.1, or 2.0';
        }

        if ($this->baseUri && !filter_var($this->baseUri, FILTER_VALIDATE_URL)) {
            $errors[] = 'Base URI must be a valid URL';
        }

        return $errors;
    }

    /**
     * Check if configuration is valid
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Merge with another configuration
     */
    public function merge(HttpClientConfig $other): self
    {
        return new self(
            timeout: $other->timeout !== 30 ? $other->timeout : $this->timeout,
            maxDuration: $other->maxDuration !== 60 ? $other->maxDuration : $this->maxDuration,
            maxRedirects: $other->maxRedirects !== 3 ? $other->maxRedirects : $this->maxRedirects,
            httpVersion: $other->httpVersion !== '2.0' ? $other->httpVersion : $this->httpVersion,
            verifySsl: $other->verifySsl !== true ? $other->verifySsl : $this->verifySsl,
            defaultHeaders: array_merge($this->defaultHeaders, $other->defaultHeaders),
            baseUri: $other->baseUri ?? $this->baseUri,
            retryConfig: array_merge($this->retryConfig, $other->retryConfig)
        );
    }
}
