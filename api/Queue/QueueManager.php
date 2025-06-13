<?php

namespace Glueful\Queue;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Registry\DriverRegistry;
use Glueful\Queue\Plugins\PluginManager;
use Glueful\Queue\Exceptions\DriverNotFoundException;
use Glueful\Queue\Exceptions\InvalidConfigurationException;

/**
 * Queue Manager
 *
 * Central queue management system that handles driver connections,
 * configuration, and provides a unified interface for queue operations.
 *
 * Features:
 * - Connection management and pooling
 * - Driver discovery and instantiation
 * - Configuration validation
 * - Plugin system integration
 * - Multiple queue driver support
 * - Lazy connection loading
 *
 * Usage:
 * ```php
 * $manager = new QueueManager($config);
 * $manager->push('ProcessEmail', ['to' => 'user@example.com']);
 * $manager->later(300, 'SendReminder', ['user_id' => 123]);
 * ```
 *
 * @package Glueful\Queue
 */
class QueueManager
{
    /** @var DriverRegistry Driver registry for managing available drivers */
    private DriverRegistry $registry;

    /** @var PluginManager Plugin manager for extensibility */
    private PluginManager $plugins;

    /** @var array Active driver connections */
    private array $connections = [];

    /** @var array Queue configuration */
    private array $config;

    /** @var string Default connection name */
    private string $defaultConnection;

