<?php

namespace Glueful\Queue\Config;

/**
 * Queue Configuration Manager
 *
 * Manages loading, validation, and access to queue configuration.
 * Provides centralized configuration management with validation,
 * caching, and environment variable resolution.
 *
 * Features:
 * - Configuration loading and parsing
 * - Automatic validation
 * - Environment variable resolution
 * - Configuration caching
 * - Hot-reloading support
 * - Configuration merging
 *
 * @package Glueful\Queue\Config
 */
class ConfigManager
{
    /** @var array Loaded configuration */
    private array $config = [];

    /** @var ConfigValidator Configuration validator */
    private ConfigValidator $validator;

    /** @var string Configuration file path */
    private string $configPath;

    /** @var bool Whether configuration is loaded */
    private bool $loaded = false;

    /** @var array Configuration cache */
    private static array $cache = [];

    /** @var array Default configuration values */
    private array $defaults = [
        'default' => 'database',
        'connections' => [
            'database' => [
                'driver' => 'database',
                'table' => 'queue_jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
                'failed_table' => 'queue_failed_jobs',
            ],
        ],
        'failed' => [
            'driver' => 'database',
            'database' => 'default',
            'table' => 'queue_failed_jobs',
            'max_retries' => 5,
            'retention_days' => 30,
        ],
        'monitoring' => [
            'enabled' => true,
            'metrics_retention_days' => 30,
            'worker_heartbeat_timeout' => 120,
        ],
        'workers' => [
            'resource_limits' => [
                'memory_limit' => '512M',
                'time_limit' => 3600,
                'job_timeout' => 300,
                'max_jobs_per_worker' => 1000,
            ],
            'performance' => [
                'sleep_seconds' => 3,
                'max_tries' => 3,
                'backoff_strategy' => 'exponential',
                'backoff_base' => 2,
                'max_backoff' => 3600,
            ],
        ],
        'performance' => [
            'connection_pooling' => [
                'enabled' => true,
                'min_connections' => 1,
                'max_connections' => 10,
                'idle_timeout' => 300,
            ],
            'job_priority' => [
                'enabled' => true,
                'default_priority' => 0,
            ],
        ],
        'security' => [
            'encryption' => [
                'enabled' => false,
            ],
            'authentication' => [
                'enabled' => false,
            ],
        ],
        'development' => [
            'debug' => false,
            'log_level' => 'info',
        ],
    ];

    /**
     * Create configuration manager
     *
     * @param string|null $configPath Configuration file path
     * @param ConfigValidator|null $validator Configuration validator
     */
    public function __construct(?string $configPath = null, ?ConfigValidator $validator = null)
    {
        $this->configPath = $configPath ?? $this->getDefaultConfigPath();
        $this->validator = $validator ?? new ConfigValidator();
    }

    /**
     * Load configuration from file
     *
     * @param bool $force Force reload even if already loaded
     * @return void
     * @throws \RuntimeException If configuration cannot be loaded
     */
    public function load(bool $force = false): void
    {
        if ($this->loaded && !$force) {
            return;
        }

        // Check cache first
        $cacheKey = md5($this->configPath);
        if (!$force && isset(self::$cache[$cacheKey])) {
            $this->config = self::$cache[$cacheKey];
            $this->loaded = true;
            return;
        }

        // Load configuration file
        if (!file_exists($this->configPath)) {
            throw new \RuntimeException("Configuration file not found: {$this->configPath}");
        }

        $config = require $this->configPath;

        if (!is_array($config)) {
            throw new \RuntimeException("Configuration file must return an array");
        }

        // Merge with defaults
        $this->config = $this->mergeConfigWithDefaults($config);

        // Resolve environment variables
        $this->config = $this->resolveEnvironmentVariables($this->config);

        // Validate configuration
        $this->validateConfiguration();

        // Cache the configuration
        self::$cache[$cacheKey] = $this->config;
        $this->loaded = true;
    }

    /**
     * Get configuration value
     *
     * @param string|null $key Configuration key (dot notation supported)
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get(?string $key = null, $default = null)
    {
        $this->ensureLoaded();

        if ($key === null) {
            return $this->config;
        }

        return $this->getNestedValue($this->config, $key, $default);
    }

    /**
     * Set configuration value
     *
     * @param string $key Configuration key (dot notation supported)
     * @param mixed $value Configuration value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->ensureLoaded();
        $this->setNestedValue($this->config, $key, $value);

        // Clear cache when configuration is modified
        $this->clearCache();
    }

    /**
     * Check if configuration key exists
     *
     * @param string $key Configuration key (dot notation supported)
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        $this->ensureLoaded();
        return $this->getNestedValue($this->config, $key, '__NOT_FOUND__') !== '__NOT_FOUND__';
    }

    /**
     * Get all configuration
     *
     * @return array Complete configuration array
     */
    public function all(): array
    {
        $this->ensureLoaded();
        return $this->config;
    }

