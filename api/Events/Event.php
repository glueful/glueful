<?php

declare(strict_types=1);

namespace Glueful\Events;

use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;
use Psr\Log\LoggerInterface;

/**
 * Glueful Event Manager
 *
 * Provides a simple, unified API for event dispatching and listening
 * Wraps Symfony's EventDispatcher with framework-specific functionality
 *
 * Usage:
 * Event::dispatch(new MyEvent($data));
 * Event::listen(MyEvent::class, $listener);
 */
class Event
{
    private static ?SymfonyEventDispatcherInterface $dispatcher = null;
    private static ?LoggerInterface $logger = null;
    private static bool $logEvents = false;

    /**
     * Initialize the event system
     *
     * @param SymfonyEventDispatcherInterface $dispatcher Symfony event dispatcher
     * @param LoggerInterface|null $logger Optional logger for event debugging
     * @param bool $logEvents Whether to log dispatched events
     */
    public static function initialize(
        SymfonyEventDispatcherInterface $dispatcher,
        ?LoggerInterface $logger = null,
        bool $logEvents = false
    ): void {
        self::$dispatcher = $dispatcher;
        self::$logger = $logger;
        self::$logEvents = $logEvents;
    }

    /**
     * Dispatch an event
     *
     * @param object $event The event to dispatch (can be any object)
     * @param string|null $eventName Optional event name (auto-detected if not provided)
     * @return object The dispatched event
     */
    public static function dispatch(object $event, ?string $eventName = null): object
    {
        if (!self::$dispatcher) {
            self::initializeFromContainer();
        }

        $eventName = $eventName ?? get_class($event);

        // Optional event logging for debugging
        if (self::$logEvents && self::$logger) {
            self::$logger->debug('Event dispatched', [
                'type' => 'event',
                'event_name' => $eventName,
                'event_class' => get_class($event),
                'timestamp' => date('c')
            ]);
        }

        // If the event is already a Symfony event, dispatch directly
        if ($event instanceof SymfonyEvent) {
            return self::$dispatcher->dispatch($event, $eventName);
        }

        // For plain PHP objects, wrap them in a Symfony event
        $wrappedEvent = new class ($event) extends SymfonyEvent {
            public function __construct(public readonly object $payload)
            {
            }
        };

        self::$dispatcher->dispatch($wrappedEvent, $eventName);

        // Return the original event object
        return $event;
    }

    /**
     * Listen for an event
     *
     * @param string $eventName The event name or class to listen for
     * @param callable $listener The listener callable
     * @param int $priority The listener priority (higher = earlier execution)
     */
    public static function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        if (!self::$dispatcher) {
            self::initializeFromContainer();
        }

        self::$dispatcher->addListener($eventName, $listener, $priority);
    }

    /**
     * Stop listening for an event
     *
     * @param string $eventName The event name
     * @param callable $listener The listener to remove
     */
    public static function forget(string $eventName, callable $listener): void
    {
        if (!self::$dispatcher) {
            return;
        }

        self::$dispatcher->removeListener($eventName, $listener);
    }

    /**
     * Get all listeners for an event
     *
     * @param string|null $eventName The event name (null for all listeners)
     * @return array The listeners
     */
    public static function getListeners(?string $eventName = null): array
    {
        if (!self::$dispatcher) {
            return [];
        }

        return self::$dispatcher->getListeners($eventName);
    }

    /**
     * Check if an event has listeners
     *
     * @param string $eventName The event name
     * @return bool True if the event has listeners
     */
    public static function hasListeners(?string $eventName = null): bool
    {
        if (!self::$dispatcher) {
            return false;
        }

        return self::$dispatcher->hasListeners($eventName);
    }

    /**
     * Enable or disable event logging
     *
     * @param bool $enabled Whether to log events
     */
    public static function setLogging(bool $enabled): void
    {
        self::$logEvents = $enabled;
    }

    /**
     * Get the underlying Symfony dispatcher (for advanced use cases)
     *
     * @return SymfonyEventDispatcherInterface|null
     */
    public static function getDispatcher(): ?SymfonyEventDispatcherInterface
    {
        return self::$dispatcher;
    }

    /**
     * Initialize from DI container if not already initialized
     */
    private static function initializeFromContainer(): void
    {
        if (!function_exists('container')) {
            return;
        }

        $container = container();
        if (!$container) {
            return;
        }

        if ($container->has(SymfonyEventDispatcherInterface::class)) {
            self::$dispatcher = $container->get(SymfonyEventDispatcherInterface::class);
        }

        if ($container->has(LoggerInterface::class)) {
            self::$logger = $container->get(LoggerInterface::class);
        }
    }
}
