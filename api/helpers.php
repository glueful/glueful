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
     * Returns the global DI container instance. This is a convenience
     * function that's equivalent to calling app() without arguments.
     *
     * @return \Glueful\DI\Interfaces\ContainerInterface Container instance
     */
    function container(): \Glueful\DI\Interfaces\ContainerInterface
    {
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            throw new \RuntimeException('DI container not initialized. Make sure bootstrap.php is loaded.');
        }

        return $container;
    }
}

/**
 * Additional helper functions can be added below.
 * Each function should have proper documentation and
 * be wrapped in function_exists() check.
 */
