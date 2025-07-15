<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Abstract Constraint Base Class
 *
 * Base class for all Glueful validation constraints, including extension constraints.
 * Provides common functionality and standardizes the constraint interface.
 *
 * Extension developers should extend this class when creating custom constraints:
 *
 * ```php
 * namespace MyExtension\Validation\Constraints;
 * use Glueful\Validation\Constraints\AbstractConstraint;
 *
 * #[\Attribute(\Attribute::TARGET_PROPERTY)]
 * class ValidSKU extends AbstractConstraint
 * {
 *     public string $message = 'Invalid SKU format for {{ field }}.';
 *     public string $pattern = '/^[A-Z]{2}-\d{4}$/';
 * }
 * ```
 */
abstract class AbstractConstraint extends Constraint
{
    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /** @var mixed Additional payload data */
    public mixed $payload = null;

    /**
     * Constructor
     *
     * @param array<string> $groups Validation groups
     * @param mixed $payload Additional payload data
     * @param array $options Additional constraint options
     */
    public function __construct(
        array $groups = [],
        mixed $payload = null,
        array $options = []
    ) {
        $this->groups = !empty($groups) ? $groups : null;
        $this->payload = $payload;

        parent::__construct($options);
    }

    /**
     * Get the validator class name
     *
     * By default, assumes the validator follows the naming convention:
     * - Constraint: MyConstraint
     * - Validator: MyConstraintValidator
     *
     * Override this method if your validator follows a different naming pattern.
     *
     * @return string Validator class name
     */
    public function validatedBy(): string
    {
        $constraintClass = static::class;

        // Check if this is an extension constraint
        if (str_contains($constraintClass, '\\Extensions\\')) {
            // For extension constraints, keep the full namespace path
            return $constraintClass . 'Validator';
        }

        // For core constraints, use the standard namespace
        $className = substr($constraintClass, strrpos($constraintClass, '\\') + 1);
        return 'Glueful\\Validation\\ConstraintValidators\\' . $className . 'Validator';
    }

    /**
     * Get constraint metadata for documentation and tooling
     *
     * @return array Constraint metadata
     */
    public function getMetadata(): array
    {
        return [
            'name' => substr(static::class, strrpos(static::class, '\\') + 1),
            'namespace' => static::class,
            'validator' => $this->validatedBy(),
            'groups' => $this->groups,
            'properties' => $this->getPublicProperties(),
        ];
    }

    /**
     * Get all public properties of the constraint
     *
     * @return array<string, mixed> Public properties
     */
    protected function getPublicProperties(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $properties[$name] = $this->$name;
        }

        return $properties;
    }

    /**
     * Check if this constraint belongs to an extension
     *
     * @return bool True if this is an extension constraint
     */
    public function isExtensionConstraint(): bool
    {
        return str_contains(static::class, '\\Extensions\\');
    }

    /**
     * Get the extension name if this is an extension constraint
     *
     * @return string|null Extension name or null if not an extension constraint
     */
    public function getExtensionName(): ?string
    {
        if (!$this->isExtensionConstraint()) {
            return null;
        }

        $parts = explode('\\', static::class);
        $extensionIndex = array_search('Extensions', $parts);

        return $extensionIndex !== false && isset($parts[$extensionIndex + 1])
            ? $parts[$extensionIndex + 1]
            : null;
    }

    /**
     * Validate constraint configuration
     *
     * Override this method to add custom validation logic for constraint setup.
     * This is called during constraint registration to ensure proper configuration.
     *
     * @throws \InvalidArgumentException If constraint configuration is invalid
     */
    public function validateConfiguration(): void
    {
        // Default implementation - override in subclasses for custom validation
    }

    /**
     * Get default error message template
     *
     * @return string Default error message
     */
    public function getDefaultMessage(): string
    {
        return property_exists($this, 'message') && is_string($this->message)
            ? $this->message
            : 'The value is not valid.';
    }

    /**
     * Get constraint type for categorization
     *
     * @return string Constraint type (e.g., 'string', 'numeric', 'database', 'custom')
     */
    public function getType(): string
    {
        return $this->isExtensionConstraint() ? 'extension' : 'core';
    }

    /**
     * Get constraint severity level
     *
     * @return string Severity level ('error', 'warning', 'info')
     */
    public function getSeverity(): string
    {
        return 'error';
    }

    /**
     * Check if constraint supports multiple values
     *
     * @return bool True if constraint can validate arrays/collections
     */
    public function supportsMultipleValues(): bool
    {
        return false;
    }

    /**
     * Get constraint documentation URL
     *
     * @return string|null Documentation URL or null if not available
     */
    public function getDocumentationUrl(): ?string
    {
        return null;
    }
}
