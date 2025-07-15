<?php

declare(strict_types=1);

namespace Glueful\Configuration;

use Symfony\Component\Config\Definition\Processor;
use Glueful\Configuration\Exceptions\ConfigurationException;

/**
 * Schema Manager
 *
 * Manages configuration schemas and provides schema discovery capabilities.
 * This is a basic implementation for Phase 1.
 *
 * @package Glueful\Configuration
 */
class SchemaManager
{
    private array $schemas = [];

    public function __construct(private Processor $processor)
    {
    }

    /**
     * Register a configuration schema
     */
    public function registerSchema(string $name, object $schema): void
    {
        $this->schemas[$name] = $schema;
    }

    /**
     * Get a registered schema
     */
    public function getSchema(string $name): ?object
    {
        return $this->schemas[$name] ?? null;
    }

    /**
     * Get all registered schemas
     */
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Check if a schema is registered
     */
    public function hasSchema(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    /**
     * Remove a schema
     */
    public function removeSchema(string $name): void
    {
        unset($this->schemas[$name]);
    }

    /**
     * Get schema names
     */
    public function getSchemaNames(): array
    {
        return array_keys($this->schemas);
    }
}
