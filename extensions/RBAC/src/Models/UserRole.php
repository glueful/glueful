<?php

namespace Glueful\Extensions\RBAC\Models;

/**
 * User Role Assignment Model
 *
 * Represents the assignment of a role to a user
 *
 * Features:
 * - Scoped role assignments (tenant, department, etc.)
 * - Temporal permissions (expiry support)
 * - Assignment tracking (who granted the role)
 * - Audit trail support
 */
class UserRole
{
    private string $uuid;
    private string $userUuid;
    private string $roleUuid;
    private ?array $scope;
    private ?string $grantedBy;
    private ?string $expiresAt;
    private string $createdAt;

    public function __construct(array $data = [])
    {
        $this->uuid = $data['uuid'] ?? '';
        $this->userUuid = $data['user_uuid'] ?? '';
        $this->roleUuid = $data['role_uuid'] ?? '';
        $this->scope = isset($data['scope']) ? json_decode($data['scope'], true) : null;
        $this->grantedBy = $data['granted_by'] ?? null;
        $this->expiresAt = $data['expires_at'] ?? null;
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

    public function getUserUuid(): string
    {
        return $this->userUuid;
    }

    public function setUserUuid(string $userUuid): self
    {
        $this->userUuid = $userUuid;
        return $this;
    }

    public function getRoleUuid(): string
    {
        return $this->roleUuid;
    }

    public function setRoleUuid(string $roleUuid): self
    {
        $this->roleUuid = $roleUuid;
        return $this;
    }

    public function getScope(): ?array
    {
        return $this->scope;
    }

    public function setScope(?array $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    public function getScopeValue(string $key, $default = null)
    {
        return $this->scope[$key] ?? $default;
    }

    public function setScopeValue(string $key, $value): self
    {
        if ($this->scope === null) {
            $this->scope = [];
        }
        $this->scope[$key] = $value;
        return $this;
    }

    public function getGrantedBy(): ?string
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?string $grantedBy): self
    {
        $this->grantedBy = $grantedBy;
        return $this;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?string $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function hasScope(): bool
    {
        return $this->scope !== null && !empty($this->scope);
    }

    public function hasExpiry(): bool
    {
        return $this->expiresAt !== null;
    }

    public function isExpired(): bool
    {
        if (!$this->hasExpiry()) {
            return false;
        }
        return strtotime($this->expiresAt) < time();
    }

    public function isActive(): bool
    {
        return !$this->isExpired();
    }

    public function matchesScope(array $requiredScope): bool
    {
        if (!$this->hasScope()) {
            return true; // No scope restriction means it applies globally
        }

        foreach ($requiredScope as $key => $value) {
            if (!isset($this->scope[$key]) || $this->scope[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'user_uuid' => $this->userUuid,
            'role_uuid' => $this->roleUuid,
            'scope' => $this->scope ? json_encode($this->scope) : null,
            'granted_by' => $this->grantedBy,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt
        ];
    }

    public function toArrayForInsert(): array
    {
        return array_filter($this->toArray(), fn($value) => $value !== null);
    }

    public function __toString(): string
    {
        return $this->userUuid . ':' . $this->roleUuid;
    }
}
