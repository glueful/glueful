<?php
declare(strict_types=1);

/**
 * Global Helper Functions
 * 
 * This file contains globally accessible helper functions for environment
 * variables and configuration management.
 */

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

/**
 * Additional helper functions can be added below.
 * Each function should have proper documentation and
 * be wrapped in function_exists() check.
 */
