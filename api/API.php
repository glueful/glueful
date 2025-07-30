<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Router};
use Glueful\Helpers\{RoutesManager};
use Glueful\Extensions\ExtensionManager;
use Glueful\Scheduler\JobScheduler;
use Psr\Log\LoggerInterface;

/**
 * Main API Initialization and Request Handler (Cleaned)
 *
 * Refactored to eliminate duplicate middleware registration.
 * Middleware is now managed centrally through MiddlewareRegistry.
 */
class API
{
    /** @var LoggerInterface|null PSR-3 compliant framework logger */
    private static ?LoggerInterface $logger = null;

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
        // Framework logs initialization start
        self::getLogger()->info('Framework initialization started', [
            'type' => 'framework',
            'message' => 'Glueful framework bootstrap initiated',
            'version' => config('app.version_full'),
            'environment' => config('app.env'),
            'php_version' => PHP_VERSION,
            'timestamp' => date('c')
        ]);

        self::initializeCore();

        // Framework logs core initialization success
        self::getLogger()->info('Framework core initialized', [
            'type' => 'framework',
            'message' => 'Core components loaded successfully',
            'components' => ['config', 'auth', 'database', 'cache'],
            'timestamp' => date('c')
        ]);

        // Register ALL middleware from configuration in one place
        self::getLogger()->debug("Registering middleware from configuration...");
        \Glueful\Http\MiddlewareRegistry::registerFromConfig();

        // Load enabled extensions first - they may register routes
        self::getLogger()->debug("Loading extensions...");
        $extensionManager = container()->get(ExtensionManager::class);
        $extensionManager->loadEnabledExtensions();

        // Initialize extensions now that DI container is ready
        self::getLogger()->debug("Initializing extensions...");
        $extensionManager->initializeLoadedExtensions();

        // Load extension routes from enabled extensions
        self::getLogger()->debug("Loading extension routes...");
        try {
            $extensionManager->loadExtensionRoutes();
        } catch (\Exception $e) {
            throw $e;
        } catch (\Error $e) {
            throw $e;
        }

        // Now load all core route definitions
        self::getLogger()->debug("Loading core routes...");
        RoutesManager::loadRoutes();

        // Initialize scheduler only for CLI
        if (PHP_SAPI === 'cli') {
            self::getLogger()->debug("Initializing job scheduler for CLI...");
            JobScheduler::getInstance();
        }

        // Framework logs successful startup
        self::getLogger()->info('Framework initialization completed', [
            'type' => 'framework',
            'message' => 'Glueful framework ready to handle requests',
            'version' => config('app.version_full'),
            'environment' => config('app.env'),
            'config' => [
                'debug' => config('app.debug'),
                'middleware_registered' => count(\Glueful\Http\MiddlewareRegistry::getRegisteredMiddleware())
            ],
            'timestamp' => date('c')
        ]);
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
     * Get PSR-3 logger instance (framework logging)
     *
     * @return LoggerInterface
     */
    public static function getLogger(): LoggerInterface
    {
        if (self::$logger === null) {
            self::$logger = container()->get(LoggerInterface::class);
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

        // Get router instance
        $router = Router::getInstance();

        // Initialize API (this registers middleware in correct order)
        self::init();

        // Let router handle the request through middleware pipeline
        $response = $router->handleRequest();

        // Send the Symfony Response object (handles headers, content, etc.)
        $response->send();

        // Framework logs request lifecycle (since this is framework request processing)
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        self::getLogger()->info('Framework request completed', [
            'type' => 'framework',
            'message' => 'Request processing pipeline completed',
            'request_id' => $requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'time_ms' => $totalTime,
            'status' => $response->getStatusCode(),
            'timestamp' => date('c')
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
