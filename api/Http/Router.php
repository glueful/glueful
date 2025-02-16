<?php
declare(strict_types=1);

namespace Mapi\Api\Http;

class Router 
{
    private static ?Router $instance = null;
    private array $routes = [];
    private array $publicRoutes = [];
    
    public static function getInstance(): Router 
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
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
    
    private function normalizePath(string $path): string 
    {
        // Remove leading/trailing slashes and normalize multiple slashes
        $path = trim($path, '/');
        $path = preg_replace('#/+#', '/', $path);
        return $path;
    }
    
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
