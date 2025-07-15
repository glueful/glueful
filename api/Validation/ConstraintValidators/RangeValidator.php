<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\Range;

/**
 * Range validator
 *
 * Validates that a numeric value is within a specified range.
 * Maps Glueful's Range constraint to validation logic.
 */
class RangeValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Range) {
            throw new UnexpectedTypeException($constraint, Range::class);
        }

        // Allow null values (use Required constraint for required fields)
        if ($value === null) {
            return;
        }

        // Must be numeric
        if (!is_numeric($value)) {
            $this->context->buildViolation('The {{ field }} must be a number.')
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
            return;
        }

        $numericValue = is_string($value) ? (float) $value : $value;

        // If both min and max are set, use range validation
        if ($constraint->min !== null && $constraint->max !== null) {
            if ($numericValue < $constraint->min || $numericValue > $constraint->max) {
                $this->context->buildViolation($constraint->notInRangeMessage)
                    ->setParameter('{{ field }}', $this->context->getPropertyName())
                    ->setParameter('{{ min }}', (string) $constraint->min)
                    ->setParameter('{{ max }}', (string) $constraint->max)
                    ->addViolation();
            }
        } else {
            // Check minimum value only if max is not set
            if ($constraint->min !== null && $numericValue < $constraint->min) {
                $this->context->buildViolation($constraint->minMessage)
                    ->setParameter('{{ field }}', $this->context->getPropertyName())
                    ->setParameter('{{ min }}', (string) $constraint->min)
                    ->addViolation();
            }

            // Check maximum value only if min is not set
            if ($constraint->max !== null && $numericValue > $constraint->max) {
                $this->context->buildViolation($constraint->maxMessage)
                    ->setParameter('{{ field }}', $this->context->getPropertyName())
                    ->setParameter('{{ max }}', (string) $constraint->max)
                    ->addViolation();
            }
        }
    }
}
