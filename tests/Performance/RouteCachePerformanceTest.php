<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

/** // phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder
 * Route Cache Performance Test
 *
 * Measures the performance improvement from route caching by comparing
 * route loading times with and without cache.
 *
 * Expected results:
 * - 50-70% faster route resolution in production
 * - Significantly reduced memory usage
 * - Consistent performance regardless of route complexity
 */

require_once __DIR__ . '/../../api/bootstrap.php';

use Glueful\Http\Router;
use Glueful\Services\RouteCacheService;
use Glueful\Extensions\ExtensionManager;
use Glueful\Helpers\RoutesManager;

class RouteCachePerformanceTest // phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
{
    private array $results = [];
    private int $iterations = 100;

    public function runPerformanceTests(): void
    {
        echo "🚀 Route Cache Performance Test\n";
        echo str_repeat("=", 50) . "\n\n";

        // Test 1: Route Loading Performance
        $this->testRouteLoadingPerformance();

        // Test 2: Route Resolution Performance
        $this->testRouteResolutionPerformance();

        // Test 3: Memory Usage Comparison
        $this->testMemoryUsage();

        // Display results
        $this->displayResults();
    }

    /**
     * Test route loading performance (cache vs file loading)
     */
    private function testRouteLoadingPerformance(): void
    {
        echo "📋 Testing Route Loading Performance...\n";

        // Ensure we start clean
        $this->clearRouteCache();

        // Test file-based loading
        $fileLoadingTime = $this->measureRouteLoading('file');
        $this->results['route_loading']['file'] = $fileLoadingTime;

        // Create route cache
        $this->createRouteCache();

        // Test cache-based loading
        $cacheLoadingTime = $this->measureRouteLoading('cache');
        $this->results['route_loading']['cache'] = $cacheLoadingTime;

        $improvement = (($fileLoadingTime - $cacheLoadingTime) / $fileLoadingTime) * 100;
        $this->results['route_loading']['improvement'] = $improvement;

        echo "   • File loading: " . number_format($fileLoadingTime * 1000, 2) . "ms\n";
        echo "   • Cache loading: " . number_format($cacheLoadingTime * 1000, 2) . "ms\n";
        echo "   • Improvement: " . number_format($improvement, 1) . "%\n\n";
    }

    /**
     * Test route resolution performance
     */
    private function testRouteResolutionPerformance(): void
    {
        echo "🔍 Testing Route Resolution Performance...\n";

        $testRoutes = [
            '/auth/login',
            '/users/123',
            '/extensions',
            '/health',
            '/api/config'
        ];

        // Test without cache
        $this->clearRouteCache();
        $this->forceRouterReset();
        $fileResolutionTime = $this->measureRouteResolution($testRoutes);
        $this->results['route_resolution']['file'] = $fileResolutionTime;

        // Test with cache
        $this->createRouteCache();
        $this->forceRouterReset();
        $cacheResolutionTime = $this->measureRouteResolution($testRoutes);
        $this->results['route_resolution']['cache'] = $cacheResolutionTime;

        $improvement = (($fileResolutionTime - $cacheResolutionTime) / $fileResolutionTime) * 100;
        $this->results['route_resolution']['improvement'] = $improvement;

        echo "   • File-based resolution: " . number_format($fileResolutionTime * 1000, 2) . "ms\n";
        echo "   • Cache-based resolution: " . number_format($cacheResolutionTime * 1000, 2) . "ms\n";
        echo "   • Improvement: " . number_format($improvement, 1) . "%\n\n";
    }

    /**
     * Test memory usage comparison
     */
    private function testMemoryUsage(): void
    {
        echo "💾 Testing Memory Usage...\n";

        // Test file loading memory usage
        $this->clearRouteCache();
        $fileMemoryUsage = $this->measureMemoryUsage('file');
        $this->results['memory']['file'] = $fileMemoryUsage;

        // Test cache loading memory usage
        $this->createRouteCache();
        $cacheMemoryUsage = $this->measureMemoryUsage('cache');
        $this->results['memory']['cache'] = $cacheMemoryUsage;

        $memoryReduction = (($fileMemoryUsage - $cacheMemoryUsage) / $fileMemoryUsage) * 100;
        $this->results['memory']['reduction'] = $memoryReduction;

        echo "   • File loading memory: " . $this->formatBytes($fileMemoryUsage) . "\n";
        echo "   • Cache loading memory: " . $this->formatBytes($cacheMemoryUsage) . "\n";
        echo "   • Memory reduction: " . number_format($memoryReduction, 1) . "%\n\n";
    }

