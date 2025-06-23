<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts;

/**
 * Event Subscriber Interface for Extensions
 *
 * Provides a standardized way for extensions to subscribe to system events
 * using Symfony EventDispatcher patterns. Extensions implementing this interface
 * can automatically register event listeners with priority support.
 *
 * @package Glueful\Extensions\Contracts
 */
interface EventSubscriberInterface
{
    /**
     * Get the events this extension subscribes to
     *
     * Returns an array where keys are event class names or event names,
     * and values are either:
     * - String: method name to call
     * - Array: [method, priority] where priority is an integer (higher = earlier)
     * - Array: [[method1, priority1], [method2, priority2]] for multiple listeners
     *
     * Examples:
     * ```php
     * return [
     *     SessionCreatedEvent::class => 'onSessionCreated',
     *     UserCreatedEvent::class => ['onUserCreated', 100],
     *     'cache.invalidated' => [
     *         ['onCacheCleared', 50],
     *         ['logCacheEvent', 10]
     *     ]
     * ];
     * ```
     *
     * @return array<string, string|array> Event subscriptions
     */
    public static function getSubscribedEvents(): array;
}
