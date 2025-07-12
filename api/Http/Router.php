<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Http\Middleware\MiddlewareInterface;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Glueful\Http\Middleware\MiddlewareDispatcher;
use Glueful\Http\Middleware\AuthenticationMiddleware;

/**
 * Advanced Router Implementation using Symfony's Routing Component with PSR-15 Middleware
 *
 * This router provides robust routing capabilities by leveraging Symfony's routing system
 * and PSR-15 compatible middleware architecture.
 *
 * Key features include:
 * - Route registration with method constraints (GET, POST, PUT, DELETE)
 * - Route grouping with shared prefixes
 * - Dynamic parameter extraction from URLs
 * - PSR-15 compatible middleware pipeline
 * - Authentication middleware integration
 * - Request context handling
 *
 * Usage Example:
 * ```php
 * // Register routes
 * Router::get('/users', [UserController::class, 'list']);
 * Router::post('/users', [UserController::class, 'create']);
 *
 * // Add middleware
 * Router::addMiddleware(new CorsMiddleware());
 *
 * // Group related routes
 * Router::group('/admin', function() {
 *     Router::get('/stats', [AdminController::class, 'stats']);
 * }, requiresAuth: true, requiresAdminAuth: true);
 *
 * // Handle the request
 * $router = Router::getInstance();
 * $response = $router->handleRequest();
 * ```
 *
 * @package Glueful\Http
 */
class Router
{
    private static ?Router $instance = null;
    private static RouteCollection $routes;
    private static RequestContext $context;
    private static UrlMatcher $matcher;

    /** @var MiddlewareInterface[] PSR-15 middleware stack */
    private static array $middlewareStack = [];

    private static array $protectedRoutes = []; // Routes that require authentication
    private static array $currentGroups = [];
    private static array $currentGroupAuth = [];
    private static array $adminProtectedRoutes = []; // Routes that require admin authentication

    /** @var array Route name cache to avoid redundant MD5 calculations */
    private static array $routeNameCache = [];

    /** @var string API version prefix for all routes */
    private static string $versionPrefix = '';

    /** @var bool Whether routes were loaded from cache */
    private static bool $routesLoadedFromCache = false;

    /**
     * Initialize the Router
     *
     * Sets up the router with a fresh RouteCollection and empty group stack.
     * In production, attempts to load cached routes for performance.
     * Should be called before any route registration.
     */
    private function __construct()
    {
        self::$routes = new RouteCollection();
        self::$context = new RequestContext();
        // Try to load cached routes in production for performance
        $this->tryLoadCachedRoutes();
    }

    /**
     * Set API version prefix for all routes
     *
     * @param string $version API version (e.g., 'v1', 'v2')
     */
    public static function setVersion(string $version): void
    {
        self::$versionPrefix = '/' . trim($version, '/');
    }

    /**
     * Get current API version prefix
     *
     * @return string Current version prefix
     */
    public static function getVersionPrefix(): string
    {
        return self::$versionPrefix;
    }

    /**
     * Ensure router is initialized
     */
    private static function ensureInitialized(): void
    {
        if (!isset(self::$routes)) {
            self::$routes = new RouteCollection();
            self::$context = new RequestContext();
        }
    }


    public static function get(
        string $path,
        callable $handler,
        bool $requiresAuth = false,
        bool $requiresAdminAuth = false,
        array $requirements = []
    ) {
        self::addRoute($path, ['GET'], $handler, $requiresAuth, $requiresAdminAuth, $requirements);
    }

    public static function post(
        string $path,
        callable $handler,
        bool $requiresAuth = false,
        bool $requiresAdminAuth = false,
        array $requirements = []
    ) {
        self::addRoute($path, ['POST'], $handler, $requiresAuth, $requiresAdminAuth, $requirements);
    }

    public static function put(
        string $path,
        callable $handler,
        bool $requiresAuth = false,
        bool $requiresAdminAuth = false,
        array $requirements = []
    ) {
        self::addRoute($path, ['PUT'], $handler, $requiresAuth, $requiresAdminAuth, $requirements);
    }

    public static function delete(
        string $path,
        callable $handler,
        bool $requiresAuth = false,
        bool $requiresAdminAuth = false,
        array $requirements = []
    ) {
        self::addRoute($path, ['DELETE'], $handler, $requiresAuth, $requiresAdminAuth, $requirements);
    }

