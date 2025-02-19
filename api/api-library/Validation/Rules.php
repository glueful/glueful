<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Validation;

class Rules
{
    private static array $customMessages = [];
    private static array $customRules = [];

    // Built-in rules
    public static function required(mixed $value): bool
    {
        return !empty($value);
    }

    public static function string(mixed $value): bool
    {
        return is_string($value);
    }

    public static function int(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public static function min(mixed $value, int $min): bool
    {
        return is_string($value) ? mb_strlen($value) >= $min : $value >= $min;
    }

    public static function max(mixed $value, int $max): bool
    {
        return is_string($value) ? mb_strlen($value) <= $max : $value <= $max;
    }

    public static function between(mixed $value, int $min, int $max): bool
    {
        return self::min($value, $min) && self::max($value, $max);
    }

    public static function email(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    // Custom rule registration
    public static function extend(string $ruleName, callable $callback): void
    {
        self::$customRules[$ruleName] = $callback;
    }

    // Custom message registration
    public static function setMessage(string $ruleName, string $message): void
    {
        self::$customMessages[$ruleName] = $message;
    }

    public static function getErrorMessage(string $rule, string $field, array $params = []): string
    {
        $message = self::$customMessages[$rule] ?? match ($rule) {
            'required' => "$field is required.",
            'string' => "$field must be a string.",
            'int' => "$field must be an integer.",
            'min' => "$field must be at least {$params[0]}.",
            'max' => "$field must be at most {$params[0]}.",
            'between' => "$field must be between {$params[0]} and {$params[1]}.",
            'email' => "$field must be a valid email address.",
            default => "$field is invalid.",
        };
        return str_replace(
            [':field', ':params'],
            [$field, implode(', ', $params)],
            $message
        );
    }

    public static function __callStatic(string $name, array $arguments)
    {
        if (isset(self::$customRules[$name])) {
            return call_user_func_array(self::$customRules[$name], $arguments);
        }
        throw new \BadMethodCallException("Rule {$name} not found");
    }
}