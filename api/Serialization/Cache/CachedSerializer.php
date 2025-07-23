<?php

declare(strict_types=1);

namespace Glueful\Serialization\Cache;

/**
 * Cached Serializer Decorator
 *
 * Wraps the main serializer with caching capabilities
 */
class CachedSerializer
{
    public function __construct(
        private \Glueful\Serialization\Serializer $serializer,
        private SerializationCache $cache,
        private bool $cacheEnabled = true
    ) {
    }

    /**
     * Serialize with caching
     */
    public function serialize(
        mixed $data,
        string $format = 'json',
        ?\Glueful\Serialization\Context\SerializationContext $context = null
    ): string {
        if (!$this->cacheEnabled || !is_object($data)) {
            return $this->serializer->serialize($data, $format, $context);
        }

        $contextArray = $context ? $context->toArray() : [];
        $cacheKey = $this->cache->generateKey($data, array_merge($contextArray, ['format' => $format]));

        // Try to get from cache
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && isset($cached['result'])) {
            return $cached['result'];
        }

        // Serialize and cache
        $result = $this->serializer->serialize($data, $format, $context);
        $this->cache->set($cacheKey, ['result' => $result, 'timestamp' => time()]);

        return $result;
    }

    /**
     * Normalize with caching
     */
    public function normalize(
        mixed $data,
        ?\Glueful\Serialization\Context\SerializationContext $context = null
    ): array {
        if (!$this->cacheEnabled || !is_object($data)) {
            return $this->serializer->normalize($data, $context);
        }

        $contextArray = $context ? $context->toArray() : [];
        $cacheKey = $this->cache->generateKey($data, array_merge($contextArray, ['operation' => 'normalize']));

        // Try to get from cache
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && isset($cached['result'])) {
            return $cached['result'];
        }

        // Normalize and cache
        $result = $this->serializer->normalize($data, $context);
        $this->cache->set($cacheKey, ['result' => $result, 'timestamp' => time()]);

        return $result;
    }

    /**
     * Enable/disable caching
     */
    public function setCacheEnabled(bool $enabled): self
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    /**
     * Check if caching is enabled
     */
    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Invalidate cache for object
     */
    public function invalidateCache(object $object): void
    {
        $this->cache->invalidateObject($object);
    }

    /**
     * Get cache stats
     */
    public function getCacheStats(): array
    {
        return $this->cache->getStats();
    }

    /**
     * Clear serialization cache
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * Get underlying serializer
     */
    public function getSerializer(): \Glueful\Serialization\Serializer
    {
        return $this->serializer;
    }

    /**
     * Get cache service
     */
    public function getCache(): SerializationCache
    {
        return $this->cache;
    }
}
