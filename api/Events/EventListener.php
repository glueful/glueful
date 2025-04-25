<?php
declare(strict_types=1);

namespace Glueful\Events;

/**
 * Event Listener Interface
 * 
 * Interface for event listeners that can be registered with the EventDispatcher.
 * 
 * @package Glueful\Events
 */
interface EventListener
{
    /**
     * Get the events that the listener should handle
     * 
     * @return array Array of event names or patterns (can include wildcards)
     */
    public function getSubscribedEvents(): array;
    
    /**
     * Handle an event
     * 
     * @param object $event The event object
     * @return mixed Return value
     */
    public function handle(object $event);
}