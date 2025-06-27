<?php

declare(strict_types=1);

namespace Glueful\Services;

use Glueful\Http\Router;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route Cache Service
 *
 * Handles compilation and caching of application routes for production performance.
 * Generates optimized route cache files that eliminate route loading overhead.
 *
 * @package Glueful\Services
 */
class RouteCacheService
{
    /** @var string Default cache directory */
    private string $cacheDir;

    /** @var string Cache file name */
    private string $cacheFileName = 'routes.php';

    public function __construct()
    {
        // Use storage/cache directory for route cache
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache';

        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get the full path to the route cache file
     */
    public function getCacheFilePath(): string
    {
        return $this->cacheDir . '/' . $this->cacheFileName;
    }

    /**
     * Check if route cache exists and is valid
     */
    public function isCacheValid(): bool
    {
        $cacheFile = $this->getCacheFilePath();

        if (!file_exists($cacheFile)) {
            return false;
        }

        // Check if cache is readable
        if (!is_readable($cacheFile)) {
            return false;
        }

        // Additional validation: check if cache file has valid PHP structure
        try {
            $cached = include $cacheFile;
            return is_array($cached) && isset($cached['routes']) && isset($cached['metadata']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Cache routes from a Router instance
     *
     * @param Router $router The router instance with loaded routes
     * @return array Result array with success status and statistics
     */
    public function cacheRoutes(Router $router): array
    {
        try {
            $cacheFile = $this->getCacheFilePath();

            // Extract route data from Router using reflection
            $routeData = $this->extractRouteData($router);

            // Generate cache content
            $cacheContent = $this->generateCacheContent($routeData);

            // Write to cache file
            $bytesWritten = file_put_contents($cacheFile, $cacheContent);

            if ($bytesWritten === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to write cache file'
                ];
            }

            // Generate statistics
            $stats = $this->generateCacheStats($routeData, $bytesWritten);

            return [
                'success' => true,
                'cache_file' => $cacheFile,
                'stats' => $stats
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Load cached routes into a Router instance
     *
     * @param Router $router The router instance to load routes into
     * @return bool True if routes were loaded successfully
     */
    public function loadCachedRoutes(Router $router): bool
    {
        if (!$this->isCacheValid()) {
            return false;
        }

        try {
            $cached = include $this->getCacheFilePath();

            // Use reflection to restore route data to Router
            $this->restoreRouteData($router, $cached);

            return true;
        } catch (\Exception $e) {
            error_log("Failed to load cached routes: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract route data from Router using reflection
     */
    private function extractRouteData(Router $router): array
    {
        $reflection = new \ReflectionClass($router);

        // Access static properties using reflection
        $routesProperty = $reflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routes = $routesProperty->getValue();

        $protectedRoutesProperty = $reflection->getProperty('protectedRoutes');
        $protectedRoutesProperty->setAccessible(true);
        $protectedRoutes = $protectedRoutesProperty->getValue();

        $adminProtectedRoutesProperty = $reflection->getProperty('adminProtectedRoutes');
        $adminProtectedRoutesProperty->setAccessible(true);
        $adminProtectedRoutes = $adminProtectedRoutesProperty->getValue();

        $versionPrefixProperty = $reflection->getProperty('versionPrefix');
        $versionPrefixProperty->setAccessible(true);
        $versionPrefix = $versionPrefixProperty->getValue();

        $routeNameCacheProperty = $reflection->getProperty('routeNameCache');
        $routeNameCacheProperty->setAccessible(true);
        $routeNameCache = $routeNameCacheProperty->getValue();

        return [
            'routes' => $this->serializeRouteCollection($routes),
            'protected_routes' => $protectedRoutes,
            'admin_protected_routes' => $adminProtectedRoutes,
            'version_prefix' => $versionPrefix,
            'route_name_cache' => $routeNameCache,
            'metadata' => [
                'created_at' => time(),
                'php_version' => PHP_VERSION,
                'framework_version' => '1.0.0', // TODO: Get from config
                'environment' => $_ENV['APP_ENV'] ?? 'production'
            ]
        ];
    }

    /**
     * Serialize RouteCollection to array format
     */
    private function serializeRouteCollection(RouteCollection $routes): array
    {
        $serialized = [];

        foreach ($routes as $name => $route) {
            $serialized[$name] = [
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
                'defaults' => $route->getDefaults(),
                'requirements' => $route->getRequirements(),
                'options' => $route->getOptions(),
                'host' => $route->getHost(),
                'schemes' => $route->getSchemes(),
                'condition' => $route->getCondition()
            ];
        }

        return $serialized;
    }

    /**
     * Generate the actual PHP cache file content
     */
    private function generateCacheContent(array $routeData): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * Glueful Route Cache\n";
        $content .= " * \n";
        $content .= " * This file was auto-generated by the route:cache command.\n";
        $content .= " * Do not modify this file directly.\n";
        $content .= " * \n";
        $content .= " * Generated: {$timestamp}\n";
        $content .= " * Environment: " . ($routeData['metadata']['environment'] ?? 'production') . "\n";
        $content .= " */\n\n";
        $content .= "return " . var_export($routeData, true) . ";\n";

        return $content;
    }

    /**
     * Restore route data to Router instance using reflection
     */
    private function restoreRouteData(Router $router, array $cached): void
    {
        $reflection = new \ReflectionClass($router);

        // Restore RouteCollection
        $routes = $this->unserializeRouteCollection($cached['routes']);
        $routesProperty = $reflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routesProperty->setValue($routes);

        // Restore protected routes arrays
        $protectedRoutesProperty = $reflection->getProperty('protectedRoutes');
        $protectedRoutesProperty->setAccessible(true);
        $protectedRoutesProperty->setValue($cached['protected_routes'] ?? []);

        $adminProtectedRoutesProperty = $reflection->getProperty('adminProtectedRoutes');
        $adminProtectedRoutesProperty->setAccessible(true);
        $adminProtectedRoutesProperty->setValue($cached['admin_protected_routes'] ?? []);

        // Restore version prefix
        $versionPrefixProperty = $reflection->getProperty('versionPrefix');
        $versionPrefixProperty->setAccessible(true);
        $versionPrefixProperty->setValue($cached['version_prefix'] ?? '');

        // Restore route name cache
        $routeNameCacheProperty = $reflection->getProperty('routeNameCache');
        $routeNameCacheProperty->setAccessible(true);
        $routeNameCacheProperty->setValue($cached['route_name_cache'] ?? []);
    }

    /**
     * Unserialize array data back to RouteCollection
     */
    private function unserializeRouteCollection(array $serializedRoutes): RouteCollection
    {
        $routes = new RouteCollection();

        foreach ($serializedRoutes as $name => $routeData) {
            $route = new \Symfony\Component\Routing\Route(
                $routeData['path'],
                $routeData['defaults'] ?? [],
                $routeData['requirements'] ?? [],
                $routeData['options'] ?? [],
                $routeData['host'] ?? '',
                $routeData['schemes'] ?? [],
                $routeData['methods'] ?? [],
                $routeData['condition'] ?? ''
            );

            $routes->add($name, $route);
        }

        return $routes;
    }

    /**
     * Generate cache statistics
     */
    private function generateCacheStats(array $routeData, int $cacheSize): array
    {
        return [
            'total_routes' => count($routeData['routes']),
            'protected_routes' => count($routeData['protected_routes']),
            'admin_routes' => count($routeData['admin_protected_routes']),
            'route_groups' => $this->countRouteGroups($routeData['routes']),
            'cache_size' => $cacheSize,
            'created_at' => $routeData['metadata']['created_at'],
            'php_version' => $routeData['metadata']['php_version'],
            'environment' => $routeData['metadata']['environment']
        ];
    }

    /**
     * Count estimated route groups based on path prefixes
     */
    private function countRouteGroups(array $routes): int
    {
        $prefixes = [];

        foreach ($routes as $route) {
            $path = $route['path'];
            $parts = explode('/', trim($path, '/'));
            if (count($parts) > 0) {
                $prefixes[$parts[0]] = true;
            }
        }

        return count($prefixes);
    }
}
