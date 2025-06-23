<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Exception Event
 *
 * Dispatched when an exception occurs during HTTP request processing.
 * Used for error logging, monitoring, and custom error handling.
 *
 * @package Glueful\Events\Http
 */
class ExceptionEvent extends Event
{
    /**
     * @param Request $request Original HTTP request
     * @param Throwable $exception Exception that occurred
     * @param array $metadata Additional exception metadata
     */
    public function __construct(
        private readonly Request $request,
        private readonly Throwable $exception,
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
     * Get exception
     *
     * @return Throwable Exception
     */
    public function getException(): Throwable
    {
        return $this->exception;
    }

    /**
     * Get exception metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get exception message
     *
     * @return string Exception message
     */
    public function getMessage(): string
    {
        return $this->exception->getMessage();
    }

    /**
     * Get exception code
     *
     * @return int|string Exception code
     */
    public function getCode(): int|string
    {
        return $this->exception->getCode();
    }

    /**
     * Get exception class name
     *
     * @return string Exception class
     */
    public function getExceptionClass(): string
    {
        return get_class($this->exception);
    }

    /**
     * Get file where exception occurred
     *
     * @return string File path
     */
    public function getFile(): string
    {
        return $this->exception->getFile();
    }

    /**
     * Get line where exception occurred
     *
     * @return int Line number
     */
    public function getLine(): int
    {
        return $this->exception->getLine();
    }

    /**
     * Get exception trace
     *
     * @return array Stack trace
     */
    public function getTrace(): array
    {
        return $this->exception->getTrace();
    }

    /**
     * Get exception trace as string
     *
     * @return string Stack trace string
     */
    public function getTraceAsString(): string
    {
        return $this->exception->getTraceAsString();
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
     * Get request URI
     *
     * @return string Request URI
     */
    public function getRequestUri(): string
    {
        return $this->request->getRequestUri();
    }

    /**
     * Get request method
     *
     * @return string HTTP method
     */
    public function getRequestMethod(): string
    {
        return $this->request->getMethod();
    }

    /**
     * Check if exception is critical
     *
     * @return bool True if critical
     */
    public function isCritical(): bool
    {
        return $this->metadata['critical'] ?? $this->isServerError();
    }

    /**
     * Check if exception represents a server error
     *
     * @return bool True if server error
     */
    public function isServerError(): bool
    {
        $code = $this->getCode();
        return is_int($code) && $code >= 500 && $code < 600;
    }

    /**
     * Check if exception represents a client error
     *
     * @return bool True if client error
     */
    public function isClientError(): bool
    {
        $code = $this->getCode();
        return is_int($code) && $code >= 400 && $code < 500;
    }

    /**
     * Get processing time until exception
     *
     * @return float|null Processing time in seconds
     */
    public function getProcessingTime(): ?float
    {
        return $this->metadata['processing_time'] ?? null;
    }

    /**
     * Get memory usage at time of exception
     *
     * @return int|null Memory usage in bytes
     */
    public function getMemoryUsage(): ?int
    {
        return $this->metadata['memory_usage'] ?? null;
    }
}