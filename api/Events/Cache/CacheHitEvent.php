<?php

declare(strict_types=1);

namespace Glueful\Events\Cache;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Cache Hit Event
 *
 * Dispatched when a cache key is successfully retrieved.
 * Used for cache analytics and performance monitoring.
 *
 * @package Glueful\Events\Cache
 */
class CacheHitEvent extends Event
{
    /**
     * @param string $key Cache key
     * @param mixed $value Retrieved value
     * @param array $tags Cache tags
     * @param float $retrievalTime Time to retrieve in seconds
     */
    public function __construct(
        private readonly string $key,
        private readonly mixed $value,
        private readonly array $tags = [],
        private readonly float $retrievalTime = 0.0
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
     * Get cached value
     *
     * @return mixed Cached value
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Get cache tags
     *
     * @return array Tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Get retrieval time
     *
     * @return float Time in seconds
     */
    public function getRetrievalTime(): float
    {
        return $this->retrievalTime;
    }

    /**
     * Get value size in bytes (approximate)
     *
     * @return int Size in bytes
     */
    public function getValueSize(): int
    {
        return strlen(serialize($this->value));
    }

    /**
     * Check if retrieval was slow
     *
     * @param float $threshold Threshold in seconds
     * @return bool True if slow
     */
    public function isSlow(float $threshold = 0.1): bool
    {
        return $this->retrievalTime > $threshold;
    }
}
