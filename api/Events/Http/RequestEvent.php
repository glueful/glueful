<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;

/**
 * Request Event
 *
 * Dispatched when an HTTP request is received and processed.
 * Used for request logging, security monitoring, and middleware processing.
 *
 * @package Glueful\Events\Http
 */
class RequestEvent extends Event
{
    /**
     * @param Request $request HTTP request object
     * @param array $metadata Additional request metadata
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get HTTP request
     *
     * @return Request HTTP request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get request metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get request method
     *
     * @return string HTTP method
     */
    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    /**
     * Get request URI
     *
     * @return string Request URI
     */
    public function getUri(): string
    {
        return $this->request->getRequestUri();
    }

    /**
     * Get client IP address
     *
     * @return string|null Client IP
     */
    public function getClientIp(): ?string
    {
        return $this->request->getClientIp();
    }

    /**
     * Get user agent
     *
     * @return string|null User agent
     */
    public function getUserAgent(): ?string
    {
        return $this->request->headers->get('User-Agent');
    }

    /**
     * Get request content type
     *
     * @return string|null Content type
     */
    public function getContentType(): ?string
    {
        return $this->request->headers->get('Content-Type');
    }

    /**
     * Check if request is AJAX
     *
     * @return bool True if AJAX
     */
    public function isXmlHttpRequest(): bool
    {
        return $this->request->isXmlHttpRequest();
    }

    /**
     * Check if request is secure (HTTPS)
     *
     * @return bool True if secure
     */
    public function isSecure(): bool
    {
        return $this->request->isSecure();
    }

    /**
     * Get request start time from metadata
     *
     * @return float|null Start time in seconds
     */
    public function getStartTime(): ?float
    {
        return $this->metadata['start_time'] ?? null;
    }

    /**
     * Get route information from metadata
     *
     * @return array|null Route data
     */
    public function getRouteInfo(): ?array
    {
        return $this->metadata['route'] ?? null;
    }
}
