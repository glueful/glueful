<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Router};
use Glueful\Helpers\{ExtensionsManager, RoutesManager};
use Glueful\Scheduler\JobScheduler;
use Glueful\Logging\LogManager;

/**
 * Main API Initialization and Request Handler (Cleaned)
 *
 * Refactored to eliminate duplicate middleware registration.
 * Middleware is now managed centrally through MiddlewareRegistry.
 */
class API
{
    /** @var LogManager|null Central logger instance */
    private static ?LogManager $logger = null;

    /**
     * Initialize the API Framework
     *
     * Bootstrap sequence for the API:
     * 1. Initialize core components (without middleware duplication)
     * 2. Register middleware from configuration
     * 3. Load extensions and routes
     */
    public static function init(): void
    {
        // Log initialization start
        self::getLogger()->info("API initialization started");

        // Initialize core components (without manual middleware registration)
        self::initializeCore();

        // Register ALL middleware from configuration in one place
        self::getLogger()->debug("Registering middleware from configuration...");
        \Glueful\Http\MiddlewareRegistry::registerFromConfig();

        // Load enabled extensions first - they may register routes
        self::getLogger()->debug("Loading extensions...");
        ExtensionsManager::loadEnabledExtensions();

        // Load extension routes from enabled extensions
        self::getLogger()->debug("Loading extension routes...");
        ExtensionsManager::loadExtensionRoutes();

        // Now load all core route definitions
        self::getLogger()->debug("Loading core routes...");
        RoutesManager::loadRoutes();

        // Initialize scheduler only for CLI
        if (PHP_SAPI === 'cli') {
            self::getLogger()->debug("Initializing job scheduler for CLI...");
            JobScheduler::getInstance();
        }

        // Initialization complete
        self::getLogger()->info("API initialization completed successfully");
    }

    /**
     * Initialize core API components (WITHOUT middleware)
     *
     * Sets up essential services that extensions and routes might depend on.
     * Middleware registration is now handled separately by MiddlewareRegistry.
     */
    private static function initializeCore(): void
    {
        // Initialize configuration first
        self::getLogger()->debug("Loading configuration...");
        if (!defined('CONFIG_LOADED')) {
            // Load critical configurations
            config('app');        // Application settings
            config('database');   // Database connection
            config('security');   // Security settings
            config('cache');      // Cache configuration
            config('session');    // Session/JWT settings

            define('CONFIG_LOADED', true);
        }

        // Initialize authentication providers
        self::getLogger()->debug("Initializing authentication services...");
        \Glueful\Auth\AuthBootstrap::initialize();

        // Initialize database connection if needed
        if (!defined('SKIP_DB_INIT') && config('database.auto_connect', true)) {
            self::getLogger()->debug("Initializing database connection...");
            new \Glueful\Database\Connection();
        }

        // Initialize cache if enabled
        if (config('cache.enabled', true)) {
            self::getLogger()->debug("Initializing cache services...");
            \Glueful\Helpers\Utils::initializeCacheDriver();
        }

        self::getLogger()->debug("Core initialization completed without middleware conflicts");
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
     * Main entry point for handling API requests with proper middleware order.
     */
    public static function processRequest(): void
    {
        $startTime = microtime(true);
        $requestId = uniqid('req-');

        // Log request start
        self::getLogger()->info("API request started", [
            'request_id' => $requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);

        // Get router instance
        $router = Router::getInstance();

        // Initialize API (this registers middleware in correct order)
        self::init();

        // Let router handle the request through middleware pipeline
        $response = $router->handleRequest();

        // Send the Symfony Response object (handles headers, content, etc.)
        $response->send();

        // Log successful response
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        self::getLogger()->info("API request completed", [
            'request_id' => $requestId,
            'time_ms' => $totalTime,
            'status' => $response->getStatusCode()
        ]);
    }

    /**
     * Get registration status for debugging
     *
     * @return array Status information
     */
    public static function getStatus(): array
    {
        return [
            'config_loaded' => defined('CONFIG_LOADED'),
            'middleware_registered' => \Glueful\Http\MiddlewareRegistry::isRegistered(),
            'registered_middleware' => \Glueful\Http\MiddlewareRegistry::getRegisteredMiddleware(),
        ];
    }
}
