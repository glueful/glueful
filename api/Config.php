<?php

namespace Mapi\Api;

class Config
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        // Check for dot notation
        if (str_contains($key, '.')) {
            [$file, $setting] = explode('.', $key, 2);
            return self::loadConfig($file, $setting, $default);
        }

        // First check environment variables
        $envValue = $_ENV[$key] ?? null;
        if ($envValue !== null) {
            return self::castValue($envValue);
        }

        // Then check our cached values
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$cache[$key] = $value;
    }

    private static function loadConfig(string $file, string $key, mixed $default = null): mixed
    {
        if (!isset(self::$cache[$file])) {
            $path = dirname(__DIR__) . "/config/{$file}.php";
            self::$cache[$file] = file_exists($path) ? require $path : [];
        }

        return self::$cache[$file][$key] ?? $default;
    }

    private static function castValue(string $value): mixed
    {
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        return $value;
    }
}
