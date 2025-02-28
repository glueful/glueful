<?php
declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Router};
use Glueful\Helpers\{Request, ExtensionsManager, RoutesManager};
use Glueful\Scheduler\JobScheduler;

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
    public static function processRequest(): array 
    {
        // Set JSON response headers
        header('Content-Type: application/json');
        
        // Initialize API
        self::init();
        
        // Get router instance
        $router = Router::getInstance();
        
        // Let router handle the request
        return $router->handleRequest();
    }
}
?>