    /**
     * Get connection configuration
     *
     * @param string|null $connection Connection name (default if null)
     * @return array Connection configuration
     * @throws \InvalidArgumentException If connection not found
     */
    public function getConnection(?string $connection = null): array
    {
        $this->ensureLoaded();

        $connectionName = $connection ?? $this->get('default');
        $connections = $this->get('connections', []);

        if (!isset($connections[$connectionName])) {
            throw new \InvalidArgumentException("Queue connection '{$connectionName}' is not configured");
        }

        return $connections[$connectionName];
    }

    /**
     * Get all connection names
     *
     * @return array Connection names
     */
    public function getConnectionNames(): array
    {
        $this->ensureLoaded();
        return array_keys($this->get('connections', []));
    }

    /**
     * Validate current configuration
     *
     * @return ValidationResult Validation result
     */
    public function validate(): ValidationResult
    {
        $this->ensureLoaded();
        return $this->validator->validate($this->config);
    }

    /**
     * Reload configuration from file
     *
     * @return void
     */
    public function reload(): void
    {
        $this->clearCache();
        $this->loaded = false;
        $this->load();
    }

    /**
     * Clear configuration cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Export configuration to array
     *
     * @param bool $includeDefaults Whether to include default values
     * @return array Configuration array
     */
    public function toArray(bool $includeDefaults = true): array
    {
        $this->ensureLoaded();

        if (!$includeDefaults) {
            // Return only explicitly set values
            $config = require $this->configPath;
            return $this->resolveEnvironmentVariables($config);
        }

        return $this->config;
    }

    /**
     * Export configuration to JSON
     *
     * @param bool $includeDefaults Whether to include default values
     * @param int $flags JSON encode flags
     * @return string JSON representation
     */
    public function toJson(bool $includeDefaults = true, int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray($includeDefaults), $flags);
    }

    /**
     * Merge configuration with defaults
     *
     * @param array $config User configuration
     * @return array Merged configuration
     */
    private function mergeConfigWithDefaults(array $config): array
    {
        return $this->mergeArraysRecursive($this->defaults, $config);
    }

    /**
     * Resolve environment variables in configuration
     *
     * @param array $config Configuration array
     * @return array Configuration with resolved environment variables
     */
    private function resolveEnvironmentVariables(array $config): array
    {
        return $this->resolveEnvRecursive($config);
    }

    /**
     * Recursively resolve environment variables
     *
     * @param mixed $value Value to resolve
     * @return mixed Resolved value
     */
    private function resolveEnvRecursive($value)
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $val) {
                $resolved[$key] = $this->resolveEnvRecursive($val);
            }
            return $resolved;
        }

        if (is_string($value) && str_starts_with($value, 'env(') && str_ends_with($value, ')')) {
            // Extract env() function call
            $envCall = substr($value, 4, -1);
            $parts = explode(',', $envCall, 2);
            $envKey = trim($parts[0], '\'" ');
            $defaultValue = isset($parts[1]) ? trim($parts[1], '\'" ') : null;

            $envValue = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey);

            if ($envValue === false) {
                return $defaultValue;
            }

            // Convert common string representations to appropriate types
            if (in_array(strtolower($envValue), ['true', 'false'])) {
                return strtolower($envValue) === 'true';
            }

            if (is_numeric($envValue)) {
                return str_contains($envValue, '.') ? (float) $envValue : (int) $envValue;
            }

            return $envValue;
        }

        return $value;
    }

    /**
     * Validate configuration
     *
     * @return void
     * @throws \RuntimeException If validation fails
     */
    private function validateConfiguration(): void
    {
        $result = $this->validator->validate($this->config);

        if (!$result->isValid()) {
            $errors = implode("\n", $result->getErrors());
            throw new \RuntimeException("Queue configuration validation failed:\n{$errors}");
        }

        // Log warnings if any
        if ($result->hasWarnings()) {
            foreach ($result->getWarnings() as $warning) {
                error_log("Queue configuration warning: {$warning}");
            }
        }
    }

    /**
     * Ensure configuration is loaded
     *
     * @return void
     */
    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }

    /**
     * Get nested array value using dot notation
     *
     * @param array $array Array to search
     * @param string $key Dot-notated key
     * @param mixed $default Default value
     * @return mixed Found value or default
     */
    private function getNestedValue(array $array, string $key, $default = null)
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

    /**
     * Set nested array value using dot notation
     *
     * @param array &$array Array to modify
     * @param string $key Dot-notated key
     * @param mixed $value Value to set
     * @return void
     */
    private function setNestedValue(array &$array, string $key, $value): void
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

    /**
     * Recursively merge arrays
     *
     * @param array $default Default array
     * @param array $override Override array
     * @return array Merged array
     */
    private function mergeArraysRecursive(array $default, array $override): array
    {
        $result = $default;

        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->mergeArraysRecursive($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get default configuration file path
     *
     * @return string Default path
     */
    private function getDefaultConfigPath(): string
    {
        return __DIR__ . '/../../../config/queue.php';
    }
}
