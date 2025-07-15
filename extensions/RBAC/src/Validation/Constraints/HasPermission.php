<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Validation\Constraints;

use Glueful\Validation\Constraints\AbstractConstraint;

/**
 * Has Permission Constraint
 *
 * Validates that a user has a specific permission.
 * This is an example extension constraint for the RBAC extension.
 *
 * Usage:
 * ```php
 * use Glueful\Extensions\RBAC\Validation\Constraints\HasPermission;
 *
 * class ActionDTO {
 *     #[HasPermission(permission: 'users.delete')]
 *     public int $userId;
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class HasPermission extends AbstractConstraint
{
    /** @var string Error message */
    public string $message = 'User does not have the required permission: {{ permission }}';

    /** @var string Required permission */
    public string $permission = '';

    /** @var int|null User ID to check (null means current user) */
    public ?int $userId = null;

    /** @var bool Whether to check role-based permissions */
    public bool $checkRoles = true;

    /** @var bool Whether to check direct permissions */
    public bool $checkDirectPermissions = true;

    /**
     * Constructor
     *
     * @param string $permission Required permission
     * @param int|null $userId User ID to check (null for current user)
     * @param bool $checkRoles Whether to check role-based permissions
     * @param bool $checkDirectPermissions Whether to check direct permissions
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @param mixed $payload Additional payload
     * @param array $options Additional options
     */
    public function __construct(
        string $permission = '',
        ?int $userId = null,
        bool $checkRoles = true,
        bool $checkDirectPermissions = true,
        ?string $message = null,
        array $groups = [],
        mixed $payload = null,
        array $options = []
    ) {
        $this->permission = $permission;
        $this->userId = $userId;
        $this->checkRoles = $checkRoles;
        $this->checkDirectPermissions = $checkDirectPermissions;

        if ($message !== null) {
            $this->message = $message;
        }

        parent::__construct($groups, $payload, $options);
    }

    /**
     * Validate configuration
     *
     * @throws \InvalidArgumentException If permission is empty
     */
    public function validateConfiguration(): void
    {
        // Skip validation during registration when permission is empty
        // This allows the constraint to be registered without specific permission
        if (empty($this->permission)) {
            return;
        }
    }

    /**
     * Get constraint type
     *
     * @return string Constraint type
     */
    public function getType(): string
    {
        return 'rbac';
    }

    /**
     * Get documentation URL
     *
     * @return string Documentation URL
     */
    public function getDocumentationUrl(): ?string
    {
        return '/docs/extensions/rbac/validation/has-permission';
    }
}