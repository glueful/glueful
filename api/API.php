<?php
declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Router};
use Glueful\Helpers\{Request, ExtensionsManager, RoutesManager};
use Glueful\Scheduler\JobScheduler;
use Glueful\Exceptions\{ValidationException, AuthenticationException};
use Throwable;

/**
 * Main API Initialization and Request Handler
 * 
 * Core class responsible for bootstrapping the API framework:
 * - Initializes the routing system
 * - Loads extensions and plugins
 * - Handles incoming API requests
 * - Delegates request processing to appropriate controllers
 * - Provides centralized error handling
 *
 * This class implements a fully modular architecture where:
 * - Routes are defined in separate route files
 * - Controllers handle domain-specific logic
 * - Extensions can hook into the system to add functionality
 * 
 * @package Glueful\Api
 * @author Glueful Core Team
 */
class API 
{    
    /**
     * Initialize the API Framework
     * 
     * Bootstrap sequence for the API:
     * 1. Load API extensions to register custom functionality
     * 2. Load route definitions from route files
     * 3. Set up middleware pipeline
     * 4. Configure dependency containers
     * 
     * This modular approach allows for easy customization and extension
     * of the API without modifying core files.
     * 
     * @return void
     */
    public static function init(): void
    {
        ExtensionsManager::loadExtensions();
         RoutesManager::loadRoutes();
        // Initialize scheduler for appropriate request types
        if (PHP_SAPI === 'cli' || Request::isAdminRequest()) {
            // Initialize scheduler only when needed
            JobScheduler::getInstance();
        }
    }

    /**
     * Process API Request
     * 
     * Main entry point for handling API requests:
     * 1. Sets appropriate response headers
     * 2. Initializes API framework components
     * 3. Routes request to appropriate handler
     * 4. Processes response through middleware
     * 5. Returns formatted JSON response
     * 
     * Error handling is delegated to controllers and the router,
     * ensuring consistent error responses across the API.
     * 
     * @return array API response with status and data
     * @throws \RuntimeException If request processing fails catastrophically
     */
    public static function processRequest(): void {
        try {
            // Set JSON response headers
            header('Content-Type: application/json');
            
            // Get router instance
            $router = Router::getInstance();
            // Initialize API
            self::init();
            
            
            
            // Let router handle the request
            $response = $router->handleRequest();
            
            // Output the response
            echo json_encode($response);
            
        } catch (ValidationException $e) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => 'validation_error', 'message' => $e->getMessage()]);
        } catch (AuthenticationException $e) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'authentication_error', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
            // Log the actual error details
        }
    }
}
?>