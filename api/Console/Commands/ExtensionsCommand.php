<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Helpers\{ExtensionsManager, Utils};
use Glueful\Extensions;

/**
 * Extensions Management Command
 *
 * Provides command-line tools for managing API extensions:
 * - List installed extensions
 * - Enable/disable extensions
 * - Create new extensions
 * - Get extension information
 * - Install extensions from archives or URLs
 * - Validate extension structure
 *
 * @package Glueful\Console\Commands
 */
class ExtensionsCommand extends Command
{
    /**
     * The name of the command
     */
    protected string $name = 'extensions';

    /**
     * The description of the command
     */
    protected string $description = 'Manage API extensions';

    /**
     * Get the command name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
    /**
     * Get Command Description
     *
     * Provides command summary:
     * - Shows in command lists
     * - Single line description
     * - Explains primary purpose
     *
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * The command syntax
     */
    protected string $syntax = 'extensions [action] [options]';

    /**
     * Command options
     */
    protected array $options = [
        'list'              => 'List all installed extensions',
        'info'              => 'Show detailed information about an extension',
        'enable'            => 'Enable an extension',
        'disable'           => 'Disable an extension',
        'create'            => 'Create a new extension scaffold',
        'template'          => 'Create an extension from a specific template type',
        'install'           => 'Install extension from URL or archive file',
        'validate'          => 'Validate an extension structure and dependencies',
        'namespaces'        => 'Show all registered extension namespaces',
        'delete'            => 'Delete an extension completely',
        'validate-config'   => 'Validate extensions.json configuration',
        'benchmark'         => 'Run performance benchmarks for extension loading',
        'debug'             => 'Show debug information about extension system'
    ];

