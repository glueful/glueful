<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\Choice;

/**
 * Choice validator
 *
 * Validates that a value is one of the given choices.
 * Maps Glueful's Choice constraint to validation logic.
 */
class ChoiceValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The value to validate
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Choice) {
            throw new UnexpectedTypeException($constraint, Choice::class);
        }

        // Allow null values (use Required constraint for required fields)
        if ($value === null) {
            return;
        }

        if (empty($constraint->choices)) {
            $this->context->buildViolation('No choices provided for {{ field }}.')
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
            return;
        }

        if ($constraint->multiple) {
            $this->validateMultiple($value, $constraint);
        } else {
            $this->validateSingle($value, $constraint);
        }
    }

    /**
     * Validate single choice
     *
     * @param mixed $value The value to validate
     * @param Choice $constraint The constraint instance
     */
    private function validateSingle(mixed $value, Choice $constraint): void
    {
        if (!in_array($value, $constraint->choices, true)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->setParameter('{{ choices }}', implode(', ', $constraint->choices))
                ->addViolation();
        }
    }

    /**
     * Validate multiple choices
     *
     * @param mixed $value The value to validate
     * @param Choice $constraint The constraint instance
     */
    private function validateMultiple(mixed $value, Choice $constraint): void
    {
        if (!is_array($value)) {
            $this->context->buildViolation('The {{ field }} must be an array when multiple choices are allowed.')
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->addViolation();
            return;
        }

        // Check minimum number of choices
        if ($constraint->min !== null && count($value) < $constraint->min) {
            $this->context->buildViolation('The {{ field }} must have at least {{ min }} choices.')
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->setParameter('{{ min }}', (string) $constraint->min)
                ->addViolation();
        }

        // Check maximum number of choices
        if ($constraint->max !== null && count($value) > $constraint->max) {
            $this->context->buildViolation('The {{ field }} must have at most {{ max }} choices.')
                ->setParameter('{{ field }}', $this->context->getPropertyName())
                ->setParameter('{{ max }}', (string) $constraint->max)
                ->addViolation();
        }

        // Check that all values are valid choices
        foreach ($value as $choice) {
            if (!in_array($choice, $constraint->choices, true)) {
                $this->context->buildViolation($constraint->multipleMessage)
                    ->setParameter('{{ field }}', $this->context->getPropertyName())
                    ->addViolation();
                break; // Only show this error once
            }
        }
    }
}
