<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Services\FileFinder;

class RoutesManager
{
    protected static string $routesDir = __DIR__ . '/../../routes';

    /**
     * Load all route files from the routes directory.
     * Skips loading if routes are already loaded from cache for performance.
     */
    public static function loadRoutes(): void
    {
        // Skip loading if routes are already loaded from cache
        if (\Glueful\Http\Router::isUsingCachedRoutes()) {
            return;
        }

        $fileFinder = container()->get(FileFinder::class);
        $routeFiles = $fileFinder->findRouteFiles([self::$routesDir]);

        if (!$routeFiles->valid()) {
            throw new \Exception("No route files found in directory: " . self::$routesDir);
        }

        foreach ($routeFiles as $file) {
            require_once $file->getPathname();
        }
    }
}
