<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Config;

use Glueful\Console\BaseCommand;
use Glueful\Configuration\Tools\IDESupport;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate IDE Support Command
 *
 * CLI command for generating IDE configuration files and JSON schemas to provide
 * autocomplete, validation, and documentation support in popular IDEs like
 * PhpStorm and VS Code.
 *
 * @package Glueful\Console\Commands\Config
 */
class GenerateIDESupportCommand extends BaseCommand
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('config:generate-ide-support')
            ->setDescription('Generate IDE support files for configuration schemas')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory', '.glueful/')
            ->addOption('phpstorm', null, InputOption::VALUE_NONE, 'Generate PhpStorm configuration')
            ->addOption('vscode', null, InputOption::VALUE_NONE, 'Generate VS Code configuration')
            ->addOption('json-schemas', null, InputOption::VALUE_NONE, 'Generate JSON schema files')
            ->addOption('meta', null, InputOption::VALUE_NONE, 'Generate PhpStorm meta file')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Generate all IDE support files')
            ->setHelp('
This command generates IDE support files for better configuration editing experience.

<info>Examples:</info>
  <comment>php glueful config:generate-ide-support --all</comment>                Generate all IDE support files
  <comment>php glueful config:generate-ide-support --phpstorm</comment>           Generate PhpStorm config
  <comment>php glueful config:generate-ide-support --vscode</comment>             Generate VS Code config
  <comment>php glueful config:generate-ide-support --json-schemas</comment>       Generate JSON schemas
  <comment>php glueful config:generate-ide-support --meta</comment>               Generate PhpStorm meta file
            ');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ideSupport = $this->getService(IDESupport::class);
        $outputDir = rtrim($input->getOption('output'), '/') . '/';

        $generatePhpStorm = $input->getOption('phpstorm');
        $generateVSCode = $input->getOption('vscode');
        $generateJsonSchemas = $input->getOption('json-schemas');
        $generateMeta = $input->getOption('meta');
        $generateAll = $input->getOption('all');

        // If no specific options, default to all
        if (!$generatePhpStorm && !$generateVSCode && !$generateJsonSchemas && !$generateMeta) {
            $generateAll = true;
        }

        if ($generateAll) {
            $generatePhpStorm = true;
            $generateVSCode = true;
            $generateJsonSchemas = true;
            $generateMeta = true;
        }

        $this->info('Generating IDE support files...');
        $this->line("Output directory: {$outputDir}");
        $this->line();

        // Create output directory
        if (!$this->ensureDirectoryExists($outputDir)) {
            return 1;
        }

        $filesGenerated = 0;

        try {
            // Generate PhpStorm configuration
            if ($generatePhpStorm) {
                $filesGenerated += $this->generatePhpStormSupport($ideSupport, $outputDir);
            }

            // Generate VS Code configuration
            if ($generateVSCode) {
                $filesGenerated += $this->generateVSCodeSupport($ideSupport, $outputDir);
            }

            // Generate JSON schema files
            if ($generateJsonSchemas) {
                $filesGenerated += $this->generateJsonSchemas($ideSupport, $outputDir);
            }

            // Generate PhpStorm meta file
            if ($generateMeta) {
                $filesGenerated += $this->generatePhpStormMeta($ideSupport, $outputDir);
            }

            $this->line();
            $this->success("IDE support files generated successfully!");
            $this->info("Generated {$filesGenerated} files in: {$outputDir}");
            $this->line();
            $this->displayUsageInstructions($generatePhpStorm, $generateVSCode, $generateJsonSchemas);

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to generate IDE support files: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Generate PhpStorm support files
     */
    private function generatePhpStormSupport(IDESupport $ideSupport, string $outputDir): int
    {
        $this->line('Generating PhpStorm configuration...');

        $config = $ideSupport->generatePhpStormConfig();
        $configFile = $outputDir . 'phpstorm-config.json';

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("  ✓ Generated: phpstorm-config.json");

        return 1;
    }

    /**
     * Generate VS Code support files
     */
    private function generateVSCodeSupport(IDESupport $ideSupport, string $outputDir): int
    {
        $this->line('Generating VS Code configuration...');

        $config = $ideSupport->generateVSCodeConfig();
        $configFile = $outputDir . 'vscode-settings.json';

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("  ✓ Generated: vscode-settings.json");

        return 1;
    }

    /**
     * Generate JSON schema files
     */
    private function generateJsonSchemas(IDESupport $ideSupport, string $outputDir): int
    {
        $this->line('Generating JSON schema files...');

        $schemasDir = $outputDir . 'schemas/';
        if (!$this->ensureDirectoryExists($schemasDir)) {
            throw new \RuntimeException("Failed to create schemas directory: {$schemasDir}");
        }

        $schemaFiles = $ideSupport->generateSchemaFiles();
        $count = 0;

        foreach ($schemaFiles as $filename => $content) {
            file_put_contents($schemasDir . $filename, $content);
            $this->line("  ✓ Generated: schemas/{$filename}");
            $count++;
        }

        return $count;
    }

    /**
     * Generate PhpStorm meta file
     */
    private function generatePhpStormMeta(IDESupport $ideSupport, string $outputDir): int
    {
        $this->line('Generating PhpStorm meta file...');

        $metaContent = $ideSupport->generatePhpStormMeta();
        $metaFile = $outputDir . '.phpstorm.meta.php';

        file_put_contents($metaFile, $metaContent);
        $this->line("  ✓ Generated: .phpstorm.meta.php");

        return 1;
    }

    /**
     * Display usage instructions
     */
    private function displayUsageInstructions(bool $phpstorm, bool $vscode, bool $jsonSchemas): void
    {
        $this->info('Usage Instructions:');
        $this->line('==================');

        if ($phpstorm) {
            $this->line();
            $this->warning('PhpStorm Setup:');
            $this->line('1. Copy .glueful/.phpstorm.meta.php to your project root');
            $this->line('2. Configure JSON schemas in PhpStorm:');
            $this->line('   - Go to Settings > Languages & Frameworks > Schemas and DTDs');
            $this->line('   - Import schema files from .glueful/schemas/');
            $this->line('3. Restart PhpStorm for changes to take effect');
        }

        if ($vscode) {
            $this->line();
            $this->warning('VS Code Setup:');
            $this->line('1. Copy settings from .glueful/vscode-settings.json to .vscode/settings.json');
            $this->line('2. Install PHP extensions for better support:');
            $this->line('   - PHP IntelliSense');
            $this->line('   - PHP Namespace Resolver');
            $this->line('3. Reload VS Code window');
        }

        if ($jsonSchemas) {
            $this->line();
            $this->warning('JSON Schemas:');
            $this->line('- Schema files are available in .glueful/schemas/');
            $this->line('- These provide autocomplete and validation for config files');
            $this->line('- Can be used with any editor that supports JSON Schema');
        }

        $this->line();
        $this->note('Tip: Run this command after adding new configuration schemas to update IDE support.');
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): bool
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Failed to create directory: {$directory}");
                return false;
            }
            $this->line("Created directory: {$directory}");
        }

        return true;
    }
}
