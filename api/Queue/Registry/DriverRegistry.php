<?php

namespace Glueful\Queue\Registry;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\DriverInfo;
use Glueful\Queue\Discovery\DriverDiscovery;
use Glueful\Queue\Exceptions\DriverNotFoundException;
use Glueful\Queue\Exceptions\InvalidConfigurationException;
use Glueful\Queue\Plugins\PluginManager;

/**
 * Driver Registry System
 *
 * Central registry for managing queue driver instances and configurations.
 * Provides driver discovery, instantiation, caching, and validation.
 *
 * Features:
 * - Automatic driver discovery and loading
 * - Driver instance caching for performance
 * - Configuration validation and schema checking
 * - Lazy loading and initialization
 * - Plugin and extension support
 *
 * Registry Lifecycle:
 * 1. Discovery phase - scan for available drivers
 * 2. Registration phase - register discovered drivers
 * 3. Instantiation phase - create driver instances on demand
 * 4. Caching phase - cache instances for reuse
 *
 * @package Glueful\Queue\Registry
 */
class DriverRegistry
{
    /** @var array Registered driver definitions */
    private array $drivers = [];

    /** @var array Cached driver instances */
    private array $instances = [];

    /** @var DriverDiscovery Driver discovery service */
    private DriverDiscovery $discovery;

    /** @var PluginManager|null Plugin manager instance */
    private ?PluginManager $pluginManager = null;

    /**
     * Initialize driver registry
     */
    public function __construct()
    {
        $this->discovery = new DriverDiscovery();
        $this->loadDrivers();
        $this->initializePluginManager();
    }

    /**
     * Discover and load all available drivers
     *
     * @return void
     */
    public function loadDrivers(): void
    {
        $discovered = $this->discovery->discoverDrivers();
        foreach ($discovered as $name => $driver) {
            $this->registerDriver($name, $driver['class'], $driver['info']);
        }
    }

    /**
     * Register a driver manually
     *
     * @param string $name Driver name
     * @param string $className Driver class name
     * @param DriverInfo $info Driver information
     * @return void
     */
    public function registerDriver(string $name, string $className, DriverInfo $info): void
    {
        $this->drivers[$name] = [
            'class' => $className,
            'info' => $info
        ];
    }

    /**
     * Get driver instance with configuration
     *
     * @param string $name Driver name
     * @param array $config Driver configuration
     * @return QueueDriverInterface Driver instance
     * @throws DriverNotFoundException If driver not found
     * @throws InvalidConfigurationException If configuration invalid
     */
    public function getDriver(string $name, array $config = []): QueueDriverInterface
    {
        $cacheKey = $name . ':' . md5(serialize($config));

        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }

        if (!isset($this->drivers[$name])) {
            throw new DriverNotFoundException("Queue driver '{$name}' not found");
        }

        $className = $this->drivers[$name]['class'];
        $instance = new $className();

        if (!empty($config)) {
            // Validate configuration against driver schema
            $errors = $this->validateConfig($name, $config);
            if (!empty($errors)) {
                throw new InvalidConfigurationException(
                    "Invalid configuration for driver '{$name}': " . implode(', ', $errors)
                );
            }

            $instance->initialize($config);
        }

