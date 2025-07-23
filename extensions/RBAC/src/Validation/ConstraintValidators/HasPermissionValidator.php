<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Extensions\RBAC\Validation\Constraints\HasPermission;

/**
 * Has Permission Validator
 *
 * Validates that a user has the required permission using the RBAC system.
 * This demonstrates how extension validators can integrate with extension services.
 */
class HasPermissionValidator extends ConstraintValidator
{
    /**
     * Validate permission
     *
     * @param mixed $value The value to validate (typically user ID)
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof HasPermission) {
            throw new UnexpectedTypeException($constraint, HasPermission::class);
        }

        // Skip validation if no user context (this would be set by the application)
        if ($value === null && $constraint->userId === null) {
            return;
        }

        $userId = $constraint->userId ?? $value;

        // In a real implementation, this would check permissions via RBAC service
        // For this example, we'll simulate the permission check
        if (!$this->hasPermission($userId, $constraint->permission, $constraint)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ permission }}', $constraint->permission)
                ->setParameter('{{ user_id }}', (string) $userId)
                ->addViolation();
        }
    }

    /**
     * Check if user has permission
     *
     * This is a simplified example. In a real implementation, this would:
     * 1. Get the user from the database
     * 2. Check their roles and permissions
     * 3. Use the RBAC service to determine access
     *
     * @param mixed $userId User ID
     * @param string $permission Required permission
     * @param HasPermission $constraint Constraint instance
     * @return bool True if user has permission
     */
    private function hasPermission(mixed $userId, string $permission, HasPermission $constraint): bool
    {
        // Example permission check logic
        // In a real implementation, this would use:
        // $rbacService = container()->get(RBACService::class);
        // return $rbacService->userHasPermission($userId, $permission);

        // For demo purposes, simulate some permission checks
        $simulatedPermissions = [
            'users.view' => true,
            'users.edit' => true,
            'users.delete' => false, // Simulate lack of permission
            'admin.settings' => false,
        ];

        return $simulatedPermissions[$permission] ?? false;
    }
}
