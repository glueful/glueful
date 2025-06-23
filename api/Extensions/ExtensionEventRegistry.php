<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\Contracts\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Glueful\Logging\LogManager;

/**
 * Extension Event Registry
 *
 * Manages automatic registration of event subscribers from loaded extensions.
 * Handles the conversion between extension event subscriber format and
 * Symfony EventDispatcher registration.
 *
 * @package Glueful\Extensions
 */
class ExtensionEventRegistry
{
    private EventDispatcherInterface $eventDispatcher;
    private ?LogManager $logger;
    private array $registeredExtensions = [];

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        ?LogManager $logger = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    /**
     * Register all event subscribers from loaded extensions
     *
     * @param array $extensions Array of loaded extension instances
     * @return int Number of event listeners registered
     */
    public function registerExtensionSubscribers(array $extensions): int
    {
        $totalRegistered = 0;

        foreach ($extensions as $extension) {
            try {
                $totalRegistered += $this->registerExtensionEventSubscribers($extension);
            } catch (\Exception $e) {
                $extensionName = is_array($extension) ? ($extension['name'] ?? 'unknown') : get_class($extension);
                $this->log('error', "Failed to register event subscribers for extension {$extensionName}: " .
                    $e->getMessage());
            }
        }

        $this->log('info', "Registered {$totalRegistered} event listeners from " . count($extensions) . " extensions");

        return $totalRegistered;
    }

    /**
     * Register event subscribers for a specific extension
     *
     * @param object|array $extension Extension instance or extension data
     * @return int Number of event listeners registered for this extension
     */
    public function registerExtensionEventSubscribers(object|array $extension): int
    {
        // Handle array format (extension data) vs object format (extension instance)
        if (is_array($extension)) {
            // If it's array data, try to get the extension class name
            $extensionName = $extension['name'] ?? null;
            if (!$extensionName) {
                return 0; // Skip if no name available
            }

            // Try to instantiate the extension
            $extensionClass = "\\Extensions\\{$extensionName}\\{$extensionName}";
            if (!class_exists($extensionClass)) {
                return 0; // Skip if class doesn't exist
            }

            try {
                $extension = new $extensionClass();
            } catch (\Exception $e) {
                $this->log('warning', "Failed to instantiate extension {$extensionName}: " . $e->getMessage());
                return 0;
            }
        }

        $extensionClass = get_class($extension);

        // Skip if already registered
        if (in_array($extensionClass, $this->registeredExtensions)) {
            return 0;
        }

        // Check if extension has getEventSubscribers method
        if (!method_exists($extension, 'getEventSubscribers')) {
            return 0;
        }

        $subscribers = $extension::getEventSubscribers();
        if (empty($subscribers)) {
            return 0;
        }

        $registeredCount = 0;

        foreach ($subscribers as $eventClass => $methodConfig) {
            $registeredCount += $this->registerEventSubscriber(
                $extension,
                $eventClass,
                $methodConfig,
                $extensionClass
            );
        }

        // Mark as registered
        $this->registeredExtensions[] = $extensionClass;

        $this->log('debug', "Registered {$registeredCount} event listeners for extension: {$extensionClass}");

        return $registeredCount;
    }

    /**
     * Register a single event subscriber
     *
     * @param object $extension Extension instance
     * @param string $eventClass Event class or name
     * @param string|array $methodConfig Method configuration
     * @param string $extensionClass Extension class name
     * @return int Number of listeners registered (0 or more)
     */
    protected function registerEventSubscriber(
        object $extension,
        string $eventClass,
        string|array $methodConfig,
        string $extensionClass
    ): int {
        try {
            if (is_string($methodConfig)) {
                // Simple method name: ['eventClass' => 'methodName']
                return $this->addListener($extension, $eventClass, $methodConfig, 0, $extensionClass);
            }

            if (is_array($methodConfig)) {
                // Check if it's a single [method, priority] or multiple [[method1, priority1], [method2, priority2]]
                if (isset($methodConfig[0]) && is_array($methodConfig[0])) {
                    // Multiple listeners: [['method1', priority1], ['method2', priority2]]
                    $count = 0;
                    foreach ($methodConfig as $listenerConfig) {
                        if (is_array($listenerConfig) && count($listenerConfig) >= 1) {
                            $method = $listenerConfig[0];
                            $priority = $listenerConfig[1] ?? 0;
                            $count += $this->addListener($extension, $eventClass, $method, $priority, $extensionClass);
                        }
                    }
                    return $count;
                } else {
                    // Single listener with priority: ['method', priority]
                    $method = $methodConfig[0] ?? $methodConfig['method'] ?? null;
                    $priority = $methodConfig[1] ?? $methodConfig['priority'] ?? 0;

                    if ($method) {
                        return $this->addListener($extension, $eventClass, $method, $priority, $extensionClass);
                    }
                }
            }

            $this->log(
                'warning',
                "Invalid event subscriber configuration in {$extensionClass} for event {$eventClass}"
            );
            return 0;
        } catch (\Throwable $e) {
            $this->log('error', "Failed to register event subscriber in {$extensionClass} for {$eventClass}: " .
                $e->getMessage());
            return 0;
        }
    }

    /**
     * Add event listener to dispatcher
     *
     * @param object $extension Extension instance
     * @param string $eventClass Event class or name
     * @param string $method Method name
     * @param int $priority Priority (higher = executed first)
     * @param string $extensionClass Extension class name for logging
     * @return int 1 if registered, 0 if failed
     */
    protected function addListener(
        object $extension,
        string $eventClass,
        string $method,
        int $priority,
        string $extensionClass
    ): int {
        // Validate method exists
        if (!method_exists($extension, $method)) {
            $this->log('warning', "Method {$method} does not exist in extension {$extensionClass}");
            return 0;
        }

        // Register with event dispatcher
        $this->eventDispatcher->addListener($eventClass, [$extension, $method], $priority);

        $this->log('debug', "Registered {$extensionClass}::{$method} for event {$eventClass} (priority: {$priority})");

        return 1;
    }

    /**
     * Get list of registered extensions
     *
     * @return array List of registered extension class names
     */
    public function getRegisteredExtensions(): array
    {
        return $this->registeredExtensions;
    }

    /**
     * Clear all registered extensions (for testing)
     *
     * @return void
     */
    public function clearRegistrations(): void
    {
        $this->registeredExtensions = [];
    }

    /**
     * Check if an extension is already registered
     *
     * @param string $extensionClass Extension class name
     * @return bool True if registered
     */
    public function isExtensionRegistered(string $extensionClass): bool
    {
        return in_array($extensionClass, $this->registeredExtensions);
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @return void
     */
    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, ['component' => 'ExtensionEventRegistry']);
        }
    }
}
