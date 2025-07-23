<?php

declare(strict_types=1);

namespace Glueful\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request Context Service
 *
 * Provides abstracted access to request data, eliminating direct superglobal usage.
 * All request-related data should be accessed through this service.
 *
 * @package Glueful\Http
 */
class RequestContext
{
    private ServerRequestInterface $request;
    private array $serverParams;
    private array $cookieParams;
    private array $queryParams;
    private array $parsedBody;
    private array $uploadedFiles;
    private array $attributes;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
        $this->serverParams = $request->getServerParams();
        $this->cookieParams = $request->getCookieParams();
        $this->queryParams = $request->getQueryParams();
        $this->parsedBody = (array)$request->getParsedBody();
        $this->uploadedFiles = $request->getUploadedFiles();
        $this->attributes = $request->getAttributes();
    }

    /**
     * Get client IP address
     *
     * Checks for forwarded IPs and falls back to REMOTE_ADDR
     *
     * @return string
     */
    public function getClientIp(): string
    {
        // Check for forwarded IP addresses
        $forwardedFor = $this->getServerParam('HTTP_X_FORWARDED_FOR');
        if ($forwardedFor) {
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0]; // First IP is the original client
        }

        $realIp = $this->getServerParam('HTTP_X_REAL_IP');
        if ($realIp) {
            return $realIp;
        }

        return $this->getServerParam('REMOTE_ADDR', '127.0.0.1');
    }

    /**
     * Get user agent string
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->getServerParam('HTTP_USER_AGENT', '');
    }

    /**
     * Get request method
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    /**
     * Get request URI
     *
     * @return string
     */
    public function getRequestUri(): string
    {
        return $this->request->getUri()->getPath();
    }

    /**
     * Get full URL
     *
     * @return string
     */
    public function getFullUrl(): string
    {
        return (string)$this->request->getUri();
    }

    /**
     * Get server parameter
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getServerParam(string $key, $default = null)
    {
        return $this->serverParams[$key] ?? $default;
    }

    /**
     * Get query parameter
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getQueryParam(string $key, $default = null)
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Get post/body parameter
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getBodyParam(string $key, $default = null)
    {
        return $this->parsedBody[$key] ?? $default;
    }

    /**
     * Get cookie value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getCookie(string $key, $default = null)
    {
        return $this->cookieParams[$key] ?? $default;
    }

    /**
     * Get authorization header
     *
     * @return string|null
     */
    public function getAuthorizationHeader(): ?string
    {
        // Check Authorization header
        $auth = $this->getServerParam('HTTP_AUTHORIZATION');
        if ($auth) {
            return $auth;
        }

        // Check alternative header (some servers use this)
        $auth = $this->getServerParam('REDIRECT_HTTP_AUTHORIZATION');
        if ($auth) {
            return $auth;
        }

        // Check PHP_AUTH_DIGEST for digest auth
        $auth = $this->getServerParam('PHP_AUTH_DIGEST');
        if ($auth) {
            return $auth;
        }

        return null;
    }

    /**
     * Get bearer token from authorization header
     *
     * @return string|null
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getAuthorizationHeader();
        if ($auth && preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get content type
     *
     * @return string
     */
    public function getContentType(): string
    {
        $contentType = $this->request->getHeaderLine('Content-Type');
        return $contentType ?: '';
    }

    /**
     * Check if request is JSON
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $contentType = $this->getContentType();
        return stripos($contentType, 'application/json') !== false;
    }

    /**
     * Check if request is AJAX
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return $this->getServerParam('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest';
    }

    /**
     * Check if request is HTTPS
     *
     * @return bool
     */
    public function isHttps(): bool
    {
        $https = $this->getServerParam('HTTPS');
        return $https && $https !== 'off';
    }

    /**
     * Get request protocol
     *
     * @return string
     */
    public function getProtocol(): string
    {
        return $this->isHttps() ? 'https' : 'http';
    }

    /**
     * Get host
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->request->getUri()->getHost();
    }

    /**
     * Get port
     *
     * @return int|null
     */
    public function getPort(): ?int
    {
        return $this->request->getUri()->getPort();
    }

    /**
     * Get referer
     *
     * @return string|null
     */
    public function getReferer(): ?string
    {
        return $this->getServerParam('HTTP_REFERER');
    }

    /**
     * Get all headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->request->getHeaders();
    }

    /**
     * Get header value
     *
     * @param string $name
     * @return string
     */
    public function getHeader(string $name): string
    {
        return $this->request->getHeaderLine($name);
    }

    /**
     * Get request attribute
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute(string $name, $default = null)
    {
        return $this->request->getAttribute($name, $default);
    }

    /**
     * Get the underlying PSR-7 request
     *
     * @return ServerRequestInterface
     */
    public function getPsr7Request(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Create from globals
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        $request = ServerRequestFactory::fromGlobals();
        return new self($request);
    }
}
