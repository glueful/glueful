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
use Glueful\Api\Library\Logging\LogManager;
use Monolog\Level;
use Glueful\Api\Http\ServerRequestFactory;
use Glueful\Api\Exceptions\{ValidationException, AuthenticationException};
// Record start time for performance tracking
$startTime = microtime(true);

// Initialize logger
$logger = new LogManager();

// Common request data for context
$requestContext = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'ip' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
    'request_id' => uniqid('req_')
];

try {
    // Create PSR-7 request object
    $request = ServerRequestFactory::fromGlobals();

    // CORS Configuration
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

    // Handle OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $logger->log(
            "CORS Preflight Request",
            $requestContext,
            Level::Debug,
            'api.cors'
        );
        exit(0);
    }

    // Parse JSON content
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $_POST = json_decode($input, true) ?? [];
            $logger->log(
                "JSON Body Parsed",
                ['body_size' => strlen($input)],
                Level::Debug,
                'api.request'
            );
        }
    }

    // Process the request
    $response = API::processRequest();

    // Calculate execution time
    $execTime = microtime(true) - $startTime;

    // Log the complete API request/response cycle
    $logger->logApiRequest(
        $request,
        $response,
        null,
        $startTime
    );

    // Send response
    header('Content-Type: application/json');
    echo json_encode($response);

} catch (ValidationException $e) {
    $logger->logApiRequest(
        $request ?? null,
        null,
        $e,
        $startTime
    );
    throw $e;

} catch (AuthenticationException $e) {
    $logger->logApiRequest(
        $request ?? null,
        null,
        $e,
        $startTime
    );
    throw $e;

} catch (Throwable $e) {
    $logger->logApiRequest(
        $request ?? null,
        null,
        $e,
        $startTime
    );
    throw $e;
}
?>
