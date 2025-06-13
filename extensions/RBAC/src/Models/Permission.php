<?php

namespace Glueful\Extensions\RBAC\Models;

/**
 * Permission Model
 *
 * Represents a permission in the RBAC system
 *
 * Features:
 * - Permission categorization
 * - Resource type classification
 * - System permission protection
 * - Metadata support for flexible permissions
 */
class Permission
{
    private string $uuid;
    private string $name;
    private string $slug;
    private ?string $description;
    private ?string $category;
    private ?string $resourceType;
    private bool $isSystem;
    private array $metadata;
    private string $createdAt;

    public function __construct(array $data = [])
    {
        $this->uuid = $data['uuid'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->slug = $data['slug'] ?? '';
        $this->description = $data['description'] ?? null;
        $this->category = $data['category'] ?? null;
        $this->resourceType = $data['resource_type'] ?? null;
        $this->isSystem = (bool)($data['is_system'] ?? false);
        $this->metadata = isset($data['metadata']) ? json_decode($data['metadata'], true) ?? [] : [];
        $this->createdAt = $data['created_at'] ?? '';
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function setResourceType(?string $resourceType): self
    {
        $this->resourceType = $resourceType;
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

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function hasCategory(): bool
    {
        return $this->category !== null;
    }

    public function hasResourceType(): bool
    {
        return $this->resourceType !== null;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'resource_type' => $this->resourceType,
            'is_system' => $this->isSystem,
            'metadata' => json_encode($this->metadata),
            'created_at' => $this->createdAt
        ];
    }

    public function toArrayForInsert(): array
    {
        return array_filter($this->toArray(), fn($value) => $value !== null);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
