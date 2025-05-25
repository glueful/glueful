<?php

/**
 * API Entry Point
 *
 * This is the main entry point for the API. Handles request routing
 * and JSON processing. CORS is handled by the Cors class directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

use Glueful\API;
use Glueful\Http\ServerRequestFactory;
use Glueful\Logging\LogManager;
use Glueful\Http\Cors;

// Create global request logger
$requestLogger = new LogManager('request');

// Log the incoming request
$requestLogger->debug("Request received", [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
]);

// Create PSR-7 request object
$request = ServerRequestFactory::fromGlobals();

// Create CORS handler and handle the request
// Option 1: Use config-based CORS (current - more secure)
$cors = new Cors(); // Uses security config automatically

// Option 2: Use universal CORS (allows ALL origins - less secure but "just works")
// $cors = Cors::universal();

// Option 3: Use development CORS (allows common localhost ports)
// $cors = Cors::development();

if (!$cors->handle()) {
    return; // OPTIONS request handled securely, don't continue to routing
}

// Initialize the API framework
// Process the request
API::processRequest();
