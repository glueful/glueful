<?php declare(strict_types=1);

/**
 * API Entry Point
 * 
 * This is the main entry point for the API. Handles request routing,
 * CORS headers, and JSON processing.
 */
require_once __DIR__ . '/../api/bootstrap.php';

use Glueful\API;
use Glueful\Http\ServerRequestFactory;
use Glueful\Logging\LogManager;

// Create global request logger
$requestLogger = new LogManager('request');

// Log the incoming request
$requestLogger->debug("Request received", [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
]);

// Create PSR-7 request object
$request = ServerRequestFactory::fromGlobals();

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Process the request
API::processRequest();