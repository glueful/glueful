<?php

declare(strict_types=1);

namespace Glueful\Config;

use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configurable Service Base Class
 *
 * Abstract base class for services that need configuration validation
 * using Symfony OptionsResolver. Provides common patterns and methods
 * for option handling.
 *
 * @package Glueful\Config
 */
abstract class ConfigurableService implements ConfigurableInterface
{
    use ConfigurableTrait;

    /**
     * Create a new configurable service instance
     *
     * @param array<string, mixed> $options Configuration options
     */
    public function __construct(array $options = [])
    {
        $this->resolveOptions($options);
    }

    /**
     * Update configuration options
     *
     * Merges new options with existing ones and re-resolves.
     *
     * @param array<string, mixed> $options New options to merge
     * @return static Current instance for method chaining
     */
    public function withOptions(array $options): static
    {
        $this->resolveOptions(array_merge($this->options, $options));
        return $this;
    }

    /**
     * Get configuration as array
     *
     * Useful for debugging or passing configuration to other services.
     *
     * @return array<string, mixed> Current configuration
     */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * Get configuration as JSON string
     *
     * @param int $flags JSON encoding flags
     * @return string JSON representation of configuration
     * @throws \JsonException If JSON encoding fails
     */
    public function toJson(int $flags = JSON_THROW_ON_ERROR): string
    {
        return json_encode($this->options, $flags);
    }

    /**
     * Configure the options resolver
     *
     * This method must be implemented by concrete classes to define
     * their specific configuration requirements.
     *
     * @param OptionsResolver $resolver The options resolver instance
     */
    abstract public function configureOptions(OptionsResolver $resolver): void;
}