        $this->instances[$cacheKey] = $instance;
        return $instance;
    }

    /**
     * Check if driver is registered
     *
     * @param string $name Driver name
     * @return bool True if driver exists
     */
    public function hasDriver(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    /**
     * Get list of all registered drivers
     *
     * @return array Driver names
     */
    public function getDriverNames(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Get driver information
     *
     * @param string $name Driver name
     * @return DriverInfo|null Driver info or null if not found
     */
    public function getDriverInfo(string $name): ?DriverInfo
    {
        return $this->drivers[$name]['info'] ?? null;
    }

    /**
     * Get all driver information
     *
     * @return array Driver information indexed by name
     */
    public function getAllDriverInfo(): array
    {
        $info = [];
        foreach ($this->drivers as $name => $driver) {
            $info[$name] = $driver['info'];
        }
        return $info;
    }

    /**
     * Validate configuration against driver schema
     *
     * @param string $driverName Driver name
     * @param array $config Configuration to validate
     * @return array Validation errors (empty if valid)
     */
    public function validateConfig(string $driverName, array $config): array
    {
        if (!isset($this->drivers[$driverName])) {
            return ['Driver not found'];
        }

        try {
            $className = $this->drivers[$driverName]['class'];
            $tempInstance = new $className();
            $schema = $tempInstance->getConfigSchema();

            return $this->validateConfigAgainstSchema($config, $schema);
        } catch (\Exception $e) {
            return ['Failed to validate configuration: ' . $e->getMessage()];
        }
    }

    /**
     * Validate configuration against schema definition
     *
     * @param array $config Configuration values
     * @param array $schema Schema definition
     * @return array Validation errors
     */
    private function validateConfigAgainstSchema(array $config, array $schema): array
    {
        $errors = [];

        foreach ($schema as $key => $rules) {
            $value = $config[$key] ?? null;

            // Check required fields
            if (($rules['required'] ?? false) && ($value === null || $value === '')) {
                $errors[] = "Required field '{$key}' is missing";
                continue;
            }

            // Skip validation if field is not provided and not required
            if ($value === null) {
                continue;
            }

            // Type validation
            if (isset($rules['type'])) {
                $typeError = $this->validateType($key, $value, $rules['type']);
                if ($typeError) {
                    $errors[] = $typeError;
                }
            }

            // Custom validation rules
            if (isset($rules['validator']) && is_callable($rules['validator'])) {
                $result = call_user_func($rules['validator'], $value);
                if ($result !== true) {
                    $errors[] = "Field '{$key}': {$result}";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate value type
     *
     * @param string $key Field name
     * @param mixed $value Value to validate
     * @param string $expectedType Expected type
     * @return string|null Error message or null if valid
     */
    private function validateType(string $key, $value, string $expectedType): ?string
    {
        switch ($expectedType) {
            case 'string':
                if (!is_string($value)) {
                    return "Field '{$key}' must be a string";
                }
                break;
            case 'int':
            case 'integer':
                if (!is_int($value)) {
                    return "Field '{$key}' must be an integer";
                }
                break;
            case 'port':
                if (!is_int($value) || $value < 1 || $value > 65535) {
                    return "Field '{$key}' must be a valid port number (1-65535)";
                }
                break;
            case 'bool':
            case 'boolean':
                if (!is_bool($value)) {
                    return "Field '{$key}' must be a boolean";
                }
                break;
            case 'array':
                if (!is_array($value)) {
                    return "Field '{$key}' must be an array";
                }
                break;
        }

        return null;
    }

    /**
     * Clear driver instance cache
     *
     * @param string|null $driverName Specific driver to clear or null for all
     * @return void
     */
    public function clearCache(?string $driverName = null): void
    {
        if ($driverName === null) {
            $this->instances = [];
        } else {
            foreach ($this->instances as $key => $instance) {
                if (strpos($key, $driverName . ':') === 0) {
                    unset($this->instances[$key]);
                }
            }
        }
    }

    /**
     * Get driver discovery service
     *
     * @return DriverDiscovery Discovery service instance
     */
    public function getDiscovery(): DriverDiscovery
    {
        return $this->discovery;
    }

    /**
     * Refresh driver registry (rediscover drivers)
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->drivers = [];
        $this->instances = [];
        $this->loadDrivers();
    }

    /**
     * Initialize plugin manager and connect it to registry
     *
     * @return void
     */
    private function initializePluginManager(): void
    {
        $this->pluginManager = new PluginManager();
        $this->pluginManager->setDriverRegistry($this);
    }

    /**
     * Get plugin manager instance
     *
     * @return PluginManager Plugin manager
     */
    public function getPluginManager(): PluginManager
    {
        if ($this->pluginManager === null) {
            $this->initializePluginManager();
        }
        return $this->pluginManager;
    }
}
