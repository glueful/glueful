<?php

declare(strict_types=1);

namespace Glueful\Events\Webhook;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Webhook Delivered Event
 *
 * Fired when a webhook is successfully delivered to a remote endpoint.
 * Allows applications to track webhook delivery success and metrics.
 */
class WebhookDeliveredEvent extends Event
{
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly int $statusCode,
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
     * Get the HTTP status code returned
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the delivery duration in milliseconds
     */
    public function getDurationMs(): float
    {
        return $this->durationMs;
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
