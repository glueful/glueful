<?php

namespace Glueful\Queue\Events;

/**
 * Event Dispatcher
 *
 * Simple event dispatcher for the queue system plugin architecture.
 * Allows plugins to listen for and dispatch events throughout the queue lifecycle.
 *
 * Features:
 * - Event listener registration
 * - Event dispatching with data
 * - Wildcard event support
 * - Priority-based listener execution
 * - Stop propagation support
 *
 * @package Glueful\Queue\Events
 */
class EventDispatcher
{
    /** @var array Registered event listeners */
    private array $listeners = [];

    /**
     * Register an event listener
     *
     * @param string $event Event name (supports wildcards with *)
     * @param callable $listener Listener callback
     * @param int $priority Priority (higher executes first)
     * @return void
     */
    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = [
            'callback' => $listener,
            'priority' => $priority
        ];

        // Sort by priority (higher first)
        usort($this->listeners[$event], function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * Dispatch an event
     *
     * @param string $event Event name
     * @param mixed $data Event data
     * @return array Results from all listeners
     */
    public function dispatch(string $event, $data = null): array
    {
        $results = [];
        $listeners = $this->getListenersForEvent($event);

        foreach ($listeners as $listener) {
            try {
                $result = call_user_func($listener['callback'], $data, $event);
                $results[] = $result;

                // Check if propagation should stop
                if ($result === false) {
                    break;
                }
            } catch (\Exception $e) {
                error_log("Event listener error for '{$event}': " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Get all listeners for an event including wildcards
     *
     * @param string $event Event name
     * @return array Array of listeners
     */
    private function getListenersForEvent(string $event): array
    {
        $listeners = [];

        // Direct listeners
        if (isset($this->listeners[$event])) {
            $listeners = array_merge($listeners, $this->listeners[$event]);
        }

        // Wildcard listeners
        foreach ($this->listeners as $pattern => $patternListeners) {
            if ($pattern !== $event && $this->matchesPattern($event, $pattern)) {
                $listeners = array_merge($listeners, $patternListeners);
            }
        }

        // Sort all collected listeners by priority
        usort($listeners, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return $listeners;
    }

    /**
     * Check if event matches a pattern
     *
     * @param string $event Event name
     * @param string $pattern Pattern with wildcards
     * @return bool True if matches
     */
    private function matchesPattern(string $event, string $pattern): bool
    {
        // Convert pattern to regex
        $regex = str_replace(
            ['*', '?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        );

        return (bool) preg_match('/^' . $regex . '$/', $event);
    }

    /**
     * Remove event listener
     *
     * @param string $event Event name
     * @param callable|null $listener Specific listener or null for all
     * @return void
     */
    public function forget(string $event, ?callable $listener = null): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        if ($listener === null) {
            // Remove all listeners for this event
            unset($this->listeners[$event]);
            return;
        }

        // Remove specific listener
        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            fn($item) => $item['callback'] !== $listener
        );

        if (empty($this->listeners[$event])) {
            unset($this->listeners[$event]);
        }
    }

    /**
     * Check if event has listeners
     *
     * @param string $event Event name
     * @return bool True if has listeners
     */
    public function hasListeners(string $event): bool
    {
        return !empty($this->getListenersForEvent($event));
    }

    /**
     * Get all registered events
     *
     * @return array Event names
     */
    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }
}
