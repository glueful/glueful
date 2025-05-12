<?php

declare(strict_types=1);

namespace Glueful\Validation;

use ReflectionClass;
use ReflectionProperty;
use Glueful\Validation\Attributes\{Rules, Sanitize};

/**
 * Data Transfer Object Validator
 *
 * Provides attribute-based validation and sanitization for DTOs.
 * Supports multiple validation rules and sanitization filters.
 *
 * Example usage:
 *
 * ```php
 * use Glueful\Validation\Validator;
 *
 * class UserDTO {
 *     #[Sanitize(['trim', 'strip_tags'])]
 *     #[Rules(['required', 'string', 'min:3', 'max:50'])]
 *     public string $name;
 *
 *     #[Sanitize(['intval'])]
 *     #[Rules(['required', 'int', 'min:18', 'max:99'])]
 *     public int $age;
 *
 *     #[Sanitize(['trim', 'sanitize_email'])]
 *     #[Rules(['required', 'email'])]
 *     public string $email;
 * }
 *
 * $user = new UserDTO();
 * $user->name = ' John Doe ';
 * $user->age = '25';
 * $user->email = 'john.doe@example.com';
 *
 * $validator = new Validator();
 * if ($validator->validate($user)) {
 *     echo "Validation passed!";
 * } else {
 *     print_r($validator->errors());
 * }
 * ```
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
        // Reset errors before validation
        $this->reset();

        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isInitialized($dto)) {
                $this->errors[$property->getName()][] = "{$property->getName()} is not initialized.";
                continue;
            }
            $value = $property->getValue($dto);
            // Apply Sanitization First
            $value = $this->sanitize($property, $value);
            // Update the property value in the DTO after sanitization
            $property->setValue($dto, $value);
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
                    'sanitize_string' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
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

        // Check for custom rule
        if (isset($this->customRules[$ruleName])) {
            $this->applyCustomRule($property, $value, $ruleName, $params);
            return;
        }

        // Apply built-in rules
        match ($ruleName) {
            'required' => $this->validateRequired($property, $value),
            'string' => $this->validateString($property, $value),
            'int' => $this->validateInt($property, $value),
            'min' => $this->validateMin($property, $value, (int)$params[0]),
            'max' => $this->validateMax($property, $value, (int)$params[0]),
            'between' => $this->validateBetween($property, $value, (int)$params[0], (int)$params[1]),
            'email' => $this->validateEmail($property, $value),
            'in' => $this->validateIn($property, $value, $params),
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
        // Special handling for values that might be falsely evaluated as empty but are valid
        if ($value === null || $value === '') {
            $this->errors[$property->getName()][] = "{$property->getName()} is required.";
        }
        // Numeric zero and boolean false are considered valid values
    }

    /**
     * Validate string type
     *
     * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
     */
    private function validateString(ReflectionProperty $property, mixed $value): void
    {
        // Special handling for null values
        if ($value === null) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a string.";
            return;
        }

        // Convert to string for display in error messages
        $displayValue = is_array($value) ? 'array' : (is_object($value) ? get_class($value) : (string)$value);

        if (!is_string($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a string, got: " . gettype($value) . " ($displayValue)";
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
        // For numeric values, check if they're in the range
        if (is_numeric($value) && ($value < $min || $value > $max)) {
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
        // First check if it's a string
        if (!is_string($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a string for email validation.";
            return;
        }

        // More strict email validation
        // Regular expression to validate email format and reject special characters in local part
        $isValid = preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $value) &&
                  filter_var($value, FILTER_VALIDATE_EMAIL);

        if (!$isValid) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a valid email.";
        }
    }

     /**
    * Validate value is in a list of values
   *
    * Ensures value is one of the specified options.
     *
    * @param ReflectionProperty $property Property to check
     * @param mixed $value Value to validate
    * @param array $options Allowed values
    */
    private function validateIn(ReflectionProperty $property, mixed $value, array $options): void
    {
        if (!in_array($value, $options, true)) { // Using strict comparison
            $this->errors[$property->getName()][] = "{$property->getName()} must be one of: " . implode(', ', $options);
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

    /**
     * Reset validation errors
     *
     * Clears all validation error messages.
     *
     * @return self For method chaining
     */
    public function reset(): self
    {
        $this->errors = [];
        return $this;
    }

    /** @var array Custom validation rules */
    private array $customRules = [];

    /**
     * Add custom validation rule
     *
     * @param string $name Rule name
     * @param callable $callback Validation callback function(mixed $value): bool
     * @param string $message Error message template
     * @return self For method chaining
     */
    public function addRule(string $name, callable $callback, string $message): self
    {
        $this->customRules[$name] = [
            'callback' => $callback,
            'message' => $message
        ];

        return $this;
    }
    // Second applyRule method removed to avoid duplicate declaration

    /**
     * Apply custom validation rule
     *
     * @param ReflectionProperty $property Property being validated
     * @param mixed $value Value to validate
     * @param string $ruleName Name of the custom rule
     * @param array $params Parameters for the rule
     */
    private function applyCustomRule(ReflectionProperty $property, mixed $value, string $ruleName, array $params): void
    {
        if (!isset($this->customRules[$ruleName])) {
            $this->errors[$property->getName()][] = "Unknown custom rule: $ruleName";
            return;
        }

        $rule = $this->customRules[$ruleName];
        $callback = $rule['callback'];

        // Make sure the callback is callable
        if (!is_callable($callback)) {
            $this->errors[$property->getName()][] = "Invalid callback for rule: $ruleName";
            return;
        }

        // Execute the custom validation and force boolean result
        $result = (bool)$callback($value, ...$params);
        if (!$result) {
            // Replace :attribute placeholder with property name in the message
            $message = str_replace(':attribute', $property->getName(), $rule['message']);
            $this->errors[$property->getName()][] = $message;
        }
    }
}
