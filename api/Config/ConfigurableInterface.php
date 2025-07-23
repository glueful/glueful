<?php

declare(strict_types=1);

namespace Glueful\Config;

use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Configurable Interface
 *
 * Defines the contract for classes that use Symfony OptionsResolver
 * for configuration validation and normalization.
 *
 * @package Glueful\Config
 */
interface ConfigurableInterface
{
    /**
     * Configure the options resolver
     *
     * This method should define:
     * - Default values for options
     * - Required options
     * - Allowed types and values
     * - Option normalizers
     *
     * @param OptionsResolver $resolver The options resolver instance
     */
    public function configureOptions(OptionsResolver $resolver): void;
}
