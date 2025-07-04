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
    'uri' => $requestUri
]);

// Create PSR-7 request object
$request = ServerRequestFactory::fromGlobals();

// Handle CORS for API requests
if (str_starts_with($requestUri, '/api/')) {
    $cors = new Cors();
    if (!$cors->handle()) {
        return; // OPTIONS request handled, don't continue
    }
}

// Process the request through the framework
API::processRequest();
