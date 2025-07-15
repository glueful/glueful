<?php

declare(strict_types=1);

namespace Glueful\Configuration\Tools;

use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Config\Definition\Dumper\XmlReferenceDumper;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Glueful\Configuration\ConfigurationProcessor;

/**
 * Configuration Dumper
 *
 * Provides tools for generating configuration documentation, templates, and
 * reference files from configuration schemas. Supports YAML and XML formats
 * for different documentation needs.
 *
 * @package Glueful\Configuration\Tools
 */
class ConfigurationDumper
{
    public function __construct(private ConfigurationProcessor $processor)
    {
    }

    /**
     * Generate YAML reference documentation for a configuration schema
     */
    public function dumpYamlReference(string $configName): string
    {
        $schema = $this->getSchema($configName);
        $dumper = new YamlReferenceDumper();
        return $dumper->dump($schema);
    }

    /**
     * Generate XML reference documentation for a configuration schema
     */
    public function dumpXmlReference(string $configName): string
    {
        $schema = $this->getSchema($configName);
        $dumper = new XmlReferenceDumper();
        return $dumper->dump($schema);
    }

    /**
     * Generate a configuration template with default values
     */
    public function generateConfigTemplate(string $configName): array
    {
        $schema = $this->getSchema($configName);
        $treeBuilder = $schema->getConfigTreeBuilder();
        $tree = $treeBuilder->buildTree();

        $defaults = $this->extractDefaults($tree);
        return is_array($defaults) ? $defaults : [];
    }

    /**
     * Generate minimal configuration with only required fields
     */
    public function generateMinimalConfig(string $configName): array
    {
        $schema = $this->getSchema($configName);
        $treeBuilder = $schema->getConfigTreeBuilder();
        $tree = $treeBuilder->buildTree();

        $required = $this->extractRequiredFields($tree);
        return is_array($required) ? $required : [];
    }

    /**
     * Get configuration information for all registered schemas
     */
    public function getAllConfigInfo(): array
    {
        $configs = [];
        foreach ($this->processor->getAllSchemas() as $name => $schema) {
            $configs[$name] = [
                'name' => $schema->getConfigurationName(),
                'description' => $schema->getDescription(),
                'version' => $schema->getVersion(),
                'template' => $this->generateConfigTemplate($name),
                'minimal' => $this->generateMinimalConfig($name)
            ];
        }
        return $configs;
    }

    /**
     * Generate configuration documentation in multiple formats
     */
    public function generateDocumentation(string $configName): array
    {
        $schema = $this->getSchema($configName);

        return [
            'name' => $schema->getConfigurationName(),
            'description' => $schema->getDescription(),
            'version' => $schema->getVersion(),
            'yaml_reference' => $this->dumpYamlReference($configName),
            'xml_reference' => $this->dumpXmlReference($configName),
            'template' => $this->generateConfigTemplate($configName),
            'minimal' => $this->generateMinimalConfig($configName),
            'schema_structure' => $this->analyzeSchemaStructure($configName)
        ];
    }

    /**
     * Analyze schema structure for documentation purposes
     */
    public function analyzeSchemaStructure(string $configName): array
    {
        $schema = $this->getSchema($configName);
        $treeBuilder = $schema->getConfigTreeBuilder();
        $tree = $treeBuilder->buildTree();

        return $this->analyzeNode($tree);
    }

    /**
     * Get a registered schema by name
     */
    private function getSchema(string $configName)
    {
        $schemas = $this->processor->getAllSchemas();
        if (!isset($schemas[$configName])) {
            throw new \InvalidArgumentException("Schema not found: {$configName}");
        }
        return $schemas[$configName];
    }

    /**
     * Extract default values from configuration tree
     */
    private function extractDefaults(NodeInterface $node): mixed
    {
        if ($node->hasDefaultValue()) {
            return $node->getDefaultValue();
        }

        if ($node instanceof ArrayNode) {
            $defaults = [];
            foreach ($node->getChildren() as $child) {
                $childDefaults = $this->extractDefaults($child);
                if ($childDefaults !== null && $childDefaults !== []) {
                    $defaults[$child->getName()] = $childDefaults;
                }
            }
            return $defaults;
        }

        return null;
    }

    /**
     * Extract only required fields from configuration tree
     */
    private function extractRequiredFields(NodeInterface $node): mixed
    {
        if ($node instanceof ArrayNode) {
            $required = [];
            foreach ($node->getChildren() as $child) {
                if ($child->isRequired()) {
                    $childRequired = $this->extractRequiredFields($child);
                    if ($childRequired !== null && $childRequired !== []) {
                        $required[$child->getName()] = $childRequired;
                    } elseif ($child->hasDefaultValue()) {
                        $required[$child->getName()] = $child->getDefaultValue();
                    } else {
                        // Provide placeholder values for required fields
                        $required[$child->getName()] = $this->getPlaceholderValue($child);
                    }
                }
            }
            return $required;
        }

        return null;
    }

    /**
     * Analyze node structure for documentation
     */
    private function analyzeNode(NodeInterface $node): array
    {
        $analysis = [
            'type' => $this->getNodeType($node),
            'required' => $node->isRequired(),
            'has_default' => $node->hasDefaultValue()
        ];

        if ($node->hasDefaultValue()) {
            $analysis['default_value'] = $node->getDefaultValue();
        }

        if ($node instanceof EnumNode) {
            $analysis['allowed_values'] = $node->getValues();
        }

        if ($node instanceof ArrayNode) {
            $analysis['children'] = [];
            foreach ($node->getChildren() as $child) {
                $analysis['children'][$child->getName()] = $this->analyzeNode($child);
            }
        }

        return $analysis;
    }

    /**
     * Get the type of a configuration node
     *
     * @param NodeInterface $node
     * @return string
     */
    private function getNodeType(NodeInterface $node): string
    {
        return match (true) {
            $node instanceof ArrayNode => 'array',
            $node instanceof BooleanNode => 'boolean',
            $node instanceof IntegerNode => 'integer',
            $node instanceof FloatNode => 'float',
            $node instanceof EnumNode => 'enum',
            $node instanceof ScalarNode => 'scalar',
            default => 'unknown'
        };
    }

    /**
     * Get placeholder value for a node type
     *
     * @param NodeInterface $node
     * @return mixed
     */
    private function getPlaceholderValue(NodeInterface $node): mixed
    {
        return match (true) {
            $node instanceof BooleanNode => false,
            $node instanceof IntegerNode => 0,
            $node instanceof FloatNode => 0.0,
            $node instanceof EnumNode => $node->getValues()[0] ?? '',
            $node instanceof ArrayNode => [],
            default => ''
        };
    }
}
