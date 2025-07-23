<?php

/**
 * Glueful Framework - Universal Entry Point
 *
 * This is the main entry point for all requests (API, Web, Admin).
 * All requests are routed through this file for proper handling.
 */

declare(strict_types=1);

// Bootstrap the framework
$container = require_once __DIR__ . '/../api/bootstrap.php';

use Glueful\API;
use Glueful\Http\ServerRequestFactory;
use Glueful\Http\Cors;
use Glueful\SpaManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Create global request logger
$requestLogger = $container->has(LoggerInterface::class)
    ? $container->get(LoggerInterface::class)
    : new NullLogger();

// Get request URI and method
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Log the incoming request
$requestLogger->debug("Request received", [
    'method' => $requestMethod,
    'uri' => $requestUri,
    'path_info' => $_SERVER['PATH_INFO'] ?? 'not_set'
]);

// Create PSR-7 request object
$request = ServerRequestFactory::fromGlobals();

// Handle CORS for API requests
if (str_starts_with($requestUri, '/api/')) {
    $cors = new Cors();
    if (!$cors->handle()) {
        return; // OPTIONS request handled, don't continue
    }
    // Strip /api prefix for API routes processing (same as old api/index.php behavior)
    $apiPath = substr($requestUri, 4); // Remove '/api'
    if (empty($apiPath)) {
        $apiPath = '/';
    }
    $_SERVER['REQUEST_URI'] = $apiPath;
    $_SERVER['PATH_INFO'] = $apiPath;
}

// Handle SPA routing for non-API requests
if (!str_starts_with($requestUri, '/api/')) {
    $path = parse_url($requestUri, PHP_URL_PATH);

    // Try SPA manager for both SPA routes and assets
    try {
        $spaManager = $container->get(SpaManager::class);
        if ($spaManager->handleSpaRouting($path)) {
            exit; // SPA or asset was served
        }
    } catch (\Throwable $e) {
        $requestLogger->error("SPA routing failed: " . $e->getMessage());
        // Continue to framework routing on SPA error
    }

    // If no SPA/asset matched, continue to framework routing
}

// Process the request through the framework API
API::processRequest();
