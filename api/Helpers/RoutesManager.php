<?php

declare(strict_types=1);

namespace Glueful\Helpers;

class RoutesManager
{
    protected static string $routesDir = __DIR__ . '/../../routes';

    /**
     * Load all route files from the routes directory.
     */
    public static function loadRoutes(): void
    {
        if (!is_dir(self::$routesDir)) {
            throw new \Exception("Routes directory not found: " . self::$routesDir);
        }

        foreach (glob(self::$routesDir . '/*.php') as $file) {
            require_once $file;
        }
    }
}
