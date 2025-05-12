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
        'list'          => 'List all installed extensions',
        'info'          => 'Show detailed information about an extension',
        'enable'        => 'Enable an extension',
        'disable'       => 'Disable an extension',
        'create'        => 'Create a new extension scaffold',
        'install'       => 'Install extension from URL or archive file',
        'validate'      => 'Validate an extension structure and dependencies',
        'namespaces'    => 'Show all registered extension namespaces',
        'delete'        => 'Delete an extension completely'
    ];

    /**
     * Execute the command
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    public function execute(array $args = [], array $options = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->showHelp();
            return Command::SUCCESS;
        }

        $action = $args[0];
        $extensionName = $args[1] ?? '';

        if (!array_key_exists($action, $this->options)) {
            $this->error("Unknown action: $action");
            $this->showHelp();
            return Command::FAILURE;
        }

        try {
            switch ($action) {
                case 'list':
                    $this->listExtensions();
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
     * @return void
     */
    protected function listExtensions(): void
    {
        $this->info('Installed Extensions');
        $this->line('===================');

        $extensions = ExtensionsManager::getLoadedExtensions();

        if (empty($extensions)) {
            $this->warning('No extensions found');
            return;
        }

        // Load config with tiered extension information
        $extensionConfigFile = $this->getConfigPath();
        $config = $this->loadConfig($extensionConfigFile);
        $enabledExtensions = $config['enabled'] ?? [];
        $coreExtensions = $config['core'] ?? [];
        $optionalExtensions = $config['optional'] ?? [];

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
            $reflection = new \ReflectionClass($extension);
            $shortName = $reflection->getShortName();
            $isEnabled = in_array($shortName, $enabledExtensions);
            $status = $isEnabled ? $this->colorText('Enabled', 'green') : $this->colorText('Disabled', 'yellow');

            // Determine if core or optional
            $isCore = in_array($shortName, $coreExtensions);
            $type = $isCore ? $this->colorText('Core', 'cyan') : 'Optional';

            // Get description from docblock or metadata
            $description = '';
            if (method_exists($extension, 'getMetadata')) {
                try {
                    $metadata = $extension::getMetadata();
                    $description = $metadata['description'] ?? '';
                } catch (\Throwable $e) {
                    // Fall back to docblock if metadata method fails
                }
            }

            if (empty($description)) {
                $description = $this->getExtensionMetadata($reflection, 'description');
            }

            // Truncate description if too long
            if (strlen($description) > 40) {
                $description = substr($description, 0, 37) . '...';
            }

            $this->line(
                Utils::padColumn($shortName, 25) .
                Utils::padColumn($status, 12) .
                Utils::padColumn($type, 10) .
                Utils::padColumn($description, 40)
            );
        }

        $this->line("\nTotal: " . count($extensions) . " extensions (" .
            count(array_intersect($enabledExtensions, $coreExtensions)) . " core, " .
            (count($enabledExtensions) - count(array_intersect($enabledExtensions, $coreExtensions))) . " optional)");
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

        $reflection = new \ReflectionClass($extension);
        $shortName = $reflection->getShortName();

        $configFile = $this->getConfigPath();
        $config = $this->loadConfig($configFile);

        // Check if this is a core extension
        $isCoreExtension = in_array($shortName, $config['core'] ?? []);
        $isEnabled = in_array($shortName, $config['enabled'] ?? []);

        $this->info("Extension: " . $shortName . ($isCoreExtension ? " " . $this->colorText("[CORE]", "cyan") : ""));
        $this->line(str_repeat('=', 50));

        // Get basic metadata
        $description = '';
        $version = '';
        $author = '';
        $requiredBy = [];
        $dependencies = [];

        // Try to get metadata from getMetadata() method first
        if (method_exists($extension, 'getMetadata')) {
            try {
                $metadata = $extension::getMetadata();
                $description = $metadata['description'] ?? '';
                $version = $metadata['version'] ?? '';
                $author = $metadata['author'] ?? '';
                $requiredBy = $metadata['requiredBy'] ?? [];

                if (isset($metadata['requires']) && isset($metadata['requires']['extensions'])) {
                    $dependencies = $metadata['requires']['extensions'];
                }

                // Get any additional dependencies from getDependencies() method
                if (method_exists($extension, 'getDependencies')) {
                    $additionalDeps = $extension::getDependencies();
                    $dependencies = array_unique(array_merge($dependencies, $additionalDeps));
                }
            } catch (\Throwable $e) {
                // Fall back to docblock if metadata method fails
            }
        }

        // Fall back to docblock for basic metadata if needed
        if (empty($description)) {
            $description = $this->getExtensionMetadata($reflection, 'description');
        }
        if (empty($version)) {
            $version = $this->getExtensionMetadata($reflection, 'version', '1.0.0');
        }
        if (empty($author)) {
            $author = $this->getExtensionMetadata($reflection, 'author', 'Unknown');
        }

        // Format status with color
        $status = $isEnabled ? $this->colorText('Enabled', 'green') : $this->colorText('Disabled', 'yellow');
        $type = $isCoreExtension ? $this->colorText('Core', 'cyan') : 'Optional';

        // Display information
        $this->line("Description: $description");
        $this->line("Version:     $version");
        $this->line("Author:      $author");
        $this->line("Status:      $status");
        $this->line("Type:        $type");
        $this->line("Class:       $extension");
        $this->line("File:        " . $reflection->getFileName());

        // Display components that require this extension
        if (!empty($requiredBy)) {
            $this->info("\nRequired by:");
            foreach ($requiredBy as $component) {
                $this->line("- $component");
            }
        }

        // Display extension dependencies
        if (!empty($dependencies)) {
            $this->info("\nDependencies:");
            foreach ($dependencies as $dependency) {
                $dependencyEnabled = in_array($dependency, $config['enabled'] ?? []);
                $status = $dependencyEnabled
                    ? $this->colorText('(enabled)', 'green')
                    : $this->colorText('(disabled)', 'red');
                $this->line("- $dependency $status");
            }
        } else {
            $this->line("\nNo dependencies required");
        }

        // Display extensions that depend on this one
        $dependentExtensions = $this->findDependentExtensions($shortName);
        if (!empty($dependentExtensions)) {
            $this->info("\nExtensions depending on this:");
            foreach ($dependentExtensions as $dependent) {
                $this->line("- $dependent");
            }
            if ($isEnabled) {
                $this->warning("Disabling this extension will affect the extensions listed above.");
            }
        }

        // Display lifecycle methods
        $this->info("\nLifecycle Methods:");
        $hasInitialize = $reflection->hasMethod('initialize');
        $hasRegisterServices = $reflection->hasMethod('registerServices');
        $hasRegisterMiddleware = $reflection->hasMethod('registerMiddleware');

        $this->line("- initialize():          " . ($hasInitialize ? '✓' : '×'));
        $this->line("- registerServices():    " . ($hasRegisterServices ? '✓' : '×'));
        $this->line("- registerMiddleware():  " . ($hasRegisterMiddleware ? '✓' : '×'));

        // Display custom methods
        $this->info("\nCustom Methods:");
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $customMethods = array_filter($methods, function ($method) {
            return $method->class !== Extensions::class &&
                   !in_array($method->getName(), ['initialize', 'registerServices', 'registerMiddleware']);
        });

        if (empty($customMethods)) {
            $this->line("No custom methods defined");
        } else {
            foreach ($customMethods as $method) {
                $params = [];
                foreach ($method->getParameters() as $param) {
                    $params[] = ($param->hasType() ? $param->getType() . ' ' : '') . '$' . $param->getName();
                }
                $this->line("- " . $method->getName() . '(' . implode(', ', $params) . ')');
            }
        }

        // Special warning for core extensions that are disabled
        if ($isCoreExtension && !$isEnabled) {
            $this->warning("\n⚠️ WARNING: This is a core extension that is currently disabled!");
            $this->warning("Some system functionality may not be working properly.");
            $this->tip("To enable: php glueful extensions enable $shortName");
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

        $reflection = new \ReflectionClass($extension);
        $shortName = $reflection->getShortName();

        $configFile = $this->getConfigPath();
        $config = $this->loadConfig($configFile);
        $enabledExtensions = $config['enabled'] ?? [];

        if (in_array($shortName, $enabledExtensions)) {
            $this->warning("Extension '$shortName' is already enabled");
            return;
        }

        $enabledExtensions[] = $shortName;
        $config['enabled'] = $enabledExtensions;

        $this->saveConfig($configFile, $config);

        $this->success("Extension '$shortName' has been enabled");
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

        $reflection = new \ReflectionClass($extension);
        $shortName = $reflection->getShortName();

        $configFile = $this->getConfigPath();

        if (!file_exists($configFile)) {
            $this->warning("No extensions are currently enabled");
            return;
        }

        $config = $this->loadConfig($configFile);
        $enabledExtensions = $config['enabled'] ?? [];

        if (!in_array($shortName, $enabledExtensions)) {
            $this->warning("Extension '$shortName' is already disabled");
            return;
        }

        // Check if this is a core extension
        $isCoreExtension = in_array($shortName, $config['core'] ?? []);

        if ($isCoreExtension) {
            $this->warning($this->colorText("⚠️ WARNING: '$shortName' is a core extension and is required for core functionality!", 'red'));
            $this->warning("Disabling this extension may break essential system features.");

            // List components that depend on this extension
            if (method_exists($extension, 'getMetadata')) {
                $metadata = $extension::getMetadata();
                if (isset($metadata['requiredBy']) && !empty($metadata['requiredBy'])) {
                    $this->warning("This extension is required by:");
                    foreach ($metadata['requiredBy'] as $component) {
                        $this->line("  - " . $this->colorText($component, 'yellow'));
                    }
                }
            }

            // Check for dependent extensions
            $dependentExtensions = $this->findDependentExtensions($shortName);
            if (!empty($dependentExtensions)) {
                $this->warning("The following enabled extensions depend on '$shortName':");
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

            $this->warning($this->colorText("Proceeding with disabling core extension '$shortName'...", 'red'));
        } else {
            // Check for dependent extensions for non-core extensions too
            $dependentExtensions = $this->findDependentExtensions($shortName);
            if (!empty($dependentExtensions)) {
                $this->warning("The following enabled extensions depend on '$shortName':");
                foreach ($dependentExtensions as $dependent) {
                    $this->line("  - " . $this->colorText($dependent, 'yellow'));
                }

                if (!$this->confirm("Are you sure you want to disable this extension?")) {
                    $this->info("Operation cancelled");
                    return;
                }
            }
        }

        // All checks passed, disable the extension
        $enabledExtensions = array_diff($enabledExtensions, [$shortName]);
        $config['enabled'] = $enabledExtensions;

        $this->saveConfig($configFile, $config);

        if ($isCoreExtension) {
            $this->success("Core extension '$shortName' has been disabled");
            $this->warning($this->colorText("⚠️ Caution: You have disabled a core extension. Some system functionality may not work properly.", 'red'));
        } else {
            $this->success("Extension '$shortName' has been disabled");
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
        $this->info("Creating extension: $extensionName");

        // Use ExtensionsManager to create the extension
        $result = ExtensionsManager::createExtension($extensionName);

        if (!$result['success']) {
            $this->error($result['message']);
            return;
        }

        $this->success($result['message']);

        // Display created files
        $this->info("Files created:");
        foreach ($result['files'] as $file) {
            $this->line("- $file");
        }

        $this->tip("To enable your extension, run: php glueful extensions enable $extensionName");
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
        $this->success("Extension installed at: " . config('paths.project_extensions') . $installedName);

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
     * Find extension class by name
     *
     * @param string $extensionName Extension name
     * @return string|null Full extension class name or null if not found
     */
    protected function findExtension(string $extensionName): ?string
    {
        $extensions = ExtensionsManager::getLoadedExtensions();
        error_log(print_r($extensions, true));

        foreach ($extensions as $extension) {
            $reflection = new \ReflectionClass($extension);
            if ($reflection->getShortName() === $extensionName) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Get config file path
     *
     * @return string Config file path
     */
    protected function getConfigPath(): string
    {
        $configDir = dirname(__DIR__, 2) . '/../config';

        // Ensure config directory exists
        if (!is_dir($configDir)) {
            // Only attempt to create if it doesn't exist
            if (!mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                // This is a more robust check: try to create it, and if that fails, check again if it exists
                throw new \RuntimeException("Failed to create config directory: $configDir");
            }
        }

        $configFile = $configDir . '/extensions.php';

        // Create the extensions.php file if it doesn't exist
        if (!file_exists($configFile)) {
            $this->createConfigFile($configFile);
        }

        return $configFile;
    }

    /**
     * Create default extensions config file
     *
     * @param string $configFile Path to config file to create
     * @return bool Success status
     */
    protected function createConfigFile(string $configFile): bool
    {
        $defaultConfig = [
            'enabled' => [],
            'paths' => [
                'extensions' => config('paths.project_extensions'),
            ]
        ];

        $this->info("Creating extensions configuration file: $configFile");
        return $this->saveConfig($configFile, $defaultConfig);
    }

    /**
     * Get enabled extensions from config
     *
     * @param string $configFile Config file path
     * @return array List of enabled extensions
     */
    protected function getEnabledExtensions(string $configFile): array
    {
        if (!file_exists($configFile)) {
            return [];
        }

        $config = $this->loadConfig($configFile);
        return $config['enabled'] ?? [];
    }

    /**
     * Load configuration from file
     *
     * @param string $file Config file path
     * @return array Configuration array
     */
    protected function loadConfig(string $file): array
    {
        if (!file_exists($file)) {
            return ['enabled' => []];
        }

        return include $file;
    }

    /**
     * Save configuration to file
     *
     * @param string $file Config file path
     * @param array $config Configuration array
     * @return bool Success status
     */
    protected function saveConfig(string $file, array $config): bool
    {
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        return file_put_contents($file, $content) !== false;
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

Arguments:
  <n>                 Extension name (in PascalCase, e.g. MyExtension)
  <source>            Source URL or file path for install action
  <target>            Target extension name for install action

Options:
  -h, --help          Show this help message

Examples:
  php glueful extensions list
  php glueful extensions info MyExtension
  php glueful extensions enable PaymentGateway
  php glueful extensions disable Analytics
  php glueful extensions create MyExtension
  php glueful extensions install https://example.com/extension.zip MyExtension
  php glueful extensions validate MyExtension
  php glueful extensions namespaces
HELP;
    }

    /**
     * Show command help
     *
     * @return void
     */
    protected function showHelp(): void
    {
        $this->line($this->getHelp());
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

        $namespaces = ExtensionsManager::getRegisteredNamespaces();

        if (empty($namespaces)) {
            $this->warning('No extension namespaces registered');
            return;
        }

        foreach ($namespaces as $namespace => $paths) {
            $this->info($namespace);

            if (is_array($paths)) {
                foreach ($paths as $path) {
                    $this->line("  → " . $path);
                }
            } else {
                $this->line("  → " . $paths);
            }

            $this->line('');
        }

        $this->line("\nTip: These namespaces are dynamically registered at runtime by ExtensionsManager.");
        $this->line("No manual changes to composer.json are needed for extension autoloading.");
    }

    /**
     * Download or copy an archive file
     *
     * @param string $source Source URL or file path
     * @return string|false Path to the temporary archive file or false on failure
     */
    protected function downloadOrCopyArchive(string $source): string|false
    {
        // Create temporary directory if it doesn't exist
        $tempDir = sys_get_temp_dir() . '/glueful_extensions';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true)) {
            $this->error("Failed to create temporary directory: $tempDir");
            return false;
        }

        // Generate a temporary file name
        $tempFile = $tempDir . '/' . md5($source . time()) . '.zip';

        $this->info("Retrieving extension package...");

        // Handle URL or local file
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            // It's a URL, download it
            $this->line("Downloading from URL: $source");

            $ch = curl_init($source);
            $fp = fopen($tempFile, 'wb');

            if (!$ch || !$fp) {
                $this->error("Failed to initialize download");
                return false;
            }

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            if (!curl_exec($ch)) {
                $this->error("Download failed: " . curl_error($ch));
                curl_close($ch);
                fclose($fp);
                return false;
            }

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($statusCode !== 200) {
                $this->error("Download failed with HTTP status code: $statusCode");
                return false;
            }
        } else {
            // It's a local file, copy it
            $this->line("Copying local file: $source");
            if (!copy($source, $tempFile)) {
                $this->error("Failed to copy file");
                return false;
            }
        }

        $this->success("Package retrieved successfully");
        return $tempFile;
    }

    /**
     * Extract an archive to the destination directory
     *
     * @param string $archiveFile Path to the archive file
     * @param string $destDir Destination directory
     * @return bool Success status
     */
    protected function extractArchive(string $archiveFile, string $destDir): bool
    {
        $this->info("Extracting extension...");

        // Ensure destination directory exists
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
            $this->error("Failed to create destination directory: $destDir");
            return false;
        }

        // Extract using ZipArchive
        $zip = new \ZipArchive();
        if ($zip->open($archiveFile) !== true) {
            $this->error("Failed to open archive: $archiveFile");
            return false;
        }

        // Check if the archive has a single root folder
        $rootFolderName = null;
        $hasSingleRoot = $this->hasSingleRootFolder($zip, $rootFolderName);

        // Extract the archive
        if (!$zip->extractTo($hasSingleRoot ? dirname($destDir) : $destDir)) {
            $this->error("Failed to extract archive");
            $zip->close();
            return false;
        }

        $zip->close();

        // If the archive had a single root folder, rename it to the target name
        if ($hasSingleRoot && $rootFolderName) {
            $extractedPath = dirname($destDir) . '/' . $rootFolderName;
            if (is_dir($extractedPath) && $extractedPath !== $destDir) {
                if (!rename($extractedPath, $destDir)) {
                    $this->error("Failed to rename extracted folder to target name");
                    return false;
                }
            }
        }

        $this->success("Extension extracted successfully");
        return true;
    }

    /**
     * Check if a zip archive has a single root folder
     *
     * @param \ZipArchive $zip Zip archive
     * @param string|null &$rootFolderName Variable to store the root folder name
     * @return bool True if the archive has a single root folder
     */
    protected function hasSingleRootFolder(\ZipArchive $zip, ?string &$rootFolderName): bool
    {
        $rootFolders = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip directories that are not at the root level
            if (substr_count($name, '/') > 1) {
                continue;
            }

            // If it's a root-level file
            if (strpos($name, '/') === false) {
                return false; // Archive doesn't have a single root folder
            }

            // Extract the root folder name
            $rootFolder = substr($name, 0, strpos($name, '/'));
            if (!empty($rootFolder) && !in_array($rootFolder, $rootFolders)) {
                $rootFolders[] = $rootFolder;
            }
        }

        if (count($rootFolders) === 1) {
            $rootFolderName = $rootFolders[0];
            return true;
        }

        return false;
    }

    /**
     * Validate extension structure
     *
     * @param \ReflectionClass $reflection Extension class reflection
     * @return void
     */
    protected function validateExtensionStructure(\ReflectionClass $reflection): void
    {
        $this->info("Checking extension structure...");

        $extensionName = $reflection->getShortName();
        $extensionDir = dirname($reflection->getFileName());

        // Required files
        $requiredFiles = [
            "$extensionName.php" => "Main extension class",
            "README.md" => "Documentation",
        ];

        // Optional but recommended files
        $recommendedFiles = [
            "config.php" => "Configuration file",
            "routes.php" => "Routes definition",
        ];

        // Check required files
        $hasAllRequired = true;
        foreach ($requiredFiles as $file => $description) {
            if (file_exists("$extensionDir/$file")) {
                $this->line("✓ $file - $description");
            } else {
                $this->warning("✗ Missing $file - $description");
                $hasAllRequired = false;
            }
        }

        // Check recommended files
        foreach ($recommendedFiles as $file => $description) {
            if (file_exists("$extensionDir/$file")) {
                $this->line("✓ $file - $description");
            } else {
                $this->line("- $file - $description (recommended but not required)");
            }
        }

        // Check class structure
        $this->info("\nChecking class structure...");

        // Required methods
        $requiredMethods = [
            'initialize' => 'Extension initialization',
            'registerServices' => 'Service registration',
            'registerMiddleware' => 'Middleware registration'
        ];

        foreach ($requiredMethods as $method => $description) {
            if ($reflection->hasMethod($method)) {
                $this->line("✓ $method() - $description");
            } else {
                $this->warning("✗ Missing $method() - $description");
                $hasAllRequired = false;
            }
        }

        // Metadata check
        $this->info("\nChecking metadata...");
        $metadataTags = [
            'description' => $this->getExtensionMetadata($reflection, 'description'),
            'version' => $this->getExtensionMetadata($reflection, 'version'),
            'author' => $this->getExtensionMetadata($reflection, 'author')
        ];

        foreach ($metadataTags as $tag => $value) {
            if (!empty($value)) {
                $this->line("✓ @$tag: $value");
            } else {
                $this->warning("✗ Missing @$tag tag in class docblock");
            }
        }

        // Final result
        if ($hasAllRequired) {
            $this->success("\nStructure validation passed");
        } else {
            $this->warning("\nStructure validation found issues");
            $this->line("The extension may work, but it's recommended to fix these issues for better compatibility.");
        }
    }

    /**
     * Validate extension dependencies
     *
     * @param \ReflectionClass $reflection Extension class reflection
     * @return void
     */
    protected function validateExtensionDependencies(\ReflectionClass $reflection): void
    {
        $this->info("\nChecking extension dependencies...");

        $extensionClass = $reflection->getName();
        $extensionName = $reflection->getShortName();

        // Get enabled extensions
        $enabledExtensions = $this->getEnabledExtensions($this->getConfigPath());

        try {
            // Get dependencies from the extension
            $dependencies = [];

            // Check if the extension implements getDependencies() method
            if ($reflection->hasMethod('getDependencies')) {
                $dependencies = $extensionClass::getDependencies();
            }

            // Check if the extension implements getMetadata() method
            if ($reflection->hasMethod('getMetadata')) {
                $metadata = $extensionClass::getMetadata();
                if (isset($metadata['requires']) && isset($metadata['requires']['extensions'])) {
                    $dependencies = array_merge($dependencies, $metadata['requires']['extensions']);
                }
            }

            // Remove duplicates
            $dependencies = array_unique($dependencies);

            if (empty($dependencies)) {
                $this->line("✓ No dependencies required");
            } else {
                $this->line("Found " . count($dependencies) . " dependencies:");
                $allDependenciesMet = true;

                foreach ($dependencies as $dependency) {
                    // Check if dependency exists
                    $dependencyClass = $this->findExtension($dependency);

                    if ($dependencyClass) {
                        // Check if dependency is enabled
                        $isEnabled = in_array($dependency, $enabledExtensions);

                        if ($isEnabled) {
                            $this->line("✓ $dependency - Found and enabled");
                        } else {
                            $this->warning("✗ $dependency - Found but not enabled");
                            $allDependenciesMet = false;
                        }
                    } else {
                        $this->error("✗ $dependency - Not found in the system");
                        $allDependenciesMet = false;
                    }
                }

                // Final result
                if ($allDependenciesMet) {
                    $this->success("\nAll dependencies are met");
                } else {
                    $this->warning("\nSome dependencies are not met");
                    $this->line("You should enable or install the missing dependencies before enabling this extension.");
                    $this->tip("Run 'php glueful extensions enable <dependency>' for each missing dependency.");
                }
            }

            // Check if other extensions depend on this one
            $dependentExtensions = [];

            foreach (ExtensionsManager::getLoadedExtensions() as $otherExtension) {
                if ($otherExtension === $extensionClass) {
                    continue; // Skip self
                }

                $otherReflection = new \ReflectionClass($otherExtension);
                $otherName = $otherReflection->getShortName();

                // Only check enabled extensions
                if (!in_array($otherName, $enabledExtensions)) {
                    continue;
                }

                try {
                    // Check if the extension implements getDependencies() method
                    if ($otherReflection->hasMethod('getDependencies')) {
                        $otherDependencies = $otherExtension::getDependencies();
                        if (in_array($extensionName, $otherDependencies)) {
                            $dependentExtensions[] = $otherName;
                        }
                    }

                    // Check if the extension implements getMetadata() method
                    if ($otherReflection->hasMethod('getMetadata')) {
                        $otherMetadata = $otherExtension::getMetadata();
                        if (
                            isset($otherMetadata['requires']) &&
                            isset($otherMetadata['requires']['extensions']) &&
                            in_array($extensionName, $otherMetadata['requires']['extensions'])
                        ) {
                            if (!in_array($otherName, $dependentExtensions)) {
                                $dependentExtensions[] = $otherName;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip extensions with errors
                    continue;
                }
            }

            // Display dependent extensions
            if (!empty($dependentExtensions)) {
                $this->info("\nExtensions that depend on $extensionName:");
                foreach ($dependentExtensions as $dependent) {
                    $this->line("- $dependent");
                }
                $this->warning("Disabling this extension may break the extensions listed above.");
            } else {
                $this->line("\n✓ No enabled extensions depend on $extensionName");
            }
        } catch (\Exception $e) {
            $this->error("Error validating dependencies: " . $e->getMessage());
        }
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
     * Find extensions that depend on the given extension
     *
     * @param string $extensionName The name of the extension
     * @return array List of extension names that depend on this extension
     */
    protected function findDependentExtensions(string $extensionName): array
    {
        $dependentExtensions = [];
        $enabledExtensions = $this->getEnabledExtensions($this->getConfigPath());

        foreach (ExtensionsManager::getLoadedExtensions() as $otherExtension) {
            $otherReflection = new \ReflectionClass($otherExtension);
            $otherName = $otherReflection->getShortName();

            // Skip if not enabled
            if (!in_array($otherName, $enabledExtensions)) {
                continue;
            }

            // Skip if it's the extension itself
            if ($otherName === $extensionName) {
                continue;
            }

            try {
                // Check in getDependencies method
                if ($otherReflection->hasMethod('getDependencies')) {
                    $otherDependencies = $otherExtension::getDependencies();
                    if (in_array($extensionName, $otherDependencies)) {
                        $dependentExtensions[] = $otherName;
                        continue; // Skip further checks for this extension
                    }
                }

                // Check in getMetadata method
                if ($otherReflection->hasMethod('getMetadata')) {
                    $otherMetadata = $otherExtension::getMetadata();
                    if (
                        isset($otherMetadata['requires']) &&
                        isset($otherMetadata['requires']['extensions']) &&
                        in_array($extensionName, $otherMetadata['requires']['extensions'])
                    ) {
                        if (!in_array($otherName, $dependentExtensions)) {
                            $dependentExtensions[] = $otherName;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Skip extensions with errors
                continue;
            }
        }

        return $dependentExtensions;
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

        $reflection = new \ReflectionClass($extension);
        $shortName = $reflection->getShortName();
        $extensionDir = dirname($reflection->getFileName());

        // Check if the extension is enabled
        $configFile = $this->getConfigPath();
        $config = $this->loadConfig($configFile);
        $isEnabled = in_array($shortName, $config['enabled'] ?? []);
        $isCoreExtension = in_array($shortName, $config['core'] ?? []);

        // Show warnings for enabled or core extensions
        if ($isEnabled) {
            $this->warning("Extension '$shortName' is currently enabled.");
            $this->line("You should disable it first with: php glueful extensions disable $shortName");

            if (!$this->confirm("Do you want to continue anyway?")) {
                $this->info("Operation cancelled");
                return;
            }
        }

        if ($isCoreExtension) {
            $this->warning($this->colorText("⚠️ WARNING: '$shortName' is a core extension and is required for core functionality!", 'red'));
            $this->warning("Deleting this extension will break essential system features.");
            $this->warning("Only proceed if you're absolutely sure what you're doing.");

            if (!$this->confirm("Are you absolutely sure you want to delete this core extension?")) {
                $this->info("Operation cancelled");
                return;
            }

            $this->warning($this->colorText("Proceeding with deleting core extension '$shortName'...", 'red'));
        }

        // Check for dependent extensions
        $dependentExtensions = $this->findDependentExtensions($shortName);
        if (!empty($dependentExtensions)) {
            $this->warning("The following enabled extensions depend on '$shortName':");
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

        // Use ExtensionsManager to delete the extension
        $force = true; // Force deletion since we've already confirmed
        $result = ExtensionsManager::deleteExtension($shortName, $force);

        if (!$result['success']) {
            $this->error($result['message']);
            return;
        }

        $this->success($result['message']);

        // Suggest possible next steps
        if (!empty($dependentExtensions)) {
            $this->tip("You may need to update or remove the dependent extensions that relied on this extension.");
        }
    }
}
