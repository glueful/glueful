<?php
declare(strict_types=1);

namespace Glueful\Http;

/**
 * API Router
 * 
 * Handles all aspects of request routing in the API:
 * - Route registration and organization
 * - Pattern matching with parameter extraction
 * - Request dispatching to controller methods
 * - Route grouping and prefixing
 * - Authentication boundary enforcement
 * 
 * The router implements a singleton pattern for global access
 * and supports RESTful routing conventions.
 * 
 * Usage:
 * ```php
 * // Get router instance
 * $router = Router::getInstance();
 * 
 * // Define routes
 * Router::get('/users', [UserController::class, 'index']);
 * Router::post('/users', [UserController::class, 'create']);
 * Router::get('/users/{id}', [UserController::class, 'show']);
 * 
 * // Group related routes
 * Router::group('/admin', function() {
 *     Router::get('/dashboard', [AdminController::class, 'dashboard']);
 *     Router::get('/users', [AdminController::class, 'users']);
 * });
 * 
 * // Dispatch request to appropriate route handler
 * Router::dispatch();
 * ```
 * 
 * @package Glueful\Http
 */
class Router 
{
    /** @var Router|null Singleton instance */
    private static ?Router $instance = null;
    
    /** @var array Registered routes with method, path and handler */
    private array $routes = [];
    
    /** @var array Public routes that don't require authentication */
    private array $publicRoutes = [];

    /** @var array Currently active route group prefixes */
    protected static array $currentGroup = [];
    
    /**
     * Get Router singleton instance
     * 
     * Returns the global router instance, creating it if necessary.
     * This ensures all code uses the same routing configuration.
     * 
     * @return Router The router instance
     */
    public static function getInstance(): Router 
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register GET route
     * 
     * Adds a route that responds to GET requests.
     * Suitable for read operations that retrieve data.
     * 
     * @param string $path Route path pattern
     * @param callable|array $handler Controller method to handle the request
     */
    public static function get(string $path, callable|array $handler): void
    {
        self::addRoute('GET', $path, $handler);
    }

    /**
     * Register POST route
     * 
     * Adds a route that responds to POST requests.
     * Suitable for create operations that submit new data.
     * 
     * @param string $path Route path pattern
     * @param callable|array $handler Controller method to handle the request
     */
    public static function post(string $path, callable|array $handler): void
    {
        self::addRoute('POST', $path, $handler);
    }

    /**
     * Register PUT route
     * 
     * Adds a route that responds to PUT requests.
     * Suitable for update operations that replace entire resources.
     * 
     * @param string $path Route path pattern
     * @param callable|array $handler Controller method to handle the request
     */
    public static function put(string $path, callable|array $handler): void
    {
        self::addRoute('PUT', $path, $handler);
    }

    /**
     * Register DELETE route
     * 
     * Adds a route that responds to DELETE requests.
     * Suitable for delete operations that remove resources.
     * 
     * @param string $path Route path pattern
     * @param callable|array $handler Controller method to handle the request
     */
    public static function delete(string $path, callable|array $handler): void
    {
        self::addRoute('DELETE', $path, $handler);
    }

    /**
     * Create route group
     * 
     * Groups related routes under a common URL prefix.
     * Routes defined within the callback will have the prefix prepended.
     * 
     * Example:
     * ```php
     * // Basic grouping
     * Router::group('/admin', function() {
     *     Router::get('/users', [AdminController::class, 'listUsers']);
     *     Router::get('/settings', [AdminController::class, 'showSettings']);
     * });
     * 
     * // Creates routes: /admin/users and /admin/settings
     * 
     * // Nested grouping
     * Router::group('/api', function() {
     *     Router::get('/status', [ApiController::class, 'status']);
     *     
     *     Router::group('/v1', function() {
     *         Router::get('/users', [ApiController::class, 'usersV1']);
     *     });
     *     
     *     Router::group('/v2', function() {
     *         Router::get('/users', [ApiController::class, 'usersV2']);
     *     });
     * });
     * 
     * // Creates routes: /api/status, /api/v1/users, /api/v2/users
     * ```
     * 
     * @param string $prefix URL prefix for all routes in the group
     * @param callable $callback Function that defines the routes in this group
     */
    public static function group(string $prefix, callable $callback): void
    {
        self::$currentGroup[] = $prefix;
        $callback();
        array_pop(self::$currentGroup);
    }

    /**
     * Register a new route
     * 
     * Adds a route to the routing table with the specified method, path, and handler.
     * Supports dynamic path parameters using {param} syntax.
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path URL path pattern with optional parameters
     * @param callable|array $handler Route handler function or [Controller, method] array
     * @param bool $public Whether route is publicly accessible without authentication
     */
    public static function addRoute(string $method, string $path, callable|array $handler, bool $public = false): void 
    {
        $instance = self::getInstance();
        
        // Apply any active group prefixes
        $fullPath = '';
        if (!empty(self::$currentGroup)) {
            $fullPath = implode('', self::$currentGroup);
        }
        $fullPath .= '/' . ltrim($path, '/');
        
        // Normalize the final path
        $normalizedPath = $instance->normalizePath($fullPath);
        
        // Store route information
        $instance->routes[] = [
            'method' => strtoupper($method),
            'path' => $normalizedPath,
            'fullPath' => $fullPath,
            'handler' => $handler
        ];
        
        // Mark as public if specified
        if ($public) {
            $instance->publicRoutes[] = $normalizedPath;
        }
    }

