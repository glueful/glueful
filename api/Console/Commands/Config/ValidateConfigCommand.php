<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Config;

use Glueful\Console\BaseCommand;
use Glueful\Configuration\ConfigurationProcessor;
use Glueful\Configuration\Exceptions\ConfigurationException;
use Glueful\Helpers\ConfigManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validate Configuration Command
 *
 * CLI command for validating configuration files against their registered schemas.
 * Supports validation of individual configurations or all configurations at once.
 * Provides detailed error reporting and validation summaries.
 *
 * @package Glueful\Console\Commands\Config
 */
class ValidateConfigCommand extends BaseCommand
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('config:validate')
            ->setDescription('Validate configuration files against their schemas')
            ->addArgument('config', InputArgument::OPTIONAL, 'Configuration name to validate')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Validate all configurations')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Show detailed validation information')
            ->setHelp('
This command validates configuration files against their registered schemas.

<info>Examples:</info>
  <comment>php glueful config:validate database</comment>     Validate database configuration
  <comment>php glueful config:validate --all</comment>        Validate all configurations
  <comment>php glueful config:validate --all -v</comment>     Validate all with verbose output
            ');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processor = $this->getService(ConfigurationProcessor::class);
        $configManager = $this->getService(ConfigManager::class);

        if ($input->getOption('all')) {
            return $this->validateAllConfigs($processor, $configManager, $input->getOption('verbose'));
        }

        $configName = $input->getArgument('config');
        if (!$configName) {
            $this->error('Please specify a config name or use --all flag');
            $this->showUsageHelp();
            return 1;
        }

        return $this->validateSingleConfig($configName, $processor, $configManager, $input->getOption('verbose'));
    }

    /**
     * Validate all registered configuration schemas
     */
    private function validateAllConfigs(
        ConfigurationProcessor $processor,
        ConfigManager $configManager,
        bool $verbose
    ): int {
        $this->info('Validating all configuration files...');
        $this->line();

        $schemas = $processor->getAllSchemas();
        $results = [];
        $totalErrors = 0;

        $this->progressBar(count($schemas), function ($progressBar) use (
            $schemas,
            $processor,
            $configManager,
            &$results,
            &$totalErrors,
            $verbose
        ) {
            foreach ($schemas as $configName => $schema) {
                $progressBar->setMessage("Validating {$configName}...");

                try {
                    $config = $configManager->get($configName);
                    $processedConfig = $processor->processConfiguration($configName, $config);

                    $results[$configName] = [
                        'status' => 'valid',
                        'error' => null,
                        'processed_config' => $processedConfig
                    ];

                    if ($verbose) {
                        $this->line("  ✓ {$configName} - Valid");
                    }
                } catch (ConfigurationException $e) {
                    $results[$configName] = [
                        'status' => 'invalid',
                        'error' => $e->getMessage(),
                        'processed_config' => null
                    ];

                    if ($verbose) {
                        $this->line("  ✗ {$configName} - Invalid: " . $e->getMessage());
                    }
                    $totalErrors++;
                } catch (\Exception $e) {
                    $results[$configName] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'processed_config' => null
                    ];

                    if ($verbose) {
                        $this->line("  ✗ {$configName} - Error: " . $e->getMessage());
                    }
                    $totalErrors++;
                }

                $progressBar->advance();
            }
        });

        $this->line();
        $this->displayValidationSummary($results, $totalErrors);

        if ($verbose && $totalErrors > 0) {
            $this->displayDetailedErrors($results);
        }

        return $totalErrors > 0 ? 1 : 0;
    }

    /**
     * Validate a single configuration
     */
    private function validateSingleConfig(
        string $configName,
        ConfigurationProcessor $processor,
        ConfigManager $configManager,
        bool $verbose
    ): int {
        if (!$processor->hasSchema($configName)) {
            $this->error("No schema registered for configuration: {$configName}");
            $this->showAvailableSchemas($processor);
            return 1;
        }

        $this->info("Validating configuration '{$configName}'...");

        try {
            $config = $configManager->get($configName);
            $processedConfig = $processor->processConfiguration($configName, $config);

            $this->success("Configuration '{$configName}' is valid!");

            if ($verbose) {
                $this->displayConfigDetails($configName, $processor, $config, $processedConfig);
            }

            return 0;
        } catch (ConfigurationException $e) {
            $this->error("Configuration '{$configName}' is invalid!");
            $this->line("Error: " . $e->getMessage());

            if ($verbose) {
                $this->displayValidationDetails($configName, $e);
            }

            return 1;
        } catch (\Exception $e) {
            $this->error("Failed to load configuration '{$configName}': " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Display validation summary
     */
    private function displayValidationSummary(array $results, int $totalErrors): void
    {
        $totalConfigs = count($results);
        $validConfigs = $totalConfigs - $totalErrors;

        $headers = ['Configuration', 'Status', 'Error'];
        $rows = [];

        foreach ($results as $configName => $result) {
            $status = $result['status'] === 'valid' ? '<info>✓ Valid</info>' : '<error>✗ Invalid</error>';
            $error = $result['error'] ? substr($result['error'], 0, 60) . '...' : '-';
            $rows[] = [$configName, $status, $error];
        }

        $this->table($headers, $rows);

        $this->line();
        $this->info("Validation Summary:");
        $this->line("Total configurations: {$totalConfigs}");
        $this->line("Valid configurations: <info>{$validConfigs}</info>");
        $invalidText = $totalErrors > 0 ? "<error>{$totalErrors}</error>" : "<info>0</info>";
        $this->line("Invalid configurations: " . $invalidText);

        if ($totalErrors === 0) {
            $this->line();
            $this->success('🎉 All configurations are valid!');
        } else {
            $this->line();
            $this->warning("⚠️  {$totalErrors} configuration(s) have errors.");
        }
    }

    /**
     * Display detailed error information
     */
    private function displayDetailedErrors(array $results): void
    {
        $this->line();
        $this->error("Detailed Error Information:");
        $this->line("===========================");

        foreach ($results as $configName => $result) {
            if ($result['status'] !== 'valid') {
                $this->line();
                $this->warning("Configuration: {$configName}");
                $this->line("Status: " . ucfirst($result['status']));
                $this->line("Error: " . $result['error']);
            }
        }
    }

    /**
     * Display configuration details for verbose output
     */
    private function displayConfigDetails(
        string $configName,
        ConfigurationProcessor $processor,
        array $originalConfig,
        array $processedConfig
    ): void {
        $this->line();
        $this->info("Configuration Details:");
        $this->line("=====================");

        $schemaInfo = $processor->getSchemaInfo($configName);
        if ($schemaInfo) {
            $this->line("Schema: {$schemaInfo['name']} v{$schemaInfo['version']}");
            $this->line("Description: {$schemaInfo['description']}");
        }

        $this->line();
        $this->line("Original configuration structure:");
        $this->displayConfigStructure($originalConfig, 1);

        $this->line();
        $this->line("Processed configuration structure:");
        $this->displayConfigStructure($processedConfig, 1);
    }

    /**
     * Display configuration structure recursively
     */
    private function displayConfigStructure(array $config, int $level): void
    {
        $indent = str_repeat('  ', $level);

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->line("{$indent}<comment>{$key}</comment>: array[" . count($value) . "]");
                if ($level < 3) { // Limit depth to avoid overwhelming output
                    $this->displayConfigStructure($value, $level + 1);
                }
            } else {
                $type = gettype($value);
                $displayValue = is_string($value) && strlen($value) > 50
                    ? substr($value, 0, 47) . '...'
                    : (string) $value;
                $this->line(
                    "{$indent}<comment>{$key}</comment>: <info>{$type}</info>({$displayValue})"
                );
            }
        }
    }

    /**
     * Display validation details for errors
     */
    private function displayValidationDetails(string $configName, ConfigurationException $exception): void
    {
        $this->line();
        $this->error("Validation Details:");
        $this->line("==================");
        $this->line("Configuration: {$configName}");
        $this->line("Error: " . $exception->getMessage());

        if ($previous = $exception->getPrevious()) {
            $this->line("Underlying error: " . $previous->getMessage());
        }
    }

    /**
     * Show usage help
     */
    private function showUsageHelp(): void
    {
        $this->line();
        $this->warning("Usage:");
        $this->line("  php glueful config:validate <config_name>   Validate specific config");
        $this->line("  php glueful config:validate --all           Validate all configs");
        $this->line("  php glueful config:validate --all --verbose Validate all with details");
    }

    /**
     * Show available schemas
     */
    private function showAvailableSchemas(ConfigurationProcessor $processor): void
    {
        $schemas = $processor->getAllSchemas();

        if (empty($schemas)) {
            $this->warning("No configuration schemas are registered.");
            return;
        }

        $this->line();
        $this->info("Available configurations:");
        foreach ($schemas as $configName => $schema) {
            $this->line("  - <comment>{$configName}</comment>: {$schema->getDescription()}");
        }
    }
}
