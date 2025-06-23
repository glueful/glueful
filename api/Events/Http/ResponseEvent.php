<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response Event
 *
 * Dispatched when an HTTP response is generated and sent.
 * Used for response logging, performance monitoring, and analytics.
 *
 * @package Glueful\Events\Http
 */
class ResponseEvent extends Event
{
    /**
     * @param Request $request Original HTTP request
     * @param Response $response HTTP response object
     * @param array $metadata Additional response metadata
     */
    public function __construct(
        private readonly Request $request,
        private readonly Response $response,
        private readonly array $metadata = []
    ) {}

    /**
     * Get original HTTP request
     *
     * @return Request HTTP request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get HTTP response
     *
     * @return Response HTTP response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Get response metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get response status code
     *
     * @return int Status code
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Get response content type
     *
     * @return string|null Content type
     */
    public function getContentType(): ?string
    {
        return $this->response->headers->get('Content-Type');
    }

    /**
     * Get response content length
     *
     * @return int|null Content length in bytes
     */
    public function getContentLength(): ?int
    {
        $contentLength = $this->response->headers->get('Content-Length');
        return $contentLength ? (int)$contentLength : strlen($this->response->getContent());
    }

    /**
     * Get request processing time
     *
     * @return float|null Processing time in seconds
     */
    public function getProcessingTime(): ?float
    {
        return $this->metadata['processing_time'] ?? null;
    }

    /**
     * Get memory usage
     *
     * @return int|null Memory usage in bytes
     */
    public function getMemoryUsage(): ?int
    {
        return $this->metadata['memory_usage'] ?? null;
    }

    /**
     * Check if response is successful (2xx)
     *
     * @return bool True if successful
     */
    public function isSuccessful(): bool
    {
        return $this->response->isSuccessful();
    }

    /**
     * Check if response is a redirect (3xx)
     *
     * @return bool True if redirect
     */
    public function isRedirection(): bool
    {
        return $this->response->isRedirection();
    }

    /**
     * Check if response is a client error (4xx)
     *
     * @return bool True if client error
     */
    public function isClientError(): bool
    {
        return $this->response->isClientError();
    }

    /**
     * Check if response is a server error (5xx)
     *
     * @return bool True if server error
     */
    public function isServerError(): bool
    {
        return $this->response->isServerError();
    }

    /**
     * Check if response was cached
     *
     * @return bool True if cached
     */
    public function isCached(): bool
    {
        return $this->metadata['cached'] ?? false;
    }

    /**
     * Get controller/action information
     *
     * @return array|null Controller and action info
     */
    public function getControllerInfo(): ?array
    {
        return $this->metadata['controller'] ?? null;
    }

    /**
     * Get database query count from metadata
     *
     * @return int Number of database queries
     */
    public function getQueryCount(): int
    {
        return $this->metadata['query_count'] ?? 0;
    }
}