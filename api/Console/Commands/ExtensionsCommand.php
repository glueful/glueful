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
        // Check if extension name is valid
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $extensionName)) {
            $this->error("Invalid extension name '$extensionName'. Use PascalCase naming (e.g. MyExtension)");
            return;
        }
        
        $extensionDir = config('paths.project_extensions') . $extensionName;
        
        if (is_dir($extensionDir)) {
            $this->error("Extension directory already exists: $extensionDir");
            return;
        }
        
        $this->info("Creating extension: $extensionName");
        
        // Create extension directory structure
        if (!mkdir($extensionDir, 0755, true)) {
            $this->error("Failed to create directory: $extensionDir");
            return;
        }
        
        // Create main extension class file
        $mainClassFile = "$extensionDir/$extensionName.php";
        $mainClassContent = $this->generateExtensionClass($extensionName);
        
        if (file_put_contents($mainClassFile, $mainClassContent) === false) {
            $this->error("Failed to create extension class file");
            return;
        }
        
        // Create README.md
        $readmeFile = "$extensionDir/README.md";
        $readmeContent = $this->generateReadme($extensionName);
        
        if (file_put_contents($readmeFile, $readmeContent) === false) {
            $this->error("Failed to create README.md file");
            return;
        }
        
        $this->success("Extension scaffold created at: $extensionDir");
        $this->info("Files created:");
        $this->line("- $extensionName.php");
        $this->line("- README.md");
        $this->tip("To enable your extension, run: php glueful extensions enable $extensionName");
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
                'api-extensions' => config('paths.api_extensions'),
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
     * Initialize extension
     * 
     * Called when the extension is loaded
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Initialization code here
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
  extensions namespaces
  extensions [action] [options]

Actions:
  list                List all installed extensions
  info <n>            Show detailed information about an extension
  enable <n>          Enable an extension
  disable <n>         Disable an extension
  create <n>          Create a new extension scaffold
  namespaces          Show all registered extension namespaces

Arguments:
  <n>                 Extension name (in PascalCase, e.g. MyExtension)

Options:
  -h, --help          Show this help message

Examples:
  php glueful extensions list
  php glueful extensions info MyExtension
  php glueful extensions enable PaymentGateway
  php glueful extensions disable Analytics
  php glueful extensions create NewFeature
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
}
