<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Glueful\Validation\SanitizationProcessor;
use Glueful\Validation\LazyValidationProvider;

/**
 * Glueful Validation Facade
 *
 * Provides a clean, framework-specific interface for validation using
 * Symfony Validator underneath. Abstracts away direct Symfony dependencies
 * while providing enhanced error handling and developer experience.
 *
 * Example usage:
 *
 * ```php
 * use Glueful\Validation\Validator;
 * use Glueful\Validation\Constraints\{Required, StringLength, Email};
 *
 * class UserDTO {
 *     #[Required]
 *     #[StringLength(min: 3, max: 50)]
 *     public string $name;
 *
 *     #[Required]
 *     #[Email]
 *     public string $email;
 * }
 *
 * $user = new UserDTO();
 * $user->name = 'Jo';
 * $user->email = 'invalid-email';
 *
 * $validator = container()->get(Validator::class);
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
     * Constructor
     *
     * @param ValidatorInterface $symfonyValidator Symfony Validator instance
     * @param SanitizationProcessor $sanitizationProcessor Sanitization processor instance
     * @param LazyValidationProvider|null $lazyProvider Optional lazy validation provider
     */
    public function __construct(
        private ValidatorInterface $symfonyValidator,
        private SanitizationProcessor $sanitizationProcessor,
        private ?LazyValidationProvider $lazyProvider = null
    ) {
    }

    /**
     * Validate DTO object
     *
     * Sanitizes the given object using Sanitize attributes, then validates it using
     * Symfony Validator with optional validation groups. Processes all constraint
     * violations and stores them in a user-friendly format.
     *
     * @param object $dto Data Transfer Object to validate
     * @param array|null $groups Validation groups to apply (null for default group)
     * @return bool True if validation passes, false otherwise
     */
    public function validate(object $dto, ?array $groups = null): bool
    {
        $this->reset();

        // Apply sanitization first
        $dto = $this->sanitizationProcessor->sanitize($dto);

        $violations = $this->symfonyValidator->validate($dto, null, $groups);
        $this->processViolations($violations);

        return count($violations) === 0;
    }

    /**
     * Process constraint violations
     *
     * Converts Symfony constraint violations into user-friendly error messages
     * grouped by property name.
     *
     * @param ConstraintViolationListInterface $violations Symfony constraint violations
     */
    private function processViolations(ConstraintViolationListInterface $violations): void
    {
        foreach ($violations as $violation) {
            $property = $violation->getPropertyPath();
            $this->errors[$property][] = $violation->getMessage();
        }
    }

    /**
     * Get validation errors
     *
     * Returns all validation error messages grouped by property name.
     *
     * @return array<string, string[]> Property errors by field name
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error for a property
     *
     * Returns the first validation error for a specific property.
     *
     * @param string $property Property name
     * @return string|null First error message or null if no errors
     */
    public function firstError(string $property): ?string
    {
        return $this->errors[$property][0] ?? null;
    }

    /**
     * Check if validation has errors
     *
     * @return bool True if there are validation errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if a specific property has errors
     *
     * @param string $property Property name
     * @return bool True if the property has validation errors
     */
    public function hasError(string $property): bool
    {
        return isset($this->errors[$property]) && !empty($this->errors[$property]);
    }

    /**
     * Get all error messages as a flat array
     *
     * Returns all validation error messages in a single flat array.
     *
     * @return string[] All error messages
     */
    public function allErrors(): array
    {
        $allErrors = [];
        foreach ($this->errors as $propertyErrors) {
            $allErrors = array_merge($allErrors, $propertyErrors);
        }
        return $allErrors;
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

    /**
     * Get the sanitization processor instance
     *
     * Provides access to the sanitization processor for adding custom filters
     * or direct sanitization operations.
     *
     * @return SanitizationProcessor The sanitization processor instance
     */
    public function getSanitizationProcessor(): SanitizationProcessor
    {
        return $this->sanitizationProcessor;
    }

    /**
     * Sanitize a DTO object without validation
     *
     * Applies sanitization filters to the given object without running validation.
     * Useful for preprocessing data before manual validation or other operations.
     *
     * @param object $dto Data Transfer Object to sanitize
     * @return object The sanitized DTO object
     */
    public function sanitize(object $dto): object
    {
        return $this->sanitizationProcessor->sanitize($dto);
    }

    /**
     * Get performance statistics
     *
     * Returns performance metrics from the lazy validation provider
     * for monitoring and optimization purposes.
     *
     * @return array Performance statistics
     */
    public function getPerformanceStatistics(): array
    {
        if (!$this->lazyProvider) {
            return [
                'lazy_provider_enabled' => false,
                'message' => 'LazyValidationProvider not available',
            ];
        }

        return [
            'lazy_provider_enabled' => true,
            'lazy_provider_stats' => $this->lazyProvider->getStatistics(),
            'cache_memory_usage' => $this->lazyProvider->getCacheMemoryUsage(),
        ];
    }

    /**
     * Warm up validation cache
     *
     * Preloads validation constraints for frequently used DTOs
     * to improve performance in production environments.
     *
     * @param array<string> $dtoClasses Array of DTO class names to warm up
     * @return array Warm-up results
     */
    public function warmupValidationCache(array $dtoClasses): array
    {
        if (!$this->lazyProvider) {
            return [
                'success' => false,
                'message' => 'LazyValidationProvider not available',
            ];
        }

        return [
            'success' => true,
            'results' => $this->lazyProvider->preloadConstraints($dtoClasses),
        ];
    }

    /**
     * Clear validation cache
     *
     * Clears cached validation constraints to free memory
     * or force recompilation of constraints.
     *
     * @param string|null $className Optional class name to clear specific cache
     * @return bool True if cache was cleared
     */
    public function clearValidationCache(?string $className = null): bool
    {
        if (!$this->lazyProvider) {
            return false;
        }

        return $this->lazyProvider->clearCache($className);
    }
}
