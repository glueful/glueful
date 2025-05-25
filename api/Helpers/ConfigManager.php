<?php

declare(strict_types=1);

namespace Glueful\Helpers;

/**
 * Centralized Configuration Manager
 *
 * Handles loading, caching, and accessing all configuration files
 * with environment-aware loading and validation.
 */
class ConfigManager
{
    private static array $config = [];
    private static bool $loaded = false;
    private static string $environment;
    private static array $requiredConfigs = [
        'app', 'database', 'security', 'cache'
    ];

    public static function load(?string $configPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $configPath = $configPath ?: dirname(__DIR__, 2) . '/config';
        self::$environment = $_ENV['APP_ENV'] ?? 'production';

        // Load core configurations first
        foreach (self::$requiredConfigs as $config) {
            self::loadConfigFile($configPath, $config);
        }

        // Load remaining configurations
        $configFiles = glob($configPath . '/*.php');
        foreach ($configFiles as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, self::$requiredConfigs)) {
                self::loadConfigFile($configPath, $name);
            }
        }

        // Load environment-specific overrides
        self::loadEnvironmentOverrides($configPath);

        // Validate critical configurations
        self::validateConfig();

        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::getNestedValue(self::$config, $key, $default);
    }

    public static function set(string $key, $value): void
    {
        self::setNestedValue(self::$config, $key, $value);
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config;
    }

    private static function loadConfigFile(string $path, string $name): void
    {
        $file = $path . '/' . $name . '.php';
        if (file_exists($file)) {
            self::$config[$name] = require $file;
        }
    }

    private static function loadEnvironmentOverrides(string $configPath): void
    {
        $envFile = $configPath . '/environments/' . self::$environment . '.php';
        if (file_exists($envFile)) {
            $overrides = require $envFile;
            self::$config = array_merge_recursive(self::$config, $overrides);
        }
    }

    private static function validateConfig(): void
    {
        $errors = [];

        // Validate database config
        if (!self::get('database.default')) {
            $errors[] = 'Database default connection not configured';
        }

        // Validate security config
        if (!self::get('security.jwt.secret') && self::$environment === 'production') {
            $errors[] = 'JWT secret not configured for production';
        }

        // Validate app config
        if (!self::get('app.key') && self::$environment === 'production') {
            $errors[] = 'Application key not configured for production';
        }

        if (!empty($errors)) {
            throw new \RuntimeException('Configuration validation failed: ' . implode(', ', $errors));
        }
    }

    private static function getNestedValue(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;
    }
}