    /**
     * Serve static files from a directory
     *
     * This method creates a route that serves static files from the specified directory.
     * It handles MIME types, prevents directory traversal, and sets appropriate headers.
     *
     * Example:
     * ```php
     * // Serve documentation files
     * Router::static('/docs', '/path/to/documentation');
     *
     * // Serve assets with custom cache settings
     * Router::static('/assets', '/public/assets', false, [
     *     'cache' => true,
     *     'cacheMaxAge' => 86400 // 24 hours
     * ]);
     *
     * // Restrict to specific file types
     * Router::static('/images', '/storage/images', false, [
     *     'allowedExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp']
     * ]);
     *
     * // Require authentication for private docs
     * Router::static('/api-docs', '/private/api-docs', true);
     *
     * // Custom index file
     * Router::static('/app', '/dist', false, [
     *     'indexFile' => 'app.html'
     * ]);
     * ```
     *
     * @param string $urlPath The URL path prefix (e.g., '/docs')
     * @param string $directory The filesystem directory to serve files from
     * @param bool $requiresAuth Whether authentication is required to access these files
     * @param array $options Additional options (indexFile, allowedExtensions, etc.)
     */
    public static function static(
        string $urlPath,
        string $directory,
        bool $requiresAuth = false,
        array $options = []
    ): void {
        // Default options
        $defaultOptions = [
            'indexFile' => 'index.html',
            'allowedExtensions' => null, // null means all extensions allowed
            'cache' => true,
            'cacheMaxAge' => 3600, // 1 hour
        ];
        $options = array_merge($defaultOptions, $options);

        // Normalize paths
        $urlPath = '/' . trim($urlPath, '/');
        $directory = rtrim($directory, '/');

        // Create the handler
        $handler = function (Request $request) use ($directory, $options) {
            // Get the requested file path from route parameters
            $filePath = $request->attributes->get('path', '');

            // If no file specified, try index file
            if (empty($filePath) || str_ends_with($filePath, '/')) {
                $filePath = rtrim($filePath, '/') . '/' . $options['indexFile'];
            }

            // Security: Prevent directory traversal
            $filePath = str_replace(['../', '..\\', '..'], '', $filePath);
            $fullPath = $directory . '/' . ltrim($filePath, '/');

            // Check if file exists and is within the allowed directory
            if (!file_exists($fullPath) || !is_file($fullPath)) {
                return new Response('Not Found', 404);
            }

            // Ensure the file is within the allowed directory
            $realPath = realpath($fullPath);
            $realDirectory = realpath($directory);
            if (!str_starts_with($realPath, $realDirectory)) {
                return new Response('Forbidden', 403);
            }

            // Check allowed extensions if specified
            if ($options['allowedExtensions'] !== null) {
                $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
                if (!in_array($extension, $options['allowedExtensions'])) {
                    return new Response('Forbidden', 403);
                }
            }

            // Determine MIME type
            $mimeType = self::getMimeType($fullPath);

            // Read file content
            $content = file_get_contents($fullPath);
            if ($content === false) {
                return new Response('Internal Server Error', 500);
            }

            // Create response
            $response = new Response($content, 200);
            $response->headers->set('Content-Type', $mimeType);

            // Set cache headers if enabled
            if ($options['cache']) {
                $response->headers->set('Cache-Control', 'public, max-age=' . $options['cacheMaxAge']);
                $response->headers->set('ETag', md5_file($fullPath));

                // Check if client has cached version
                $etag = $request->headers->get('If-None-Match');
                if ($etag === md5_file($fullPath)) {
                    return new Response('', 304); // Not Modified
                }
            } else {
                $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            }

            return $response;
        };

        // Register the route with a catch-all pattern
        self::get($urlPath . '/{path}', $handler, $requiresAuth, false, ['path' => '.*']);

        // Also register the base path without parameters for serving index
        self::get($urlPath, $handler, $requiresAuth);
    }

