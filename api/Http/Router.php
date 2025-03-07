<?php

declare(strict_types=1);
namespace Glueful\Http;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\AuthenticationService;

/**
 * Advanced Router Implementation using Symfony's Routing Component
 * 
 * This router provides robust routing capabilities by leveraging Symfony's routing system.
 * Key features include:
 * - Route registration with method constraints (GET, POST, PUT, DELETE)
 * - Route grouping with shared prefixes
 * - Dynamic parameter extraction from URLs
 * - Support for middleware
 * - Public route designation
 * - Request context handling
 * 
 * Usage Example:
 * ```php
 * // Register routes
 * Router::get('/users', [UserController::class, 'list']);
 * Router::post('/users', [UserController::class, 'create']);
 * 
 * // Group related routes
 * Router::group('/admin', function() {
 *     Router::get('/stats', [AdminController::class, 'stats']);
 *     Router::post('/settings', [AdminController::class, 'updateSettings']);
 * });
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
    private static array $middlewares = [];
    private static array $protectedRoutes = []; // Routes that require authentication
    private static array $currentGroups = [];
    private static array $currentGroupAuth = [];
    private static array $adminProtectedRoutes = []; // Routes that require admin authentication

    /**
     * Initialize the Router
     * 
     * Sets up the router with a fresh RouteCollection and empty group stack.
     * Should be called before any route registration.
     * 
     * Usage:
     * ```php
     * Router::init();
     * ```
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

    public static function middleware(callable $middleware)
    {
        self::$middlewares[] = $middleware;
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
    public static function dispatch(Request $request): array
    {
        self::$context->fromRequest($request);
        self::$matcher = new UrlMatcher(self::$routes, self::$context);

        $pathInfo = $request->getPathInfo();

        try {
            $parameters = self::$matcher->match($pathInfo);
            $routeName = md5($request->getPathInfo() . $request->getMethod());
            $controller = $parameters['_controller'];


        
            
            // Apply global middlewares
            foreach (self::$middlewares as $middleware) {
                $middleware($request);
            }

            // Apply authentication check if required
            if (in_array($routeName, self::$protectedRoutes)) {
                if (!AuthenticationService::checkAuth($request)) {
                    return [
                        'success' => false,
                        'message' => 'Unauthorized access, invalid or expired token',
                        'code' => 401
                    ];
                }
            }

            // Apply authentication check if required
            if (in_array($routeName, self::$adminProtectedRoutes)) {
                if (!AuthenticationService::checkAdminAuth($request)) {
                    return [
                        'success' => false,
                        'message' => 'Unauthorized access, invalid or expired token',
                        'code' => 401
                    ];
                }
            }

            $reflection = new \ReflectionFunction($controller);
            $parametersInfo = $reflection->getParameters();

            if ($parametersInfo[0]->getType()->getName() === Request::class) {
                $result = call_user_func($controller, $request);
            } else {
                $result = call_user_func($controller, $parameters);
            }

            if (is_array($result)) {
                return $result;
            }
            
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'code' => 500
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'code' => 500
            ];
        }
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

