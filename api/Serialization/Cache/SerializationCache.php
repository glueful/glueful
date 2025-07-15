<?php

declare(strict_types=1);

namespace Glueful\Serialization\Cache;

use Glueful\Cache\CacheStore;

/**
 * Serialization Cache Service
 *
 * Provides caching for serialized data to improve performance
 * with configurable TTL and cache invalidation strategies.
 */
class SerializationCache
{
    private const CACHE_PREFIX = 'serialization:';
    private const DEFAULT_TTL = 3600; // 1 hour

    public function __construct(
        private CacheStore $cache,
        private int $defaultTtl = self::DEFAULT_TTL
    ) {
    }

    /**
     * Get cached serialized data
     */
    public function get(string $key): ?array
    {
        return $this->cache->get(self::CACHE_PREFIX . $key);
    }

    /**
     * Store serialized data in cache
     */
    public function set(string $key, array $data, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $this->cache->set(self::CACHE_PREFIX . $key, $data, $ttl);
    }

    /**
     * Check if cached data exists
     */
    public function has(string $key): bool
    {
        return $this->cache->has(self::CACHE_PREFIX . $key);
    }

    /**
     * Delete cached data
     */
    public function delete(string $key): bool
    {
        return $this->cache->delete(self::CACHE_PREFIX . $key);
    }

    /**
     * Generate cache key from object and context
     */
    public function generateKey(object $object, array $context = []): string
    {
        $components = [
            get_class($object),
            $this->getObjectIdentifier($object),
            md5(serialize($context))
        ];

        return md5(implode(':', $components));
    }

    /**
     * Get object identifier for cache key generation
     */
    private function getObjectIdentifier(object $object): string
    {
        // Try to get a unique identifier from the object
        if (method_exists($object, 'getId')) {
            return (string) $object->getId();
        }

        if (method_exists($object, 'getUuid')) {
            return (string) $object->getUuid();
        }

        if (property_exists($object, 'id')) {
            return (string) $object->id;
        }

        if (property_exists($object, 'uuid')) {
            return (string) $object->uuid;
        }

        // Fallback to object hash
        return spl_object_hash($object);
    }

    /**
     * Cache serialization result with automatic key generation
     */
    public function cacheResult(object $object, array $context, array $result, ?int $ttl = null): void
    {
        $key = $this->generateKey($object, $context);
        $this->set($key, $result, $ttl);
    }

    /**
     * Get cached result with automatic key generation
     */
    public function getCachedResult(object $object, array $context): ?array
    {
        $key = $this->generateKey($object, $context);
        return $this->get($key);
    }

    /**
     * Invalidate cache for specific object
     */
    public function invalidateObject(object $object): void
    {
        $pattern = self::CACHE_PREFIX . get_class($object) . ':' . $this->getObjectIdentifier($object) . ':*';
        $this->cache->deletePattern($pattern);
    }

    /**
     * Invalidate cache for object type
     */
    public function invalidateObjectType(string $className): void
    {
        $pattern = self::CACHE_PREFIX . $className . ':*';
        $this->cache->deletePattern($pattern);
    }

    /**
     * Clear all serialization cache
     */
    public function clear(): void
    {
        $pattern = self::CACHE_PREFIX . '*';
        $this->cache->deletePattern($pattern);
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        // This would depend on the cache implementation
        return [
            'total_keys' => $this->countKeys(),
            'cache_size' => $this->getCacheSize(),
            'default_ttl' => $this->defaultTtl,
        ];
    }

    /**
     * Count cache keys
     */
    private function countKeys(): int
    {
        // This is a simplified implementation
        // Real implementation would depend on cache driver
        return 0;
    }

    /**
     * Get cache size
     */
    private function getCacheSize(): string
    {
        // This is a simplified implementation
        return 'Unknown';
    }

    /**
     * Warm up cache for collection of objects
     */
    public function warmupCollection(array $objects, array $context = [], ?int $ttl = null): int
    {
        $warmedUp = 0;

        foreach ($objects as $object) {
            if (!is_object($object)) {
                continue;
            }

            $key = $this->generateKey($object, $context);

            // Only warm up if not already cached
            if (!$this->cache->has(self::CACHE_PREFIX . $key)) {
                // This would normally serialize the object
                // For now, we'll just mark it as warmed up
                $this->set($key, ['warmed_up' => true], $ttl);
                $warmedUp++;
            }
        }

        return $warmedUp;
    }
}
