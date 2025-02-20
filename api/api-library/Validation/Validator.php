<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Validation;

use ReflectionClass;
use ReflectionProperty;
use Glueful\Api\Library\Validation\Attributes\{Rules, Sanitize};

/**
 * Data Transfer Object Validator
 * 
 * Provides attribute-based validation and sanitization for DTOs.
 * Supports multiple validation rules and sanitization filters.
 */
class Validator
{
    /** @var array<string, string[]> Validation error messages */
    private array $errors = [];

    /**
     * Validate DTO object
     * 
     * Processes all properties with validation rules and sanitization filters.
     * 
     * @param object $dto Data Transfer Object to validate
     * @return bool True if validation passes
     */
    public function validate(object $dto): bool
    {
        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($dto);

            // Apply Sanitization First
            $value = $this->sanitize($property, $value);
            $value = $property->getValue($dto);
            $this->applyRules($property, $value);
        }

        return empty($this->errors);
    }

    /**
     * Apply sanitization filters
     * 
     * Processes property value through configured sanitization filters.
     * 
     * @param ReflectionProperty $property Property to sanitize
     * @param mixed $value Value to sanitize
     * @return mixed Sanitized value
     */
    private function sanitize(ReflectionProperty $property, mixed $value): mixed
    {
        foreach ($property->getAttributes(Sanitize::class) as $attribute) {
            $filters = $attribute->getArguments()[0] ?? [];
            foreach ($filters as $filter) {
                $value = match ($filter) {
                    'trim' => trim($value),
                    'strip_tags' => strip_tags($value),
                    'intval' => intval($value),
                    'sanitize_email' => filter_var($value, FILTER_SANITIZE_EMAIL),
                    default => $value,
                };
            }
        }
        return $value;
    }

    /**
     * Apply validation rules
     * 
     * Process all validation rules for a property.
     * 
     * @param ReflectionProperty $property Property to validate
     * @param mixed $value Value to validate
     */
    private function applyRules(ReflectionProperty $property, mixed $value): void
    {
        foreach ($property->getAttributes(Rules::class) as $attribute) {
            $rules = $attribute->getArguments()[0] ?? [];

            foreach ($rules as $rule) {
                $this->applyRule($property, $value, $rule);
            }
        }
    }

    /**
     * Apply single validation rule
     * 
     * Process individual validation rule with parameters.
     * 
     * @param ReflectionProperty $property Property being validated
     * @param mixed $value Value to validate
     * @param string $rule Validation rule string
     * @throws \Exception For unknown validation rules
     */
    private function applyRule(ReflectionProperty $property, mixed $value, string $rule): void
    {
        [$ruleName, $params] = $this->parseRule($rule);

        match ($ruleName) {
            'required' => $this->validateRequired($property, $value),
            'string' => $this->validateString($property, $value),
            'int' => $this->validateInt($property, $value),
            'min' => $this->validateMin($property, $value, (int)$params[0]),
            'max' => $this->validateMax($property, $value, (int)$params[0]),
            'between' => $this->validateBetween($property, $value, (int)$params[0], (int)$params[1]),
            'email' => $this->validateEmail($property, $value),
            default => throw new \Exception("Unknown validation rule: $ruleName"),
        };
    }

    /**
     * Parse rule string
     * 
     * Extracts rule name and parameters from rule string.
     * 
     * @param string $rule Rule definition (e.g., "min:5")
     * @return array{0: string, 1: array} [rule name, parameters]
     */
    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $paramStr] = explode(':', $rule, 2);
            return [$name, explode(',', $paramStr)];
        }
        return [$rule, []];
    }

    /**
     * Validate required field
     * 
     * Ensures value is not empty.
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     */
    private function validateRequired(ReflectionProperty $property, mixed $value): void
    {
        if (empty($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} is required.";
        }
    }

    /**
     * Validate string type
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     */
    private function validateString(ReflectionProperty $property, mixed $value): void
    {
        if (!is_string($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a string.";
        }
    }

    /**
     * Validate integer type
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     */
    private function validateInt(ReflectionProperty $property, mixed $value): void
    {
        if (!is_int($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be an integer.";
        }
    }

    /**
     * Validate minimum value
     * 
     * Ensures value is at least the specified minimum.
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     * @param int $min Minimum value
     */
    private function validateMin(ReflectionProperty $property, mixed $value, int $min): void
    {
        if (is_string($value) && strlen($value) < $min) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at least $min characters.";
        } elseif (is_int($value) && $value < $min) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at least $min.";
        }
    }

    /**
     * Validate maximum value
     * 
     * Ensures value is at most the specified maximum.
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     * @param int $max Maximum value
     */
    private function validateMax(ReflectionProperty $property, mixed $value, int $max): void
    {
        if (is_string($value) && strlen($value) > $max) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at most $max characters.";
        } elseif (is_int($value) && $value > $max) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at most $max.";
        }
    }

    /**
     * Validate value is between minimum and maximum
     * 
     * Ensures value is within the specified range.
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     * @param int $min Minimum value
     * @param int $max Maximum value
     */
    private function validateBetween(ReflectionProperty $property, mixed $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be between $min and $max.";
        }
    }

    /**
     * Validate email format
     * 
     * Ensures value is a valid email address.
     * 
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     */
    private function validateEmail(ReflectionProperty $property, mixed $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a valid email.";
        }
    }

    /**
     * Get validation errors
     * 
     * Returns all validation error messages.
     * 
     * @return array<string, string[]> Property errors by field name
     */
    public function errors(): array
    {
        return $this->errors;
    }
}