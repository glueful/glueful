<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Entity Created Event
 *
 * Dispatched when a new entity is created in the database.
 * Used for cache invalidation, audit logging, and notifications.
 *
 * @package Glueful\Events\Database
 */
class EntityCreatedEvent extends Event
{
    /**
     * @param mixed $entity The created entity/data
     * @param string $table Database table name
     * @param array $metadata Additional metadata
     */
    public function __construct(
        private readonly mixed $entity,
        private readonly string $table,
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get created entity
     *
     * @return mixed Entity data
     */
    public function getEntity(): mixed
    {
        return $this->entity;
    }

    /**
     * Get table name
     *
     * @return string Table name
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get entity ID if available
     *
     * @return mixed Entity ID
     */
    public function getEntityId(): mixed
    {
        if (is_array($this->entity)) {
            return $this->entity['id'] ?? $this->entity['uuid'] ?? null;
        }

        if (is_object($this->entity)) {
            return $this->entity->id ?? $this->entity->uuid ?? null;
        }

        return null;
    }

    /**
     * Get cache tags to invalidate
     *
     * @return array Cache tags
     */
    public function getCacheTags(): array
    {
        $tags = [$this->table];

        if ($entityId = $this->getEntityId()) {
            $tags[] = $this->table . ':' . $entityId;
        }

        return array_merge($tags, $this->metadata['cache_tags'] ?? []);
    }

    /**
     * Check if this is a user-related entity
     *
     * @return bool True if user-related
     */
    public function isUserRelated(): bool
    {
        return in_array($this->table, ['users', 'user_sessions', 'user_preferences']);
    }
}
