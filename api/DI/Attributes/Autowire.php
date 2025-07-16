<?php

declare(strict_types=1);

namespace Glueful\DI\Attributes;

/**
 * Autowire Attribute
 *
 * PHP 8+ attribute for marking parameters for automatic dependency injection.
 * Used in constructor parameters to specify how dependencies should be resolved.
 *
 * Usage:
 * public function __construct(
 *     #[Autowire(service: 'database')] DatabaseInterface $db,
 *     #[Autowire(parameter: 'app.name')] string $appName
 * ) { ... }
 *
 * @package Glueful\DI\Attributes
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Autowire
{
    /**
     * Autowire Attribute Constructor
     *
     * @param string|null $service Service ID to inject
     * @param string|null $parameter Parameter name to inject
     * @param mixed $value Literal value to inject
     * @param bool $optional Whether injection is optional (default false)
     */
    public function __construct(
        public readonly ?string $service = null,
        public readonly ?string $parameter = null,
        public readonly mixed $value = null,
        public readonly bool $optional = false
    ) {
        // Ensure only one injection type is specified
        $specified = array_filter([
            $this->service !== null,
            $this->parameter !== null,
            $this->value !== null
        ]);

        if (count($specified) > 1) {
            throw new \InvalidArgumentException(
                'Only one of service, parameter, or value can be specified'
            );
        }
    }

    /**
     * Get service ID
     *
     * @return string|null Service identifier
     */
    public function getService(): ?string
    {
        return $this->service;
    }

    /**
     * Get parameter name
     *
     * @return string|null Parameter name
     */
    public function getParameter(): ?string
    {
        return $this->parameter;
    }

    /**
     * Get literal value
     *
     * @return mixed Literal value
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Check if injection is optional
     *
     * @return bool True if optional
     */
    public function isOptional(): bool
    {
        return $this->optional;
    }

    /**
     * Get injection type
     *
     * @return string Type of injection (service, parameter, value, or auto)
     */
    public function getInjectionType(): string
    {
        if ($this->service !== null) {
            return 'service';
        }
        if ($this->parameter !== null) {
            return 'parameter';
        }
        if ($this->value !== null) {
            return 'value';
        }
        return 'auto';
    }
}