    /**
     * Execute the command
     *
     * @param array $args Command arguments
     * @param array $options Command options
     * @return int Exit code
     */
    public function execute(array $args = [], array $options = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $action = $args[0];
        $extensionName = $args[1] ?? '';

        if (!array_key_exists($action, $this->options)) {
            $this->error("Unknown action: $action");
            $this->info($this->getHelp());
            return Command::FAILURE;
        }

        try {
            switch ($action) {
                case 'list':
                    $showAutoload = in_array('--show-autoload', $args) || isset($options['show-autoload']);
                    $this->listExtensions($showAutoload);
                    break;

                case 'info':
                    if (empty($extensionName)) {
                        $this->error('Extension name is required for info action');
                        return Command::INVALID;
                    }
                    $this->showExtensionInfo($extensionName);
                    break;

                case 'enable':
                    if (empty($extensionName)) {
                        $this->error('Extension name is required for enable action');
                        return Command::INVALID;
                    }
                    $this->enableExtension($extensionName);
                    break;

                case 'disable':
                    if (empty($extensionName)) {
                        $this->error('Extension name is required for disable action');
                        return Command::INVALID;
                    }
                    $this->disableExtension($extensionName);
                    break;

                case 'create':
                    if (empty($extensionName)) {
                        $this->error('Extension name is required for create action');
                        return Command::INVALID;
                    }
                    $this->createExtension($extensionName);
                    break;

                case 'install':
                    if (empty($extensionName)) {
                        $this->error('Source path or URL is required for install action');
                        return Command::INVALID;
                    }
                    $targetName = $args[2] ?? '';
                    $this->installExtension($extensionName, $targetName);
                    break;

                case 'validate':
                    if (empty($extensionName)) {
                        $this->error('Extension name is required for validate action');
                        return Command::INVALID;
                    }
                    $this->validateExtension($extensionName);
                    break;

                case 'template':
                    $templateType = $extensionName;
                    $extensionName = $args[2] ?? '';
                    $this->generateTemplate($templateType, $extensionName);
                    break;

                case 'namespaces':
                    $this->showNamespaces();
                    break;

                case 'delete':
                    if (empty($extensionName)) {
                        $this->error('Extension name is required for delete action');
                        return Command::INVALID;
                    }
                    $this->deleteExtension($extensionName);
                    break;

                case 'validate-config':
                    $this->validateConfig();
                    break;

                case 'benchmark':
                    $this->runBenchmark();
                    break;

                case 'debug':
                    $this->showDebugInfo();
                    break;

                default:
                    $this->error("Action not implemented: $action");
                    return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Command failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }


    /**
     * List all installed extensions
     *
     * @param bool $showAutoload Whether to show autoload information
     * @return void
     */
    protected function listExtensions(bool $showAutoload = false): void
    {
        $this->info('Installed Extensions');
        $this->line('===================');

        $extensions = ExtensionsManager::getLoadedExtensions();

        if (empty($extensions)) {
            $this->warning('No extensions found');
            return;
        }

        // Create a table header
        $this->line(
            Utils::padColumn('Name', 25) .
            Utils::padColumn('Status', 12) .
            Utils::padColumn('Type', 10) .
            Utils::padColumn('Description', 40)
        );
        $this->line(str_repeat('-', 87));

        // Display each extension
        foreach ($extensions as $extension) {
            $name = $extension['name'];
            $metadata = $extension['metadata'];

            $isEnabled = $metadata['enabled'] ?? false;
            $status = $isEnabled ? $this->colorText('Enabled', 'green') : $this->colorText('Disabled', 'yellow');

            // Determine if core or optional
            $type = ($metadata['type'] ?? 'optional') === 'core'
                ? $this->colorText('Core', 'cyan')
                : 'Optional';

            // Get description from metadata
            $description = $metadata['description'] ?? '';

            // Truncate description if too long
            if (strlen($description) > 40) {
                $description = substr($description, 0, 37) . '...';
            }

            $this->line(
                Utils::padColumn($name, 25) .
                Utils::padColumn($status, 12) .
                Utils::padColumn($type, 10) .
                Utils::padColumn($description, 40)
            );
        }

        // Count extensions by type and status
        $totalExtensions = count($extensions);
        $enabledCount = count(array_filter($extensions, fn($ext) => $ext['metadata']['enabled'] ?? false));
        $coreCount = count(array_filter($extensions, fn($ext) => ($ext['metadata']['type'] ?? 'optional') === 'core'));
        $optionalCount = $totalExtensions - $coreCount;

        $this->line(
            "\nTotal: $totalExtensions extensions " .
            "($enabledCount enabled, $coreCount core, $optionalCount optional)"
        );

        // Show autoload information if requested
        if ($showAutoload) {
            $this->line("\nAutoload Information:");
            $this->line(str_repeat('=', 50));

            foreach ($extensions as $extension) {
                $name = $extension['name'];
                $metadata = $extension['metadata'];
                $autoload = $metadata['autoload']['psr-4'] ?? [];

                if (empty($autoload)) {
                    continue;
                }

                $status = ($metadata['enabled'] ?? false)
                    ? $this->colorText('(enabled)', 'green')
                    : $this->colorText('(disabled)', 'yellow');

                $this->info("\n$name $status");
                foreach ($autoload as $namespace => $path) {
                    $this->line("  $namespace → $path");
                }
            }
        }
    }

    /**
     * Show detailed information about an extension
     *
     * @param string $extensionName Extension name
     * @return void
     */
    protected function showExtensionInfo(string $extensionName): void
    {
        $extension = $this->findExtension($extensionName);

        if (!$extension) {
            $this->error("Extension '$extensionName' not found");
            return;
        }

        $name = $extension['name'];
        $metadata = $extension['metadata'];

        // Check if this is a core extension
        $isCoreExtension = ($metadata['type'] ?? 'optional') === 'core';
        $isEnabled = $metadata['enabled'] ?? false;

        $this->info("Extension: " . $name . ($isCoreExtension ? " " . $this->colorText("[CORE]", "cyan") : ""));
        $this->line(str_repeat('=', 50));

        // Get metadata from the extensions.json
        $description = $metadata['description'] ?? 'No description available';
        $version = $metadata['version'] ?? '1.0.0';
        $author = $metadata['author'] ?? 'Unknown';
        $license = $metadata['license'] ?? 'Unknown';
        $dependencies = $metadata['dependencies']['extensions'] ?? [];
        $installPath = $metadata['installPath'] ?? "extensions/$name";

        // Format status with color
        $status = $isEnabled ? $this->colorText('Enabled', 'green') : $this->colorText('Disabled', 'yellow');
        $type = $isCoreExtension ? $this->colorText('Core', 'cyan') : 'Optional';

        // Display information
        $this->line("Description: $description");
        $this->line("Version:     $version");
        $this->line("Author:      $author");
        $this->line("License:     $license");
        $this->line("Status:      $status");
        $this->line("Type:        $type");
        $this->line("Path:        $installPath");

        // Display extension dependencies
        if (!empty($dependencies)) {
            $this->info("\nDependencies:");
            foreach ($dependencies as $dependency) {
                // Check if dependency is enabled by finding it in the extensions list
                $allExtensions = ExtensionsManager::getLoadedExtensions();
                $dependencyEnabled = false;
                foreach ($allExtensions as $ext) {
                    if ($ext['name'] === $dependency && ($ext['metadata']['enabled'] ?? false)) {
                        $dependencyEnabled = true;
                        break;
                    }
                }

                $dependencyStatus = $dependencyEnabled
                    ? $this->colorText('(enabled)', 'green')
                    : $this->colorText('(disabled)', 'red');
                $this->line("- $dependency $dependencyStatus");
            }
        } else {
            $this->line("\nNo dependencies required");
        }

        // Display extensions that depend on this one
        $dependentExtensions = $this->findDependentExtensions($name);
        if (!empty($dependentExtensions)) {
            $this->info("\nExtensions depending on this:");
            foreach ($dependentExtensions as $dependent) {
                $this->line("- $dependent");
            }
            if ($isEnabled) {
                $this->warning("Disabling this extension will affect the extensions listed above.");
            }
        }

        // Display what the extension provides
        $provides = $metadata['provides'] ?? [];
        if (!empty($provides)) {
            $this->info("\nProvides:");
            if (!empty($provides['services'])) {
                $this->line("- Service providers: " . count($provides['services']));
            }
            if (!empty($provides['routes'])) {
                $this->line("- Route files: " . count($provides['routes']));
            }
            if (!empty($provides['middleware'])) {
                $this->line("- Middleware: " . count($provides['middleware']));
            }
            if (!empty($provides['commands'])) {
                $this->line("- Commands: " . count($provides['commands']));
            }
            if (!empty($provides['migrations'])) {
                $this->line("- Migrations: " . count($provides['migrations']));
            }
            if (isset($provides['main'])) {
                $this->line("- Main file: " . basename($provides['main']));
            }
        }

        // Special warning for core extensions that are disabled
        if ($isCoreExtension && !$isEnabled) {
            $this->warning("\n⚠️ WARNING: This is a core extension that is currently disabled!");
            $this->warning("Some system functionality may not be working properly.");
            $this->tip("To enable: php glueful extensions enable $name");
        }
    }

    /**
     * Enable an extension
     *
     * @param string $extensionName Extension name
     * @return void
     */
    protected function enableExtension(string $extensionName): void
    {
        $extension = $this->findExtension($extensionName);

        if (!$extension) {
            $this->error("Extension '$extensionName' not found");
            return;
        }

        $name = $extension['name'];
        $metadata = $extension['metadata'];
        $isEnabled = $metadata['enabled'] ?? false;

        if ($isEnabled) {
            $this->warning("Extension '$name' is already enabled");
            return;
        }

        // Load extensions config
        $configPath = ExtensionsManager::getExtensionsConfigPath();
        $config = json_decode(file_get_contents($configPath), true);

        // Enable the extension
        $config['extensions'][$name]['enabled'] = true;

        // Update the metadata
        $config['metadata']['last_updated'] = date('c');
        $enabledCount = count(array_filter($config['extensions'], fn($ext) => $ext['enabled'] ?? false));
        $config['metadata']['enabled_extensions'] = $enabledCount;

        // Save the updated config
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->success("Extension '$name' has been enabled");
    }

    /**
     * Disable an extension
     *
     * @param string $extensionName Extension name
     * @return void
     */
    protected function disableExtension(string $extensionName): void
    {
        $extension = $this->findExtension($extensionName);

        if (!$extension) {
            $this->error("Extension '$extensionName' not found");
            return;
        }

        $name = $extension['name'];
        $metadata = $extension['metadata'];
        $isEnabled = $metadata['enabled'] ?? false;
        $isCoreExtension = ($metadata['type'] ?? 'optional') === 'core';

        if (!$isEnabled) {
            $this->warning("Extension '$name' is already disabled");
            return;
        }

        if ($isCoreExtension) {
            $this->warning($this->colorText(
                "⚠️ WARNING: '$name' is a core extension and is required for core functionality!",
                'red'
            ));
            $this->warning("Disabling this extension may break essential system features.");

            // Check for dependent extensions
            $dependentExtensions = $this->findDependentExtensions($name);
            if (!empty($dependentExtensions)) {
                $this->warning("The following enabled extensions depend on '$name':");
                foreach ($dependentExtensions as $dependent) {
                    $this->line("  - " . $this->colorText($dependent, 'yellow'));
                }

                if (!$this->confirm("Are you sure you want to disable this extension?")) {
                    $this->info("Operation cancelled");
                    return;
                }
            }

            // Ask for confirmation
            if (!$this->confirm("Are you absolutely sure you want to disable this core extension?")) {
                $this->info("Operation cancelled");
                return;
            }

            $this->warning($this->colorText("Proceeding with disabling core extension '$name'...", 'red'));
        } else {
            // Check for dependent extensions for non-core extensions too
            $dependentExtensions = $this->findDependentExtensions($name);
            if (!empty($dependentExtensions)) {
                $this->warning("The following enabled extensions depend on '$name':");
                foreach ($dependentExtensions as $dependent) {
                    $this->line("  - " . $this->colorText($dependent, 'yellow'));
                }

                if (!$this->confirm("Are you sure you want to disable this extension?")) {
                    $this->info("Operation cancelled");
                    return;
                }
            }
        }

        // Load extensions config
        $configPath = ExtensionsManager::getExtensionsConfigPath();
        $config = json_decode(file_get_contents($configPath), true);

        // Disable the extension
        $config['extensions'][$name]['enabled'] = false;

        // Update the metadata
        $config['metadata']['last_updated'] = date('c');
        $enabledCount = count(array_filter($config['extensions'], fn($ext) => $ext['enabled'] ?? false));
        $config['metadata']['enabled_extensions'] = $enabledCount;

        // Save the updated config
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($isCoreExtension) {
            $this->success("Core extension '$name' has been disabled");
            $this->warning($this->colorText(
                "⚠️ Caution: You have disabled a core extension. Some system functionality may not work properly.",
                'red'
            ));
        } else {
            $this->success("Extension '$name' has been disabled");
        }
    }

    /**
     * Create a new extension scaffold
     *
     * @param string $extensionName Extension name
     * @return void
     */
    protected function createExtension(string $extensionName): void
    {
        // Use provided name or prompt user
        $name = $extensionName;
        if (!$name) {
            $name = $this->promptInput('Enter extension name (PascalCase):');
        }

        $this->info("Creating extension: $name");

        // Interactive prompts for additional settings
        $description = $this->promptInput('Enter extension description:');
        $author = $this->promptInput('Enter author name:');
        $email = $this->promptInput('Enter author email (optional):', false);

        $extensionType = $this->choicePrompt(
            'Select extension type:',
            [
                'optional' => 'Optional Extension (can be enabled/disabled)',
                'core' => 'Core Extension (always enabled)'
            ],
            'optional'
        );

        $templateType = $this->choicePrompt(
            'Select template type:',
            [
                'Basic' => 'Basic Extension (minimal structure)',
                'Auth' => 'Authentication Provider',
                'Payment' => 'Payment Gateway'
            ],
            'Basic'
        );

        $features = $this->multiChoice(
            'Select features to include:',
            [
                'routes' => 'API Routes',
                'config' => 'Configuration File',
                'migrations' => 'Database Migrations',
                'admin_ui' => 'Admin UI Components'
            ]
        );

        // Additional template data for customization
        $templateData = [
            'description' => $description,
            'author' => $author,
            'email' => $email,
            'features' => $features
        ];

        // Use ExtensionsManager to create the extension
        $result = ExtensionsManager::createExtension(
            $name,
            $extensionType,
            $templateType,
            $templateData
        );

        if (!$result['success']) {
            $this->error($result['message']);
            return;
        }

        $this->success($result['message']);

        // Display created files
        if (isset($result['data']['files']) && !empty($result['data']['files'])) {
            $this->info("Files created:");
            foreach ($result['data']['files'] as $file) {
                $this->line("- $file");
            }
        }

        // Offer to enable the extension
        if ($this->confirm("Would you like to enable this extension now?")) {
            $this->enableExtension($name);
        } else {
            $this->tip("To enable your extension later, run: php glueful extensions enable $name");
        }
    }

    /**
     * Install an extension from URL or archive file
     *
     * @param string $source Source URL or file path
     * @param string $targetName Target extension name
     * @return void
     */
    protected function installExtension(string $source, string $targetName = ''): void
    {
        $this->info("Installing extension from: $source");
        $this->line("Target extension name: " . ($targetName ?: 'auto-generated from source'));

        // Use ExtensionsManager to handle the installation
        $result = ExtensionsManager::installExtension($source, $targetName);

        if (!$result['success']) {
            $this->error($result['message']);
            return;
        }

        // Get the extension name (could be auto-generated if none was provided)
        $installedName = $result['name'] ?? $targetName;
        $this->success("Extension installed at: " . config('app.paths.project_extensions') . $installedName);

        // If the extension was successfully installed, offer to enable it
        if ($this->confirm("Would you like to enable this extension now?")) {
            $this->enableExtension($installedName);
        } else {
            $this->tip("To enable your extension later, run: php glueful extensions enable $installedName");
        }

        $this->tip("To validate your extension structure, run: php glueful extensions validate $installedName");
    }

    /**
     * Sanitize a string to a valid extension name
     *
     * @param string $name Input name
     * @return string Sanitized extension name
     */
    protected function sanitizeExtensionName(string $name): string
    {
        // Remove non-alphanumeric characters
        $name = preg_replace('/[^a-zA-Z0-9]/', '', $name);

        // Ensure it starts with uppercase letter
        $name = ucfirst($name);

        return $name;
    }

    /**
     * Check if an extension name is valid
     *
     * @param string $name Extension name
     * @return bool True if valid
     */
    protected function isValidExtensionName(string $name): bool
    {
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name) === 1;
    }

    /**
     * Show a confirmation prompt
     *
     * @param string $message Prompt message
     * @return bool User response
     */
    protected function confirm(string $message): bool
    {
        echo $message . " [y/N] ";
        $response = strtolower(trim(fgets(STDIN)));
        return $response === 'y' || $response === 'yes';
    }

    /**
     * Validate an extension structure and dependencies
     *
     * @param string $extensionName Extension name
     * @return void
     */
    protected function validateExtension(string $extensionName): void
    {
        $this->info("Validating extension: $extensionName");
        $this->line(str_repeat('=', 50));

        // Use ExtensionsManager to handle validation
        $result = ExtensionsManager::validateExtension($extensionName);

        if (!$result['success']) {
            $this->error($result['message']);

            // Display structure validation issues
            if (!empty($result['structureValidation']['issues'])) {
                $this->info("\nStructure Issues:");
                foreach ($result['structureValidation']['issues'] as $issue) {
                    $this->warning("✗ $issue");
                }
            }

            // Display structure validation warnings
            if (!empty($result['structureValidation']['warnings'])) {
                $this->info("\nStructure Warnings:");
                foreach ($result['structureValidation']['warnings'] as $warning) {
                    $this->line("- $warning");
                }
            }

            // Display dependency validation issues
            if (!empty($result['dependencyValidation']['issues'])) {
                $this->info("\nDependency Issues:");
                foreach ($result['dependencyValidation']['issues'] as $issue) {
                    $this->warning("✗ $issue");
                }
            }

            // Show dependencies
            if (!empty($result['dependencyValidation']['dependencies'])) {
                $this->info("\nRequired Dependencies:");
                foreach ($result['dependencyValidation']['dependencies'] as $dependency) {
                    $this->line("- $dependency");
                }
            }

            $this->tip("Fix these issues for better compatibility with the system.");
            return;
        }

        $this->success("Extension '$extensionName' passed validation");

        // Display any warnings
        if (!empty($result['structureValidation']['warnings'])) {
            $this->info("\nNon-critical warnings:");
            foreach ($result['structureValidation']['warnings'] as $warning) {
                $this->line("- $warning");
            }
        }

        // Show dependencies
        if (!empty($result['dependencyValidation']['dependencies'])) {
            $this->info("\nRequired Dependencies:");
            foreach ($result['dependencyValidation']['dependencies'] as $dependency) {
                $this->line("- $dependency");
            }
            $this->success("All dependencies are met");
        } else {
            $this->line("\n✓ No dependencies required");
        }

        // Show dependent extensions
        if (!empty($result['dependencyValidation']['dependentExtensions'])) {
            $this->info("\nExtensions that depend on $extensionName:");
            foreach ($result['dependencyValidation']['dependentExtensions'] as $dependent) {
                $this->line("- $dependent");
            }
            $this->warning("Disabling this extension may break the extensions listed above.");
        } else {
            $this->line("\n✓ No enabled extensions depend on $extensionName");
        }
    }

    /**
     * Get extension metadata from docblock
     *
     * @param \ReflectionClass $reflection Class reflection
     * @param string $tag Tag name to find
     * @param string|null $default Default value if tag not found
     * @return string Metadata value
     */
    protected function getExtensionMetadata(\ReflectionClass $reflection, string $tag, ?string $default = null): string
    {
        $docComment = $reflection->getDocComment();

        if ($docComment) {
            preg_match('/@' . $tag . '\s+(.*)\s*$/m', $docComment, $matches);
            return $matches[1] ?? ($default ?? '');
        }

        return $default ?? '';
    }

    /**
     * Find extension by name
     *
     * @param string $extensionName Extension name
     * @return array|null Extension data or null if not found
     */
    protected function findExtension(string $extensionName): ?array
    {
        $extensions = ExtensionsManager::getLoadedExtensions();

        foreach ($extensions as $extension) {
            if ($extension['name'] === $extensionName) {
                return $extension;
            }
        }

        return null;
    }


    /**
     * Generate README.md content
     *
     * @param string $extensionName Extension name
     * @return string Generated README.md content
     */
    protected function generateReadme(string $extensionName): string
    {
        return "# $extensionName Extension

This is a Glueful API extension.

## Features

- Add your features here

## Installation

1. Copy this directory to your `extensions/` folder
2. Enable the extension using:
   ```
   php glueful extensions enable $extensionName
   ```

## Usage

Add usage instructions here.

## Configuration

Add configuration instructions if needed.

## License

Add license information here.
";
    }

    /**
     * Get Command Help
     *
     * Provides detailed usage instructions:
     * - Shows command syntax
     * - Lists all available actions
     * - Includes usage examples
     * - Documents parameters
     *
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Extensions Management Command
============================

Manages API extensions - list, enable, disable, create and get information about extensions.

Usage:
  extensions list
  extensions info <n>
  extensions enable <n>
  extensions disable <n>
  extensions create <n>
  extensions install <source> <target>
  extensions validate <n>
  extensions namespaces
  extensions [action] [options]

Actions:
  list                List all installed extensions
  info <n>            Show detailed information about an extension
  enable <n>          Enable an extension
  disable <n>         Disable an extension
  create <n>          Create a new extension scaffold
  install <source> <target> Install extension from URL or archive file
  validate <n>        Validate an extension structure and dependencies
  namespaces          Show all registered extension namespaces
  validate-config     Validate extensions.json configuration
  benchmark           Run performance benchmarks for extension loading
  debug               Show debug information about extension system

Arguments:
  <n>                 Extension name (in PascalCase, e.g. MyExtension)
  <source>            Source URL or file path for install action
  <target>            Target extension name for install action

Options:
  -h, --help          Show this help message

Examples:
  php glueful extensions list
  php glueful extensions list --show-autoload
  php glueful extensions info MyExtension
  php glueful extensions enable PaymentGateway
  php glueful extensions disable Analytics
  php glueful extensions create MyExtension
  php glueful extensions install https://example.com/extension.zip MyExtension
  php glueful extensions validate MyExtension
  php glueful extensions namespaces
  php glueful extensions validate-config
  php glueful extensions benchmark
  php glueful extensions debug
HELP;
    }

    /**
     * Show registered extension namespaces
     *
     * @return void
     */
    protected function showNamespaces(): void
    {
        $this->info('Registered Extension Namespaces');
        $this->line('==============================');

        $extensions = ExtensionsManager::getLoadedExtensions();

        if (empty($extensions)) {
            $this->warning('No extension namespaces registered');
            return;
        }

        foreach ($extensions as $extension) {
            $name = $extension['name'];
            $metadata = $extension['metadata'];
            $autoload = $metadata['autoload']['psr-4'] ?? [];

            if (empty($autoload)) {
                continue;
            }

            $this->info($name);

            foreach ($autoload as $namespace => $path) {
                $status = ($metadata['enabled'] ?? false)
                    ? $this->colorText('(enabled)', 'green')
                    : $this->colorText('(disabled)', 'yellow');
                $this->line("  → $namespace → $path $status");
            }

            $this->line('');
        }

        $this->line("\nTip: These namespaces are pre-computed in extensions.json for fast loading.");
        $this->line("No manual changes to composer.json are needed for extension autoloading.");
    }

    /**
     * Show a tip to the user
     *
     * @param string $message Tip message
     * @return void
     */
    protected function tip(string $message): void
    {
        $this->line($this->colorText("💡 $message", 'cyan'));
    }

    /**
     * Delete an extension
     *
     * @param string $extensionName Extension name
     * @return void
     */
    protected function deleteExtension(string $extensionName): void
    {
        $extension = $this->findExtension($extensionName);

        if (!$extension) {
            $this->error("Extension '$extensionName' not found");
            return;
        }

        $name = $extension['name'];
        $metadata = $extension['metadata'];
        $isEnabled = $metadata['enabled'] ?? false;
        $isCoreExtension = ($metadata['type'] ?? 'optional') === 'core';
        $extensionDir = $metadata['installPath'] ?? "extensions/$name";

        // Show warnings for enabled or core extensions
        if ($isEnabled) {
            $this->warning("Extension '$name' is currently enabled.");
            $this->line("You should disable it first with: php glueful extensions disable $name");

            if (!$this->confirm("Do you want to continue anyway?")) {
                $this->info("Operation cancelled");
                return;
            }
        }

        if ($isCoreExtension) {
            $this->warning($this->colorText(
                "⚠️ WARNING: '$name' is a core extension and is required for core functionality!",
                'red'
            ));
            $this->warning("Deleting this extension will break essential system features.");
            $this->warning("Only proceed if you're absolutely sure what you're doing.");

            if (!$this->confirm("Are you absolutely sure you want to delete this core extension?")) {
                $this->info("Operation cancelled");
                return;
            }

            $this->warning($this->colorText("Proceeding with deleting core extension '$name'...", 'red'));
        }

        // Check for dependent extensions
        $dependentExtensions = $this->findDependentExtensions($name);
        if (!empty($dependentExtensions)) {
            $this->warning("The following enabled extensions depend on '$name':");
            foreach ($dependentExtensions as $dependent) {
                $this->line("  - " . $this->colorText($dependent, 'yellow'));
            }
            $this->warning("Deleting this extension will break these dependent extensions.");

            if (!$this->confirm("Are you sure you want to delete this extension?")) {
                $this->info("Operation cancelled");
                return;
            }
        }

        // Final confirmation before deletion
        $this->info("This will completely remove the extension directory:");
        $this->line("  " . $extensionDir);

        if (!$this->confirm("Are you sure you want to permanently delete this extension?")) {
            $this->info("Operation cancelled");
            return;
        }

        // Remove the extension directory
        if (is_dir($extensionDir)) {
            $this->deleteDirectory($extensionDir);
        }

        // Remove from extensions.json
        $configPath = ExtensionsManager::getExtensionsConfigPath();
        $config = json_decode(file_get_contents($configPath), true);

        unset($config['extensions'][$name]);

        // Update metadata
        $config['metadata']['last_updated'] = date('c');
        $config['metadata']['total_extensions'] = count($config['extensions']);
        $enabledCount = count(array_filter($config['extensions'], fn($ext) => $ext['enabled'] ?? false));
        $config['metadata']['enabled_extensions'] = $enabledCount;

        // Update load order if extension was in it
        if (isset($config['global_config']['load_order'])) {
            $config['global_config']['load_order'] = array_values(
                array_filter($config['global_config']['load_order'], fn($ext) => $ext !== $name)
            );
        }

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->success("Extension '$name' has been permanently deleted");

        // Suggest possible next steps
        if (!empty($dependentExtensions)) {
            $this->tip("You may need to update or remove the dependent extensions that relied on this extension.");
        }
    }

    /**
     * Generate an extension from a specific template type
     *
     * @param string|null $templateType Template type to use
     * @param string|null $extensionName Extension name
     * @return void
     */
    protected function generateTemplate(?string $templateType = null, ?string $extensionName = null): void
    {
        if (!$templateType || !$extensionName) {
            $templateType = $this->choicePrompt(
                'Select template type:',
                [
                    'auth' => 'Authentication Provider',
                    'payment' => 'Payment Gateway',
                    'admin' => 'Admin Dashboard Widget',
                    'data' => 'Data Import/Export Tool'
                ]
            );

            $extensionName = $this->promptInput('Enter extension name (PascalCase):');
        }

        // Sanitize and validate extension name
        $extensionName = ExtensionsManager::sanitizeExtensionName($extensionName);

        if (!ExtensionsManager::isValidExtensionName($extensionName)) {
            $this->error("Invalid extension name: $extensionName");
            $this->line("Extension names must be in PascalCase format (e.g. MyExtension).");
            return;
        }

        $this->info("Generating $templateType extension: $extensionName");

        // Additional data for template generation
        $templateData = [
            'description' => $this->promptInput('Enter extension description:'),
            'author' => $this->promptInput('Enter author name:'),
            'email' => $this->promptInput('Enter author email (optional):', false)
        ];

        // Use ExtensionsManager to create an extension from the template
        $result = ExtensionsManager::createExtension(
            $extensionName,
            'optional',
            $templateType,
            $templateData
        );

        if (!$result['success']) {
            $this->error($result['message']);
            return;
        }

        $this->success($result['message']);

        // Display created files if available
        if (isset($result['data']['files']) && !empty($result['data']['files'])) {
            $this->info("Files created:");
            foreach ($result['data']['files'] as $file) {
                $this->line("- $file");
            }
        }

        // Offer to enable the extension
        if ($this->confirm("Would you like to enable this extension now?")) {
            $this->enableExtension($extensionName);
        } else {
            $this->tip("To enable your extension later, run: php glueful extensions enable $extensionName");
        }
    }

    /**
     * Display a choice prompt to the user
     *
     * @param string $message The prompt message
     * @param array $choices An array of choices (key => description)
     * @param string|null $default Default choice key
     * @return string The selected choice key
     */
    protected function choicePrompt(string $message, array $choices, ?string $default = null): string
    {
        $this->line($message);
        $i = 1;
        $options = [];

        foreach ($choices as $key => $description) {
            $this->line("  [$i] $description" . ($key === $default ? ' (default)' : ''));
            $options[$i] = $key;
            $i++;
        }

        $this->line('');
        $selection = trim($this->promptInput("Enter your choice (1-" . ($i - 1) . "):", false));

        if (empty($selection) && $default !== null) {
            return $default;
        }

        if (!is_numeric($selection) || !isset($options[(int)$selection])) {
            $this->error("Invalid selection.");
            return $this->choicePrompt($message, $choices, $default);
        }

        return $options[(int)$selection];
    }

    /**
     * Display a prompt to the user
     *
     * @param string $message The prompt message
     * @param bool $required Whether the input is required
     * @return string The user input
     */
    protected function promptInput(string $message, bool $required = true): string
    {
        echo "$message ";
        $input = trim(fgets(STDIN));

        if ($required && empty($input)) {
            $this->error("Input is required.");
            return $this->promptInput($message, $required);
        }

        return $input;
    }

    /**
     * Display a multi-choice selection prompt to the user
     *
     * @param string $message The prompt message
     * @param array $choices An array of choices (key => description)
     * @return array The selected choice keys
     */
    protected function multiChoice(string $message, array $choices): array
    {
        $this->line($message);
        $this->line("(Select multiple by entering numbers separated by commas, e.g. 1,3,4)");

        $i = 1;
        $options = [];

        foreach ($choices as $key => $description) {
            $this->line("  [$i] $description");
            $options[$i] = $key;
            $i++;
        }

        $this->line("");
        $selection = trim($this->promptInput("Enter your choices (1-" . ($i - 1) . "):", false));

        if (empty($selection)) {
            return [];
        }

        $selected = [];
        $parts = explode(',', $selection);

        foreach ($parts as $part) {
            $part = trim($part);
            if (is_numeric($part) && isset($options[(int)$part])) {
                $selected[] = $options[(int)$part];
            }
        }

        return $selected;
    }

    /**
     * Find extensions that depend on the given extension
     *
     * @param string $extensionName Extension name to check dependencies for
     * @return array List of dependent extension names
     */
    protected function findDependentExtensions(string $extensionName): array
    {
        $extensions = ExtensionsManager::getLoadedExtensions();
        $dependents = [];

        foreach ($extensions as $extension) {
            $metadata = $extension['metadata'];
            $dependencies = $metadata['dependencies']['extensions'] ?? [];

            if (in_array($extensionName, $dependencies) && ($metadata['enabled'] ?? false)) {
                $dependents[] = $extension['name'];
            }
        }

        return $dependents;
    }

    /**
     * Recursively delete a directory and its contents
     *
     * @param string $dir Directory path to delete
     * @return bool True on success
     */
    protected function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Validate extensions.json configuration
     *
     * @return void
     */
    protected function validateConfig(): void
    {
        $this->info('Validating Extensions Configuration');
        $this->line('==================================');

        $configPath = ExtensionsManager::getExtensionsConfigPath();

        // Check if file exists
        if (!file_exists($configPath)) {
            $this->error("Configuration file not found: $configPath");
            return;
        }

        // Read and validate JSON
        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON: " . json_last_error_msg());
            return;
        }

        $errors = [];
        $warnings = [];

        // Validate schema version
        $schemaVersion = $config['schema_version'] ?? null;
        if ($schemaVersion !== '2.0') {
            $errors[] = "Invalid schema version: $schemaVersion (expected 2.0)";
        }

        // Validate extensions section
        if (!isset($config['extensions']) || !is_array($config['extensions'])) {
            $errors[] = "Missing or invalid 'extensions' section";
        } else {
            foreach ($config['extensions'] as $name => $extension) {
                $this->validateExtensionConfig($name, $extension, $errors, $warnings);
            }
        }

        // Validate metadata
        if (!isset($config['metadata'])) {
            $warnings[] = "Missing metadata section";
        }

        // Validate global config
        if (!isset($config['global_config'])) {
            $warnings[] = "Missing global_config section";
        }

        // Display results
        if (!empty($errors)) {
            $this->error("\nValidation Errors:");
            foreach ($errors as $error) {
                $this->line("  ✗ $error");
            }
        }

        if (!empty($warnings)) {
            $this->warning("\nValidation Warnings:");
            foreach ($warnings as $warning) {
                $this->line("  ⚠ $warning");
            }
        }

        if (empty($errors) && empty($warnings)) {
            $this->success("✓ Configuration is valid");
        } elseif (empty($errors)) {
            $this->success("✓ Configuration is valid (with warnings)");
        } else {
            $this->error("✗ Configuration validation failed");
        }
    }

