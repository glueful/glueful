<?php

declare(strict_types=1);

namespace Glueful\Config;

use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configurable Trait
 *
 * Provides common functionality for classes that implement ConfigurableInterface.
 * Handles option resolution using Symfony OptionsResolver.
 *
 * @package Glueful\Config
 */
trait ConfigurableTrait
{
    /**
     * Resolved configuration options
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Resolve options using the OptionsResolver
     *
     * @param array<string, mixed> $options Raw options array
     * @return array<string, mixed> Resolved and validated options
     */
    protected function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);

        return $this->options = $resolver->resolve($options);
    }

    /**
     * Get a specific option value
     *
     * @param string $name Option name
     * @param mixed $default Default value if option doesn't exist
     * @return mixed Option value or default
     */
    protected function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Get all resolved options
     *
     * @return array<string, mixed> All resolved options
     */
    protected function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Check if an option exists
     *
     * @param string $name Option name
     * @return bool True if option exists
     */
    protected function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * Configure the options resolver
     *
     * This method must be implemented by classes using this trait.
     *
     * @param OptionsResolver $resolver The options resolver instance
     */
    abstract public function configureOptions(OptionsResolver $resolver): void;
}
