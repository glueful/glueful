<?php

declare(strict_types=1);

namespace Glueful\Serialization;

use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Glueful\Security\SecureSerializer;
use Glueful\Serialization\Context\SerializationContext;
use Glueful\Serialization\Cache\SerializationCache;
use Glueful\Serialization\Registry\LazyNormalizerRegistry;

/**
 * Glueful Serializer Service
 *
 * A wrapper around Symfony Serializer that provides a clean developer interface
 * while integrating with Glueful's security features and context system.
 */
class Serializer
{
    public function __construct(
        private SerializerInterface $symfonySerializer,
        private SecureSerializer $secureSerializer,
        private ?SerializationCache $cache = null,
        private ?LazyNormalizerRegistry $normalizerRegistry = null,
        private bool $cacheEnabled = true
    ) {
    }

    /**
     * Serialize data to the specified format
     *
     * @param mixed $data Data to serialize
     * @param string $format Target format (json, xml, etc.)
     * @param SerializationContext|null $context Serialization context
     * @return string Serialized data
     */
    public function serialize(mixed $data, string $format = 'json', ?SerializationContext $context = null): string
    {
        $contextData = $context ? $context->toArray() : [];

        // Try cache if available and data is an object
        if ($this->cache && $this->cacheEnabled && is_object($data)) {
            $cacheKey = $this->cache->generateKey($data, array_merge($contextData, ['format' => $format]));

            $cached = $this->cache->get($cacheKey);
            if ($cached !== null && isset($cached['result'])) {
                return $cached['result'];
            }

            // Serialize and cache result
            $result = $this->symfonySerializer->serialize($data, $format, $contextData);
            $this->cache->set($cacheKey, ['result' => $result, 'timestamp' => time()]);

            return $result;
        }

        return $this->symfonySerializer->serialize($data, $format, $contextData);
    }

    /**
     * Deserialize data from the specified format
     *
     * @param string $data Serialized data
     * @param string $type Target type/class
     * @param string $format Source format (json, xml, etc.)
     * @param SerializationContext|null $context Deserialization context
     * @return mixed Deserialized data
     */
    public function deserialize(
        string $data,
        string $type,
        string $format = 'json',
        ?SerializationContext $context = null
    ): mixed {
        $contextData = $context ? $context->toArray() : [];

        return $this->symfonySerializer->deserialize($data, $type, $format, $contextData);
    }

    /**
     * Normalize data to array format
     *
     * @param mixed $data Data to normalize
     * @param SerializationContext|null $context Normalization context
     * @return array Normalized data
     */
    public function normalize(mixed $data, ?SerializationContext $context = null): array
    {
        $contextData = $context ? $context->toArray() : [];

        // Try cache if available and data is an object
        if ($this->cache && $this->cacheEnabled && is_object($data)) {
            $cacheKey = $this->cache->generateKey($data, array_merge($contextData, ['operation' => 'normalize']));

            $cached = $this->cache->get($cacheKey);
            if ($cached !== null && isset($cached['result'])) {
                return $cached['result'];
            }
        }

        // Cast to NormalizerInterface to access normalize method
        if ($this->symfonySerializer instanceof NormalizerInterface) {
            $result = $this->symfonySerializer->normalize($data, null, $contextData);

            // Cache the result if caching is enabled
            if ($this->cache && $this->cacheEnabled && is_object($data)) {
                $cacheKey = $this->cache->generateKey($data, array_merge($contextData, ['operation' => 'normalize']));
                $this->cache->set($cacheKey, ['result' => $result, 'timestamp' => time()]);
            }

            return $result;
        }

        throw new \RuntimeException('Serializer does not support normalization');
    }

    /**
     * Denormalize array data to object
     *
     * @param mixed $data Data to denormalize
     * @param string $type Target type/class
     * @param SerializationContext|null $context Denormalization context
     * @return mixed Denormalized object
     */
    public function denormalize(mixed $data, string $type, ?SerializationContext $context = null): mixed
    {
        $contextData = $context ? $context->toArray() : [];

        // Cast to DenormalizerInterface to access denormalize method
        if ($this->symfonySerializer instanceof DenormalizerInterface) {
            return $this->symfonySerializer->denormalize($data, $type, null, $contextData);
        }

        throw new \RuntimeException('Serializer does not support denormalization');
    }

    /**
     * Security-aware serialization for cache and queue operations
     *
     * @param mixed $data Data to serialize securely
     * @param bool $forcePhp Force PHP serialization format
     * @return string Securely serialized data
     */
    public function secureSerialize(mixed $data, bool $forcePhp = false): string
    {
        return $this->secureSerializer->serialize($data, $forcePhp);
    }

