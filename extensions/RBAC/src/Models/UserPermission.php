<?php

namespace Glueful\Extensions\RBAC\Models;

/**
 * User Permission Model
 *
 * Represents direct permission assignments to users (bypassing roles)
 *
 * Features:
 * - Direct user-permission assignments
 * - Resource-level filtering
 * - Temporal constraints (time, IP, device restrictions)
 * - Permission overrides (grant or deny)
 * - Assignment tracking and audit trail
 */
class UserPermission
{
    private string $uuid;
    private string $userUuid;
    private string $permissionUuid;
    private ?array $resourceFilter;
    private ?array $constraints;
    private ?string $grantedBy;
    private ?string $expiresAt;
    private string $createdAt;

    public function __construct(array $data = [])
    {
        $this->uuid = $data['uuid'] ?? '';
        $this->userUuid = $data['user_uuid'] ?? '';
        $this->permissionUuid = $data['permission_uuid'] ?? '';
        $this->resourceFilter = isset($data['resource_filter']) ? json_decode($data['resource_filter'], true) : null;
        $this->constraints = isset($data['constraints']) ? json_decode($data['constraints'], true) : null;
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

    public function getPermissionUuid(): string
    {
        return $this->permissionUuid;
    }

    public function setPermissionUuid(string $permissionUuid): self
    {
        $this->permissionUuid = $permissionUuid;
        return $this;
    }

    public function getResourceFilter(): ?array
    {
        return $this->resourceFilter;
    }

    public function setResourceFilter(?array $resourceFilter): self
    {
        $this->resourceFilter = $resourceFilter;
        return $this;
    }

    public function getResourceFilterValue(string $key, $default = null)
    {
        return $this->resourceFilter[$key] ?? $default;
    }

    public function setResourceFilterValue(string $key, $value): self
    {
        if ($this->resourceFilter === null) {
            $this->resourceFilter = [];
        }
        $this->resourceFilter[$key] = $value;
        return $this;
    }

    public function getConstraints(): ?array
    {
        return $this->constraints;
    }

    public function setConstraints(?array $constraints): self
    {
        $this->constraints = $constraints;
        return $this;
    }

    public function getConstraintValue(string $key, $default = null)
    {
        return $this->constraints[$key] ?? $default;
    }

    public function setConstraintValue(string $key, $value): self
    {
        if ($this->constraints === null) {
            $this->constraints = [];
        }
        $this->constraints[$key] = $value;
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

    public function hasResourceFilter(): bool
    {
        return $this->resourceFilter !== null && !empty($this->resourceFilter);
    }

    public function hasConstraints(): bool
    {
        return $this->constraints !== null && !empty($this->constraints);
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

    public function matchesResource(array $resourceContext): bool
    {
        if (!$this->hasResourceFilter()) {
            return true; // No filter means it applies to all resources
        }

        foreach ($this->resourceFilter as $key => $value) {
            if (!isset($resourceContext[$key]) || $resourceContext[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function satisfiesConstraints(array $context): bool
    {
        if (!$this->hasConstraints()) {
            return true; // No constraints means always satisfied
        }

        // Check time constraints
        if (isset($this->constraints['time_start']) && isset($this->constraints['time_end'])) {
            $currentTime = date('H:i:s');
            if ($currentTime < $this->constraints['time_start'] || $currentTime > $this->constraints['time_end']) {
                return false;
            }
        }

        // Check IP constraints
        if (isset($this->constraints['allowed_ips'])) {
            $userIp = $context['ip'] ?? '';
            if (!in_array($userIp, $this->constraints['allowed_ips'])) {
                return false;
            }
        }

        // Check day of week constraints
        if (isset($this->constraints['allowed_days'])) {
            $currentDay = strtolower(date('l'));
            if (!in_array($currentDay, array_map('strtolower', $this->constraints['allowed_days']))) {
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
            'permission_uuid' => $this->permissionUuid,
            'resource_filter' => $this->resourceFilter ? json_encode($this->resourceFilter) : null,
            'constraints' => $this->constraints ? json_encode($this->constraints) : null,
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
        return $this->userUuid . ':' . $this->permissionUuid;
    }
}
