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
        
        // Split the key into filename and key parts
        [$file, $setting] = explode('.', $key);
        
        // Load config file if not cached
        if (!isset($config[$file])) {
            $path = dirname(__DIR__) . "/config/{$file}.php";
            $config[$file] = file_exists($path) ? require $path : [];
        }
        
        return $config[$file][$setting] ?? $default;
    }
}
