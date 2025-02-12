<?php

if (!function_exists('env')) {
    /**
     * Gets the value of an environment variable
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
     */
    function config(string $key, $default = null) {
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
