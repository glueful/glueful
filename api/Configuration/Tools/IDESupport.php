<?php

declare(strict_types=1);

namespace Glueful\Configuration\Tools;

use Glueful\Configuration\ConfigurationProcessor;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\NodeInterface;

/**
 * IDE Support Generator
 *
 * Generates IDE configuration files and JSON schemas to provide autocomplete,
 * validation, and documentation support for configuration files in popular IDEs
 * like PhpStorm and VS Code.
 *
 * @package Glueful\Configuration\Tools
 */
class IDESupport
{
    public function __construct(private ConfigurationProcessor $processor)
    {
    }

    /**
     * Generate PhpStorm configuration for configuration schemas
     */
    public function generatePhpStormConfig(): array
    {
        $schemas = $this->processor->getAllSchemas();
        $config = [
            'version' => '1.0',
            'name' => 'Glueful Configuration Schemas',
            'description' => 'Configuration schema support for Glueful framework',
            'schemas' => []
        ];

        foreach ($schemas as $configName => $schema) {
            $config['schemas'][] = [
                'name' => $configName,
                'description' => $schema->getDescription(),
                'version' => $schema->getVersion(),
                'fileMatch' => [
                    "config/{$configName}.php",
                    "*/{$configName}.config.php"
                ],
                'schema' => $this->generateJsonSchema($schema)
            ];
        }

        return $config;
    }

    /**
     * Generate VS Code configuration for configuration schemas
     */
    public function generateVSCodeConfig(): array
    {
        $schemas = $this->processor->getAllSchemas();
        $config = [
            'json.schemas' => [],
            'php.validate.enable' => true,
            'php.suggest.basic' => true
        ];

        foreach ($schemas as $configName => $schema) {
            $config['json.schemas'][] = [
                'fileMatch' => [
                    "config/{$configName}.php",
                    "*/{$configName}.config.php"
                ],
                'url' => ".glueful/schemas/{$configName}.schema.json"
            ];
        }

        return $config;
    }

    /**
     * Generate JSON Schema for a configuration schema
     */
    public function generateJsonSchema($schema): array
    {
        $treeBuilder = $schema->getConfigTreeBuilder();
        $tree = $treeBuilder->buildTree();

        $jsonSchema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            '$id' => "https://glueful.org/schemas/{$schema->getConfigurationName()}.json",
            'title' => ucfirst($schema->getConfigurationName()) . ' Configuration',
            'description' => $schema->getDescription(),
            'type' => 'object'
        ];

        $properties = $this->convertNodeToJsonSchema($tree);
        if (!empty($properties)) {
            $jsonSchema = array_merge($jsonSchema, $properties);
        }

