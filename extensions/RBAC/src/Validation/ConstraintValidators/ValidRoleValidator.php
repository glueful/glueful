<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Extensions\RBAC\Validation\Constraints\ValidRole;

/**
 * Valid Role Validator
 *
 * Validates that a role exists in the RBAC system.
 * This demonstrates database validation within an extension.
 */
class ValidRoleValidator extends ConstraintValidator
{
    /**
     * Validate role
     *
     * @param mixed $value The value to validate (role name or ID)
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRole) {
            throw new UnexpectedTypeException($constraint, ValidRole::class);
        }

        // Allow null values (use Required constraint for required fields)
        if ($value === null) {
            return;
        }

        // Validate role exists
        if (!$this->roleExists($value, $constraint)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->setParameter('{{ mode }}', $constraint->mode)
                ->addViolation();
        }
    }

    /**
     * Check if role exists
     *
     * In a real implementation, this would query the roles table:
     * ```php
     * $roleRepo = container()->get(RoleRepository::class);
     * if ($constraint->mode === 'id') {
     *     return $roleRepo->findById($value, $constraint->activeOnly) !== null;
     * } else {
     *     return $roleRepo->findByName($value, $constraint->activeOnly) !== null;
     * }
     * ```
     *
     * @param mixed $value Role name or ID
     * @param ValidRole $constraint Constraint instance
     * @return bool True if role exists
     */
    private function roleExists(mixed $value, ValidRole $constraint): bool
    {
        // Simulate role validation
        $simulatedRoles = [
            'admin' => true,
            'user' => true,
            'moderator' => true,
            'guest' => false, // Simulate inactive role
        ];

        if ($constraint->mode === 'id') {
            // Simulate ID-based validation
            return is_numeric($value) && (int) $value > 0 && (int) $value <= 3;
        } else {
            // Name-based validation
            return isset($simulatedRoles[$value]) &&
                   ($simulatedRoles[$value] || !$constraint->activeOnly);
        }
    }
}
