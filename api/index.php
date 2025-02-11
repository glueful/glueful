<?php
declare(strict_types=1);

/**
 * @author Xose Ahlijah
 * @version 3.0
 **/
require_once __DIR__ . '/../api/bootstrap.php';

use Mapi\Api\API;

// Debug logging
// error_log("Request received: " . $_SERVER['REQUEST_URI']);
// error_log("Method: " . $_SERVER['REQUEST_METHOD']);
// error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none'));

// CORS headers setup
$corsHeaders = [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
    'Access-Control-Max-Age' => '86400'
];

// Send CORS headers
foreach ($corsHeaders as $header => $value) {
    header("$header: $value");
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Handle JSON request body
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        $_POST = json_decode($input, true) ?? [];
        error_log("JSON Body received: " . $input);
    }
}

// Process request
$response = isset($_FILES) ? 
    API::processRequest($_GET, $_POST, $_FILES) : 
    API::processRequest($_GET, $_POST);

// Ensure JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