        return $jsonSchema;
    }

    /**
     * Generate individual JSON schema files for each configuration
     */
    public function generateSchemaFiles(): array
    {
        $schemas = $this->processor->getAllSchemas();
        $files = [];

        foreach ($schemas as $configName => $schema) {
            $jsonSchema = $this->generateJsonSchema($schema);
            $files["{$configName}.schema.json"] = json_encode($jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $files;
    }

    /**
     * Generate PhpStorm meta file for better autocompletion
     */
    public function generatePhpStormMeta(): string
    {
        $schemas = $this->processor->getAllSchemas();
        $configKeys = [];

        foreach ($schemas as $configName => $schema) {
            $configKeys[] = "'{$configName}'";

            // Add nested keys if available
            $treeBuilder = $schema->getConfigTreeBuilder();
            $tree = $treeBuilder->buildTree();
            $nestedKeys = $this->extractConfigKeys($tree, $configName);
            $configKeys = array_merge($configKeys, $nestedKeys);
        }

        $meta = "<?php\n\n";
        $meta .= "namespace PHPSTORM_META {\n\n";
        $meta .= "    // Glueful Configuration Keys\n";
        $meta .= "    override(\\config(0), map([\n";

        foreach ($configKeys as $key) {
            $meta .= "        {$key} => \$configValue,\n";
        }

        $meta .= "    ]));\n\n";
        $meta .= "    // Glueful Helper Functions\n";
        $meta .= "    override(\\Glueful\\Helpers\\ConfigManager::get(0), map([\n";

        foreach ($schemas as $configName => $schema) {
            $meta .= "        '{$configName}' => \\{$schema->getConfigurationName()}Config::class,\n";
        }

        $meta .= "    ]));\n\n";
        $meta .= "}\n";

        return $meta;
    }

    /**
     * Convert Symfony Config node to JSON Schema
     */
    private function convertNodeToJsonSchema(NodeInterface $node): array
    {
        $schema = [];

        if ($node instanceof ArrayNode) {
            $schema['type'] = 'object';
            $schema['properties'] = [];
            $required = [];

            foreach ($node->getChildren() as $child) {
                $childName = $child->getName();
                $childSchema = $this->convertNodeToJsonSchema($child);

                if (!empty($childSchema)) {
                    $schema['properties'][$childName] = $childSchema;
                }

                if ($child->isRequired()) {
                    $required[] = $childName;
                }
            }

            if (!empty($required)) {
                $schema['required'] = $required;
            }

            // Add additional properties setting
            $schema['additionalProperties'] = false;
        } elseif ($node instanceof BooleanNode) {
            $schema['type'] = 'boolean';
            if ($node->hasDefaultValue()) {
                $schema['default'] = $node->getDefaultValue();
            }
        } elseif ($node instanceof IntegerNode) {
            $schema['type'] = 'integer';
            if ($node->hasDefaultValue()) {
                $schema['default'] = $node->getDefaultValue();
            }
        } elseif ($node instanceof FloatNode) {
            $schema['type'] = 'number';
            if ($node->hasDefaultValue()) {
                $schema['default'] = $node->getDefaultValue();
            }
        } elseif ($node instanceof EnumNode) {
            $schema['type'] = 'string';
            $schema['enum'] = $node->getValues();
            if ($node->hasDefaultValue()) {
                $schema['default'] = $node->getDefaultValue();
            }
        } elseif ($node instanceof ScalarNode) {
            $schema['type'] = 'string';
            if ($node->hasDefaultValue()) {
                $schema['default'] = $node->getDefaultValue();
            }
        }

        return $schema;
    }

    /**
     * Extract configuration keys for PhpStorm meta
     */
    private function extractConfigKeys(NodeInterface $node, string $prefix = ''): array
    {
        $keys = [];

        if ($node instanceof ArrayNode) {
            foreach ($node->getChildren() as $child) {
                $childName = $child->getName();
                $fullKey = $prefix ? "{$prefix}.{$childName}" : $childName;
                $keys[] = "'{$fullKey}'";

                // Recursively extract nested keys (limit depth to avoid too many keys)
                if ($child instanceof ArrayNode && substr_count($fullKey, '.') < 3) {
                    $nestedKeys = $this->extractConfigKeys($child, $fullKey);
                    $keys = array_merge($keys, $nestedKeys);
                }
            }
        }

        return $keys;
    }

    /**
     * Generate autocomplete suggestions for configuration values
     */
    public function generateAutocompleteSuggestions(): array
    {
        $schemas = $this->processor->getAllSchemas();
        $suggestions = [];

        foreach ($schemas as $configName => $schema) {
            $treeBuilder = $schema->getConfigTreeBuilder();
            $tree = $treeBuilder->buildTree();

            $suggestions[$configName] = $this->extractSuggestions($tree);
        }

        return $suggestions;
    }

    /**
     * Extract autocomplete suggestions from node
     */
    private function extractSuggestions(NodeInterface $node): array
    {
        $suggestions = [];

        if ($node instanceof EnumNode) {
            $suggestions['enum'] = $node->getValues();
        } elseif ($node instanceof BooleanNode) {
            $suggestions['boolean'] = [true, false];
        } elseif ($node instanceof ArrayNode) {
            foreach ($node->getChildren() as $child) {
                $childSuggestions = $this->extractSuggestions($child);
                if (!empty($childSuggestions)) {
                    $suggestions[$child->getName()] = $childSuggestions;
                }
            }
        }

        return $suggestions;
    }
}
