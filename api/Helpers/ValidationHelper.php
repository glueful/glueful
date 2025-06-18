<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Exceptions\ValidationException;

/**
 * Validation Helper
 *
 * Provides standardized validation methods across the application.
 * Ensures consistent validation error handling and messages.
 *
 * @package Glueful\Helpers
 */
class ValidationHelper
{
    /**
     * Validate required fields
     *
     * @param array $data Data to validate
     * @param array $required Array of required field names
     * @throws ValidationException If any required field is missing
     */
    public static function validateRequired(array $data, array $required): void
    {
        $missing = [];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || is_null($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new ValidationException([
                'required_fields' => "The following fields are required: " . implode(', ', $missing),
                'missing_fields' => $missing
            ]);
        }
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @param string $fieldName Field name for error message
     * @throws ValidationException If email format is invalid
     */
    public static function validateEmail(string $email, string $fieldName = 'email'): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException([
                $fieldName => "Invalid email format: {$email}"
            ]);
        }
    }

    /**
     * Validate UUID format
     *
     * @param string $uuid UUID to validate
     * @param string $fieldName Field name for error message
     * @throws ValidationException If UUID format is invalid
     */
    public static function validateUuid(string $uuid, string $fieldName = 'uuid'): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        if (!preg_match($pattern, $uuid)) {
            throw new ValidationException([
                $fieldName => "Invalid UUID format: {$uuid}"
            ]);
        }
    }

    /**
     * Validate string length
     *
     * @param string $value Value to validate
     * @param int $minLength Minimum length
     * @param int $maxLength Maximum length
     * @param string $fieldName Field name for error message
     * @throws ValidationException If length is invalid
     */
    public static function validateLength(string $value, int $minLength, int $maxLength, string $fieldName): void
    {
        $length = strlen($value);

        if ($length < $minLength) {
            throw new ValidationException([
                $fieldName => "{$fieldName} must be at least {$minLength} characters long"
            ]);
        }

        if ($length > $maxLength) {
            throw new ValidationException([
                $fieldName => "{$fieldName} must not exceed {$maxLength} characters"
            ]);
        }
    }

    /**
     * Validate array contains only allowed values
     *
     * @param array $values Values to validate
     * @param array $allowed Allowed values
     * @param string $fieldName Field name for error message
     * @throws ValidationException If any value is not allowed
     */
    public static function validateAllowedValues(array $values, array $allowed, string $fieldName): void
    {
        $invalid = array_diff($values, $allowed);

        if (!empty($invalid)) {
            throw new ValidationException([
                $fieldName => "Invalid values: " . implode(', ', $invalid) . ". Allowed: " . implode(', ', $allowed)
            ]);
        }
    }

    /**
     * Validate positive integer
     *
     * @param mixed $value Value to validate
     * @param string $fieldName Field name for error message
     * @throws ValidationException If value is not a positive integer
     */
    public static function validatePositiveInteger($value, string $fieldName): void
    {
        if (!is_int($value) && !ctype_digit((string)$value)) {
            throw new ValidationException([
                $fieldName => "{$fieldName} must be a positive integer"
            ]);
        }

        if ((int)$value <= 0) {
            throw new ValidationException([
                $fieldName => "{$fieldName} must be greater than 0"
            ]);
        }
    }

    /**
     * Validate date format
     *
     * @param string $date Date string to validate
     * @param string $format Expected format (default: Y-m-d H:i:s)
     * @param string $fieldName Field name for error message
     * @throws ValidationException If date format is invalid
     */
    public static function validateDateFormat(
        string $date,
        string $format = 'Y-m-d H:i:s',
        string $fieldName = 'date'
    ): void {
        $dateTime = \DateTime::createFromFormat($format, $date);

        if (!$dateTime || $dateTime->format($format) !== $date) {
            throw new ValidationException([
                $fieldName => "Invalid date format. Expected: {$format}"
            ]);
        }
    }

    /**
     * Validate JSON string
     *
     * @param string $json JSON string to validate
     * @param string $fieldName Field name for error message
     * @throws ValidationException If JSON is invalid
     */
    public static function validateJson(string $json, string $fieldName = 'json'): void
    {
        json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException([
                $fieldName => "Invalid JSON: " . json_last_error_msg()
            ]);
        }
    }

    /**
     * Sanitize input string
     *
     * @param string $input Input to sanitize
     * @param bool $stripTags Whether to strip HTML tags
     * @return string Sanitized string
     */
    public static function sanitizeString(string $input, bool $stripTags = true): string
    {
        $sanitized = trim($input);

        if ($stripTags) {
            $sanitized = strip_tags($sanitized);
        }

        return $sanitized;
    }

    /**
     * Validate and sanitize multiple fields
     *
     * @param array $data Data to validate
     * @param array $rules Validation rules
     * @return array Sanitized data
     * @throws ValidationException If validation fails
     */
    public static function validateAndSanitize(array $data, array $rules): array
    {
        $sanitized = [];
        $errors = [];

        foreach ($rules as $field => $rule) {
            try {
                $value = $data[$field] ?? null;

                // Apply validation rules
                if (isset($rule['required']) && $rule['required'] && ($value === null || $value === '')) {
                    $errors[$field] = "{$field} is required";
                    continue;
                }

                if ($value !== null && $value !== '') {
                    // Apply type-specific validations
                    if (isset($rule['type'])) {
                        switch ($rule['type']) {
                            case 'email':
                                self::validateEmail($value, $field);
                                break;
                            case 'uuid':
                                self::validateUuid($value, $field);
                                break;
                            case 'positive_integer':
                                self::validatePositiveInteger($value, $field);
                                break;
                            case 'json':
                                self::validateJson($value, $field);
                                break;
                        }
                    }

                    // Apply length validation
                    if (isset($rule['min_length']) || isset($rule['max_length'])) {
                        $min = $rule['min_length'] ?? 0;
                        $max = $rule['max_length'] ?? PHP_INT_MAX;
                        self::validateLength($value, $min, $max, $field);
                    }

                    // Apply pattern validation
                    if (isset($rule['pattern']) && is_string($value)) {
                        if (!preg_match($rule['pattern'], $value)) {
                            $errors[$field] = "{$field} format is invalid";
                            continue;
                        }
                    }

                    // Apply allowed values validation
                    if (isset($rule['allowed'])) {
                        if (is_array($value)) {
                            self::validateAllowedValues($value, $rule['allowed'], $field);
                        } elseif (!in_array($value, $rule['allowed'])) {
                            $errors[$field] = "Invalid value for {$field}. Allowed: " . implode(', ', $rule['allowed']);
                            continue;
                        }
                    }

                    // Sanitize the value
                    if (is_string($value)) {
                        $sanitized[$field] = self::sanitizeString($value, $rule['strip_tags'] ?? true);
                    } else {
                        $sanitized[$field] = $value;
                    }
                } elseif (!isset($rule['required']) || !$rule['required']) {
                    $sanitized[$field] = $value;
                }
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->getErrors());
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $sanitized;
    }
}
