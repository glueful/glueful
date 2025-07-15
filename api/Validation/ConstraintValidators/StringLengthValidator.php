<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\StringLength;

/**
 * StringLength validator
 *
 * Validates that a string is between a minimum and maximum length.
 * Maps Glueful's StringLength constraint to validation logic.
 */
class StringLengthValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof StringLength) {
            throw new UnexpectedTypeException($constraint, StringLength::class);
        }

        // Allow null values (use Required constraint for required fields)
        if ($value === null) {
            return;
        }

        // Convert to string if possible
        if (!is_string($value) && !is_numeric($value)) {
            $this->context->buildViolation('The {{ field }} must be a string.')
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
            return;
        }

        $stringValue = (string) $value;
        $length = mb_strlen($stringValue);

        // Check exact length first
        if ($constraint->exact !== null) {
            if ($length !== $constraint->exact) {
                $this->context->buildViolation($constraint->exactMessage)
                    ->setParameter('{{ field }}', $this->context->getPropertyName())
                    ->setParameter('{{ exact }}', (string) $constraint->exact)
                    ->addViolation();
            }
            return;
        }

        // Check minimum length
        if ($constraint->min !== null && $length < $constraint->min) {
            $this->context->buildViolation($constraint->minMessage)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->setParameter('{{ min }}', (string) $constraint->min)
                ->addViolation();
        }

        // Check maximum length
        if ($constraint->max !== null && $length > $constraint->max) {
            $this->context->buildViolation($constraint->maxMessage)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->setParameter('{{ max }}', (string) $constraint->max)
                ->addViolation();
        }
    }
}
