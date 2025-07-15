<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\Required;

/**
 * Required validator
 *
 * Validates that a value is not blank (not null and not empty string).
 * Maps Glueful's Required constraint to validation logic.
 */
class RequiredValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Required) {
            throw new UnexpectedTypeException($constraint, Required::class);
        }

        // Consider null, empty string, and empty arrays as invalid
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
        }
    }
}
