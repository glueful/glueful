<?php

declare(strict_types=1);

namespace Glueful\DI\Attributes;

/**
 * Service Attribute
 *
 * PHP 8+ attribute for marking classes as services in the DI container.
 * Provides metadata for automatic service registration and configuration.
 *
 * Usage:
 * #[Service(id: 'my.service', public: true, tags: ['console.command'])]
 * class MyService { ... }
 *
 * @package Glueful\DI\Attributes
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Service
{
    /**
     * Service Attribute Constructor
     *
     * @param string|null $id Service identifier (defaults to class name)
     * @param bool $public Whether service should be public (default false)
     * @param array $tags Array of service tags for grouping
     * @param bool $shared Whether service should be shared/singleton (default true)
     * @param bool $lazy Whether service should be lazy-loaded (default false)
     * @param string|null $factory Factory method or service for creating the service
     * @param array $arguments Constructor arguments to inject
     * @param array $calls Method calls to make after instantiation
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly bool $public = false,
        public readonly array $tags = [],
        public readonly bool $shared = true,
        public readonly bool $lazy = false,
        public readonly ?string $factory = null,
        public readonly array $arguments = [],
        public readonly array $calls = []
    ) {
    }

    /**
     * Get service ID
     *
     * Returns the service ID, using class name as fallback if not specified.
     *
     * @param string $className Class name to use as fallback
     * @return string Service identifier
     */
    public function getId(string $className): string
    {
        return $this->id ?? $className;
    }

    /**
     * Check if service is public
     *
     * @return bool True if service should be public
     */
    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * Get service tags
     *
     * @return array Array of tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Check if service is shared/singleton
     *
     * @return bool True if service should be shared
     */
    public function isShared(): bool
    {
        return $this->shared;
    }

    /**
     * Check if service should be lazy-loaded
     *
     * @return bool True if service should be lazy
     */
    public function isLazy(): bool
    {
        return $this->lazy;
    }

    /**
     * Get factory method or service
     *
     * @return string|null Factory specification
     */
    public function getFactory(): ?string
    {
        return $this->factory;
    }

    /**
     * Get constructor arguments
     *
     * @return array Constructor arguments
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Get method calls
     *
     * @return array Method calls to make after instantiation
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Convert to array representation
     *
     * Useful for debugging and serialization.
     *
     * @param string $className Class name for ID fallback
     * @return array Service configuration as array
     */
    public function toArray(string $className): array
    {
        return [
            'id' => $this->getId($className),
            'class' => $className,
            'public' => $this->isPublic(),
            'tags' => $this->getTags(),
            'shared' => $this->isShared(),
            'lazy' => $this->isLazy(),
            'factory' => $this->getFactory(),
            'arguments' => $this->getArguments(),
            'calls' => $this->getCalls()
        ];
    }
}
