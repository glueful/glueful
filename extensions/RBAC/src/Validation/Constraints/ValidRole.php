<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Validation\Constraints;

use Glueful\Validation\Constraints\AbstractConstraint;

/**
 * Valid Role Constraint
 *
 * Validates that a role name or ID exists in the RBAC system.
 * This demonstrates database validation within an extension constraint.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ValidRole extends AbstractConstraint
{
    /** @var string Error message */
    public string $message = 'Invalid role: {{ value }}';

    /** @var string Validation mode: 'name' or 'id' */
    public string $mode = 'name';

    /** @var bool Whether to check if role is active */
    public bool $activeOnly = true;

    /**
     * Constructor
     *
     * @param string $mode Validation mode ('name' or 'id')
     * @param bool $activeOnly Whether to check if role is active
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @param mixed $payload Additional payload
     * @param array $options Additional options
     */
    public function __construct(
        string $mode = 'name',
        bool $activeOnly = true,
        ?string $message = null,
        array $groups = [],
        mixed $payload = null,
        array $options = []
    ) {
        $this->mode = $mode;
        $this->activeOnly = $activeOnly;

        if ($message !== null) {
            $this->message = $message;
        }

        parent::__construct($groups, $payload, $options);
    }

    /**
     * Validate configuration
     *
     * @throws \InvalidArgumentException If mode is invalid
     */
    public function validateConfiguration(): void
    {
        if (!in_array($this->mode, ['name', 'id'])) {
            throw new \InvalidArgumentException('Mode must be either "name" or "id"');
        }
    }

    /**
     * Get constraint type
     *
     * @return string Constraint type
     */
    public function getType(): string
    {
        return 'rbac_database';
    }
}
