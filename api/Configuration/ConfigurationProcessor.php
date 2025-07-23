<?php

declare(strict_types=1);

namespace Glueful\Configuration;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Glueful\Configuration\Schema\ConfigurationInterface;
use Glueful\Configuration\Extension\ExtensionSchemaInterface;
use Glueful\Configuration\Exceptions\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * Configuration Processor
 *
 * Provides schema-aware configuration processing and validation using Symfony Config.
 * Handles registration of configuration schemas, processing of configuration data,
 * and validation with detailed error reporting.
 *
 * @package Glueful\Configuration
 */
class ConfigurationProcessor
{
    private array $schemas = [];
    private array $processedConfigs = [];

    public function __construct(
        private Processor $processor,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Register a configuration schema
     */
    public function registerSchema(ConfigurationInterface $schema): void
    {
        $this->schemas[$schema->getConfigurationName()] = $schema;

        $this->logger->debug('Configuration schema registered', [
            'config_name' => $schema->getConfigurationName(),
            'description' => $schema->getDescription(),
            'version' => $schema->getVersion()
        ]);
    }

    /**
     * Process configuration data against its registered schema
     */
    public function processConfiguration(string $configName, array $configs): array
    {
        if (!isset($this->schemas[$configName])) {
            throw new ConfigurationException("No schema registered for configuration: {$configName}");
        }

        try {
            $schema = $this->schemas[$configName];
            $processedConfig = $this->processor->processConfiguration($schema, [$configs]);

            $this->processedConfigs[$configName] = $processedConfig;

            $this->logger->debug('Configuration processed successfully', [
                'config_name' => $configName,
                'schema_version' => $schema->getVersion()
            ]);

            return $processedConfig;
        } catch (InvalidConfigurationException $e) {
            $this->logger->error('Configuration validation failed', [
                'config_name' => $configName,
                'error' => $e->getMessage()
            ]);

            throw new ConfigurationException(
                "Invalid configuration for {$configName}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Validate configuration without storing the processed result
     */
    public function validateConfiguration(string $configName, array $config): array
    {
        return $this->processConfiguration($configName, $config);
    }

    /**
     * Get previously processed configuration
     */
    public function getProcessedConfiguration(string $configName): ?array
    {
        return $this->processedConfigs[$configName] ?? null;
    }

    /**
     * Get all registered schemas
     */
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Get schema information for a specific configuration
     */
    public function getSchemaInfo(string $configName): ?array
    {
        $schema = $this->schemas[$configName] ?? null;
        if (!$schema) {
            return null;
        }

        return [
            'name' => $schema->getConfigurationName(),
            'description' => $schema->getDescription(),
            'version' => $schema->getVersion()
        ];
    }

    /**
     * Check if a schema is registered for the given configuration name
     */
    public function hasSchema(string $configName): bool
    {
        return isset($this->schemas[$configName]);
    }

    /**
     * Get a registered schema by name
     */
    public function getSchema(string $configName): ?ConfigurationInterface
    {
        return $this->schemas[$configName] ?? null;
    }

    /**
     * Clear all processed configurations (useful for testing)
     */
    public function clearProcessedConfigurations(): void
    {
        $this->processedConfigs = [];

        $this->logger->debug('Cleared all processed configurations');
    }

    /**
     * Get summary of all registered schemas
     */
    public function getSchemaSummary(): array
    {
        $summary = [];

        foreach ($this->schemas as $configName => $schema) {
            $summary[$configName] = [
                'name' => $schema->getConfigurationName(),
                'description' => $schema->getDescription(),
                'version' => $schema->getVersion(),
                'processed' => isset($this->processedConfigs[$configName])
            ];
        }

        return $summary;
    }

    /**
     * Register an extension schema
     */
    public function registerExtensionSchema(ExtensionSchemaInterface $schema): void
    {
        $this->registerSchema($schema);

        $this->logger->debug('Extension schema registered', [
            'config_name' => $schema->getConfigurationName(),
            'extension_name' => $schema->getExtensionName(),
            'extension_version' => $schema->getExtensionVersion(),
            'supported_manifest_versions' => $schema->getSupportedManifestVersions()
        ]);
    }

    /**
     * Get all extension schemas
     */
    public function getExtensionSchemas(): array
    {
        $extensionSchemas = [];

        foreach ($this->schemas as $configName => $schema) {
            if ($schema instanceof ExtensionSchemaInterface) {
                $extensionSchemas[$configName] = $schema;
            }
        }

        return $extensionSchemas;
    }

    /**
     * Get extension schemas by extension name
     */
    public function getExtensionSchemasByExtension(string $extensionName): array
    {
        $schemas = [];

        foreach ($this->schemas as $configName => $schema) {
            if (
                $schema instanceof ExtensionSchemaInterface &&
                $schema->getExtensionName() === $extensionName
            ) {
                $schemas[$configName] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * Process extension manifest data
     */
    public function processExtensionManifest(array $manifestData): array
    {
        return $this->processConfiguration('extension_manifest', $manifestData);
    }
}
