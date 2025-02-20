<?php
declare(strict_types=1);

namespace Glueful\Api\Http;

/**
 * API Router
 * 
 * Handles API route registration, matching, and request dispatching.
 * Supports dynamic route parameters and public/protected route designation.
 */
class Router 
{
    /** @var Router|null Singleton instance */
    private static ?Router $instance = null;
    
    /** @var array Registered routes */
    private array $routes = [];
    
    /** @var array Public routes that don't require authentication */
    private array $publicRoutes = [];
    
    /**
     * Get Router singleton instance
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
     * Register a new route
     * 
     * @param string $method HTTP method (GET, POST, etc)
     * @param string $path URL path pattern
     * @param callable $handler Route handler function
     * @param bool $public Whether route is publicly accessible
     */
    public function addRoute(string $method, string $path, callable $handler, bool $public = false): void 
    {
        $normalizedPath = $this->normalizePath($path);
        $this->routes[] = [
            'method' => $method,
            'path' => $normalizedPath,
            'handler' => $handler
        ];
        if ($public) {
            $this->publicRoutes[] = $normalizedPath;
        }
    }
    
    /**
     * Normalize route path
     * 
     * Removes leading/trailing slashes and normalizes multiple slashes.
     * 
     * @param string $path Raw path
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
     * Determines if a route can be accessed without authentication.
     * 
     * @param string $requestPath Request URL path
     * @return bool True if route is public
     */
    public function isPublicRoute(string $requestPath): bool 
    {
        $normalizedPath = $this->normalizePath($requestPath);
        foreach ($this->publicRoutes as $route) {
            // Convert route pattern to regex
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
     * Finds matching route and extracts URL parameters.
     * 
     * @param string $method HTTP method
     * @param string $requestPath Request URL path
     * @return array|null Route data if matched, null if no match
     */
    public function match(string $method, string $requestPath): ?array 
    {
        $normalizedPath = $this->normalizePath($requestPath);
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            $pattern = $route['path'];
            $paramNames = [];
            
            // First extract parameter names
            if (preg_match_all('/\{([^}]+)\}/', $pattern, $matches)) {
                $paramNames = $matches[1];
                // Then convert to regex pattern without escaping
                $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
            }

            // Now escape the pattern but preserve the capture groups
            $pattern = str_replace('/', '\/', $pattern);
            $pattern = '#^' . $pattern . '$#';
            
            
            if (preg_match($pattern, $normalizedPath, $matches)) {
                array_shift($matches); // Remove full match
                
                // Create params array from matched values
                $params = [];
                foreach ($paramNames as $index => $name) {
                    $params[$name] = $matches[$index] ?? null;
                }
                
                return [
                    'handler' => $route['handler'],
                    'params' => $params
                ];
            }
        }
        
        return null;
    }

    /**
     * Handle incoming request
     * 
     * Main request processing method. Matches route and executes handler.
     * 
     * @return array API response data
     */
    public function handleRequest(): array 
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];

        // Strip base path and get clean route path
        $path = preg_replace('#^.*/api/#', '', $requestUri);
        $path = strtok($path, '?'); // Remove query parameters

        // Match route
        $match = $this->match($method, $path);
        
        if (!$match) {
            return [
                'success' => false,
                'message' => 'Route not found',
                'code' => 404
            ];
        }

       
        
        try {
            // Execute route handler with parameters
            $result = ($match['handler'])($match['params']);
            
            // If result is already an array, return it
            if (is_array($result)) {
                return $result;
            }
            
            // Otherwise wrap it in a success response
            return [
                'success' => true,
                'data' => $result
            ];
            
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return [
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }
}
