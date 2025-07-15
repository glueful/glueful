<?php

declare(strict_types=1);

namespace Glueful\Configuration\Schema;

use Symfony\Component\Config\Definition\ConfigurationInterface as SymfonyConfigurationInterface;

/**
 * Configuration Interface
 *
 * Extends Symfony's ConfigurationInterface to add Glueful-specific metadata
 * for schema discovery, versioning, and documentation.
 *
 * @package Glueful\Configuration\Schema
 */
interface ConfigurationInterface extends SymfonyConfigurationInterface
{
    /**
     * Get the configuration name (e.g., 'database', 'app', 'cache')
     */
    public function getConfigurationName(): string;

    /**
     * Get a human-readable description of what this configuration manages
     */
    public function getDescription(): string;

    /**
     * Get the schema version for compatibility and migration tracking
     */
    public function getVersion(): string;
}