    /**
     * Create queue manager
     *
     * @param array $config Queue configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->normalizeConfig($config);
        $this->defaultConnection = $this->config['default'] ?? 'database';

        // Initialize registry and plugins
        $this->registry = new DriverRegistry();
        $this->plugins = new PluginManager();

        // Set up plugin integration
        $this->plugins->setDriverRegistry($this->registry);

        // Execute plugin initialization hooks
        $this->plugins->executeHook('queue_manager_init', ['config' => $this->config]);
    }

    /**
     * Get queue connection
     *
     * @param string|null $name Connection name (null for default)
     * @return QueueDriverInterface Queue driver instance
     * @throws DriverNotFoundException If connection/driver not found
     * @throws InvalidConfigurationException If configuration invalid
     */
    public function connection(?string $name = null): QueueDriverInterface
    {
        $name = $name ?? $this->defaultConnection;

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Push job to queue
     *
     * @param string $job Job class name
     * @param array $data Job data
     * @param string|null $queue Queue name
     * @param string|null $connection Connection name
     * @return string Job UUID
     */
    public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
    {
        return $this->connection($connection)->push($job, $data, $queue);
    }

    /**
     * Push delayed job to queue
     *
     * @param int $delay Delay in seconds
     * @param string $job Job class name
     * @param array $data Job data
     * @param string|null $queue Queue name
     * @param string|null $connection Connection name
     * @return string Job UUID
     */
    public function later(
        int $delay,
        string $job,
        array $data = [],
        ?string $queue = null,
        ?string $connection = null
    ): string {
        return $this->connection($connection)->later($delay, $job, $data, $queue);
    }

    /**
     * Push multiple jobs in bulk
     *
     * @param array $jobs Array of job definitions
     * @param string|null $queue Queue name
     * @param string|null $connection Connection name
     * @return array Array of job UUIDs
     */
    public function bulk(array $jobs, ?string $queue = null, ?string $connection = null): array
    {
        return $this->connection($connection)->bulk($jobs, $queue);
    }

    /**
     * Get queue size
     *
     * @param string|null $queue Queue name
     * @param string|null $connection Connection name
     * @return int Number of jobs
     */
    public function size(?string $queue = null, ?string $connection = null): int
    {
        return $this->connection($connection)->size($queue);
    }

    /**
     * Purge queue
     *
     * @param string|null $queue Queue name
     * @param string|null $connection Connection name
     * @return int Number of jobs purged
     */
    public function purge(?string $queue = null, ?string $connection = null): int
    {
        return $this->connection($connection)->purge($queue);
    }

    /**
     * Get queue statistics
     *
     * @param string|null $queue Queue name
     * @param string|null $connection Connection name
     * @return array Statistics
     */
    public function getStats(?string $queue = null, ?string $connection = null): array
    {
        return $this->connection($connection)->getStats($queue);
    }

    /**
     * Check if connection exists
     *
     * @param string $name Connection name
     * @return bool True if connection exists
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->config['connections'][$name]);
    }

    /**
     * Get available connections
     *
     * @return array Connection names
     */
    public function getAvailableConnections(): array
    {
        return array_keys($this->config['connections'] ?? []);
    }

    /**
     * Get available drivers
     *
     * @return array Driver information
     */
    public function getAvailableDrivers(): array
    {
        $drivers = [];
        $driverInfo = $this->registry->getAllDriverInfo();

        foreach ($driverInfo as $name => $info) {
            $drivers[] = [
                'name' => $info->name,
                'version' => $info->version,
                'author' => $info->author,
                'description' => $info->description,
                'features' => $info->supportedFeatures,
                'dependencies' => $info->requiredDependencies
            ];
        }

        return $drivers;
    }

    /**
     * Get driver registry
     *
     * @return DriverRegistry Driver registry
     */
    public function getDriverRegistry(): DriverRegistry
    {
        return $this->registry;
    }

    /**
     * Get plugin manager
     *
     * @return PluginManager Plugin manager
     */
    public function getPluginManager(): PluginManager
    {
        return $this->plugins;
    }

    /**
     * Get configuration
     *
     * @return array Configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Create new connection
     *
     * @param string $name Connection name
     * @return QueueDriverInterface Driver instance
     * @throws DriverNotFoundException If driver not found
     * @throws InvalidConfigurationException If configuration invalid
     */
    private function createConnection(string $name): QueueDriverInterface
    {
        $config = $this->getConnectionConfig($name);
        $driverName = $config['driver'];

        if (!$this->registry->hasDriver($driverName)) {
            throw new DriverNotFoundException("Queue driver '{$driverName}' not found");
        }

        // Validate configuration
        $errors = $this->registry->validateConfig($driverName, $config);
        if (!empty($errors)) {
            throw new InvalidConfigurationException("Invalid configuration for '{$name}': " . implode(', ', $errors));
        }

        $driver = $this->registry->getDriver($driverName, $config);

        // Execute plugin hooks
        $this->plugins->executeHook('driver_created', [
            'driver' => $driver,
            'name' => $name,
            'config' => $config
        ]);

        return $driver;
    }

    /**
     * Get connection configuration
     *
     * @param string $name Connection name
     * @return array Connection config
     * @throws InvalidConfigurationException If connection not configured
     */
    private function getConnectionConfig(string $name): array
    {
        if (!isset($this->config['connections'][$name])) {
            throw new InvalidConfigurationException("Queue connection '{$name}' is not configured");
        }

        $config = $this->config['connections'][$name];

        // Ensure driver is specified
        if (!isset($config['driver'])) {
            throw new InvalidConfigurationException("Driver not specified for connection '{$name}'");
        }

        return $config;
    }

    /**
     * Normalize configuration with defaults
     *
     * @param array $config Raw configuration
     * @return array Normalized configuration
     */
    private function normalizeConfig(array $config): array
    {
        // Set defaults
        $normalized = [
            'default' => $config['default'] ?? 'database',
            'connections' => $config['connections'] ?? []
        ];

        // Add default database connection if none specified
        if (empty($normalized['connections'])) {
            $normalized['connections']['database'] = [
                'driver' => 'database',
                'table' => 'queue_jobs',
                'failed_table' => 'queue_failed_jobs',
                'retry_after' => 90
            ];
        }

        // Add default Redis connection if Redis is available
        if (extension_loaded('redis') && !isset($normalized['connections']['redis'])) {
            $normalized['connections']['redis'] = [
                'driver' => 'redis',
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'prefix' => 'glueful:queue:',
                'retry_after' => 90
            ];
        }

        return $normalized;
    }

    /**
     * Close all connections
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->connections = [];
    }

    /**
     * Reset connection (force reconnect)
     *
     * @param string|null $name Connection name (null for all)
     * @return void
     */
    public function reconnect(?string $name = null): void
    {
        if ($name === null) {
            $this->connections = [];
        } else {
            unset($this->connections[$name]);
        }
    }

    /**
     * Test connection health
     *
     * @param string|null $name Connection name
     * @return array Health status
     */
    public function testConnection(?string $name = null): array
    {
        try {
            $driver = $this->connection($name);
            $health = $driver->healthCheck();

            return [
                'connection' => $name ?? $this->defaultConnection,
                'healthy' => $health->isHealthy(),
                'message' => $health->message,
                'metrics' => $health->metrics,
                'response_time' => $health->responseTime
            ];
        } catch (\Exception $e) {
            return [
                'connection' => $name ?? $this->defaultConnection,
                'healthy' => false,
                'message' => $e->getMessage(),
                'metrics' => [],
                'response_time' => 0
            ];
        }
    }

    /**
     * Create queue manager from configuration file
     *
     * @param string $configPath Configuration file path
     * @return self Queue manager instance
     * @throws \Exception If config file not found or invalid
     */
    public static function fromConfigFile(string $configPath): self
    {
        if (!file_exists($configPath)) {
            throw new \Exception("Queue configuration file not found: {$configPath}");
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new \Exception("Queue configuration must return an array");
        }

        return new self($config);
    }

    /**
     * Create default queue manager for Glueful
     *
     * @return self Queue manager instance
     */
    public static function createDefault(): self
    {
        // Try to load from Glueful config
        $configPath = dirname(__DIR__, 2) . '/config/queue.php';

        if (file_exists($configPath)) {
            return self::fromConfigFile($configPath);
        }

        // Fallback to default configuration
        return new self();
    }
}
