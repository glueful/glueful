<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Helpers\ExtensionsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extensions Validate Command
 * Comprehensive extension validation command with flexible scope:
 * - Individual extension validation (structure, config, dependencies)
 * - Global extensions configuration validation
 * - Bulk validation of all extensions
 * - Schema validation and dependency checking
 * - Automatic fix capabilities for common issues
 * Command: php glueful extensions:validate [name] [options]
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:validate',
    description: 'Validate extension structure, configuration, and dependencies'
)]
class ValidateCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Validate extension structure, configuration, and dependencies')
             ->setHelp(
                 'This command performs comprehensive validation of extensions. ' .
                 'Validates individual extensions when name is provided, or global configuration when omitted.'
             )
             ->addArgument(
                 'name',
                 InputArgument::OPTIONAL,
                 'Extension name to validate (validates global config if omitted)'
             )
             ->addOption(
                 'all',
                 'a',
                 InputOption::VALUE_NONE,
                 'Validate all extensions and global configuration'
             )
             ->addOption(
                 'strict',
                 's',
                 InputOption::VALUE_NONE,
                 'Enable strict validation mode with additional checks'
             )
             ->addOption(
                 'fix',
                 'f',
                 InputOption::VALUE_NONE,
                 'Attempt to automatically fix minor issues'
             )
             ->addOption(
                 'schema',
                 null,
                 InputOption::VALUE_NONE,
                 'Validate against JSON schema'
             )
             ->addOption(
                 'dependencies',
                 'd',
                 InputOption::VALUE_NONE,
                 'Focus on dependency validation'
             )
             ->addOption(
                 'structure',
                 null,
                 InputOption::VALUE_NONE,
                 'Focus on file structure validation'
             )
             ->addOption(
                 'config-only',
                 'c',
                 InputOption::VALUE_NONE,
                 'Only validate configuration files'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        $validateAll = $input->getOption('all');
        $strict = $input->getOption('strict');
        $autoFix = $input->getOption('fix');
        $validateSchema = $input->getOption('schema');
        $focusDependencies = $input->getOption('dependencies');
        $focusStructure = $input->getOption('structure');
        $configOnly = $input->getOption('config-only');

        try {
            if ($extensionName) {
                // Validate specific extension
                return $this->validateSingleExtension(
                    $extensionName,
                    $strict,
                    $autoFix,
                    $validateSchema,
                    $focusDependencies,
                    $focusStructure,
                    $configOnly
                );
            } elseif ($validateAll) {
                // Validate all extensions and global config
                return $this->validateAllExtensions(
                    $strict,
                    $autoFix,
                    $validateSchema,
                    $focusDependencies,
                    $focusStructure,
                    $configOnly
                );
            } else {
                // Validate global configuration
                return $this->validateGlobalConfiguration(
                    $autoFix,
                    $strict,
                    $validateSchema,
                    $focusDependencies
                );
            }
        } catch (\Exception $e) {
            $this->error("Validation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function validateSingleExtension(
        string $extensionName,
        bool $strict,
        bool $autoFix,
        bool $validateSchema,
        bool $focusDependencies,
        bool $focusStructure,
        bool $configOnly
    ): int {
        $this->info("🔍 Validating extension: {$extensionName}");

        if (!$this->extensionDirectoryExists($extensionName)) {
            $extensionPath = $this->getExtensionPath($extensionName);
            $this->error("Extension directory not found: {$extensionPath}");
            return self::FAILURE;
        }

        $extensionPath = $this->getExtensionPath($extensionName);
        $results = [];

        // Perform validations based on options
        if (!$configOnly && !$focusDependencies) {
            if (!$focusStructure) {
                $results = array_merge($results, $this->validateStructure($extensionPath, $extensionName, $autoFix));
            }
            $results = array_merge($results, $this->validateConfiguration($extensionPath, $extensionName, $autoFix));
            $results = array_merge($results, $this->validatePermissions($extensionPath));
        }

        if ($focusStructure && !$configOnly) {
            $results = array_merge($results, $this->validateStructure($extensionPath, $extensionName, $autoFix));
        }

        if ($configOnly || !$focusStructure) {
            $results = array_merge($results, $this->validateConfiguration($extensionPath, $extensionName, $autoFix));
        }

        if (!$configOnly && !$focusStructure) {
            $results = array_merge($results, $this->validateExtensionDependencies($extensionPath, $extensionName));
        }

        if ($focusDependencies) {
            $results = array_merge($results, $this->validateExtensionDependencies($extensionPath, $extensionName));
        }

        if ($strict) {
            $results = array_merge($results, $this->validateStrict($extensionPath, $extensionName));
        }

        if ($validateSchema) {
            $results = array_merge($results, $this->validateJsonSchema($extensionPath, $extensionName));
        }

        $this->displayValidationResults($results);

        $hasErrors = array_filter($results, fn($result) => $result['type'] === 'error');

        if (empty($hasErrors)) {
            $this->success("✅ Extension '{$extensionName}' validation passed!");
            return self::SUCCESS;
        } else {
            $this->error("❌ Extension '{$extensionName}' validation failed with errors.");
            return self::FAILURE;
        }
    }

    private function validateAllExtensions(
        bool $strict,
        bool $autoFix,
        bool $validateSchema,
        bool $focusDependencies,
        bool $focusStructure,
        bool $configOnly
    ): int {
        $this->info('🔍 Validating all extensions and global configuration');
        $this->line('====================================================');

        $allResults = [];

        // Validate global configuration first
        $globalResults = $this->performGlobalConfigValidation($autoFix);
        $allResults = array_merge($allResults, $globalResults);

        // Validate all individual extensions
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';
        if (is_dir($extensionsDir)) {
            $directories = scandir($extensionsDir);
            $validExtensions = 0;
            $totalExtensions = 0;

            foreach ($directories as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                    continue;
                }

                $totalExtensions++;
                $extensionResults = $this->performSingleExtensionValidation(
                    "{$extensionsDir}/{$dir}",
                    $dir,
                    $autoFix,
                    $strict,
                    $validateSchema,
                    $focusDependencies,
                    $focusStructure,
                    $configOnly
                );
                $allResults = array_merge($allResults, $extensionResults);

                $hasErrors = array_filter($extensionResults, fn($result) => $result['type'] === 'error');
                if (empty($hasErrors)) {
                    $validExtensions++;
                }
            }

            // Add summary
            $allResults[] = [
                'type' => $validExtensions === $totalExtensions ? 'success' : 'warning',
                'message' => "Extension validation summary: {$validExtensions}/{$totalExtensions} extensions passed",
                'category' => 'Validation Summary'
            ];
        }

        // Global dependency validation
        if ($focusDependencies || !$configOnly) {
            $depResults = $this->validateAllDependencies();
            $allResults = array_merge($allResults, $depResults);
        }

        $this->displayValidationResults($allResults);

        $hasErrors = array_filter($allResults, fn($result) => $result['type'] === 'error');

        if (empty($hasErrors)) {
            $this->success('✅ All extensions validation passed!');
            return self::SUCCESS;
        } else {
            $this->error('❌ Extensions validation failed with errors.');
            return self::FAILURE;
        }
    }

    private function validateGlobalConfiguration(
        bool $autoFix,
        bool $strict,
        bool $validateSchema,
        bool $focusDependencies
    ): int {
        $this->info('🔍 Validating global extensions configuration');
        $this->line('==========================================');

        $validationResults = [];

        // Validate global configuration
        $validationResults = array_merge($validationResults, $this->performGlobalConfigValidation($autoFix));

        // Validate individual extension configs
        $validationResults = array_merge($validationResults, $this->validateExtensionConfigs($autoFix, $strict));

        // Validate JSON schema if requested
        if ($validateSchema) {
            $validationResults = array_merge($validationResults, $this->validateGlobalJsonSchema());
        }

        // Validate dependencies
        if ($focusDependencies) {
            $validationResults = array_merge($validationResults, $this->validateAllDependencies());
        }

        $this->displayValidationResults($validationResults);

        $hasErrors = array_filter($validationResults, fn($result) => $result['type'] === 'error');

        if (empty($hasErrors)) {
            $this->success('✅ Global extensions configuration validation passed!');
            return self::SUCCESS;
        } else {
            $this->error('❌ Global extensions configuration validation failed with errors.');
            return self::FAILURE;
        }
    }

    private function performSingleExtensionValidation(
        string $path,
        string $name,
        bool $autoFix,
        bool $strict,
        bool $validateSchema,
        bool $focusDependencies,
        bool $focusStructure,
        bool $configOnly
    ): array {
        $results = [];

        if (!$configOnly && !$focusDependencies) {
            if (!$focusStructure) {
                $results = array_merge($results, $this->validateStructure($path, $name, $autoFix));
            }
            $results = array_merge($results, $this->validateConfiguration($path, $name, $autoFix));
            $results = array_merge($results, $this->validatePermissions($path));
        }

        if ($focusStructure && !$configOnly) {
            $results = array_merge($results, $this->validateStructure($path, $name, $autoFix));
        }

        if ($configOnly || !$focusStructure) {
            $results = array_merge($results, $this->validateConfiguration($path, $name, $autoFix));
        }

        if ($focusDependencies) {
            $results = array_merge($results, $this->validateExtensionDependencies($path, $name));
        }

        if ($strict) {
            $results = array_merge($results, $this->validateStrict($path, $name));
        }

        if ($validateSchema) {
            $results = array_merge($results, $this->validateJsonSchema($path, $name));
        }

        return $results;
    }

    private function performGlobalConfigValidation(bool $autoFix): array
    {
        $results = [];

        // Check if extensions directory exists
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';
        if (!is_dir($extensionsDir)) {
            if ($autoFix) {
                mkdir($extensionsDir, 0755, true);
                $results[] = [
                    'type' => 'info',
                    'message' => 'Created missing extensions directory',
                    'category' => 'Global Configuration'
                ];
            } else {
                $results[] = [
                    'type' => 'error',
                    'message' => 'Extensions directory not found: ' . $extensionsDir,
                    'category' => 'Global Configuration'
                ];
            }
        } else {
            $results[] = [
                'type' => 'success',
                'message' => 'Extensions directory exists',
                'category' => 'Global Configuration'
            ];
        }

        // Check directory permissions
        if (is_dir($extensionsDir)) {
            if (!is_readable($extensionsDir)) {
                $results[] = [
                    'type' => 'error',
                    'message' => 'Extensions directory is not readable',
                    'category' => 'Global Configuration'
                ];
            } elseif (!is_writable($extensionsDir)) {
                $results[] = [
                    'type' => 'warning',
                    'message' => 'Extensions directory is not writable (may affect installation)',
                    'category' => 'Global Configuration'
                ];
            } else {
                $results[] = [
                    'type' => 'success',
                    'message' => 'Extensions directory has correct permissions',
                    'category' => 'Global Configuration'
                ];
            }
        }

        return $results;
    }

    private function validateStructure(string $path, string $name, bool $autoFix): array
    {
        $results = [];

        // Required files/directories
        $required = [
            'extension.json' => 'file',
            'src' => 'directory',
            "{$name}.php" => 'file'
        ];

        foreach ($required as $item => $type) {
            $fullPath = "{$path}/{$item}";
            $exists = $type === 'file' ?
                (file_exists($fullPath) && is_file($fullPath)) :
                (file_exists($fullPath) && is_dir($fullPath));

            if (!$exists) {
                if ($autoFix && $type === 'directory') {
                    mkdir($fullPath, 0755, true);
                    $results[] = [
                        'type' => 'info',
                        'message' => "Created missing directory: {$item}",
                        'category' => "Extension: {$name}"
                    ];
                } else {
                    $results[] = [
                        'type' => 'error',
                        'message' => "Missing required {$type}: {$item}",
                        'category' => "Extension: {$name}"
                    ];
                }
            } else {
                $results[] = [
                    'type' => 'success',
                    'message' => "Found required {$type}: {$item}",
                    'category' => "Extension: {$name}"
                ];
            }
        }

        return $results;
    }

    private function validateConfiguration(string $path, string $name, bool $autoFix): array
    {
        $results = [];
        $configFile = "{$path}/extension.json";

        if (!file_exists($configFile)) {
            if ($autoFix) {
                $this->createDefaultConfig($configFile, $name);
                $results[] = [
                    'type' => 'info',
                    'message' => "Created default extension.json",
                    'category' => "Extension: {$name}"
                ];
            } else {
                $results[] = [
                    'type' => 'error',
                    'message' => 'Configuration file extension.json not found',
                    'category' => "Extension: {$name}"
                ];
                return $results;
            }
        }

        // Parse JSON
        $configContent = file_get_contents($configFile);
        $config = json_decode($configContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $results[] = [
                'type' => 'error',
                'message' => 'Invalid JSON in extension.json: ' . json_last_error_msg(),
                'category' => "Extension: {$name}"
            ];
            return $results;
        }

        // Required fields
        $requiredFields = ['name', 'version'];
        $recommendedFields = ['description', 'author', 'license'];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                $results[] = [
                    'type' => 'error',
                    'message' => "Missing required field in extension.json: {$field}",
                    'category' => "Extension: {$name}"
                ];
            } else {
                $results[] = [
                    'type' => 'success',
                    'message' => "Found required field: {$field}",
                    'category' => "Extension: {$name}"
                ];
            }
        }

        foreach ($recommendedFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                $results[] = [
                    'type' => 'warning',
                    'message' => "Missing recommended field in extension.json: {$field}",
                    'category' => "Extension: {$name}"
                ];
            }
        }

        // Validate name matches directory
        if (isset($config['name']) && $config['name'] !== $name) {
            $results[] = [
                'type' => 'warning',
                'message' => "Extension name in config ('{$config['name']}') doesn't match directory name ('{$name}')",
                'category' => "Extension: {$name}"
            ];
        }

        // Validate version format
        if (isset($config['version']) && !preg_match('/^\d+\.\d+\.\d+/', $config['version'])) {
            $results[] = [
                'type' => 'warning',
                'message' => "Version '{$config['version']}' doesn't follow semantic versioning",
                'category' => "Extension: {$name}"
            ];
        }

        // Validate autoload configuration
        if (isset($config['autoload'])) {
            $results = array_merge($results, $this->validateAutoload($config['autoload'], $name, $path));
        }

        return $results;
    }

    private function validateExtensionDependencies(string $path, string $name): array
    {
        $results = [];
        $configFile = "{$path}/extension.json";

        if (!file_exists($configFile)) {
            return [];
        }

        $config = json_decode(file_get_contents($configFile), true);
        $dependencies = $config['dependencies'] ?? [];

        if (empty($dependencies)) {
            $results[] = [
                'type' => 'info',
                'message' => 'No dependencies defined',
                'category' => "Extension: {$name}"
            ];
            return $results;
        }

        $extensionsManager = new ExtensionsManager();

        if (is_array($dependencies)) {
            foreach ($dependencies as $depName => $version) {
                unset($version); // Version validation could be added here in future
                $depExtension = $this->findExtension($extensionsManager, $depName);
                if (!$depExtension) {
                    $results[] = [
                        'type' => 'error',
                        'message' => "Dependency not found: {$depName}",
                        'category' => "Extension: {$name}"
                    ];
                } else {
                    $results[] = [
                        'type' => 'success',
                        'message' => "Dependency found: {$depName}",
                        'category' => "Extension: {$name}"
                    ];
                }
            }
        }

        return $results;
    }

    private function validatePermissions(string $path): array
    {
        $results = [];
        $extensionName = basename($path);

        // Check directory permissions
        if (!file_exists($path) || !is_readable($path)) {
            $results[] = [
                'type' => 'error',
                'message' => 'Extension directory is not readable',
                'category' => "Extension: {$extensionName}"
            ];
        } else {
            $results[] = [
                'type' => 'success',
                'message' => 'Extension directory is readable',
                'category' => "Extension: {$extensionName}"
            ];
        }

        // Check key files
        $keyFiles = ['extension.json'];
        foreach ($keyFiles as $file) {
            $filePath = "{$path}/{$file}";
            if (file_exists($filePath)) {
                if (!is_readable($filePath)) {
                    $results[] = [
                        'type' => 'error',
                        'message' => "File is not readable: {$file}",
                        'category' => "Extension: {$extensionName}"
                    ];
                } else {
                    $results[] = [
                        'type' => 'success',
                        'message' => "File is readable: {$file}",
                        'category' => "Extension: {$extensionName}"
                    ];
                }
            }
        }

        return $results;
    }

    private function validateStrict(string $path, string $name): array
    {
        $results = [];

        // Check for README
        $readmeFiles = ['README.md', 'README.txt', 'readme.md'];
        $hasReadme = false;
        foreach ($readmeFiles as $readme) {
            if (file_exists("{$path}/{$readme}")) {
                $hasReadme = true;
                break;
            }
        }

        if (!$hasReadme) {
            $results[] = [
                'type' => 'warning',
                'message' => 'No README file found (recommended for documentation)',
                'category' => "Extension: {$name}"
            ];
        } else {
            $results[] = [
                'type' => 'success',
                'message' => 'README file found',
                'category' => "Extension: {$name}"
            ];
        }

        // Check for license file
        if (!file_exists("{$path}/LICENSE") && !file_exists("{$path}/LICENSE.txt")) {
            $results[] = [
                'type' => 'warning',
                'message' => 'No LICENSE file found (recommended)',
                'category' => "Extension: {$name}"
            ];
        } else {
            $results[] = [
                'type' => 'success',
                'message' => 'LICENSE file found',
                'category' => "Extension: {$name}"
            ];
        }

        return $results;
    }

    private function validateJsonSchema(string $path, string $name): array
    {
        unset($path); // Schema path validation could be added here in future
        // This would validate against a JSON schema if one exists
        // For now, return a placeholder
        return [[
            'type' => 'info',
            'message' => 'JSON schema validation not yet implemented',
            'category' => "Extension: {$name}"
        ]];
    }

    private function validateGlobalJsonSchema(): array
    {
        // This would validate against a JSON schema if one exists
        // For now, return a placeholder
        return [[
            'type' => 'info',
            'message' => 'Global JSON schema validation not yet implemented',
            'category' => 'Schema Validation'
        ]];
    }

    private function validateExtensionConfigs(bool $autoFix, bool $strict): array
    {
        $results = [];
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            return $results;
        }

        $directories = scandir($extensionsDir);

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                continue;
            }

            $extensionResults = $this->validateConfiguration("{$extensionsDir}/{$dir}", $dir, $autoFix);
            $results = array_merge($results, $extensionResults);

            if ($strict) {
                $strictResults = $this->validateStrict("{$extensionsDir}/{$dir}", $dir);
                $results = array_merge($results, $strictResults);
            }
        }

        return $results;
    }

    private function validateAllDependencies(): array
    {
        $results = [];
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            return $results;
        }

        // Collect all extensions and their dependencies
        $extensions = [];
        $directories = scandir($extensionsDir);

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                continue;
            }

            $configFile = "{$extensionsDir}/{$dir}/extension.json";
            if (!file_exists($configFile)) {
                continue;
            }

            $config = json_decode(file_get_contents($configFile), true);
            if (!$config) {
                continue;
            }

            $extensions[$dir] = [
                'config' => $config,
                'dependencies' => $config['dependencies'] ?? []
            ];
        }

        // Check for missing dependencies
        foreach ($extensions as $extName => $extension) {
            if (is_array($extension['dependencies'])) {
                foreach ($extension['dependencies'] as $depName => $version) {
                    unset($version); // Version validation could be added here in future
                    if (!isset($extensions[$depName])) {
                        $results[] = [
                            'type' => 'error',
                            'message' => "Extension '{$extName}' depends on missing extension '{$depName}'",
                            'category' => 'Dependency Validation'
                        ];
                    }
                }
            }
        }

        // Check for circular dependencies
        $circularDeps = $this->detectCircularDependencies($extensions);
        foreach ($circularDeps as $cycle) {
            $results[] = [
                'type' => 'error',
                'message' => 'Circular dependency detected: ' . implode(' → ', $cycle),
                'category' => 'Dependency Validation'
            ];
        }

        if (empty($results)) {
            $results[] = [
                'type' => 'success',
                'message' => 'All dependencies are valid',
                'category' => 'Dependency Validation'
            ];
        }

        return $results;
    }

    private function validateAutoload(array $autoload, string $extensionName, string $extensionPath): array
    {
        $results = [];

        if (!isset($autoload['psr-4'])) {
            $results[] = [
                'type' => 'warning',
                'message' => 'No PSR-4 autoload configuration found',
                'category' => "Extension: {$extensionName}"
            ];
            return $results;
        }

        foreach ($autoload['psr-4'] as $namespace => $path) {
            $fullPath = "{$extensionPath}/{$path}";

            if (!is_dir($fullPath)) {
                $results[] = [
                    'type' => 'error',
                    'message' => "Autoload path does not exist: {$path}",
                    'category' => "Extension: {$extensionName}"
                ];
            } else {
                $results[] = [
                    'type' => 'success',
                    'message' => "Autoload path exists: {$path}",
                    'category' => "Extension: {$extensionName}"
                ];
            }

            // Validate namespace format
            if (!str_ends_with($namespace, '\\')) {
                $results[] = [
                    'type' => 'warning',
                    'message' => "PSR-4 namespace should end with backslash: {$namespace}",
                    'category' => "Extension: {$extensionName}"
                ];
            }
        }

        return $results;
    }

    private function detectCircularDependencies(array $extensions): array
    {
        $cycles = [];
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($extensions) as $extension) {
            if (!isset($visited[$extension])) {
                $this->detectCircularDependenciesRecursive(
                    $extension,
                    $extensions,
                    $visited,
                    $recursionStack,
                    [],
                    $cycles
                );
            }
        }

        return $cycles;
    }

    private function detectCircularDependenciesRecursive(
        string $current,
        array $extensions,
        array &$visited,
        array &$recursionStack,
        array $path,
        array &$cycles
    ): void {
        $visited[$current] = true;
        $recursionStack[$current] = true;
        $path[] = $current;

        if (isset($extensions[$current]) && is_array($extensions[$current]['dependencies'])) {
            foreach ($extensions[$current]['dependencies'] as $dependency => $version) {
                unset($version); // Version validation could be added here in future
                if (!isset($visited[$dependency])) {
                    $this->detectCircularDependenciesRecursive(
                        $dependency,
                        $extensions,
                        $visited,
                        $recursionStack,
                        $path,
                        $cycles
                    );
                } elseif (isset($recursionStack[$dependency]) && $recursionStack[$dependency]) {
                    // Found a cycle
                    $cycleStart = array_search($dependency, $path);
                    $cycle = array_slice($path, $cycleStart);
                    $cycle[] = $dependency; // Complete the cycle
                    $cycles[] = $cycle;
                }
            }
        }

        $recursionStack[$current] = false;
    }

    private function createDefaultConfig(string $configFile, string $extensionName): void
    {
        $defaultConfig = [
            'name' => $extensionName,
            'version' => '1.0.0',
            'description' => 'Auto-generated configuration',
            'author' => 'Unknown',
            'license' => 'MIT',
            'enabled' => false,
            'autoload' => [
                'psr-4' => [
                    "Extensions\\{$extensionName}\\" => 'src/'
                ]
            ],
            'dependencies' => []
        ];

        file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function displayValidationResults(array $results): void
    {
        $this->line('');
        $this->info('📋 Validation Results:');
        $this->line('=====================');

        $categories = [];
        foreach ($results as $result) {
            $categories[$result['category']][] = $result;
        }

        foreach ($categories as $category => $categoryResults) {
            $this->line('');
            $this->line("<comment>{$category}:</comment>");

            foreach ($categoryResults as $result) {
                $icon = match ($result['type']) {
                    'success' => '<info>✓</info>',
                    'warning' => '<comment>⚠</comment>',
                    'error' => '<error>✗</error>',
                    'info' => '<comment>ℹ</comment>',
                    default => '•'
                };

                $this->line("  {$icon} {$result['message']}");
            }
        }

        // Summary
        $successCount = count(array_filter($results, fn($r) => $r['type'] === 'success'));
        $warningCount = count(array_filter($results, fn($r) => $r['type'] === 'warning'));
        $errorCount = count(array_filter($results, fn($r) => $r['type'] === 'error'));

        $this->line('');
        $this->table(['Status', 'Count'], [
            ['✅ Passed', $successCount],
            ['⚠️  Warnings', $warningCount],
            ['❌ Errors', $errorCount]
        ]);
    }
}
