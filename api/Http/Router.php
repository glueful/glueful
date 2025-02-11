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
            
            // Extract parameter names and convert to regex pattern
            if (preg_match_all('/\{([^}]+)\}/', $pattern, $matches)) {
                $paramNames = $matches[1];
                $pattern = preg_replace('/\{[^}]+\}/', '([^\/]+)', $pattern);
            }
            
            $pattern = str_replace('/', '\/', $pattern);
            if (preg_match("/^$pattern$/", $normalizedPath, $matches)) {
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
}
