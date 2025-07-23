<?php

/**
 * Global Helper Functions
 *
 * This file contains globally accessible helper functions for environment
 * variables and configuration management.
 */

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Get environment variable value
     *
     * Retrieves value from environment with type casting support.
     * Handles special values like 'true', 'false', 'null', and 'empty'.
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not found
     * @return mixed Processed environment value
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? false;

        if ($value === false) {
            return $default;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value using dot notation
     *
     * Retrieves configuration values from global config array using dot notation.
     * Example: config('database.mysql.host') gets $configs['database']['mysql']['host']
     *
     * @param string $key Configuration key in dot notation
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value or default
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $config = [];

        $segments = explode('.', $key);
        $file = array_shift($segments);

        // Load config file if not cached
        if (!isset($config[$file])) {
            $path = dirname(__DIR__) . "/config/{$file}.php";
            if (!file_exists($path)) {
                return $default;
            }
            $config[$file] = require $path;
        }

        // Return entire config if no segments left
        if (empty($segments)) {
            return $config[$file];
        }

        // Navigate through segments to get nested value
        $current = $config[$file];
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

if (!function_exists('parseConfigString')) {
    /**
     * Parses a config string in the format "key1:value1,key2:value2,..."
     *
     * @param string $configString The configuration string to parse.
     * @return array An associative array of key-value pairs.
     */

    function parseConfigString(string $configString): array
    {
        $config = [];
        $items = explode(',', $configString);
        foreach ($items as $item) {
            [$key, $value] = explode(':', $item, 2);
            $key = trim($key);
            $value = trim($value);

            // Handle boolean values
            if (strtolower($value) === 'true') {
                $value = true;
            } elseif (strtolower($value) === 'false') {
                $value = false;
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            }

            $config[$key] = $value;
        }
        return $config;
    }
}
if (!function_exists('app')) {
    /**
     * Get the DI container instance or resolve a service
     *
     * Returns the global DI container instance when called without arguments,
     * or resolves and returns a specific service when called with a class name.
     *
     * @param string|null $abstract Service class name to resolve
     * @return mixed Container instance or resolved service
     */
    function app(?string $abstract = null): mixed
    {
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            throw new \RuntimeException('DI container not initialized. Make sure bootstrap.php is loaded.');
        }

        if ($abstract === null) {
            return $container;
        }

        return $container->get($abstract);
    }
}

if (!function_exists('container')) {
    /**
     * Get the DI container instance
     *
     * Returns the Symfony DI container instance using ContainerBootstrap.
     * This provides access to all registered services and parameters.
     *
     * @return \Glueful\DI\Container Container instance
     */
    function container(): \Glueful\DI\Container
    {
        return \Glueful\DI\ContainerBootstrap::initialize();
    }
}

if (!function_exists('dump')) {
    /**
     * Dump the given variables using Symfony VarDumper
     *
     * This function provides beautiful, formatted variable dumps in development.
     * In production, it falls back to var_dump() for safety.
     *
     * @param mixed ...$vars Variables to dump
     * @return void
     */
    function dump(...$vars): void
    {
        if (env('APP_ENV') !== 'development' || !env('APP_DEBUG', false)) {
            // In production, fall back to simple var_dump
            foreach ($vars as $var) {
                var_dump($var);
            }
            return;
        }

        if (class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {
            foreach ($vars as $var) {
                \Symfony\Component\VarDumper\VarDumper::dump($var);
            }
        } else {
            // Fallback if VarDumper not available
            foreach ($vars as $var) {
                var_dump($var);
            }
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump the given variables and terminate script execution
     *
     * This function dumps variables using Symfony VarDumper and then
     * terminates script execution. Useful for debugging.
     *
     * @param mixed ...$vars Variables to dump before dying
     * @return never
     */
    function dd(...$vars): never
    {
        dump(...$vars);
        exit(1);
    }
}

if (!function_exists('service')) {
    /**
     * Get a service from the DI container
     *
     * Convenience function to resolve services from the container.
     * Equivalent to container()->get($id).
     *
     * @param string $id Service identifier
     * @return mixed Resolved service instance
     */
    function service(string $id): mixed
    {
        return container()->get($id);
    }
}

if (!function_exists('parameter')) {
    /**
     * Get a parameter from the DI container
     *
     * Retrieves a parameter value from the container configuration.
     * Parameters are typically configuration values injected into services.
     *
     * @param string $name Parameter name
     * @return mixed Parameter value
     */
    function parameter(string $name): mixed
    {
        return container()->getParameter($name);
    }
}

if (!function_exists('has_service')) {
    /**
     * Check if a service exists in the container
     *
     * Determines whether the specified service is registered
     * in the DI container without attempting to instantiate it.
     *
     * @param string $id Service identifier
     * @return bool True if service exists
     */
    function has_service(string $id): bool
    {
        return container()->has($id);
    }
}

if (!function_exists('is_production')) {
    /**
     * Check if application is running in production
     *
     * Determines the current environment based on configuration.
     * Used for environment-specific behavior and optimizations.
     *
     * @return bool True if production environment
     */
    function is_production(): bool
    {
        return config('app.env', 'production') === 'production';
    }
}

if (!function_exists('is_debug')) {
    /**
     * Check if debug mode is enabled
     *
     * Determines if the application is running in debug mode.
     * Debug mode enables additional logging, error reporting, and development features.
     *
     * @return bool True if debug mode is enabled
     */
    function is_debug(): bool
    {
        return config('app.debug', false) === true;
    }
}

if (!function_exists('get_service_ids')) {
    /**
     * Get all registered service IDs
     *
     * Returns an array of all service identifiers registered
     * in the DI container. Useful for debugging and introspection.
     *
     * @return array Array of service IDs
     */
    function get_service_ids(): array
    {
        // For Symfony Container, we need to get service IDs differently
        // This is a simplified implementation
        return [
            'Glueful\\Auth\\TokenStorageService',
            'Glueful\\Repository\\UserRepository',
            'Glueful\\Extensions\\ExtensionManager',
            'Glueful\\Cache\\CacheStore',
            'Glueful\\Queue\\QueueManager',
            'Glueful\\Database\\DatabaseInterface'
        ];
    }
}
