<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

use Glueful\Events\Event;

/**
 * Dispatchable Trait
 *
 * Allows events to be dispatched using a static method.
 * Inspired by Laravel's Dispatchable trait.
 *
 * Usage:
 * UserRegisteredEvent::dispatch($userId, $email);
 */
trait Dispatchable
{
    /**
     * Dispatch the event
     *
     * @param mixed ...$args Constructor arguments for the event
     * @return static The dispatched event instance
     */
    public static function dispatch(...$args): static
    {
        $event = new static(...$args);
        Event::dispatch($event);
        return $event;
    }

    /**
     * Dispatch the event if the given condition is true
     *
     * @param bool $condition
     * @param mixed ...$args Constructor arguments for the event
     * @return static|null The dispatched event instance or null
     */
    public static function dispatchIf(bool $condition, ...$args): ?static
    {
        if ($condition) {
            return static::dispatch(...$args);
        }

        return null;
    }

    /**
     * Dispatch the event unless the given condition is true
     *
     * @param bool $condition
     * @param mixed ...$args Constructor arguments for the event
     * @return static|null The dispatched event instance or null
     */
    public static function dispatchUnless(bool $condition, ...$args): ?static
    {
        if (!$condition) {
            return static::dispatch(...$args);
        }

        return null;
    }
}
