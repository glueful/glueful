<?php

namespace Glueful\Queue\Plugins;

use Glueful\Queue\Events\EventDispatcher;
use Glueful\Queue\Registry\DriverRegistry;

/**
 * Plugin Manager for Queue Extensions
 *
 * Manages queue system plugins and extensions, providing a hook-based
 * architecture for extending queue functionality.
 *
 * Plugin Features:
 * - Driver registration
 * - Event listener registration
 * - Hook system for plugin integration
 * - Initialization callbacks
 * - Plugin dependency management
 *
 * Plugin Structure:
 * ```php
 * return [
 *     'name' => 'My Queue Plugin',
 *     'version' => '1.0.0',
 *     'drivers' => [...],
 *     'listeners' => [...],
 *     'hooks' => [...],
 *     'init' => function() { ... }
 * ];
 * ```
 *
 * @package Glueful\Queue\Plugins
 */
class PluginManager
{
    /** @var array Loaded plugins */
    private array $plugins = [];

    /** @var EventDispatcher Event dispatcher instance */
    private EventDispatcher $events;

    /** @var DriverRegistry|null Driver registry for plugin drivers */
    private ?DriverRegistry $driverRegistry = null;

    /**
     * Initialize plugin manager
     */
    public function __construct()
    {
        $this->events = new EventDispatcher();
        $this->loadPlugins();
    }

    /**
     * Load all available plugins
     *
     * @return void
     */
    public function loadPlugins(): void
    {
        $basePath = dirname(__DIR__, 4);
        $pluginPaths = [
            $basePath . '/extensions/*/queue-plugin.php',
            $basePath . '/vendor/*/queue-plugins/*/plugin.php'
        ];

        foreach ($pluginPaths as $pattern) {
            $files = glob($pattern);
            if ($files) {
                foreach ($files as $pluginFile) {
                    $this->loadPlugin($pluginFile);
                }
            }
        }
    }

    /**
     * Load a single plugin file
     *
     * @param string $file Plugin file path
     * @return void
     */
    private function loadPlugin(string $file): void
    {
        try {
            if (!file_exists($file)) {
                return;
            }

            $plugin = require $file;

            // Validate plugin structure
            if (!is_array($plugin) || !isset($plugin['name'])) {
                error_log("Invalid plugin format in {$file}: missing 'name' field");
                return;
            }

            // Store plugin
            $this->plugins[$plugin['name']] = $plugin;

            // Register drivers
            if (isset($plugin['drivers']) && is_array($plugin['drivers'])) {
                foreach ($plugin['drivers'] as $driverConfig) {
                    $this->registerPluginDriver($driverConfig);
                }
            }

            // Register event listeners
            if (isset($plugin['listeners']) && is_array($plugin['listeners'])) {
                foreach ($plugin['listeners'] as $event => $listener) {
                    if (is_callable($listener)) {
                        $this->events->listen($event, $listener);
                    } elseif (is_string($listener) && class_exists($listener)) {
                        // Support class-based listeners
                        $this->events->listen($event, [new $listener(), 'handle']);
                    }
                }
            }

            // Execute initialization callback
            if (isset($plugin['init']) && is_callable($plugin['init'])) {
                call_user_func($plugin['init'], $this);
            }
        } catch (\Exception $e) {
            error_log("Failed to load queue plugin {$file}: " . $e->getMessage());
        }
    }

    /**
     * Register a driver from plugin
     *
     * @param array $driverConfig Driver configuration
     * @return void
     */
    private function registerPluginDriver(array $driverConfig): void
    {
        if (!isset($driverConfig['name']) || !isset($driverConfig['class'])) {
            error_log("Invalid driver configuration: missing name or class");
            return;
        }

        // Validate driver class exists
        if (!class_exists($driverConfig['class'])) {
            error_log("Driver class not found: {$driverConfig['class']}");
            return;
        }

        // Register with driver registry if available
        if ($this->driverRegistry) {
            try {
                $driver = new $driverConfig['class']();
                $info = $driver->getDriverInfo();
                $this->driverRegistry->registerDriver(
                    $driverConfig['name'],
                    $driverConfig['class'],
                    $info
                );
            } catch (\Exception $e) {
                error_log("Failed to register driver '{$driverConfig['name']}': " . $e->getMessage());
            }
        }
    }

    /**
     * Execute plugin hooks
     *
     * @param string $hook Hook name
     * @param array $data Hook data
     * @return array Results from all hook handlers
     */
    public function executeHook(string $hook, array $data = []): array
    {
        $results = [];

        foreach ($this->plugins as $plugin) {
            if (isset($plugin['hooks'][$hook]) && is_callable($plugin['hooks'][$hook])) {
                try {
                    $result = call_user_func($plugin['hooks'][$hook], $data);
                    $results[] = $result;
                } catch (\Exception $e) {
                    error_log("Plugin hook error in '{$plugin['name']}' for hook '{$hook}': " . $e->getMessage());
                }
            }
        }

        return $results;
    }

    /**
     * Set driver registry for plugin driver registration
     *
     * @param DriverRegistry $registry Driver registry instance
     * @return void
     */
    public function setDriverRegistry(DriverRegistry $registry): void
    {
        $this->driverRegistry = $registry;

        // Re-register any drivers from already loaded plugins
        foreach ($this->plugins as $plugin) {
            if (isset($plugin['drivers']) && is_array($plugin['drivers'])) {
                foreach ($plugin['drivers'] as $driverConfig) {
                    $this->registerPluginDriver($driverConfig);
                }
            }
        }
    }

    /**
     * Get event dispatcher
     *
     * @return EventDispatcher Event dispatcher instance
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->events;
    }

    /**
     * Get loaded plugins
     *
     * @return array Array of loaded plugins
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Check if plugin is loaded
     *
     * @param string $name Plugin name
     * @return bool True if plugin is loaded
     */
    public function hasPlugin(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * Get plugin information
     *
     * @param string $name Plugin name
     * @return array|null Plugin data or null if not found
     */
    public function getPlugin(string $name): ?array
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Dispatch event through plugin system
     *
     * @param string $event Event name
     * @param mixed $data Event data
     * @return array Results from event listeners
     */
    public function dispatchEvent(string $event, $data = null): array
    {
        return $this->events->dispatch($event, $data);
    }

    /**
     * Register additional plugin manually
     *
     * @param array $plugin Plugin configuration
     * @return void
     */
    public function registerPlugin(array $plugin): void
    {
        if (!isset($plugin['name'])) {
            throw new \InvalidArgumentException('Plugin must have a name');
        }

        $this->plugins[$plugin['name']] = $plugin;

        // Process plugin components
        if (isset($plugin['drivers'])) {
            foreach ($plugin['drivers'] as $driverConfig) {
                $this->registerPluginDriver($driverConfig);
            }
        }

        if (isset($plugin['listeners'])) {
            foreach ($plugin['listeners'] as $event => $listener) {
                if (is_callable($listener)) {
                    $this->events->listen($event, $listener);
                }
            }
        }

        if (isset($plugin['init']) && is_callable($plugin['init'])) {
            call_user_func($plugin['init'], $this);
        }
    }
}
