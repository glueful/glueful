<?php

declare(strict_types=1);

namespace Glueful\Events\Cache;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Cache Miss Event
 *
 * Dispatched when a cache key is not found.
 * Used for cache analytics and warming strategies.
 *
 * @package Glueful\Events\Cache
 */
class CacheMissEvent extends Event
{
    /**
     * @param string $key Cache key that was missed
     * @param array $tags Expected cache tags
     * @param mixed $valueLoader Callback to load the value
     */
    public function __construct(
        private readonly string $key,
        private readonly array $tags = [],
        private readonly mixed $valueLoader = null
    ) {
    }

    /**
     * Get cache key
     *
     * @return string Cache key
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Get expected tags
     *
     * @return array Tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Get value loader callback
     *
     * @return mixed Value loader
     */
    public function getValueLoader(): mixed
    {
        return $this->valueLoader;
    }

    /**
     * Check if value loader is available
     *
     * @return bool True if loader available
     */
    public function hasValueLoader(): bool
    {
        return $this->valueLoader !== null && is_callable($this->valueLoader);
    }

    /**
     * Load the value using the callback
     *
     * @return mixed Loaded value
     */
    public function loadValue(): mixed
    {
        if (!$this->hasValueLoader()) {
            throw new \RuntimeException('No value loader available');
        }

        return call_user_func($this->valueLoader);
    }
}
