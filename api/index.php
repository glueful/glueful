<?php
declare(strict_types=1);

/**
 * API Entry Point
 * 
 * This is the main entry point for the API. Handles request routing,
 * CORS headers, and JSON processing.
 * 
 * @author Xose Ahlijah
 * @version 3.0
 */

require_once __DIR__ . '/../api/bootstrap.php';

use Glueful\Api\API;

/**
 * CORS Configuration
 * 
 * Define headers for Cross-Origin Resource Sharing.
 * Allows controlled access from different domains.
 */
$corsHeaders = [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
    'Access-Control-Max-Age' => '86400' // 24 hours cache
];

// Send CORS headers
foreach ($corsHeaders as $header => $value) {
    header("$header: $value");
}

/**
 * Preflight Request Handler
 * 
 * Handle OPTIONS requests for CORS preflight checks.
 * Allows browsers to verify if the actual request is allowed.
 */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * JSON Request Processing
 * 
 * Parse incoming JSON request bodies and make them
 * available through $_POST for consistent access.
 */
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        $_POST = json_decode($input, true) ?? [];
        error_log("JSON Body received: " . $input);
    }
}

/**
 * Request Processing
 * 
 * Process the incoming request through the API router
 * and return JSON response.
 */
$response = API::processRequest();

// Ensure JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
