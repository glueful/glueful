<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

/**
 * InteractsWithQueue Trait
 *
 * Adds queue interaction functionality to events.
 * Allows events to be processed asynchronously via the queue system.
 */
trait InteractsWithQueue
{
    /**
     * The queue connection to use
     */
    public ?string $connection = null;

    /**
     * The queue to dispatch the event to
     */
    public ?string $queue = null;

    /**
     * The delay before processing the event
     */
    public ?int $delay = null;

    /**
     * Set the queue connection
     */
    public function onConnection(string $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Set the queue name
     */
    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Set the delay in seconds
     */
    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    /**
     * Dispatch the event to the queue
     */
    public static function dispatchToQueue(...$args): static
    {
        $event = new static(...$args);

        // Queue the event for async processing
        // This would integrate with Glueful's queue system
        if (function_exists('queue')) {
            queue()->push(static::class, $event->toArray(), $event->queue, $event->delay);
        }

        return $event;
    }

    /**
     * Dispatch the event after the current database transaction commits
     */
    public static function dispatchAfterCommit(...$args): static
    {
        $event = new static(...$args);

        // This would integrate with Glueful's database transaction system
        if (function_exists('db') && method_exists(db(), 'afterCommit')) {
            db()->afterCommit(function () use ($event) {
                Event::dispatch($event);
            });
        } else {
            // Fallback to immediate dispatch
            Event::dispatch($event);
        }

        return $event;
    }

    /**
     * Get queue configuration for this event
     */
    public function getQueueConfig(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'delay' => $this->delay
        ];
    }
}
