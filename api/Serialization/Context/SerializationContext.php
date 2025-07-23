<?php

declare(strict_types=1);

namespace Glueful\Serialization\Context;

/**
 * Serialization Context Builder
 *
 * Provides a fluent interface for building serialization contexts
 * that configure how objects are serialized and deserialized.
 */
class SerializationContext
{
    private array $context = [];

    /**
     * Create a new serialization context
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Set serialization groups
     */
    public function withGroups(array $groups): self
    {
        $this->context['groups'] = $groups;
        return $this;
    }

    /**
     * Enable max depth handling
     */
    public function withMaxDepth(int $depth): self
    {
        $this->context['enable_max_depth'] = true;
        $this->context['max_depth_handler'] = function () {
            return null;
        };
        return $this;
    }

    /**
     * Set custom date format
     */
    public function withDateFormat(string $format): self
    {
        $this->context['datetime_format'] = $format;
        return $this;
    }

    /**
     * Set attributes to ignore during serialization
     */
    public function withIgnoredAttributes(array $attributes): self
    {
        $this->context['ignored_attributes'] = $attributes;
        return $this;
    }

    /**
     * Set circular reference handler
     */
    public function withCircularReferenceHandler(callable $handler): self
    {
        $this->context['circular_reference_handler'] = $handler;
        return $this;
    }

    /**
     * Enable/disable attribute caching
     */
    public function withAttributeCaching(bool $enabled = true): self
    {
        $this->context['cache_attributes'] = $enabled;
        return $this;
    }

    /**
     * Set custom normalizer context
     */
    public function withNormalizerContext(string $normalizer, array $context): self
    {
        $this->context['normalizer_context'][$normalizer] = $context;
        return $this;
    }

    /**
     * Convert context to array for Symfony Serializer
     */
    public function toArray(): array
    {
        return $this->context;
    }

    /**
     * Check if context has groups
     */
    public function hasGroups(): bool
    {
        return !empty($this->context['groups']);
    }

    /**
     * Get groups
     */
    public function getGroups(): array
    {
        return $this->context['groups'] ?? [];
    }

    /**
     * Check if context has ignored attributes
     */
    public function hasIgnoredAttributes(): bool
    {
        return !empty($this->context['ignored_attributes']);
    }

    /**
     * Get ignored attributes
     */
    public function getIgnoredAttributes(): array
    {
        return $this->context['ignored_attributes'] ?? [];
    }
}
