<?php

declare(strict_types=1);

namespace Glueful\Validation\ConstraintValidators;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Glueful\Validation\Constraints\ConditionalRequired;

/**
 * ConditionalRequired validator
 *
 * Validates that a field is required only when another field meets specific conditions.
 * Supports various comparison operators for flexible conditional logic.
 */
class ConditionalRequiredValidator extends ConstraintValidator
{
    /**
     * Validate the value
     *
     * @param mixed $value The object being validated
     * @param Constraint $constraint The constraint instance
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ConditionalRequired) {
            throw new UnexpectedTypeException($constraint, ConditionalRequired::class);
        }

        if (!is_object($value)) {
            return;
        }

        $whenField = $constraint->when;
        $targetField = $constraint->field;

        // Get the value of the condition field
        $conditionValue = $this->getPropertyValue($value, $whenField);

        // Check if condition is met
        $conditionMet = $this->evaluateCondition($conditionValue, $constraint->equals, $constraint->operator);

        if ($conditionMet) {
            // Get the value of the target field
            $targetValue = $this->getPropertyValue($value, $targetField);

            // Check if the target field is empty (required validation)
            if ($this->isEmpty($targetValue)) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ field }}', $targetField)
                    ->setParameter('{{ when }}', $whenField)
                    ->setParameter('{{ equals }}', $this->formatDisplayValue($constraint->equals))
                    ->addViolation();
            }
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
     * Evaluate condition based on operator
     *
     * @param mixed $actual Actual value
     * @param mixed $expected Expected value
     * @param string $operator Comparison operator
     * @return bool Whether condition is met
     */
    private function evaluateCondition(mixed $actual, mixed $expected, string $operator): bool
    {
        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && !in_array($actual, $expected, true),
            'empty' => $this->isEmpty($actual),
            'not_empty' => !$this->isEmpty($actual),
            'null' => $actual === null,
            'not_null' => $actual !== null,
            'true' => $actual === true,
            'false' => $actual === false,
            default => false,
        };
    }

    /**
     * Check if value is empty
     *
     * @param mixed $value Value to check
     * @return bool True if empty
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && empty($value));
    }

    /**
     * Format value for error message
     *
     * @param mixed $value Value to format
     * @return string Formatted value
     */
    private function formatDisplayValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return '[' . implode(', ', $value) . ']';
        }

        return (string) $value;
    }
}
