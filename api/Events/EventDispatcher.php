<?php

declare(strict_types=1);

namespace Glueful\Events;

use Closure;
use Exception;
use Glueful\Logging\LogManager;

/**
 * Event Dispatcher
 *
 * Manages event listeners and handles dispatching events to registered listeners.
 * Implements the observer pattern for decoupled event handling.
 *
 * @package Glueful\Events
 */
class EventDispatcher
{
    /**
     * @var array Array of event listeners
     */
    private array $listeners = [];

    /**
     * @var array Event listener wildcards
     */
    private array $wildcards = [];

    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;

    /**
     * @var array Configuration options
     */
    private array $config;

    /**
     * EventDispatcher constructor
     *
     * @param LogManager|null $logger Logger instance
     * @param array $config Configuration options
     */
    public function __construct(?LogManager $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Register an event listener
     *
     * @param string $event Event name to listen for
     * @param callable $listener The callback to execute when event is fired
     * @param int $priority Higher priorities execute first (default: 0)
     * @return self
     */
    public function listen(string $event, callable $listener, int $priority = 0): self
    {
        // Handle wildcard listeners separately (e.g., "notification.*")
        if (strpos($event, '*') !== false) {
            $this->wildcards[$event][$priority][] = $listener;

            // Sort wildcard listeners by priority (higher first)
            if (isset($this->wildcards[$event])) {
                krsort($this->wildcards[$event]);
            }

            return $this;
        }

        // Regular event listeners
        $this->listeners[$event][$priority][] = $listener;

        // Sort listeners by priority (higher first)
        if (isset($this->listeners[$event])) {
            krsort($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * Register a listener for a specific event once
     *
     * @param string $event Event name
     * @param callable $listener The listener callback
     * @param int $priority Higher priorities execute first (default: 0)
     * @return self
     */
    public function listenOnce(string $event, callable $listener, int $priority = 0): self
    {
        // Create a wrapper that removes itself after execution
        $wrapper = function (...$args) use ($event, $listener, &$wrapper) {
            $this->removeListener($event, $wrapper);
            return call_user_func_array($listener, $args);
        };

        return $this->listen($event, $wrapper, $priority);
    }

    /**
     * Remove a specific listener from an event
     *
     * @param string $event Event name
     * @param callable $listener The listener to remove
     * @return self
     */
    public function removeListener(string $event, callable $listener): self
    {
        // Handle wildcards
        if (strpos($event, '*') !== false) {
            if (isset($this->wildcards[$event])) {
                foreach ($this->wildcards[$event] as $priority => $listeners) {
                    foreach ($listeners as $key => $registeredListener) {
                        if ($this->listenersAreEqual($registeredListener, $listener)) {
                            unset($this->wildcards[$event][$priority][$key]);
                        }
                    }
                }
            }

            return $this;
        }

        // Regular events
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $priority => $listeners) {
                foreach ($listeners as $key => $registeredListener) {
                    if ($this->listenersAreEqual($registeredListener, $listener)) {
                        unset($this->listeners[$event][$priority][$key]);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Remove all listeners for a specific event
     *
     * @param string|null $event Event name (null to remove all listeners)
     * @return self
     */
    public function removeAllListeners(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
            $this->wildcards = [];
        } else {
            unset($this->listeners[$event]);

            // Also remove wildcards that might match this event
            foreach (array_keys($this->wildcards) as $wildcard) {
                if ($this->eventMatchesWildcard($event, $wildcard)) {
                    unset($this->wildcards[$wildcard]);
                }
            }
        }

        return $this;
    }

    /**
     * Dispatch an event to all registered listeners
     *
     * @param object|string $event The event object or event name
     * @param array $payload Event data (if event is a string)
     * @return array Results from listeners
     */
    public function dispatch($event, array $payload = []): array
    {
        $eventName = is_object($event) ? $this->getEventName($event) : (string)$event;
        $eventObject = is_object($event) ? $event : null;

        $this->log('debug', "Dispatching event: {$eventName}", [
            'event' => $eventName,
            'payload' => $payload
        ]);

        $results = [];

        // Execute the regular listeners
        if (isset($this->listeners[$eventName])) {
            foreach ($this->listeners[$eventName] as $priority => $listeners) {
                foreach ($listeners as $listener) {
                    try {
                        $results[] = $this->callListener($listener, $eventObject, $payload);
                    } catch (\Throwable $e) {
                        $this->log('error', "Error in event listener for {$eventName}: " . $e->getMessage(), [
                            'event' => $eventName,
                            'exception' => $e
                        ]);
                    }
                }
            }
        }

        // Execute wildcard listeners
        foreach ($this->wildcards as $wildcard => $prioritizedListeners) {
            if ($this->eventMatchesWildcard($eventName, $wildcard)) {
                foreach ($prioritizedListeners as $priority => $listeners) {
                    foreach ($listeners as $listener) {
                        try {
                            $results[] = $this->callListener($listener, $eventObject, $payload);
                        } catch (\Throwable $e) {
                            $this->log('error', "Error in wildcard listener ({$wildcard}) for {$eventName}: " . $e->getMessage(), [
                                'event' => $eventName,
                                'wildcard' => $wildcard,
                                'exception' => $e
                            ]);
                        }
                    }
                }
            }
        }

        return array_filter($results);
    }

    /**
     * Get all registered listeners for an event
     *
     * @param string|null $eventName Event name (null for all listeners)
     * @return array Registered listeners
     */
    public function getListeners(?string $eventName = null): array
    {
        if ($eventName === null) {
            return $this->listeners;
        }

        $listeners = $this->listeners[$eventName] ?? [];

        // Add wildcard listeners that match this event
        foreach ($this->wildcards as $wildcard => $prioritizedListeners) {
            if ($this->eventMatchesWildcard($eventName, $wildcard)) {
                foreach ($prioritizedListeners as $priority => $wildcardListeners) {
                    if (!isset($listeners[$priority])) {
                        $listeners[$priority] = [];
                    }
                    $listeners[$priority] = array_merge($listeners[$priority], $wildcardListeners);
                }
            }
        }

        // Sort by priority
        krsort($listeners);

        return $listeners;
    }

    /**
     * Check if a listener exists for an event
     *
     * @param string $eventName Event name
     * @return bool True if listeners exist
     */
    public function hasListeners(string $eventName): bool
    {
        if (isset($this->listeners[$eventName]) && !empty($this->listeners[$eventName])) {
            return true;
        }

        // Check for matching wildcard listeners
        foreach (array_keys($this->wildcards) as $wildcard) {
            if ($this->eventMatchesWildcard($eventName, $wildcard)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set configuration option
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Get configuration option
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set the logger instance
     *
     * @param LogManager $logger Logger instance
     * @return self
     */
    public function setLogger(LogManager $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the name of an event object
     *
     * @param object $event Event object
     * @return string Event name
     */
    protected function getEventName(object $event): string
    {
        if (method_exists($event, 'getName')) {
            return $event->getName();
        }

        return get_class($event);
    }

    /**
     * Check if two listeners are equal
     *
     * @param callable $a First listener
     * @param callable $b Second listener
     * @return bool True if listeners are equal
     */
    protected function listenersAreEqual(callable $a, callable $b): bool
    {
        if ($a instanceof Closure && $b instanceof Closure) {
            return false; // Can't easily compare closures
        }

        if (is_array($a) && is_array($b)) {
            return $a[0] === $b[0] && $a[1] === $b[1];
        }

        return $a === $b;
    }

    /**
     * Call a listener with the event or payload
     *
     * @param callable $listener The listener to call
     * @param object|null $event Event object
     * @param array $payload Event payload
     * @return mixed Listener result
     */
    protected function callListener(callable $listener, ?object $event, array $payload)
    {
        if ($event !== null) {
            return call_user_func($listener, $event);
        }

        return call_user_func_array($listener, $payload);
    }

    /**
     * Check if an event name matches a wildcard pattern
     *
     * @param string $eventName Event name
     * @param string $wildcard Wildcard pattern
     * @return bool True if event matches wildcard
     */
    protected function eventMatchesWildcard(string $eventName, string $wildcard): bool
    {
        $pattern = str_replace('\\*', '.*', preg_quote($wildcard, '#'));
        return (bool) preg_match('#^' . $pattern . '$#u', $eventName);
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Log context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }
}