    /**
     * Measure route loading time
     */
    private function measureRouteLoading(string $method): float
    {
        $totalTime = 0;

        for ($i = 0; $i < $this->iterations; $i++) {
            $this->forceRouterReset();

            $startTime = microtime(true);

            if ($method === 'cache') {
                // Simulate production environment for cache
                $_ENV['APP_ENV'] = 'production';
                $_ENV['APP_DEBUG'] = 'false';
            } else {
                $_ENV['APP_ENV'] = 'development';
                $_ENV['APP_DEBUG'] = 'true';
            }

            // Initialize router (this triggers route loading)
            $router = Router::getInstance();

            // Load routes
            $extensionManager = container()->get(ExtensionManager::class);
            $extensionManager->loadEnabledExtensions();
            $extensionManager->loadExtensionRoutes();
            RoutesManager::loadRoutes();

            $endTime = microtime(true);
            $totalTime += ($endTime - $startTime);
        }

        return $totalTime / $this->iterations;
    }

    /**
     * Measure route resolution time
     */
    private function measureRouteResolution(array $routes): float
    {
        $totalTime = 0;
        $iterations = 50; // Fewer iterations for resolution test

        for ($i = 0; $i < $iterations; $i++) {
            foreach ($routes as $route) {
                $startTime = microtime(true);

                try {
                    // Create a mock request for the route
                    $request = \Symfony\Component\HttpFoundation\Request::create($route, 'GET');

                    // This would normally go through Router::dispatch but we just want to measure matching
                    $router = Router::getInstance();
                    $context = new \Symfony\Component\Routing\RequestContext();
                    $context->fromRequest($request);
                    $matcher = new \Symfony\Component\Routing\Matcher\UrlMatcher(Router::getRoutes(), $context);

                    try {
                        $parameters = $matcher->match($request->getPathInfo());
                    } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
                        // Route not found, that's ok for this test
                    }
                } catch (\Exception $e) {
                    // Ignore errors for performance testing
                }

                $endTime = microtime(true);
                $totalTime += ($endTime - $startTime);
            }
        }

