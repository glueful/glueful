<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Config;

use Glueful\Console\BaseCommand;
use Glueful\Configuration\Tools\ConfigurationDumper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate Configuration Documentation Command
 *
 * CLI command for generating configuration documentation from registered schemas.
 * Supports multiple output formats (YAML, XML) and creates comprehensive
 * documentation including reference files, templates, and index summaries.
 *
 * @package Glueful\Console\Commands\Config
 */
class GenerateDocsCommand extends BaseCommand
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('config:generate-docs')
            ->setDescription('Generate configuration documentation from schemas')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Documentation format (yaml|xml)', 'yaml')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory', 'docs/config/')
            ->addOption('include-templates', 't', InputOption::VALUE_NONE, 'Include configuration templates')
            ->addOption('include-minimal', 'm', InputOption::VALUE_NONE, 'Include minimal configuration examples')
            ->setHelp('
This command generates comprehensive documentation for all registered configuration schemas.

<info>Examples:</info>
  <comment>php glueful config:generate-docs</comment>                        Generate YAML docs
  <comment>php glueful config:generate-docs --format=xml</comment>            Generate XML docs
  <comment>php glueful config:generate-docs --output=custom/path/</comment>   Custom output directory
  <comment>php glueful config:generate-docs -tm</comment>                     Include templates and minimal configs
            ');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dumper = $this->getService(ConfigurationDumper::class);

        $format = $input->getOption('format');
        $outputDir = rtrim($input->getOption('output'), '/') . '/';
        $includeTemplates = $input->getOption('include-templates');
        $includeMinimal = $input->getOption('include-minimal');

        // Validate format
        if (!in_array($format, ['yaml', 'xml'])) {
            $this->error("Invalid format '{$format}'. Supported formats: yaml, xml");
            return 1;
        }

        // Create output directory
        if (!$this->ensureDirectoryExists($outputDir)) {
            return 1;
        }

        $this->info("Generating configuration documentation in {$format} format...");
        $this->line("Output directory: {$outputDir}");
        $this->line();

        try {
            $configInfo = $dumper->getAllConfigInfo();

            if (empty($configInfo)) {
                $this->warning('No configuration schemas found.');
                return 0;
            }

            $this->generateDocumentationFiles($dumper, $configInfo, $outputDir, $format, $includeTemplates, $includeMinimal);
            $this->generateIndexFile($configInfo, $outputDir, $format);

            $this->line();
            $this->success('Documentation generated successfully!');
            $this->info("Generated files in: {$outputDir}");

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to generate documentation: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Generate documentation files for all configurations
     */
    private function generateDocumentationFiles(
        ConfigurationDumper $dumper,
        array $configInfo,
        string $outputDir,
        string $format,
        bool $includeTemplates,
        bool $includeMinimal
    ): void {
        $totalConfigs = count($configInfo);

        $this->progressBar($totalConfigs, function ($progressBar) use (
            $dumper,
            $configInfo,
            $outputDir,
            $format,
            $includeTemplates,
            $includeMinimal
        ) {
            foreach ($configInfo as $configName => $info) {
                $progressBar->setMessage("Generating docs for {$configName}...");

                // Generate reference documentation
                $this->generateReferenceFile($dumper, $configName, $outputDir, $format);

                // Generate template files if requested
                if ($includeTemplates) {
                    $this->generateTemplateFile($info, $configName, $outputDir);
                }

                // Generate minimal config files if requested
                if ($includeMinimal) {
                    $this->generateMinimalFile($info, $configName, $outputDir);
                }

                $progressBar->advance();
            }
        });
    }

    /**
     * Generate reference documentation file
     */
    private function generateReferenceFile(ConfigurationDumper $dumper, string $configName, string $outputDir, string $format): void
    {
        $filename = "{$outputDir}{$configName}.reference.{$format}";

        if ($format === 'yaml') {
            $content = $dumper->dumpYamlReference($configName);
        } else {
            $content = $dumper->dumpXmlReference($configName);
        }

        file_put_contents($filename, $content);
        $this->line("  Generated: {$configName}.reference.{$format}");
    }

    /**
     * Generate template configuration file
     */
    private function generateTemplateFile(array $info, string $configName, string $outputDir): void
    {
        $filename = "{$outputDir}{$configName}.template.php";
        $template = $info['template'];

        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * {$info['name']} Configuration Template\n";
        $content .= " * \n";
        $content .= " * {$info['description']}\n";
        $content .= " * Version: {$info['version']}\n";
        $content .= " * \n";
        $content .= " * This file contains all available configuration options with their default values.\n";
        $content .= " * Copy and modify as needed for your application.\n";
        $content .= " */\n\n";
        $content .= "return " . $this->formatArrayAsPhp($template, 0) . ";\n";

        file_put_contents($filename, $content);
        $this->line("  Generated: {$configName}.template.php");
    }

    /**
     * Generate minimal configuration file
     */
    private function generateMinimalFile(array $info, string $configName, string $outputDir): void
    {
        $filename = "{$outputDir}{$configName}.minimal.php";
        $minimal = $info['minimal'];

        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * {$info['name']} Minimal Configuration\n";
        $content .= " * \n";
        $content .= " * {$info['description']}\n";
        $content .= " * Version: {$info['version']}\n";
        $content .= " * \n";
        $content .= " * This file contains only the required configuration options.\n";
        $content .= " * Use this as a starting point for your configuration.\n";
        $content .= " */\n\n";
        $content .= "return " . $this->formatArrayAsPhp($minimal, 0) . ";\n";

        file_put_contents($filename, $content);
        $this->line("  Generated: {$configName}.minimal.php");
    }

    /**
     * Generate index/summary file
     */
    private function generateIndexFile(array $configInfo, string $outputDir, string $format): void
    {
        $indexFile = "{$outputDir}README.md";
        $content = $this->generateIndexContent($configInfo, $format);

        file_put_contents($indexFile, $content);
        $this->line("Generated index file: README.md");
    }

    /**
     * Generate index content
     */
    private function generateIndexContent(array $configInfo, string $format): string
    {
        $content = "# Configuration Documentation\n\n";
        $content .= "This directory contains configuration schema documentation for Glueful.\n\n";
        $content .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Format: " . strtoupper($format) . "\n\n";

        $content .= "## Available Configurations\n\n";
        $content .= "| Configuration | Description | Version | Files |\n";
        $content .= "|---------------|-------------|---------|-------|\n";

        foreach ($configInfo as $configName => $info) {
            $files = [];
            $files[] = "[Reference](./{$configName}.reference.{$format})";

            if (file_exists(dirname(__FILE__) . "/../../../{$configName}.template.php")) {
                $files[] = "[Template](./{$configName}.template.php)";
            }

            if (file_exists(dirname(__FILE__) . "/../../../{$configName}.minimal.php")) {
                $files[] = "[Minimal](./{$configName}.minimal.php)";
            }

            $filesStr = implode(' • ', $files);
            $content .= "| **{$configName}** | {$info['description']} | {$info['version']} | {$filesStr} |\n";
        }

        $content .= "\n## File Types\n\n";
        $content .= "- **Reference**: Complete schema documentation with all available options\n";
        $content .= "- **Template**: Full configuration file with default values\n";
        $content .= "- **Minimal**: Configuration file with only required options\n\n";

        $content .= "## Usage\n\n";
        $content .= "1. **Reference files** provide complete documentation of all configuration options\n";
        $content .= "2. **Template files** can be copied to your `config/` directory and customized\n";
        $content .= "3. **Minimal files** provide a starting point with only required settings\n\n";

        $content .= "## Validation\n\n";
        $content .= "Use the configuration validation command to check your configurations:\n\n";
        $content .= "```bash\n";
        $content .= "# Validate specific configuration\n";
        $content .= "php glueful config:validate database\n\n";
        $content .= "# Validate all configurations\n";
        $content .= "php glueful config:validate --all\n";
        $content .= "```\n";

        return $content;
    }

    /**
     * Format array as PHP code
     */
    private function formatArrayAsPhp(array $array, int $indent): string
    {
        if (empty($array)) {
            return '[]';
        }

        $spaces = str_repeat('    ', $indent);
        $nextSpaces = str_repeat('    ', $indent + 1);

        $result = "[\n";

        foreach ($array as $key => $value) {
            $keyStr = is_string($key) ? "'{$key}'" : $key;

            if (is_array($value)) {
                $result .= "{$nextSpaces}{$keyStr} => " . $this->formatArrayAsPhp($value, $indent + 1) . ",\n";
            } elseif (is_string($value)) {
                $escapedValue = addslashes($value);
                $result .= "{$nextSpaces}{$keyStr} => '{$escapedValue}',\n";
            } elseif (is_bool($value)) {
                $boolStr = $value ? 'true' : 'false';
                $result .= "{$nextSpaces}{$keyStr} => {$boolStr},\n";
            } elseif (is_null($value)) {
                $result .= "{$nextSpaces}{$keyStr} => null,\n";
            } else {
                $result .= "{$nextSpaces}{$keyStr} => {$value},\n";
            }
        }

        $result .= "{$spaces}]";

        return $result;
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): bool
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Failed to create output directory: {$directory}");
                return false;
            }
            $this->line("Created output directory: {$directory}");
        }

        return true;
    }
}