    /**
     * Security-aware deserialization for cache and queue operations
     *
     * @param string $data Serialized data to deserialize
     * @param array $additionalAllowedClasses Additional classes to allow
     * @return mixed Deserialized data
     */
    public function secureDeserialize(string $data, array $additionalAllowedClasses = []): mixed
    {
        return $this->secureSerializer->unserialize($data, $additionalAllowedClasses);
    }

    /**
     * Convert data to JSON with optional context
     *
     * @param mixed $data Data to convert
     * @param SerializationContext|null $context Serialization context
     * @return string JSON representation
     */
    public function toJson(mixed $data, ?SerializationContext $context = null): string
    {
        return $this->serialize($data, 'json', $context);
    }

    /**
     * Convert data to XML with optional context
     *
     * @param mixed $data Data to convert
     * @param SerializationContext|null $context Serialization context
     * @return string XML representation
     */
    public function toXml(mixed $data, ?SerializationContext $context = null): string
    {
        return $this->serialize($data, 'xml', $context);
    }

    /**
     * Convert JSON to array with optional context
     *
     * @param string $json JSON string
     * @param SerializationContext|null $context Deserialization context
     * @return array Array representation
     */
    public function fromJson(string $json, ?SerializationContext $context = null): array
    {
        return $this->deserialize($json, 'array', 'json', $context);
    }

    /**
     * Convert XML to array with optional context
     *
     * @param string $xml XML string
     * @param SerializationContext|null $context Deserialization context
     * @return array Array representation
     */
    public function fromXml(string $xml, ?SerializationContext $context = null): array
    {
        return $this->deserialize($xml, 'array', 'xml', $context);
    }

    /**
     * Serialize data with groups
     *
     * @param mixed $data Data to serialize
     * @param array $groups Serialization groups
     * @param string $format Target format
     * @return string Serialized data
     */
    public function serializeWithGroups(mixed $data, array $groups, string $format = 'json'): string
    {
        $context = SerializationContext::create()->withGroups($groups);
        return $this->serialize($data, $format, $context);
    }

    /**
     * Normalize data with groups
     *
     * @param mixed $data Data to normalize
     * @param array $groups Serialization groups
     * @return array Normalized data
     */
    public function normalizeWithGroups(mixed $data, array $groups): array
    {
        $context = SerializationContext::create()->withGroups($groups);
        return $this->normalize($data, $context);
    }

    /**
     * Get the underlying Symfony Serializer (for advanced usage)
     *
     * @return SerializerInterface Symfony serializer instance
     */
    public function getSymfonySerializer(): SerializerInterface
    {
        return $this->symfonySerializer;
    }

    /**
     * Get the secure serializer (for cache/queue operations)
     *
     * @return SecureSerializer Secure serializer instance
     */
    public function getSecureSerializer(): SecureSerializer
    {
        return $this->secureSerializer;
    }

    /**
     * Performance Optimization Methods
     */

    /**
     * Enable or disable caching
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
     * Get cache instance
     */
    public function getCache(): ?SerializationCache
    {
        return $this->cache;
    }

    /**
     * Get normalizer registry
     */
    public function getNormalizerRegistry(): ?LazyNormalizerRegistry
    {
        return $this->normalizerRegistry;
    }

    /**
     * Invalidate cache for specific object
     */
    public function invalidateCache(object $object): void
    {
        if ($this->cache) {
            $this->cache->invalidateObject($object);
        }
    }

    /**
     * Clear all serialization cache
     */
    public function clearCache(): void
    {
        if ($this->cache) {
            $this->cache->clear();
        }
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        if ($this->cache) {
            return $this->cache->getStats();
        }
        return ['cache_enabled' => false];
    }

    /**
     * Get normalizer registry statistics
     */
    public function getNormalizerStats(): array
    {
        if ($this->normalizerRegistry) {
            return $this->normalizerRegistry->getStats();
        }
        return ['registry_enabled' => false];
    }

    /**
     * Preload specific normalizers for better performance
     */
    public function preloadNormalizers(array $classes): void
    {
        if ($this->normalizerRegistry) {
            $this->normalizerRegistry->preload($classes);
        }
    }

    /**
     * Warm up cache for collection of objects
     */
    public function warmupCache(array $objects, ?SerializationContext $context = null): int
    {
        if (!$this->cache || !$this->cacheEnabled) {
            return 0;
        }

        $warmedUp = 0;
        foreach ($objects as $object) {
            if (is_object($object)) {
                $contextData = $context ? $context->toArray() : [];
                $cacheKey = $this->cache->generateKey($object, $contextData);

                if (!$this->cache->has($cacheKey)) {
                    try {
                        $this->normalize($object, $context);
                        $warmedUp++;
                    } catch (\Exception $e) {
                        // Skip objects that can't be normalized
                        continue;
                    }
                }
            }
        }

        return $warmedUp;
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'cache_enabled' => $this->cacheEnabled,
            'cache_stats' => $this->getCacheStats(),
            'normalizer_stats' => $this->getNormalizerStats(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }
}
