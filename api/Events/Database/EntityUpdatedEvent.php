<?php

declare(strict_types=1);

namespace Glueful\Events\Database;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Entity Updated Event
 *
 * Dispatched when an entity is updated in the database.
 * Used for cache invalidation, audit logging, and change notifications.
 *
 * @package Glueful\Events\Database
 */
class EntityUpdatedEvent extends Event
{
    /**
     * @param mixed $entity The updated entity/data
     * @param string $table Database table name
     * @param array $changes Changed fields
     * @param array $metadata Additional metadata
     */
    public function __construct(
        private readonly mixed $entity,
        private readonly string $table,
        private readonly array $changes = [],
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get updated entity
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
     * Get changed fields
     *
     * @return array Changed fields
     */
    public function getChanges(): array
    {
        return $this->changes;
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
     * Check if specific field was changed
     *
     * @param string $field Field name
     * @return bool True if changed
     */
    public function hasFieldChanged(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }

    /**
     * Get old value for a field
     *
     * @param string $field Field name
     * @return mixed Old value
     */
    public function getOldValue(string $field): mixed
    {
        return $this->changes[$field]['old'] ?? null;
    }

    /**
     * Get new value for a field
     *
     * @param string $field Field name
     * @return mixed New value
     */
    public function getNewValue(string $field): mixed
    {
        return $this->changes[$field]['new'] ?? null;
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
     * Check if this is a critical field update
     *
     * @return bool True if critical
     */
    public function isCriticalUpdate(): bool
    {
        $criticalFields = ['status', 'active', 'enabled', 'permissions'];

        foreach ($criticalFields as $field) {
            if ($this->hasFieldChanged($field)) {
                return true;
            }
        }

        return false;
    }
}