    /**
     * Get MIME type for a file
     *
     * @param string $filePath Path to the file
     * @return string MIME type
     */
    private static function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Common MIME types for documentation and web assets
        $mimeTypes = [
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        // Use mime_content_type if available for better detection
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($filePath);
            if ($detected !== false) {
                return $detected;
            }
        }

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Create route group with prefix
     *
     * Groups related routes under a common URL prefix.
     * Routes defined within the callback will have the prefix prepended.
     * Supports nested groups and optional authentication.
     *
     * Example:
     * ```php
     * Router::group('/api', function() {
     *     Router::get('/users', [UserController::class, 'index']);
     *
     *     Router::group('/admin', function() {
     *         Router::get('/stats', [AdminController::class, 'stats']);
     *     }, requiresAuth: true, requiresAdminAuth: true);
     * });
     * ```
     *
     * @param string $prefix URL prefix for all routes in group
     * @param callable $callback Function containing route definitions
     * @param array $middleware Optional middleware for all routes in group
     * @param bool $requiresAuth Apply authentication to all routes in this group
     * @param bool $requiresAdminAuth Apply admin authentication to all routes in this group
     */
    public static function group(
        string $prefix,
        callable $callback,
        array $middleware = [],
        bool $requiresAuth = false,
        bool $requiresAdminAuth = false
    ): void {
        // Normalize prefix
        $prefix = '/' . trim($prefix, '/');

        // Store the current group's authentication requirements
        self::$currentGroupAuth[] = ['auth' => $requiresAuth, 'admin' => $requiresAdminAuth];

        // Add prefix to current group stack
        self::$currentGroups[] = $prefix;

        // Execute the group callback
        $callback();

        // Remove this group's prefix and auth settings after execution
        array_pop(self::$currentGroups);
        array_pop(self::$currentGroupAuth);
    }

   /**
     * Get current group prefix and authentication settings
     *
     * Combines all active group prefixes into a single path and retrieves
     * authentication settings for the deepest active group.
     *
     * @return array Contains 'prefix' (string) and 'auth' settings (array with 'auth' and 'admin' keys)
     */
    private static function getCurrentGroupContext(): array
    {
        $prefix = empty(self::$currentGroups) ? '' : implode('', self::$currentGroups);

        // Get the latest auth settings or use default values
        $authSettings = !empty(self::$currentGroupAuth)
            ? end(self::$currentGroupAuth)
            : ['auth' => false, 'admin' => false];

        return [
            'prefix' => $prefix,
            'auth' => $authSettings
        ];
    }

    /**
     * Add a new route to the collection
     *
     * Registers a route with the specified HTTP method, path, and handler.
     * Supports both closure and controller method handlers.
     *
     * @param string $path URL path pattern (e.g., '/users/{id}')
     * @param array $methods HTTP methods (GET, POST, etc.)
     * @param callable $handler Route handler (closure or [Controller::class, 'method'])
     * @param bool $requiresAuth Whether this route requires authentication
     * @param bool $requiresAdminAuth Whether this route requires admin authentication
     */
    private static function addRoute(
        string $path,
        array $methods,
        callable $handler,
        bool $requiresAuth = false,
        bool $requiresAdminAuth = false,
        array $requirements = []
    ) {
        // Ensure router is initialized
        self::ensureInitialized();
        // Get the current group context
        $groupContext = self::getCurrentGroupContext();
        $fullPath = self::$versionPrefix . $groupContext['prefix'] . '/' . trim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        // Check if group context auth settings exist and are arrays
        $groupAuth = is_array($groupContext['auth']) ? $groupContext['auth'] : ['auth' => false, 'admin' => false];

        // Inherit authentication settings from the group if not explicitly set
        $requiresAuth = $requiresAuth || ($groupAuth['auth'] ?? false);
        $requiresAdminAuth = $requiresAdminAuth || ($groupAuth['admin'] ?? false);

        $routeKey = $fullPath . '|' . implode('|', $methods);
        $routeName = self::$routeNameCache[$routeKey] ??= md5($routeKey);
        $route = new Route($fullPath, ['_controller' => $handler], $requirements, [], '', [], $methods);
        self::$routes->add($routeName, $route);

        if ($requiresAdminAuth) {
            self::$adminProtectedRoutes[] = $routeName; // Admin-only routes
        } elseif ($requiresAuth) {
            self::$protectedRoutes[] = $routeName; // General authentication
        }
    }


    /**
     * Add a PSR-15 compatible middleware to the stack
     *
     * @param MiddlewareInterface $middleware The middleware to add
     */
    public static function addMiddleware(MiddlewareInterface $middleware): void
    {
        self::$middlewareStack[] = $middleware;
    }

