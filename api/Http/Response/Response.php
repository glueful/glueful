<?php

declare(strict_types=1);

namespace Glueful\Http\Response;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Glueful\Http\Exceptions\HttpResponseException;

/**
 * Enhanced HTTP Response Wrapper
 *
 * Wraps Symfony HttpClient ResponseInterface with additional convenience methods
 * and maintains compatibility with existing HttpResponse interface.
 */
class Response
{
    public function __construct(private ResponseInterface $response)
    {
    }

    /**
     * Get HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Get response content as string
     */
    public function getContent(): string
    {
        return $this->response->getContent();
    }

    /**
     * Get response body (alias for getContent)
     */
    public function getBody(): string
    {
        return $this->getContent();
    }

    /**
     * Get response contents (alias for getContent)
     */
    public function getContents(): string
    {
        return $this->getContent();
    }

    /**
     * Get all response headers
     */
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Get a specific response header
     */
    public function getHeader(string $name, $default = null)
    {
        $headers = $this->getHeaders();
        $name = strtolower($name);

        foreach ($headers as $key => $values) {
            if (strtolower($key) === $name) {
                return is_array($values) ? $values[0] : $values;
            }
        }

        return $default;
    }

    /**
     * Parse response as JSON array
     */
    public function toArray(): array
    {
        try {
            return $this->response->toArray();
        } catch (\Exception $e) {
            throw new HttpResponseException('Failed to decode JSON response: ' . $e->getMessage());
        }
    }

    /**
     * Parse response as JSON (compatible with existing HttpResponse)
     */
    public function json(bool $assoc = true, int $depth = 512, int $options = 0)
    {
        try {
            return json_decode($this->getContent(), $assoc, $depth, $options | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpResponseException('Failed to decode JSON response: ' . $e->getMessage());
        }
    }

    /**
     * Check if response was successful (2xx status code)
     */
    public function isSuccessful(): bool
    {
        $statusCode = $this->getStatusCode();
        return $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * Check if response is a redirection (3xx status code)
     */
    public function isRedirection(): bool
    {
        $statusCode = $this->getStatusCode();
        return $statusCode >= 300 && $statusCode < 400;
    }

    /**
     * Check if response indicates a client error (4xx status code)
     */
    public function isClientError(): bool
    {
        $statusCode = $this->getStatusCode();
        return $statusCode >= 400 && $statusCode < 500;
    }

    /**
     * Check if response indicates a server error (5xx status code)
     */
    public function isServerError(): bool
    {
        $statusCode = $this->getStatusCode();
        return $statusCode >= 500;
    }

    /**
     * Save response content to file
     */
    public function saveToFile(string $filePath): void
    {
        file_put_contents($filePath, $this->getContent());
    }

    /**
     * Stream response content for large files
     *
     * Note: This is a simplified streaming implementation.
     * For true streaming with Symfony HttpClient, use HttpClientInterface::stream() directly.
     */
    public function getContentStream(): \Generator
    {
        // For now, provide a simple implementation that reads content in chunks
        // In a real streaming scenario, you would use HttpClientInterface::stream()
        $content = $this->getContent();
        $chunkSize = 8192;
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $chunk = substr($content, $offset, $chunkSize);
            yield $chunk;
            $offset += $chunkSize;
        }
    }

    /**
     * Get the underlying Symfony ResponseInterface
     */
    public function getSymfonyResponse(): ResponseInterface
    {
        return $this->response;
    }
}