        return $totalTime / ($iterations * count($routes));
    }

    /**
     * Measure memory usage for route loading
     */
    private function measureMemoryUsage(string $method): int
    {
        $this->forceRouterReset();
        gc_collect_cycles(); // Clean up memory

        $memoryBefore = memory_get_usage(true);

        if ($method === 'cache') {
            $_ENV['APP_ENV'] = 'production';
            $_ENV['APP_DEBUG'] = 'false';
        } else {
            $_ENV['APP_ENV'] = 'development';
            $_ENV['APP_DEBUG'] = 'true';
        }

        // Load routes
        $router = Router::getInstance();
        $extensionManager = container()->get(ExtensionManager::class);
        $extensionManager->loadEnabledExtensions();
        $extensionManager->loadExtensionRoutes();
        RoutesManager::loadRoutes();

        $memoryAfter = memory_get_usage(true);

        return $memoryAfter - $memoryBefore;
    }

    /**
     * Create route cache
     */
    private function createRouteCache(): void
    {
        $this->forceRouterReset();

        // Set development environment to load routes normally first
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_DEBUG'] = 'true';

        $router = Router::getInstance();
        $extensionManager = container()->get(ExtensionManager::class);
        $extensionManager->loadEnabledExtensions();
        $extensionManager->loadExtensionRoutes();
        RoutesManager::loadRoutes();

        $cacheService = new RouteCacheService();
        $result = $cacheService->cacheRoutes($router);

        if (!$result['success']) {
            throw new \Exception("Failed to create route cache: " . $result['error']);
        }
    }

    /**
     * Clear route cache
     */
    private function clearRouteCache(): void
    {
        $cacheService = new RouteCacheService();
        $cacheFile = $cacheService->getCacheFilePath();

        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Force router reset
     */
    private function forceRouterReset(): void
    {
        // Reset router static state using reflection
        $reflection = new \ReflectionClass(Router::class);

        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null);

        $routesProperty = $reflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routesProperty->setValue(new \Symfony\Component\Routing\RouteCollection());

        $protectedRoutesProperty = $reflection->getProperty('protectedRoutes');
        $protectedRoutesProperty->setAccessible(true);
        $protectedRoutesProperty->setValue([]);

        $adminProtectedRoutesProperty = $reflection->getProperty('adminProtectedRoutes');
        $adminProtectedRoutesProperty->setAccessible(true);
        $adminProtectedRoutesProperty->setValue([]);

        $routeNameCacheProperty = $reflection->getProperty('routeNameCache');
        $routeNameCacheProperty->setAccessible(true);
        $routeNameCacheProperty->setValue([]);

        $routesLoadedFromCacheProperty = $reflection->getProperty('routesLoadedFromCache');
        $routesLoadedFromCacheProperty->setAccessible(true);
        $routesLoadedFromCacheProperty->setValue(false);
    }

    /**
     * Display comprehensive results
     */
    private function displayResults(): void
    {
        echo "📊 Performance Test Results\n";
        echo str_repeat("=", 50) . "\n\n";

        // Route Loading Results
        echo "🚀 Route Loading Performance:\n";
        echo "   • File-based: " . number_format($this->results['route_loading']['file'] * 1000, 2) . "ms\n";
        echo "   • Cache-based: " . number_format($this->results['route_loading']['cache'] * 1000, 2) . "ms\n";
        echo "   • Improvement: " . number_format($this->results['route_loading']['improvement'], 1) . "%\n\n";

        // Route Resolution Results
        echo "🔍 Route Resolution Performance:\n";
        echo "   • File-based: " . number_format($this->results['route_resolution']['file'] * 1000, 2) . "ms\n";
        echo "   • Cache-based: " . number_format($this->results['route_resolution']['cache'] * 1000, 2) . "ms\n";
        echo "   • Improvement: " . number_format($this->results['route_resolution']['improvement'], 1) . "%\n\n";

        // Memory Usage Results
        echo "💾 Memory Usage:\n";
        echo "   • File-based: " . $this->formatBytes($this->results['memory']['file']) . "\n";
        echo "   • Cache-based: " . $this->formatBytes($this->results['memory']['cache']) . "\n";
        echo "   • Reduction: " . number_format($this->results['memory']['reduction'], 1) . "%\n\n";

        // Overall Analysis
        $avgImprovement = ($this->results['route_loading']['improvement'] +
                          $this->results['route_resolution']['improvement']) / 2;

        echo "📈 Overall Analysis:\n";
        echo "   • Average performance improvement: " . number_format($avgImprovement, 1) . "%\n";
        echo "   • Memory reduction: " . number_format($this->results['memory']['reduction'], 1) . "%\n";
        echo "   • Recommended for production: " . ($avgImprovement > 30 ? "✅ YES" : "❌ NO") . "\n\n";

        // Recommendations
        echo "💡 Recommendations:\n";
        if ($avgImprovement > 50) {
            echo "   • Excellent performance gains! Deploy route cache to production.\n";
        } elseif ($avgImprovement > 30) {
            echo "   • Good performance gains. Route cache recommended for production.\n";
        } else {
            echo "   • Moderate gains. Consider route cache for high-traffic applications.\n";
        }

        echo "   • Use 'php glueful route:cache' to create cache\n";
        echo "   • Use 'php glueful route:clear' during development\n";
        echo "   • Cache is automatically used in APP_ENV=production\n";
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Run the performance test
if (php_sapi_name() === 'cli') {
    $test = new RouteCachePerformanceTest();
    $test->runPerformanceTests();
}