    /**
     * Validate individual extension configuration
     *
     * @param string $name Extension name
     * @param array $extension Extension config
     * @param array $errors Reference to errors array
     * @param array $warnings Reference to warnings array
     * @return void
     */
    protected function validateExtensionConfig(string $name, array $extension, array &$errors, array &$warnings): void
    {
        // Required fields
        $required = ['version', 'enabled', 'type', 'description', 'author', 'autoload'];
        foreach ($required as $field) {
            if (!isset($extension[$field])) {
                $errors[] = "Extension '$name': Missing required field '$field'";
            }
        }

        // Validate type
        if (isset($extension['type']) && !in_array($extension['type'], ['core', 'optional'])) {
            $errors[] = "Extension '$name': Invalid type '{$extension['type']}' (must be 'core' or 'optional')";
        }

        // Validate enabled field
        if (isset($extension['enabled']) && !is_bool($extension['enabled'])) {
            $errors[] = "Extension '$name': 'enabled' must be boolean";
        }

        // Validate autoload structure
        if (isset($extension['autoload'])) {
            if (!isset($extension['autoload']['psr-4']) || !is_array($extension['autoload']['psr-4'])) {
                $errors[] = "Extension '$name': Missing or invalid 'autoload.psr-4' section";
            }
        }

        // Check if extension directory exists
        $installPath = $extension['installPath'] ?? "extensions/$name";
        if (!is_dir($installPath)) {
            $warnings[] = "Extension '$name': Directory not found at '$installPath'";
        }
    }

