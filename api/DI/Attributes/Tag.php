<?php

declare(strict_types=1);

namespace Glueful\DI\Attributes;

/**
 * Tag Attribute
 *
 * PHP 8+ attribute for tagging services in the DI container.
 * Services with the same tag can be collected and processed together.
 *
 * Usage:
 * #[Tag('console.command', priority: 10)]
 * #[Tag('event.listener', event: 'user.created')]
 * class MyService { ... }
 *
 * @package Glueful\DI\Attributes
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Tag
{
    /**
     * Tag Attribute Constructor
     *
     * @param string $name Tag name
     * @param array $attributes Additional tag attributes
     * @param int $priority Tag priority for ordering (higher = first)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
        public readonly int $priority = 0
    ) {
    }

    /**
     * Get tag name
     *
     * @return string Tag name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get tag attributes
     *
     * @return array Tag attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get tag priority
     *
     * @return int Priority value
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get specific attribute value
     *
     * @param string $key Attribute key
     * @param mixed $default Default value if not found
     * @return mixed Attribute value
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Convert to Symfony tag format
     *
     * @return array Symfony-compatible tag definition
     */
    public function toSymfonyTag(): array
    {
        $tag = ['name' => $this->name];

        if ($this->priority !== 0) {
            $tag['priority'] = $this->priority;
        }

        return array_merge($tag, $this->attributes);
    }
}
