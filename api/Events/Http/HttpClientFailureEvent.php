<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Glueful\Exceptions\HttpException;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * HTTP Client Failure Event
 *
 * Fired when HTTP client operations fail, allowing applications to handle
 * business-specific logging and error handling for external service failures.
 */
class HttpClientFailureEvent extends Event
{
    /**
     * Create a new HTTP client failure event
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url The URL that failed
     * @param HttpException $exception The exception that occurred
     * @param string $failureReason Reason for failure (connection_failed, request_failed, etc.)
     * @param array $context Additional context data
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly HttpException $exception,
        public readonly string $failureReason,
        public readonly array $context = []
    ) {
    }

    /**
     * Check if this is a connection failure
     *
     * @return bool
     */
    public function isConnectionFailure(): bool
    {
        return $this->failureReason === 'connection_failed';
    }

    /**
     * Check if this is a request failure
     *
     * @return bool
     */
    public function isRequestFailure(): bool
    {
        return $this->failureReason === 'request_failed';
    }

    /**
     * Get the host from the URL
     *
     * @return string|null
     */
    public function getHost(): ?string
    {
        $parsed = parse_url($this->url);
        return $parsed['host'] ?? null;
    }

    /**
     * Get the scheme from the URL (http/https)
     *
     * @return string|null
     */
    public function getScheme(): ?string
    {
        $parsed = parse_url($this->url);
        return $parsed['scheme'] ?? null;
    }
}
