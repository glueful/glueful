<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Attributes\Sanitize;
use ReflectionClass;
use ReflectionProperty;

/**
 * Sanitization Processor
 *
 * Processes #[Sanitize] attributes on DTO properties and applies
 * the specified sanitization filters to clean input data.
 */
class SanitizationProcessor
{
    /** @var array<string, callable> Available sanitization filters */
    private array $filters = [];

    /**
     * Constructor - Initialize default filters
     */
    public function __construct()
    {
        $this->initializeDefaultFilters();
    }

    /**
     * Process sanitization for a DTO object
     *
     * @param object $dto The DTO object to sanitize
     * @return object The sanitized DTO object
     */
    public function sanitize(object $dto): object
    {
        $reflection = new ReflectionClass($dto);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $this->processProperty($dto, $property);
        }

        return $dto;
    }

    /**
     * Process a single property for sanitization
     *
     * @param object $dto The DTO object
     * @param ReflectionProperty $property The property to process
     */
    private function processProperty(object $dto, ReflectionProperty $property): void
    {
        $attributes = $property->getAttributes(Sanitize::class);

        if (empty($attributes)) {
            return;
        }

        // Get current property value
        $property->setAccessible(true);
        $value = $property->getValue($dto);

        // Apply all sanitization filters from all Sanitize attributes
        foreach ($attributes as $attribute) {
            $sanitizeInstance = $attribute->newInstance();
            $value = $this->applyFilters($value, $sanitizeInstance->filters);
        }

        // Set the sanitized value back to the property
        $property->setValue($dto, $value);
    }

    /**
     * Apply sanitization filters to a value
     *
     * @param mixed $value The value to sanitize
     * @param array $filterNames Array of filter names to apply
     * @return mixed The sanitized value
     */
    private function applyFilters(mixed $value, array $filterNames): mixed
    {
        foreach ($filterNames as $filterName) {
            if (isset($this->filters[$filterName])) {
                $value = $this->filters[$filterName]($value);
            }
        }

        return $value;
    }

    /**
     * Initialize default sanitization filters
     */
    private function initializeDefaultFilters(): void
    {
        $this->filters = [
            'trim' => fn($value) => is_string($value) ? trim($value) : $value,

            'strip_tags' => fn($value) => is_string($value) ? strip_tags($value) : $value,

            'intval' => fn($value) => is_numeric($value) ? (int) $value : $value,

            'sanitize_email' => fn($value) => is_string($value) ?
                filter_var(trim($value), FILTER_SANITIZE_EMAIL) : $value,

            'lowercase' => fn($value) => is_string($value) ?
                strtolower($value) : $value,

            'uppercase' => fn($value) => is_string($value) ?
                strtoupper($value) : $value,

            'sanitize_string' => fn($value) => is_string($value) ?
                filter_var(trim($value), FILTER_SANITIZE_STRING) : $value,

            'remove_whitespace' => fn($value) => is_string($value) ?
                preg_replace('/\s+/', '', $value) : $value,

            'normalize_whitespace' => fn($value) => is_string($value) ?
                preg_replace('/\s+/', ' ', trim($value)) : $value,

            'sanitize_url' => fn($value) => is_string($value) ?
                filter_var(trim($value), FILTER_SANITIZE_URL) : $value,

            'floatval' => fn($value) => is_numeric($value) ? (float) $value : $value,

            'boolval' => fn($value) => is_bool($value) ? $value :
                (in_array(strtolower((string) $value), ['true', '1', 'yes', 'on']) ? true : false),

            'remove_html' => fn($value) => is_string($value) ?
                preg_replace('/<[^>]*>/', '', $value) : $value,

            'alphanumeric' => fn($value) => is_string($value) ?
                preg_replace('/[^a-zA-Z0-9]/', '', $value) : $value,

            'alpha' => fn($value) => is_string($value) ?
                preg_replace('/[^a-zA-Z]/', '', $value) : $value,

            'numeric' => fn($value) => is_string($value) ?
                preg_replace('/[^0-9]/', '', $value) : $value,
        ];
    }

    /**
     * Register a custom sanitization filter
     *
     * @param string $name Filter name
     * @param callable $filter Filter function
     * @return self
     */
    public function addFilter(string $name, callable $filter): self
    {
        $this->filters[$name] = $filter;
        return $this;
    }

    /**
     * Get all available filter names
     *
     * @return array<string> Array of filter names
     */
    public function getAvailableFilters(): array
    {
        return array_keys($this->filters);
    }

    /**
     * Check if a filter exists
     *
     * @param string $name Filter name
     * @return bool True if filter exists
     */
    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    /**
     * Remove a filter
     *
     * @param string $name Filter name
     * @return self
     */
    public function removeFilter(string $name): self
    {
        unset($this->filters[$name]);
        return $this;
    }
}
