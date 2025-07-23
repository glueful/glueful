<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\FieldsMatch;

/**
 * FieldsMatch validator
 *
 * Validates that two fields have matching values.
 * Supports case-sensitive and case-insensitive comparison.
 */
class FieldsMatchValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The object being validated
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof FieldsMatch) {
            throw new UnexpectedTypeException($constraint, FieldsMatch::class);
        }

        if (!is_object($value)) {
            return;
        }

        $field1Value = $this->getPropertyValue($value, $constraint->field);
        $field2Value = $this->getPropertyValue($value, $constraint->otherField);

        // If both values are null or empty, consider them matching
        if ($this->isEmpty($field1Value) && $this->isEmpty($field2Value)) {
            return;
        }

        // Check if values match
        $match = $constraint->caseSensitive
            ? $this->strictCompare($field1Value, $field2Value)
            : $this->caseInsensitiveCompare($field1Value, $field2Value);

        if (!$match) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ field }}', $constraint->otherField)
                ->setParameter('{{ otherField }}', $constraint->field)
                ->addViolation();
        }
    }

    /**
     * Get property value from object
     *
     * @param object $object Object to get value from
     * @param string $property Property name
     * @return mixed Property value
     */
    private function getPropertyValue(object $object, string $property): mixed
    {
        try {
            $reflection = new \ReflectionClass($object);
            $prop = $reflection->getProperty($property);

            if (!$prop->isInitialized($object)) {
                return null;
            }

            return $prop->getValue($object);
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Strict comparison of values
     *
     * @param mixed $value1 First value
     * @param mixed $value2 Second value
     * @return bool True if values match exactly
     */
    private function strictCompare(mixed $value1, mixed $value2): bool
    {
        return $value1 === $value2;
    }

    /**
     * Case-insensitive comparison of values
     *
     * @param mixed $value1 First value
     * @param mixed $value2 Second value
     * @return bool True if values match (case-insensitive for strings)
     */
    private function caseInsensitiveCompare(mixed $value1, mixed $value2): bool
    {
        // If both are strings, do case-insensitive comparison
        if (is_string($value1) && is_string($value2)) {
            return strcasecmp($value1, $value2) === 0;
        }

        // For non-strings, use strict comparison
        return $value1 === $value2;
    }

    /**
     * Check if value is empty
     *
     * @param mixed $value Value to check
     * @return bool True if empty
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
