<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Symfony\Component\HttpFoundation\Request;

/**
 * Request Helper
 *
 * Static utility methods for common request operations.
 * Provides backwards compatibility while transitioning from custom Request class.
 */
class RequestHelper
{
    /**
     * Get request data (POST/PUT/PATCH) with automatic JSON parsing
     *
     * @param Request|null $request Request instance (null uses createFromGlobals)
     * @return array
     */
    public static function getRequestData(?Request $request = null): array
    {
        $request = $request ?? Request::createFromGlobals();
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $content = $request->getContent();
            return $content ? json_decode($content, true) ?? [] : [];
        }

        return $request->request->all();
    }

    /**
     * Get PUT/PATCH data with automatic JSON parsing
     *
     * @param Request|null $request Request instance (null uses createFromGlobals)
     * @return array
     */
    public static function getPutData(?Request $request = null): array
    {
        $request = $request ?? Request::createFromGlobals();
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $content = $request->getContent();
            return $content ? json_decode($content, true) ?? [] : [];
        }

        // For form-encoded PUT data
        $content = $request->getContent();
        parse_str($content, $data);
        return $data;
    }

    /**
     * Check if the current request is for admin endpoints
     *
     * @param Request|null $request Request instance (null uses createFromGlobals)
     * @return bool
     */
    public static function isAdminRequest(?Request $request = null): bool
    {
        $request = $request ?? Request::createFromGlobals();
        $requestUri = $request->getRequestUri();

        // Check if URL path contains /admin segment
        if (str_contains($requestUri, '/admin')) {
            return true;
        }

        // Check for admin API endpoints
        if (str_contains($requestUri, '/api/admin')) {
            return true;
        }

        // Check for admin-specific query parameter
        if ($request->query->get('admin') === 'true') {
            return true;
        }

        // Check for requests with admin token in header
        if ($request->headers->has('X-Admin-Access') || $request->headers->has('X-ADMIN-ACCESS')) {
            return true;
        }

        return false;
    }
}
