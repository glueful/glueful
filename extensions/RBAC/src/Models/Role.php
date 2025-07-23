<?php

namespace Glueful\Extensions\RBAC\Models;

/**
 * Role Model
 *
 * Represents a role in the RBAC system with hierarchical support
 *
 * Features:
 * - Hierarchical role structure (parent/child relationships)
 * - Role metadata support
 * - System role protection
 * - Status management
 * - Level-based hierarchy
 */
class Role
{
    private string $uuid;
    private string $name;
    private string $slug;
    private ?string $description;
    private ?string $parentUuid;
    private int $level;
    private bool $isSystem;
    private array $metadata;
    private string $status;
    private string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    public function __construct(array $data = [])
    {
        $this->uuid = $data['uuid'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->slug = $data['slug'] ?? '';
        $this->description = $data['description'] ?? null;
        $this->parentUuid = $data['parent_uuid'] ?? null;
        $this->level = (int)($data['level'] ?? 0);
        $this->isSystem = (bool)($data['is_system'] ?? false);
        $this->metadata = isset($data['metadata']) ? json_decode($data['metadata'], true) ?? [] : [];
        $this->status = $data['status'] ?? 'active';
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? null;
        $this->deletedAt = $data['deleted_at'] ?? null;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getParentUuid(): ?string
    {
        return $this->parentUuid;
    }

    public function setParentUuid(?string $parentUuid): self
    {
        $this->parentUuid = $parentUuid;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadataValue(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function hasParent(): bool
    {
        return $this->parentUuid !== null;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent_uuid' => $this->parentUuid,
            'level' => $this->level,
            'is_system' => $this->isSystem,
            'metadata' => json_encode($this->metadata),
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt
        ];
    }

    public function toArrayForInsert(): array
    {
        $data = $this->toArray();
        unset($data['updated_at'], $data['deleted_at']);
        return array_filter($data, fn($value) => $value !== null);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
