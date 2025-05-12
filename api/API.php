<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Router};
use Glueful\Helpers\{Request, ExtensionsManager, RoutesManager};
use Glueful\Scheduler\JobScheduler;
use Glueful\Exceptions\{ValidationException, AuthenticationException};
use Glueful\Logging\LogManager;
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
    /** @var LogManager|null Central logger instance - making it nullable for testing */
    private static ?LogManager $logger = null;

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
        try {
            // Log initialization start
            self::getLogger()->info("API initialization started");

            // Initialize core components
            self::initializeCore();

            // Load extensions first - they may register routes
            self::getLogger()->debug("Loading extensions...");
            ExtensionsManager::loadExtensions();

            // Now load all route definitions
            self::getLogger()->debug("Loading routes...");
            RoutesManager::loadRoutes();

            // Initialize scheduler for appropriate request types
            if (PHP_SAPI === 'cli' || Request::isAdminRequest()) {
                self::getLogger()->debug("Initializing job scheduler...");
                // Initialize scheduler only when needed
                JobScheduler::getInstance();
            }

            // Initialization complete
            self::getLogger()->info("API initialization completed successfully");
        } catch (\Throwable $e) {
            // Log initialization failure
            self::getLogger()->error("API initialization failed", [
                'error' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // Re-throw to be handled by the main error handler
            throw new \RuntimeException("API initialization failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Initialize core API components
     *
     * Sets up essential services that extensions and routes might depend on:
     * - Authentication providers
     * - Database connections
     * - Cache services
     * - Configuration
     * - API metrics collection
     *
     * @return void
     */
    private static function initializeCore(): void
    {
        // Initialize configuration first
        self::getLogger()->debug("Loading configuration...");
        // Load critical configurations if not already loaded
        if (!defined('CONFIG_LOADED')) {
            // Ensure paths are configured
            config('paths');
            // Ensure database connection is ready
            config('database');
            // Load security settings
            config('security');

            define('CONFIG_LOADED', true);
        }

        // Initialize API metrics middleware for tracking and analyzing API usage
        self::getLogger()->debug("Initializing API metrics collection...");
        $apiMetricsMiddleware = new \Glueful\Http\Middleware\ApiMetricsMiddleware();
        \Glueful\Http\Router::addMiddleware($apiMetricsMiddleware);

        // Initialize authentication providers
        self::getLogger()->debug("Initializing authentication services...");
        // This ensures auth services are available to routes and extensions
        \Glueful\Auth\AuthBootstrap::initialize();

        // Initialize DB connection if it will be needed
        if (!defined('SKIP_DB_INIT') && config('database.auto_connect', true)) {
            self::getLogger()->debug("Initializing database connection...");
            // Create a new Connection instance (which will be pooled internally)
            new \Glueful\Database\Connection();
        }
    }

     /**
      * Get logger instance
      *
      * @return LogManager
      */
    public static function getLogger(): LogManager
    {
        if (!isset(self::$logger)) {
            self::$logger = $GLOBALS['logger'] ?? new LogManager('api');
        }

        return self::$logger;
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
    public static function processRequest(): void
    {
        $startTime = microtime(true);
        $requestId = uniqid('req-');

        try {
            // Log request start
            self::getLogger()->info("API request started", [
                'request_id' => $requestId,
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

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

            // Log successful response
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            self::getLogger()->info("API request completed", [
                'request_id' => $requestId,
                'time_ms' => $totalTime,
                'status' => $response['code'] ?? 200
            ]);
        } catch (ValidationException $e) {
             // Log validation error
             self::getLogger()->notice("Validation error", [
                'request_id' => $requestId,
                'error' => $e->getMessage()
             ]);

            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => 'validation_error', 'message' => $e->getMessage()]);
        } catch (AuthenticationException $e) {
             // Log authentication error
             self::getLogger()->warning("Authentication error", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
             ]);

            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'authentication_error', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Log server error
            self::getLogger()->error("Server error", [
                'request_id' => $requestId,
                'error' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            error_log($e->getMessage());


            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
            // Log the actual error details
        }
    }
}