    /**
     * Run performance benchmarks for extension loading
     *
     * @return void
     */
    protected function runBenchmark(): void
    {
        $this->info('Extension Loading Performance Benchmark');
        $this->line('======================================');

        $iterations = 100;

        // Benchmark 1: Loading extensions.json
        $this->line("Testing extensions.json loading ($iterations iterations)...");
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            ExtensionsManager::loadExtensionsConfig();
        }
        $jsonTime = (microtime(true) - $start) * 1000;

        // Benchmark 2: Getting loaded extensions
        $this->line("Testing getLoadedExtensions() ($iterations iterations)...");
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            ExtensionsManager::getLoadedExtensions();
        }
        $loadedTime = (microtime(true) - $start) * 1000;

        // Benchmark 3: Memory usage
        $memoryBefore = memory_get_usage();
        ExtensionsManager::loadExtensionsConfig();
        $extensions = ExtensionsManager::getLoadedExtensions();
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Display results
        $this->line("\nResults:");
        $this->line("========");
        $jsonAvg = $jsonTime / $iterations;
        $loadAvg = $loadedTime / $iterations;
        $this->success(sprintf("JSON Loading: %.2fms average (%.2fms total)", $jsonAvg, $jsonTime));
        $this->success(sprintf("Extension Loading: %.2fms average (%.2fms total)", $loadAvg, $loadedTime));
        $this->success(sprintf("Memory Usage: %s", $this->formatBytes($memoryUsed)));
        $this->success(sprintf("Extensions Found: %d", count($extensions)));

        // Performance comparison note
        $this->line("\nPerformance Notes:");
        $avgTime = ($jsonTime + $loadedTime) / (2 * $iterations);
        if ($avgTime < 1) {
            $this->success("✓ Excellent performance (< 1ms average)");
        } elseif ($avgTime < 5) {
            $this->info("✓ Good performance (< 5ms average)");
        } else {
            $this->warning("⚠ Consider optimization (> 5ms average)");
        }
    }

    /**
     * Show debug information about the extension system
     *
     * @return void
     */
    protected function showDebugInfo(): void
    {
        $this->info('Extension System Debug Information');
        $this->line('=================================');

        // 1. Configuration Details
        $this->info("\n1. Configuration:");
        $configPath = ExtensionsManager::getExtensionsConfigPath();
        $this->line("   Config Path: $configPath");
        $this->line("   Config Exists: " . (file_exists($configPath) ? 'Yes' : 'No'));

        if (file_exists($configPath)) {
            $size = filesize($configPath);
            $this->line("   Config Size: " . $this->formatBytes($size));
            $modified = date('Y-m-d H:i:s', filemtime($configPath));
            $this->line("   Last Modified: $modified");
        }

        // 2. Extensions Summary
        $extensions = ExtensionsManager::getLoadedExtensions();
        $this->info("\n2. Extensions Summary:");
        $this->line("   Total Extensions: " . count($extensions));

        $enabled = array_filter($extensions, fn($ext) => $ext['metadata']['enabled'] ?? false);
        $this->line("   Enabled: " . count($enabled));

        $core = array_filter($extensions, fn($ext) => ($ext['metadata']['type'] ?? 'optional') === 'core');
        $this->line("   Core Extensions: " . count($core));

        // 3. Loaded Namespaces
        $this->info("\n3. Loaded Namespaces:");
        foreach ($enabled as $extension) {
            $name = $extension['name'];
            $autoload = $extension['metadata']['autoload']['psr-4'] ?? [];

            if (!empty($autoload)) {
                $this->line("   $name:");
                foreach ($autoload as $namespace => $path) {
                    $exists = is_dir($path) ? '✓' : '✗';
                    $this->line("     $exists $namespace → $path");
                }
            }
        }

        // 4. Performance Metrics
        $this->info("\n4. Performance Metrics:");
        $start = microtime(true);
        ExtensionsManager::loadExtensionsConfig();
        $configTime = (microtime(true) - $start) * 1000;

        $start = microtime(true);
        ExtensionsManager::getLoadedExtensions();
        $loadTime = (microtime(true) - $start) * 1000;

        $this->line(sprintf("   Config Load Time: %.2fms", $configTime));
        $this->line(sprintf("   Extensions Load Time: %.2fms", $loadTime));
        $this->line(sprintf("   Total Time: %.2fms", $configTime + $loadTime));

        // 5. Configuration Validation
        $this->info("\n5. Configuration Validation:");
        $config = ExtensionsManager::loadExtensionsConfig();

        $schemaVersion = $config['schema_version'] ?? 'unknown';
        $this->line("   Schema Version: $schemaVersion");

        $hasMetadata = isset($config['metadata']) ? '✓' : '✗';
        $this->line("   Has Metadata: $hasMetadata");

        $hasGlobalConfig = isset($config['global_config']) ? '✓' : '✗';
        $this->line("   Has Global Config: $hasGlobalConfig");

        // 6. Dependency Tree
        $this->info("\n6. Dependency Tree:");
        foreach ($enabled as $extension) {
            $name = $extension['name'];
            $dependencies = $extension['metadata']['dependencies']['extensions'] ?? [];

            if (!empty($dependencies)) {
                $this->line("   $name depends on:");
                foreach ($dependencies as $dep) {
                    $depEnabled = false;
                    foreach ($enabled as $ext) {
                        if ($ext['name'] === $dep) {
                            $depEnabled = true;
                            break;
                        }
                    }
                    $status = $depEnabled ? '✓' : '✗';
                    $this->line("     $status $dep");
                }
            } else {
                $this->line("   $name: No dependencies");
            }
        }
    }

    /**
     * Format bytes into human readable format
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
