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
        'list'       => 'List all installed extensions',
        'info'       => 'Show detailed information about an extension',
        'enable'     => 'Enable an extension',
        'disable'    => 'Disable an extension',
        'create'     => 'Create a new extension scaffold',
        'install'    => 'Install extension from URL or archive file',
        'validate'   => 'Validate an extension structure and dependencies',
        'namespaces' => 'Show all registered extension namespaces'
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

        // Load enabled extensions from config
        $extensionConfigFile = $this->getConfigPath();
        $enabledExtensions = $this->getEnabledExtensions($extensionConfigFile);
        
        // Create a table header
        $this->line(
            Utils::padColumn('Name', 30) . 
            Utils::padColumn('Status', 15) 
        );
        $this->line(str_repeat('-', 80));
        
        // Display each extension
        foreach ($extensions as $extension) {
            $reflection = new \ReflectionClass($extension);
            $shortName = $reflection->getShortName();
            $isEnabled = in_array($shortName, $enabledExtensions);
            $status = $isEnabled ? $this->colorText('Enabled', 'green') : $this->colorText('Disabled', 'yellow');
            
            // Get description from docblock
            $description = $this->getExtensionMetadata($reflection, 'description');
            
            $this->line(
                Utils::padColumn($shortName, 30) . 
                Utils::padColumn($status, 15)
                
            );
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

        $reflection = new \ReflectionClass($extension);
        
        $this->info("Extension: {$reflection->getShortName()}");
        $this->line(str_repeat('=', 50));
        
        // Get metadata
        $description = $this->getExtensionMetadata($reflection, 'description');
        $version = $this->getExtensionMetadata($reflection, 'version', '1.0.0');
        $author = $this->getExtensionMetadata($reflection, 'author', 'Unknown');
        
        // Check status
        $isEnabled = in_array(
            $reflection->getShortName(), 
            $this->getEnabledExtensions($this->getConfigPath())
        );
        $status = $isEnabled ? $this->colorText('Enabled', 'green') : $this->colorText('Disabled', 'yellow');
        
        // Display information
        $this->line("Description: $description");
        $this->line("Version:     $version");
        $this->line("Author:      $author");
        $this->line("Status:      $status");
        $this->line("Class:       $extension");
        $this->line("File:        " . $reflection->getFileName());
        
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
        $customMethods = array_filter($methods, function($method) {
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
        
        $enabledExtensions = array_diff($enabledExtensions, [$shortName]);
        $config['enabled'] = $enabledExtensions;
        
        $this->saveConfig($configFile, $config);
        
        $this->success("Extension '$shortName' has been disabled");
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
     * Generate extension class content
     * 
     * @param string $extensionName Extension name
     * @return string Generated class content
     */
    protected function generateExtensionClass(string $extensionName): string
    {
        return "<?php
    declare(strict_types=1);

    namespace Glueful\\Extensions;

    use Glueful\\Http\\Response;
    use Glueful\\Http\\Router;
    use Glueful\\Helpers\\Request;

/**
 * $extensionName Extension
 * 
 * @description Add your extension description here
 * @version 1.0.0
 * @author Your Name <your.email@example.com>
 */
class $extensionName extends \\Glueful\\Extensions
{
    /**
     * Extension configuration
     */
    private static array \$config = [];
    
    /**
     * Initialize extension
     * 
     * Called when the extension is loaded
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::\$config = require __DIR__ . '/config.php';
        }
        
        // Additional initialization code here
    }
    
    /**
     * Register extension-provided services
     * 
     * @return void
     */
    public static function registerServices(): void
    {
        // Register services here
    }
    
    /**
     * Register extension-provided middleware
     * 
     * @return void
     */
    public static function registerMiddleware(): void
    {
        // Register middleware here
    }
    
    /**
     * Process extension request
     * 
     * Main request handler for extension endpoints.
     * 
     * @param array \$getParams Query parameters
     * @param array \$postParams Post data
     * @return array Extension response
     */
    public static function process(array \$getParams, array \$postParams): array
    {
        // Example implementation of the process method
        \$action = \$getParams['action'] ?? 'default';
        
        return match(\$action) {
            'greet' => [
                'success' => true,
                'code' => 200,
                'data' => [
                    'message' => self::greet(\$getParams['name'] ?? 'World')
                ]
            ],
            'default' => [
                'success' => true,
                'code' => 200,
                'data' => [
                    'extension' => '$extensionName',
                    'message' => 'Extension is working properly'
                ]
            ],
            default => [
                'success' => false,
                'code' => 400,
                'error' => 'Unknown action: ' . \$action
            ]
        };
    }
    
    /**
     * Get extension metadata
     * 
     * This method follows the Glueful Extension Metadata Standard.
     * 
     * @return array Extension metadata for admin interface and marketplace
     */
    public static function getMetadata(): array
    {
        return [
            // Required fields
            'name' => '$extensionName',
            'description' => 'Add your extension description here',
            'version' => '1.0.0',
            'author' => 'Your Name',
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => [],
                'dependencies' => []
            ],
            
            // Optional fields - uncomment and customize as needed
            // 'homepage' => 'https://example.com/$extensionName',
            // 'documentation' => 'https://docs.example.com/extensions/$extensionName',
            // 'license' => 'MIT',
            // 'keywords' => ['keyword1', 'keyword2', 'keyword3'],
            // 'category' => 'utilities',
            
            'features' => [
                'Feature 1 description',
                'Feature 2 description',
                'Feature 3 description'
            ],
            
            'compatibility' => [
                'browsers' => ['Chrome', 'Firefox', 'Safari', 'Edge'],
                'environments' => ['production', 'development'],
                'conflicts' => []
            ],
            
            'settings' => [
                'configurable' => true,
                'has_admin_ui' => false,
                'setup_required' => false,
                'default_config' => [
                    // Default configuration values
                    'setting1' => 'default_value',
                    'setting2' => true
                ]
            ],
            
            'support' => [
                'email' => 'your.email@example.com',
                'issues' => 'https://github.com/yourusername/$extensionName/issues'
            ]
        ];
    }
    
    /**
     * Get extension dependencies
     * 
     * Returns a list of other extensions this extension depends on.
     * 
     * @return array List of extension dependencies
     */
    public static function getDependencies(): array
    {
        // By default, get dependencies from metadata
        \$metadata = self::getMetadata();
        return \$metadata['requires']['extensions'] ?? [];
    }
    
    /**
     * Check environment-specific configuration
     * 
     * Determines if the extension should be enabled in the current environment.
     * 
     * @param string \$environment Current environment (dev, staging, production)
     * @return bool Whether the extension should be enabled in this environment
     */
    public static function isEnabledForEnvironment(string \$environment): bool
    {
        // By default, enable in all environments
        // Override this method to enable only in specific environments
        return true;
    }
    
    /**
     * Validate extension health
     * 
     * Checks if the extension is functioning correctly.
     * 
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        \$healthy = true;
        \$issues = [];
        
        // Example health check - verify config is loaded correctly
        if (empty(self::\$config) && file_exists(__DIR__ . '/config.php')) {
            \$healthy = false;
            \$issues[] = 'Configuration could not be loaded properly';
        }
        
        // Add your own health checks here
        
        return [
            'healthy' => \$healthy,
            'issues' => \$issues,
            'metrics' => [
                'memory_usage' => memory_get_usage(true),
                'execution_time' => 0, // You could track this with microtime()
                'database_queries' => 0, // Track queries if your extension uses the database
                'cache_usage' => 0 // Track cache usage if applicable
            ]
        ];
    }
    
    /**
     * Get extension resource usage
     * 
     * Returns information about resources used by this extension.
     * 
     * @return array Resource usage metrics
     */
    public static function getResourceUsage(): array
    {
        // Customize with your own resource metrics
        return [
            'memory_usage' => memory_get_usage(true),
            'execution_time' => 0,
            'database_queries' => 0,
            'cache_usage' => 0
        ];
    }
    
    /**
     * Get extension configuration
     * 
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::\$config;
    }
    
    /**
     * Set extension configuration
     * 
     * @param array \$config New configuration
     * @return void
     */
    public static function setConfig(array \$config): void
    {
        self::\$config = \$config;
    }
    
    /**
     * Example extension method
     * 
     * @param string \$name Name parameter
     * @return string Greeting message
     */
    public static function greet(string \$name): string
    {
        return \"Hello, {\$name}! Welcome to the $extensionName extension.\";
    }
}
";
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
  php glueful extensions create NewFeature
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
        
        // Display each namespace and its path
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
                        if (isset($otherMetadata['requires']) && 
                            isset($otherMetadata['requires']['extensions']) &&
                            in_array($extensionName, $otherMetadata['requires']['extensions'])) {
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
}
