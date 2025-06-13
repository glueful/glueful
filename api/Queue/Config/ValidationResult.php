<?php

namespace Glueful\Queue\Config;

/**
 * Configuration Validation Result
 *
 * Contains the results of configuration validation including
 * validation status, errors, warnings, and recommendations.
 *
 * @package Glueful\Queue\Config
 */
class ValidationResult
{
    /** @var bool Whether configuration is valid */
    public readonly bool $isValid;

    /** @var array Validation errors */
    public readonly array $errors;

    /** @var array Validation warnings */
    public readonly array $warnings;

    /** @var array Configuration recommendations */
    public readonly array $recommendations;

    /**
     * Create validation result
     *
     * @param bool $isValid Whether configuration is valid
     * @param array $errors Validation errors
     * @param array $warnings Validation warnings
     * @param array $recommendations Configuration recommendations
     */
    public function __construct(
        bool $isValid,
        array $errors = [],
        array $warnings = [],
        array $recommendations = []
    ) {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->recommendations = $recommendations;
    }

    /**
     * Check if validation passed
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Check if there are any errors
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if there are any warnings
     *
     * @return bool True if warnings exist
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Check if there are any recommendations
     *
     * @return bool True if recommendations exist
     */
    public function hasRecommendations(): bool
    {
        return !empty($this->recommendations);
    }

    /**
     * Get all errors
     *
     * @return array Validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warnings
     *
     * @return array Validation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get all recommendations
     *
     * @return array Configuration recommendations
     */
    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    /**
     * Get first error message
     *
     * @return string|null First error or null
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Get formatted error summary
     *
     * @return string Error summary
     */
    public function getErrorSummary(): string
    {
        if (empty($this->errors)) {
            return 'No errors found.';
        }

        $count = count($this->errors);
        $plural = $count === 1 ? 'error' : 'errors';

        return "Found {$count} {$plural}:\n" . implode("\n", array_map(
            fn($error, $index) => "  " . ($index + 1) . ". {$error}",
            $this->errors,
            array_keys($this->errors)
        ));
    }

    /**
     * Get formatted warning summary
     *
     * @return string Warning summary
     */
    public function getWarningSummary(): string
    {
        if (empty($this->warnings)) {
            return 'No warnings found.';
        }

        $count = count($this->warnings);
        $plural = $count === 1 ? 'warning' : 'warnings';

        return "Found {$count} {$plural}:\n" . implode("\n", array_map(
            fn($warning, $index) => "  " . ($index + 1) . ". {$warning}",
            $this->warnings,
            array_keys($this->warnings)
        ));
    }

    /**
     * Get formatted recommendation summary
     *
     * @return string Recommendation summary
     */
    public function getRecommendationSummary(): string
    {
        if (empty($this->recommendations)) {
            return 'No recommendations available.';
        }

        $count = count($this->recommendations);
        $plural = $count === 1 ? 'recommendation' : 'recommendations';

        return "Found {$count} {$plural}:\n" . implode("\n", array_map(
            fn($rec, $index) => "  " . ($index + 1) . ". {$rec}",
            $this->recommendations,
            array_keys($this->recommendations)
        ));
    }

    /**
     * Get complete validation summary
     *
     * @return string Complete summary
     */
    public function getSummary(): string
    {
        $parts = [];

        if ($this->isValid) {
            $parts[] = "✅ Configuration is valid!";
        } else {
            $parts[] = "❌ Configuration validation failed!";
        }

        if ($this->hasErrors()) {
            $parts[] = "\n" . $this->getErrorSummary();
        }

        if ($this->hasWarnings()) {
            $parts[] = "\n" . $this->getWarningSummary();
        }

        if ($this->hasRecommendations()) {
            $parts[] = "\n" . $this->getRecommendationSummary();
        }

        return implode("\n", $parts);
    }

    /**
     * Convert to array format
     *
     * @return array Validation result as array
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'recommendations' => $this->recommendations,
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Convert to JSON format
     *
     * @param int $flags JSON encode flags
     * @return string JSON representation
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags);
    }

    /**
     * Create a successful validation result
     *
     * @param array $warnings Optional warnings
     * @param array $recommendations Optional recommendations
     * @return self Validation result
     */
    public static function success(array $warnings = [], array $recommendations = []): self
    {
        return new self(true, [], $warnings, $recommendations);
    }

    /**
     * Create a failed validation result
     *
     * @param array $errors Validation errors
     * @param array $warnings Optional warnings
     * @param array $recommendations Optional recommendations
     * @return self Validation result
     */
    public static function failure(array $errors, array $warnings = [], array $recommendations = []): self
    {
        return new self(false, $errors, $warnings, $recommendations);
    }
}