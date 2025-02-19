<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Validation;

class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        foreach ($rules as $field => $ruleSet) {
            $ruleList = explode('|', $ruleSet);
            foreach ($ruleList as $rule) {
                [$ruleName, $params] = $this->parseRule($rule);

                if (!Rules::$ruleName($data[$field] ?? null, ...$params)) {
                    $this->errors[$field][] = Rules::getErrorMessage($ruleName, $field, $params);
                }
            }
        }
        return empty($this->errors);
    }

    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$ruleName, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        } else {
            $ruleName = $rule;
            $params = [];
        }
        return [$ruleName, $params];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}