<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Validation;

use ReflectionClass;
use ReflectionProperty;
use Glueful\Api\Library\Validation\Attributes\{Rules, Sanitize};

class Validator
{
    private array $errors = [];

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

    private function applyRules(ReflectionProperty $property, mixed $value): void
    {
        foreach ($property->getAttributes(Rules::class) as $attribute) {
            $rules = $attribute->getArguments()[0] ?? [];

            foreach ($rules as $rule) {
                $this->applyRule($property, $value, $rule);
            }
        }
    }

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

    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $paramStr] = explode(':', $rule, 2);
            return [$name, explode(',', $paramStr)];
        }
        return [$rule, []];
    }

    private function validateRequired(ReflectionProperty $property, mixed $value): void
    {
        if (empty($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} is required.";
        }
    }

    private function validateString(ReflectionProperty $property, mixed $value): void
    {
        if (!is_string($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a string.";
        }
    }

    private function validateInt(ReflectionProperty $property, mixed $value): void
    {
        if (!is_int($value)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be an integer.";
        }
    }

    private function validateMin(ReflectionProperty $property, mixed $value, int $min): void
    {
        if (is_string($value) && strlen($value) < $min) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at least $min characters.";
        } elseif (is_int($value) && $value < $min) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at least $min.";
        }
    }

    private function validateMax(ReflectionProperty $property, mixed $value, int $max): void
    {
        if (is_string($value) && strlen($value) > $max) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at most $max characters.";
        } elseif (is_int($value) && $value > $max) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be at most $max.";
        }
    }

    private function validateBetween(ReflectionProperty $property, mixed $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be between $min and $max.";
        }
    }

    private function validateEmail(ReflectionProperty $property, mixed $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$property->getName()][] = "{$property->getName()} must be a valid email.";
        }
    }

    public function errors(): array
    {
        return $this->errors;
    }
}