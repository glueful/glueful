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
    
    /** @var callable[] Legacy middleware functions (for backward compatibility) */
    private static array $legacyMiddlewares = [];
    
    private static array $protectedRoutes = []; // Routes that require authentication
    private static array $currentGroups = [];
    private static array $currentGroupAuth = [];
    private static array $adminProtectedRoutes = []; // Routes that require admin authentication

    /**
     * Initialize the Router
     * 
     * Sets up the router with a fresh RouteCollection and empty group stack.
     * Should be called before any route registration.
     */
    private function __construct()
    {
        self::$routes = new RouteCollection();
        self::$context = new RequestContext();
    }


    public static function get(string $path, callable $handler, bool $requiresAuth = false, bool $requiresAdminAuth = false)
    {
        self::addRoute($path, ['GET'], $handler, $requiresAuth, $requiresAdminAuth);
    }

    public static function post(string $path, callable $handler, bool $requiresAuth = false, bool $requiresAdminAuth = false)
    {
        self::addRoute($path, ['POST'], $handler, $requiresAuth, $requiresAdminAuth);
    }

    public static function put(string $path, callable $handler, bool $requiresAuth = false, bool $requiresAdminAuth = false)
    {
        self::addRoute($path, ['PUT'], $handler, $requiresAuth, $requiresAdminAuth);
    }

    public static function delete(string $path, callable $handler, bool $requiresAuth = false, bool $requiresAdminAuth = false)
    {
        self::addRoute($path, ['DELETE'], $handler, $requiresAuth, $requiresAdminAuth);
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
    public static function group(string $prefix, callable $callback, array $middleware = [], bool $requiresAuth = false, bool $requiresAdminAuth = false): void
    {
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
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path URL path pattern (e.g., '/users/{id}')
     * @param callable|array $handler Route handler (closure or [Controller::class, 'method'])
     * @param array $options Additional route options (middleware, public access, etc.)
     */
    private static function addRoute(string $path, array $methods, callable $handler, bool $requiresAuth = false, bool $requiresAdminAuth = false)
    {
        // Get the current group context
        $groupContext = self::getCurrentGroupContext();
        $fullPath = $groupContext['prefix'] . '/' . trim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        // Check if group context auth settings exist and are arrays
        $groupAuth = is_array($groupContext['auth']) ? $groupContext['auth'] : ['auth' => false, 'admin' => false];
        
        // Inherit authentication settings from the group if not explicitly set
        $requiresAuth = $requiresAuth || ($groupAuth['auth'] ?? false);
        $requiresAdminAuth = $requiresAdminAuth || ($groupAuth['admin'] ?? false);

        $routeName = md5($fullPath . implode('|', $methods));
        $route = new Route($fullPath, ['_controller' => $handler], [], [], '', [], $methods);
        self::$routes->add($routeName, $route);

        if ($requiresAdminAuth) {
            self::$adminProtectedRoutes[] = $routeName; // Admin-only routes
        } elseif ($requiresAuth) {
            self::$protectedRoutes[] = $routeName; // General authentication
        }
    }

    /**
     * Add a middleware using the legacy interface (for backward compatibility)
     * 
     * @param callable $middleware The middleware function
     */
    public static function middleware(callable $middleware)
    {
        self::$legacyMiddlewares[] = $middleware;
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
     * Convert legacy middleware functions to PSR-15 compatible middleware
     * 
     * This allows for easy migration from the old middleware system to the new PSR-15 compatible one.
     * 
     * @return void
     */
    public static function convertLegacyMiddleware(): void
    {
        foreach (self::$legacyMiddlewares as $middleware) {
            self::$middlewareStack[] = self::convertToMiddleware($middleware);
        }
        
        // Clear legacy middleware since they've been converted
        self::$legacyMiddlewares = [];
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
        return new class($callable) implements MiddlewareInterface {
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
     * @param Request $request The request to handle
     * @return array API response array with success/error information
     */
    public static function dispatch(Request $request): array
    {
        self::$context->fromRequest($request);
        self::$matcher = new UrlMatcher(self::$routes, self::$context);

        $pathInfo = $request->getPathInfo();

        try {
            // Match the route
            $parameters = self::$matcher->match($pathInfo);
            $routeName = md5($request->getPathInfo() . $request->getMethod());
            $controller = $parameters['_controller'];
            
            // Set up middleware pipeline
            $dispatcher = new MiddlewareDispatcher(function(Request $request) use ($controller, $parameters) {
                // Remove internal routing parameters
                unset($parameters['_controller']);
                unset($parameters['_route']);
                
                // Execute controller
                $reflection = new \ReflectionFunction($controller);
                $parametersInfo = $reflection->getParameters();
                
                if ($parametersInfo[0]->getType()->getName() === Request::class) {
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
            });
            
            // Add authentication middleware if required, using our new abstraction
            $authManager = \Glueful\Auth\AuthBootstrap::getManager();
            
            if (in_array($routeName, self::$adminProtectedRoutes)) {
                $dispatcher->pipe(new AuthenticationMiddleware(
                    true, // requires admin
                    $authManager, // using our new authentication manager
                    ['jwt', 'api_key'] // try multiple auth methods in sequence
                ));
            } elseif (in_array($routeName, self::$protectedRoutes)) {
                $dispatcher->pipe(new AuthenticationMiddleware(
                    false, // standard authentication
                    $authManager, // using our new authentication manager
                    ['jwt', 'api_key'] // try multiple auth methods in sequence
                ));
            }
            
            // Add PSR-15 middleware to the pipeline
            foreach (self::$middlewareStack as $middleware) {
                $dispatcher->pipe($middleware);
            }
            
            // Convert and add legacy middleware to the pipeline
            foreach (self::$legacyMiddlewares as $middleware) {
                $dispatcher->pipe(self::convertToMiddleware($middleware));
            }
            
            // Process the request through the middleware pipeline
            $response = $dispatcher->handle($request);
            
            // Convert the response to an array
            if ($response instanceof JsonResponse) {
                return json_decode($response->getContent(), true);
            }
            
            return [
                'success' => true,
                'data' => $response->getContent(),
                'code' => $response->getStatusCode()
            ];
        } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
            return [
                'success' => false,
                'message' => 'Route not found',
                'code' => 404
            ];
        } catch (\Throwable $e) {
            // Consolidated exception handling for all other exceptions
            // Log the exception with detailed context
            self::logException($e);
            
            // Return a consistent error response
            return [
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'code' => $e->getCode() ?: 500,
                'type' => get_class($e)
            ];
        }
    }
    
    /**
     * Log exception details for debugging
     * 
     * Logs exception information to help with troubleshooting.
     * Delegates to the main ExceptionHandler if possible, otherwise falls back
     * to basic error logging.
     * 
     * @param \Throwable $exception The exception to log
     */
    private static function logException(\Throwable $exception): void
    {
        // Create context for logging
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'type' => get_class($exception)
        ];
        
        // Try to use the framework's exception handler if available
        if (class_exists('\\Glueful\\Exceptions\\ExceptionHandler')) {
            try {
                // Call the framework's exception handler's logging method
                call_user_func(['\\Glueful\\Exceptions\\ExceptionHandler', 'logError'], $exception, $context);
                return;
            } catch (\Throwable $e) {
                // Fall back to error_log if the exception handler fails
            }
        }
        
        // Fall back to basic error logging
        error_log(sprintf(
            "Exception: %s, Message: %s, File: %s, Line: %d", 
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
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
     * @param Request|null $request Optional Symfony Request object
     * @return array API response array with success/error information
     */
    public function handleRequest()
    {
        $request = Request::createFromGlobals();
        $response = self::dispatch($request);
        return $response;
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