    /**
     * Dispatch incoming request
     * 
     * Processes the current HTTP request against registered routes.
     * Finds matching route, extracts parameters, and executes handler.
     * Outputs JSON response or error if no route matches.
     */
    public static function dispatch(): void
    {
        $instance = self::getInstance();
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $normalizedPath = $instance->normalizePath($requestUri);
        
        // Find matching route
        foreach ($instance->routes as $route) {
            $params = [];
            if ($requestMethod === $route['method'] && 
                $instance->matchPath($route['path'], $normalizedPath, $params)) {
                
                // Execute the matching route handler
                $instance->executeHandler($route['handler'], $params);
                return;
            }
        }

        // No matching route found
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Route not found',
            'code' => 404
        ]);
    }
    
    /**
     * Normalize route path
     * 
     * Standardizes path format by:
     * - Removing leading/trailing slashes
     * - Collapsing multiple consecutive slashes
     * - Ensuring consistent formatting
     * 
     * @param string $path Raw path to normalize
     * @return string Normalized path
     */
    private function normalizePath(string $path): string 
    {
        // Remove leading/trailing slashes and normalize multiple slashes
        $path = trim($path, '/');
        $path = preg_replace('#/+#', '/', $path);
        return $path;
    }
    
    /**
     * Check if route is public
     * 
     * Determines if a route can be accessed without authentication by
     * checking against the registered public routes list.
     * 
     * @param string $requestPath Request URL path
     * @return bool True if route is public, false if authentication is required
     */
    public function isPublicRoute(string $requestPath): bool 
    {
        $normalizedPath = $this->normalizePath($requestPath);
        
        foreach ($this->publicRoutes as $route) {
            // Convert route pattern to regex for matching
            $pattern = str_replace('/', '\/', $route);
            $pattern = preg_replace('/\{[^}]+\}/', '[^\/]+', $pattern);
            
            if (preg_match("/^$pattern$/", $normalizedPath)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Match request to registered route
     * 
     * Finds matching route based on HTTP method and path.
     * Extracts dynamic parameters from URL if present.
     * 
     * @param string $method HTTP method of the request
     * @param string $requestPath Request URL path
     * @return array|null Route data and parameters if matched, null if no match
     */
    public function match(string $method, string $requestPath): ?array 
    {
        $normalizedPath = $this->normalizePath($requestPath);
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            
            $params = [];
            if ($this->matchPath($route['path'], $normalizedPath, $params)) {
                return [
                    'handler' => $route['handler'],
                    'params' => $params
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Match path against route pattern
     * 
     * Tests if a request path matches a route pattern and
     * extracts named parameters if present.
     * 
     * @param string $routePath Route pattern with parameter placeholders
     * @param string $requestPath Actual request path to test
     * @param array &$params Output parameter to store extracted values
     * @return bool True if path matches the route pattern
     */
    private function matchPath(string $routePath, string $requestPath, array &$params): bool
    {
        $paramNames = [];
        $pattern = $routePath;
            
        // Extract parameter names from {param} syntax
        if (preg_match_all('/\{([^}]+)\}/', $pattern, $matches)) {
            $paramNames = $matches[1];
            // Convert to regex pattern
            $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
        }

        // Escape for regex matching
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '#^' . $pattern . '$#';
            
        // Attempt to match and capture parameters
        if (preg_match($pattern, $requestPath, $matches)) {
            array_shift($matches); // Remove full match
                
            // Map captured values to parameter names
            foreach ($paramNames as $index => $name) {
                $params[$name] = $matches[$index] ?? null;
            }
            
            return true;
        }
            
        return false;
    }

    /**
     * Handle incoming request
     * 
     * Main entry point for processing API requests.
     * Matches the current request against registered routes and
     * executes the appropriate handler.
     * 
     * @return array API response data
     */
    public function handleRequest(): array 
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];

        // Extract API path from request URI
        $path = preg_replace('#^.*/api/#', '', $requestUri);
        $path = strtok($path, '?'); // Remove query parameters

        // Find matching route
        $match = $this->match($method, $path);
        
        // Handle 404 if no route matches
        if (!$match) {
            return [
                'success' => false,
                'message' => 'Route not found',
                'code' => 404
            ];
        }
        
        try {
            // Execute route handler with parameters
            $result = $this->executeHandler($match['handler'], $match['params']);
            
            // Format the response
            if (is_array($result)) {
                return $result;
            }
            
            return [
                'success' => true,
                'data' => $result
            ];
            
        } catch (\Exception $e) {
            // Log the error and return error response
            error_log($e->getMessage());
            return [
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    /**
     * Execute route handler
     * 
     * Invokes the handler function or controller method for a route.
     * Supports both closure handlers and [Controller, method] arrays.
     * 
     * @param callable|array $handler Route handler to execute
     * @param array $params Parameters extracted from URL
     * @return mixed Result from the handler
     * @throws \RuntimeException If handler cannot be executed
     */
    protected function executeHandler(callable|array $handler, array $params)
    {
        if (is_callable($handler)) {
            // Direct function call
            return call_user_func_array($handler, $params);
            
        } elseif (is_array($handler) && count($handler) === 2) {
            // Controller method call
            [$class, $method] = $handler;
            
            if (class_exists($class) && method_exists($class, $method)) {
                $instance = new $class();
                return call_user_func_array([$instance, $method], $params);
            } else {
                throw new \RuntimeException(
                    "Invalid route handler: class '$class' or method '$method' not found"
                );
            }
        }
        
        throw new \RuntimeException("Invalid route handler format");
    }
}
