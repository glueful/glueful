<?php

declare(strict_types=1);

namespace Glueful\Helpers;

class Request
{
    private array $queryParams;
    private array $postData;
    private array $serverData;
    private array $files;
    private string $contentType;

    public function __construct()
    {
        $this->queryParams = $_GET;
        $this->postData = $_POST;
        $this->serverData = $_SERVER;
        $this->files = $_FILES;
        $this->contentType = $this->serverData['CONTENT_TYPE'] ?? '';
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public static function getPostData(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $postData = [];

        if (strpos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $postData = json_decode($input, true) ?? [];
        } else {
            $postData = $_POST;
        }
        return $postData;
    }

    public static function getPutData(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $putData = [];
        if (strpos($contentType, 'application/json') !== false) {
            $putData = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            parse_str(file_get_contents('php://input'), $putData);
        }
        return $putData;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Check if the current request is for the admin panel/endpoints
     *
     * Determines if the request is accessing admin functionality by:
     * - Checking request path for '/admin' prefix
     * - Verifying admin-specific URL pattern
     * - Examining request headers for admin indicators
     *
     * @return bool True if this is an admin request
     */
    public static function isAdminRequest(): bool
    {
        // Get the current request URI
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Check if URL path contains /admin segment
        if (strpos($requestUri, '/admin') !== false) {
            return true;
        }

        // Check for admin API endpoints
        if (strpos($requestUri, '/api/admin') !== false) {
            return true;
        }

        // Check for admin-specific query parameter
        if (isset($_GET['admin']) && $_GET['admin'] === 'true') {
            return true;
        }

        // Check for requests with admin token in header
        $headers = getallheaders();
        if (isset($headers['X-Admin-Access']) || isset($headers['X-ADMIN-ACCESS'])) {
            return true;
        }

        return false;
    }
}
