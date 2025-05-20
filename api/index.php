<?php

/**
 * API Entry Point
 *
 * This is the main entry point for the API. Handles request routing,
 * CORS headers, and JSON processing.
 */

declare(strict_types=1);

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

// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }

    exit(0);
}

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Process the request
API::processRequest();
