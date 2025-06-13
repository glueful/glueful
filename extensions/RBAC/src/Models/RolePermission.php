<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Models;

/**
 * Role Permission Model
 *
 * Represents a permission assignment to a role in the RBAC system.
 * Manages the many-to-many relationship between roles and permissions.
 */
class RolePermission
{
    private int $id;
    private string $uuid;
    private string $roleUuid;
    private string $permissionUuid;
    private ?array $resourceFilter = null;
    private ?array $constraints = null;
    private ?string $grantedBy = null;
    private ?string $expiresAt = null;
    private string $createdAt;

    /**
     * Constructor
     *
     * @param array $data Role permission data
     */
    public function __construct(array $data = [])
    {
        $this->id = (int) ($data['id'] ?? 0);
        $this->uuid = $data['uuid'] ?? '';
        $this->roleUuid = $data['role_uuid'] ?? '';
        $this->permissionUuid = $data['permission_uuid'] ?? '';
        $this->grantedBy = $data['granted_by'] ?? null;
        $this->expiresAt = $data['expires_at'] ?? null;
        $this->createdAt = $data['created_at'] ?? date('Y-m-d H:i:s');

        // Decode JSON fields
        if (isset($data['resource_filter'])) {
            $this->resourceFilter = is_string($data['resource_filter'])
                ? json_decode($data['resource_filter'], true)
                : $data['resource_filter'];
        }

        if (isset($data['constraints'])) {
            $this->constraints = is_string($data['constraints'])
                ? json_decode($data['constraints'], true)
                : $data['constraints'];
        }
    }

    /**
     * Get ID
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get UUID
     *
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Get role UUID
     *
     * @return string
     */
    public function getRoleUuid(): string
    {
        return $this->roleUuid;
    }

    /**
     * Get permission UUID
     *
     * @return string
     */
    public function getPermissionUuid(): string
    {
        return $this->permissionUuid;
    }

    /**
     * Get resource filter
     *
     * @return array|null
     */
    public function getResourceFilter(): ?array
    {
        return $this->resourceFilter;
    }

    /**
     * Get constraints
     *
     * @return array|null
     */
    public function getConstraints(): ?array
    {
        return $this->constraints;
    }

    /**
     * Get granted by user UUID
     *
     * @return string|null
     */
    public function getGrantedBy(): ?string
    {
        return $this->grantedBy;
    }

    /**
     * Get expiration timestamp
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    /**
     * Get creation timestamp
     *
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * Check if assignment is expired
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return strtotime($this->expiresAt) < time();
    }

    /**
     * Check if assignment is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !$this->isExpired();
    }

    /**
     * Check if resource matches filter
     *
     * @param array $context Resource context
     * @return bool
     */
    public function matchesResource(array $context): bool
    {
        if (empty($this->resourceFilter)) {
            return true; // No filter means all resources
        }

        $filterResource = $this->resourceFilter['resource'] ?? '*';

        if ($filterResource === '*') {
            return true; // Wildcard matches all
        }

        $contextResource = $context['resource'] ?? '';

        if (empty($contextResource)) {
            return false;
        }

        // Exact match
        if ($filterResource === $contextResource) {
            return true;
        }

        // Pattern match (e.g., "users.*" matches "users.create")
        $pattern = str_replace('*', '.*', $filterResource);
        return (bool) preg_match('/^' . $pattern . '$/', $contextResource);
    }

    /**
     * Check if context satisfies constraints
     *
     * @param array $context Context to check
     * @return bool
     */
    public function satisfiesConstraints(array $context): bool
    {
        if (empty($this->constraints)) {
            return true; // No constraints means always satisfied
        }

        foreach ($this->constraints as $key => $constraint) {
            if (!isset($context[$key])) {
                return false;
            }

            if (!$this->evaluateConstraint($context[$key], $constraint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'role_uuid' => $this->roleUuid,
            'permission_uuid' => $this->permissionUuid,
            'resource_filter' => $this->resourceFilter,
            'constraints' => $this->constraints,
            'granted_by' => $this->grantedBy,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
            'is_expired' => $this->isExpired(),
            'is_active' => $this->isActive()
        ];
    }

    /**
     * Create from database row
     *
     * @param array $row Database row
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    // Private helper methods

    private function evaluateConstraint($value, $constraint): bool
    {
        // Array constraint - value must be in array
        if (is_array($constraint)) {
            return in_array($value, $constraint);
        }

        // Operator constraint (e.g., ">:100", "<=:50")
        if (is_string($constraint) && strpos($constraint, ':') !== false) {
            list($operator, $constraintValue) = explode(':', $constraint, 2);

            switch ($operator) {
                case '>':
                    return $value > $constraintValue;
                case '>=':
                    return $value >= $constraintValue;
                case '<':
                    return $value < $constraintValue;
                case '<=':
                    return $value <= $constraintValue;
                case '!=':
                    return $value != $constraintValue;
                case 'in':
                    return in_array($value, explode(',', $constraintValue));
                case 'not_in':
                    return !in_array($value, explode(',', $constraintValue));
            }
        }

        // Direct value comparison
        return $value == $constraint;
    }
}
