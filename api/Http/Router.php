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
    public static function init()
    {
        self::$routes = new RouteCollection();
        self::$context = new RequestContext();
    }

    public static function get(string $path, callable $handler, bool $requiresAuth = false)
    {
        self::addRoute($path, ['GET'], $handler, $requiresAuth);
    }

    public static function post(string $path, callable $handler, bool $requiresAuth = false)
    {
        self::addRoute($path, ['POST'], $handler, $requiresAuth);
    }

    public static function put(string $path, callable $handler, bool $requiresAuth = false)
    {
        self::addRoute($path, ['PUT'], $handler, $requiresAuth);
    }

    public static function delete(string $path, callable $handler, bool $requiresAuth = false)
    {
        self::addRoute($path, ['DELETE'], $handler, $requiresAuth);
    }

    /**
     * Create route group with prefix
     * 
     * Groups related routes under a common URL prefix.
     * Routes defined within the callback will have the prefix prepended.
     * Supports nested groups.
     * 
     * Example:
     * ```php
     * Router::group('/api', function() {
     *     Router::get('/users', [UserController::class, 'index']);
     *     
     *     Router::group('/admin', function() {
     *         Router::get('/stats', [AdminController::class, 'stats']);
     *     });
     * });
     * ```
     * 
     * @param string $prefix URL prefix for all routes in group
     * @param callable $callback Function containing route definitions
     * @param array $middleware Optional middleware for all routes in group
     */
    public static function group(string $prefix, callable $callback, array $middleware = []): void
    {
        // Normalize prefix
        $prefix = '/' . trim($prefix, '/');
        
        // Add prefix to current group stack
        self::$currentGroups[] = $prefix;
        
        // Execute the group callback
        $callback();
        
        // Remove this group's prefix
        array_pop(self::$currentGroups);
    }

    /**
     * Get current group prefix
     * 
     * Combines all active group prefixes into a single path.
     * 
     * @return string Combined prefix from all active groups
     */
    private static function getCurrentGroupPrefix(): string
    {
        if (empty(self::$currentGroups)) {
            return '';
        }
        
        return implode('', self::$currentGroups);
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
    private static function addRoute(string $path, array $methods, callable $handler, bool $requiresAuth)
    {
        // Apply group prefix if any exists
        $fullPath = self::getCurrentGroupPrefix() . '/' . trim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');
        
        $routeName = md5($fullPath . implode('|', $methods));
        $route = new Route($fullPath, ['_controller' => $handler], [], [], '', [], $methods);
        self::$routes->add($routeName, $route);

        if ($requiresAuth) {
            self::$protectedRoutes[] = $routeName;
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
        // exit;
        // var_dump($request);
        self::$context->fromRequest($request);
        self::$matcher = new UrlMatcher(self::$routes, self::$context);


        // $scriptName = dirname($request->server->get('SCRIPT_NAME')); // "/glueful/api"
        $pathInfo = $request->getPathInfo();
        // $basePath = dirname($scriptName);
        // $routePath = substr($pathInfo, strlen($basePath));
        // var_dump($pathInfo);
        // exit;

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
                if (!self::checkAuth($request)) {
                    return [
                        'success' => false,
                        'message' => 'Unauthorized access, invalid or expired token',
                        'code' => 401
                    ];
                }
            }

            $reflection = new \ReflectionFunction($controller);
            $parametersInfo = $reflection->getParameters();

            // var_dump($parametersInfo[0]->getType()->getName() === Request::class);
            // exit;

            if ($parametersInfo[0]->getType()->getName() === Request::class) {
                $result = call_user_func($controller, $request);
            } else {
                $result = call_user_func($controller, $parameters);
            }


            // $result = call_user_func($controller, $request);
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

    private static function checkAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return false;
        }

        $token = $matches[1];
        return self::validateToken($token);
    }

    private static function validateToken(string $token): bool
    {   
        $authService = new AuthenticationService();
        $result = $authService->validateAccessToken($token);

        if (!$result) {
            return false;
        }
        
        return true;
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

