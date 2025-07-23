<?php

declare(strict_types=1);

namespace Glueful\Events\Webhook;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Webhook Failed Event
 *
 * Fired when a webhook delivery fails due to network errors, HTTP errors,
 * or other delivery issues. Allows applications to track failures and implement
 * fallback mechanisms.
 */
class WebhookFailedEvent extends Event
{
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly int $statusCode,
        public readonly string $reason,
        public readonly float $durationMs
    ) {
    }

    /**
     * Get the webhook endpoint URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the webhook payload
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Get the HTTP status code (0 for network errors)
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the failure reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get the attempt duration in milliseconds
     */
    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    /**
     * Check if this was a network error
     */
    public function isNetworkError(): bool
    {
        return $this->statusCode === 0;
    }

    /**
     * Check if this was a client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if this was a server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Get the host from the webhook URL
     */
    public function getHost(): ?string
    {
        $parsed = parse_url($this->url);
        return $parsed['host'] ?? null;
    }

    /**
     * Get the payload size in bytes
     */
    public function getPayloadSize(): int
    {
        return strlen(json_encode($this->payload));
    }
}