    /**
     * Add multiple PSR-15 compatible middleware to the stack
     *
     * @param array $middlewareList The list of middleware to add
     */
    public static function addMiddlewares(array $middlewareList): void
    {
        foreach ($middlewareList as $middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                self::addMiddleware($middleware);
            }
        }
    }

    /**
     * Add middleware by class name (resolved through DI container)
     *
     * @param string $middlewareClass The middleware class name
     * @param array $constructorArgs Additional constructor arguments
     */
    public static function addMiddlewareClass(string $middlewareClass, array $constructorArgs = []): void
    {
        // Get DI container
        $container = function_exists('app') ? app() : null;

        if ($container === null) {
            // Fallback to manual instantiation if container not available
            $middleware = new $middlewareClass(...$constructorArgs);
        } else {
            // Resolve middleware through DI container
            if (empty($constructorArgs)) {
                $middleware = $container->get($middlewareClass);
            } else {
                // If constructor args are provided, create instance manually
                $middleware = new $middlewareClass(...$constructorArgs);
            }
        }

        if ($middleware instanceof MiddlewareInterface) {
            self::addMiddleware($middleware);
        }
    }

    /**
     * Add multiple middleware by class names
     *
     * @param array $middlewareClasses Array of middleware class names or [class => args] pairs
     */
    public static function addMiddlewareClasses(array $middlewareClasses): void
    {
        foreach ($middlewareClasses as $key => $value) {
            if (is_string($key)) {
                // Format: ['ClassName' => [arg1, arg2]]
                self::addMiddlewareClass($key, $value);
            } else {
                // Format: ['ClassName1', 'ClassName2']
                self::addMiddlewareClass($value);
            }
        }
    }


    /**
     * Convert a callable to a PSR-15 compatible middleware
     *
     * This allows for smooth transition from the old middleware system
     * to the new PSR-15 compatible one.
     *
     * @param callable $callable The callable to convert
     * @return MiddlewareInterface The converted middleware
     */
    public static function convertToMiddleware(callable $callable): MiddlewareInterface
    {
        return new class ($callable) implements MiddlewareInterface {
            private $callable;

            public function __construct(callable $callable)
            {
                $this->callable = $callable;
            }

            public function process(Request $request, RequestHandlerInterface $handler): Response
            {
                // Call the middleware
                $result = call_user_func($this->callable, $request);

                // If it returns a response, return it
                if ($result instanceof Response) {
                    return $result;
                }

                // Otherwise, continue to the next middleware
                return $handler->handle($request);
            }
        };
    }

    /**
     * Handle an incoming HTTP request
     *
     * Main entry point for processing requests through the router and middleware:
     * 1. Creates request context from current HTTP request
     * 2. Matches request against registered routes
     * 3. Processes through middleware pipeline
     * 4. Executes appropriate handler with parameters
     * 5. Returns formatted response
     *
     * Route not found errors are converted to NotFoundException for consistent
     * handling by the global exception handler.
     *
     * @param Request $request The request to handle
     * @return Response Symfony Response object
     */
    public static function dispatch(Request $request): Response
    {
        self::$context->fromRequest($request);
        self::$matcher = new UrlMatcher(self::$routes, self::$context);

        $pathInfo = $request->getPathInfo();

        // Match the route - convert ResourceNotFoundException to NotFoundException
        try {
            $parameters = self::$matcher->match($pathInfo);

            // 👇 Inject matched route parameters into the Request
            foreach ($parameters as $key => $value) {
                $request->attributes->set($key, $value);
            }
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
            throw new \Glueful\Exceptions\NotFoundException('Route not found: ' . $pathInfo);
        }

        // Use the route name from Symfony's routing system instead of generating our own
        // This ensures dynamic routes work correctly
        $routeName = $parameters['_route'] ?? null;
        $controller = $parameters['_controller'];

        // Set up middleware pipeline with DI container
        $container = null;
        if (function_exists('app')) {
            try {
                $container = app();
            } catch (\Exception $e) {
                // In test environment, continue without container
                $container = null;
            }
        }
        $dispatcher = new MiddlewareDispatcher(function (Request $request) use ($controller, $parameters) {
            // Remove internal routing parameters
            unset($parameters['_controller']);
            unset($parameters['_route']);

            // Execute controller
            $reflection = new \ReflectionFunction($controller);
            $parametersInfo = $reflection->getParameters();

            // Check if there are parameters before trying to access them
            if (
                !empty($parametersInfo) &&
                $parametersInfo[0]->getType() &&
                (
                    // Use is_a to safely check the parameter type across PHP versions
                    (method_exists($parametersInfo[0]->getType(), 'getName') &&
                     $parametersInfo[0]->getType()->getName() === Request::class) ||
                    (method_exists($parametersInfo[0]->getType(), '__toString') &&
                     (string)$parametersInfo[0]->getType() === Request::class)
                )
            ) {
                $result = call_user_func($controller, $request);
            } else {
                $result = call_user_func($controller, $parameters);
            }

            // Convert the result to a Response object
            if ($result instanceof Response) {
                return $result;
            }

            if (is_array($result)) {
                $statusCode = $result['code'] ?? ($result['success'] ?? true ? 200 : 500);
                return new JsonResponse($result, $statusCode);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ], 200);
        }, $container);

        // Add authentication middleware if required, using our new abstraction
        $authManager = \Glueful\Auth\AuthBootstrap::getManager();

        if (in_array($routeName, self::$adminProtectedRoutes)) {
            $dispatcher->pipeClass(AuthenticationMiddleware::class, [
                true, // requires admin
                $authManager, // using our new authentication manager
                ['admin', 'jwt', 'api_key'] // try each auth method in sequence until one succeeds
            ]);
        } elseif (in_array($routeName, self::$protectedRoutes)) {
            $dispatcher->pipeClass(AuthenticationMiddleware::class, [
                false, // standard authentication
                $authManager, // using our new authentication manager
                ['jwt', 'api_key'] // try each auth method in sequence until one succeeds
            ]);
        }

        // Add PSR-15 middleware to the pipeline
        foreach (self::$middlewareStack as $middleware) {
            $dispatcher->pipe($middleware);
        }

        // Update request in container for middleware to share state
        if ($container) {
            $container->instance(Request::class, $request);
        }

        // Process the request through the middleware pipeline
        $response = $dispatcher->handle($request);

        // Return the Symfony Response object directly
        // This allows proper middleware processing and HTTP compliance
        return $response;
    }

    public static function getInstance(): Router
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Handle an incoming HTTP request
     *
     * Main entry point for processing requests through the router:
     * 1. Creates request context from current HTTP request
     * 2. Matches request against registered routes
     * 3. Executes appropriate handler with parameters
     * 4. Returns formatted response
     *
     * @return Response Symfony Response object
     */
    public function handleRequest(): Response
    {
        $request = Request::createFromGlobals();
        $response = self::dispatch($request);
        return $response;
    }

    /**
     * Try to load cached routes for production performance
     *
     * This method checks if we're in production and if a valid route cache exists.
     * If both conditions are met, routes are loaded from cache instead of
     * processing route files, providing significant performance improvement.
     */
    private function tryLoadCachedRoutes(): void
    {
        // Only use route cache in production environment
        if (!$this->shouldUseCachedRoutes()) {
            return;
        }

        try {
            $cacheService = new \Glueful\Services\RouteCacheService();

            if ($cacheService->isCacheValid()) {
                $loaded = $cacheService->loadCachedRoutes($this);

                if ($loaded) {
                    // Mark that routes were loaded from cache
                    self::$routesLoadedFromCache = true;
                    return;
                }
            }
        } catch (\Exception $e) {
            // If cache loading fails, fall back to normal route loading
            // Log the error but don't break the application
            error_log("Route cache loading failed: " . $e->getMessage());
        }
    }

    /**
     * Check if cached routes should be used
     *
     * Routes are cached only in production environment and when
     * the application is not in debug mode.
     */
    private function shouldUseCachedRoutes(): bool
    {
        $environment = $_ENV['APP_ENV'] ?? 'development';
        $debug = $_ENV['APP_DEBUG'] ?? 'true';

        // Use cache only in production with debug disabled
        return $environment === 'production' &&
               (strtolower($debug) === 'false' || $debug === '0');
    }

    /**
     * Check if routes were loaded from cache
     *
     * @return bool True if routes were loaded from cache
     */
    public static function isUsingCachedRoutes(): bool
    {
        return self::$routesLoadedFromCache ?? false;
    }

    /**
     * Force reload routes from source files
     *
     * This method bypasses the cache and forces routes to be loaded
     * from source files. Useful for development or cache invalidation.
     */
    public static function reloadRoutes(): void
    {
        self::$routes = new RouteCollection();
        self::$protectedRoutes = [];
        self::$adminProtectedRoutes = [];
        self::$routeNameCache = [];
        self::$routesLoadedFromCache = false;

        // Reload routes from source files
        $extensionManager = container()->get(\Glueful\Extensions\ExtensionManager::class);
        $extensionManager->loadEnabledExtensions();
        $extensionManager->loadExtensionRoutes();
        \Glueful\Helpers\RoutesManager::loadRoutes();
    }

    /**
     * Get all registered routes
     *
     * @return RouteCollection Symfony route collection
     */
    public static function getRoutes(): RouteCollection
    {
        return self::$routes;
    }
}
