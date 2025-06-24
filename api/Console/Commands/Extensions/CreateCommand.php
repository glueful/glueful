<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extensions Create Command
 * Comprehensive extension creation command with advanced features:
 * - Interactive extension scaffold creation with templates
 * - Multiple built-in extension types (API, middleware, service, full)
 * - Support for custom template directories
 * - Advanced template variable substitution
 * - Preview mode to see what will be created
 * - Automatic file generation with proper structure matching real extensions
 * - Uses FileFinder and FileManager for robust file operations
 * Command: php glueful extensions:create <name> [options]
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:create',
    description: 'Create a new extension from template with advanced features'
)]
class CreateCommand extends BaseExtensionCommand
{
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        // Initialize the parent class properties if they're not already set
        if ($this->fileFinder === null) {
            $this->fileFinder = new FileFinder();
        }
        if ($this->fileManager === null) {
            $this->fileManager = new FileManager();
        }
    }

    protected function configure(): void
    {
        $this->setDescription('Create a new extension from template with advanced features')
             ->setHelp(
                 'This command creates a new extension with proper structure, configuration, and template files. ' .
                 'Supports both built-in templates and custom template directories with variable substitution.'
             )
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the extension to create'
             )
             ->addOption(
                 'template',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Extension template type (api, middleware, service, full, custom)',
                 'api'
             )
             ->addOption(
                 'author',
                 'a',
                 InputOption::VALUE_REQUIRED,
                 'Author name for the extension',
                 'Unknown'
             )
             ->addOption(
                 'description',
                 'd',
                 InputOption::VALUE_REQUIRED,
                 'Description of the extension',
                 'A custom extension for Glueful'
             )
             ->addOption(
                 'version',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Initial version number',
                 '1.0.0'
             )
             ->addOption(
                 'template-dir',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Custom template directory path'
             )
             ->addOption(
                 'vars',
                 null,
                 InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                 'Template variables in format key:value'
             )
             ->addOption(
                 'preview',
                 'p',
                 InputOption::VALUE_NONE,
                 'Preview template without creating files'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Overwrite existing extension'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        $templateType = $input->getOption('template');
        $author = $input->getOption('author');
        $description = $input->getOption('description');
        $version = $input->getOption('version');
        $templateDir = $input->getOption('template-dir');
        $vars = $input->getOption('vars');
        $preview = $input->getOption('preview');
        $force = $input->getOption('force');

        try {
            // Validate extension name
            if (!$this->validateExtensionName($extensionName)) {
                return self::FAILURE;
            }

            // Validate template type
            if (!$this->validateTemplateType($templateType, $templateDir)) {
                return self::FAILURE;
            }

            $this->info("🚀 Creating extension: {$extensionName}");
            $this->info("📋 Template type: {$templateType}");

            // Parse template variables
            $templateVars = $this->parseTemplateVars($vars, $extensionName, $author, $description, $version);

            // Get template path
            $templatePath = $this->getTemplatePath($templateType, $templateDir);

            if ($preview) {
                $this->previewTemplate($templatePath, $templateVars);
                return self::SUCCESS;
            }

            // Check if extension exists
            $extensionPath = dirname(__DIR__, 6) . "/extensions/{$extensionName}";
            if ($this->fileManager->exists($extensionPath) && !$force) {
                $this->error("Extension '{$extensionName}' already exists.");
                $this->tip('Use --force to overwrite existing extension.');
                return self::FAILURE;
            }

            // Generate extension from template
            $this->generateFromTemplate($templatePath, $extensionPath, $templateVars, $force);

            $this->success("✅ Extension '{$extensionName}' created successfully!");
            $this->displayCreationSummary($extensionName, $templateType, $extensionPath);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Extension creation failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function validateExtensionName(string $name): bool
    {
        if (empty($name)) {
            $this->error('Extension name cannot be empty.');
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Extension name must be in PascalCase and contain only letters and numbers.');
            $this->tip('Example: MyExtension, ApiHelper, UserManager');
            return false;
        }

        return true;
    }

    private function validateTemplateType(string $type, ?string $customDir): bool
    {
        $builtInTemplates = ['api', 'middleware', 'service', 'full', 'custom'];

        if (in_array($type, $builtInTemplates)) {
            return true;
        }

        // Check if it's a custom template
        if ($customDir && $this->fileManager->exists($customDir)) {
            $templatePath = "{$customDir}/{$type}";
            if (is_dir($templatePath)) {
                return true;
            }
        }

        $this->error("Unknown template type: {$type}");
        $this->info('Available built-in templates:');
        foreach ($builtInTemplates as $template) {
            $this->line("• {$template}");
        }

        if ($customDir) {
            $this->info("\nCustom templates in {$customDir}:");
            if ($this->fileManager->exists($customDir)) {
                $customTemplates = $this->fileFinder->findDirectories($customDir, '*', 0);
                foreach ($customTemplates as $template) {
                    $templateName = $template->getBasename();
                    $this->line("• {$templateName}");
                }
            }
        }

        return false;
    }

    private function parseTemplateVars(
        array $vars,
        string $extensionName,
        string $author,
        string $description,
        string $version
    ): array {
        $templateVars = [
            'EXTENSION_NAME' => $extensionName,
            'EXTENSION_NAMESPACE' => "Extensions\\{$extensionName}",
            'EXTENSION_CLASS' => "{$extensionName}Extension",
            'EXTENSION_DESCRIPTION' => $description,
            'AUTHOR' => $author,
            'VERSION' => $version,
            'DATE' => date('Y-m-d'),
            'YEAR' => date('Y'),
        ];

        // Parse user-provided variables
        foreach ($vars as $var) {
            if (str_contains($var, ':')) {
                [$key, $value] = explode(':', $var, 2);
                $templateVars[strtoupper(trim($key))] = trim($value);
            }
        }

        return $templateVars;
    }

    private function getTemplatePath(string $type, ?string $customDir): string
    {
        if ($customDir && $this->fileManager->exists($customDir)) {
            $customPath = "{$customDir}/{$type}";
            if (is_dir($customPath)) {
                return $customPath;
            }
        }

        // Built-in template path (would be in a templates directory)
        $builtInPath = dirname(__DIR__, 6) . "/resources/templates/extensions/{$type}";

        if (!$this->fileManager->exists($builtInPath)) {
            // Fallback: create template on-the-fly
            return $this->createBuiltInTemplate($type);
        }

        return $builtInPath;
    }

    private function createBuiltInTemplate(string $type): string
    {
        // Create built-in templates on-the-fly
        // In a future implementation, these could be stored as template files
        $tempDir = sys_get_temp_dir() . '/glueful_template_' . uniqid();
        $this->fileManager->createDirectory($tempDir);

        // Create template structure
        $this->createTemplateStructure($tempDir, $type);

        return $tempDir;
    }

    private function createTemplateStructure(string $path, string $type): void
    {
        // Create directories to match actual extension structure
        $this->fileManager->createDirectory("{$path}/src");
        $this->fileManager->createDirectory("{$path}/assets");
        $this->fileManager->createDirectory("{$path}/screenshots");
        $this->fileManager->createDirectory("{$path}/public");

        // Create extension.json template (matches production extension format)
        $configTemplate = [
            'name' => '{{EXTENSION_NAME}}',
            'displayName' => '{{EXTENSION_NAME}}',
            'version' => '{{VERSION}}',
            'publisher' => '{{AUTHOR}}',
            'description' => '{{EXTENSION_DESCRIPTION}}',
            'categories' => ['utilities'],
            'icon' => 'assets/icon.png',
            'galleryBanner' => [
                'color' => '#1F2937',
                'theme' => 'dark'
            ],
            'engines' => [
                'glueful' => '>=0.27.0'
            ],
            'main' => './{{EXTENSION_NAME}}.php',
            'dependencies' => [
                'php' => '>=8.2.0'
            ],
            'repository' => [
                'type' => 'git',
                'url' => 'https://github.com/{{AUTHOR|lower}}/{{EXTENSION_NAME}}'
            ],
            'extensionDependencies' => [],
            'features' => [
                'Custom {{EXTENSION_NAME}} functionality for Glueful'
            ],
            'compatibility' => [
                'environments' => ['production', 'development']
            ],
            'support' => [
                'email' => 'support@example.com',
                'issues' => 'https://github.com/{{AUTHOR|lower}}/{{EXTENSION_NAME}}/issues'
            ]
        ];

        $this->fileManager->writeFile(
            "{$path}/extension.json",
            json_encode($configTemplate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Create main extension class template
        $classTemplate = <<<PHP
<?php

declare(strict_types=1);

/**
 * {{EXTENSION_NAME}} Extension
 * {{EXTENSION_DESCRIPTION}}
 * @author {{AUTHOR}}
 * @version {{VERSION}}
 * @created {{DATE}}
 */
class {{EXTENSION_NAME}}
{
    /**
     * Extension initialization
     */
    public function init(): void
    {
        // Initialize extension functionality
        \$this->registerServices();
        \$this->registerRoutes();
    }

    /**
     * Extension activation
     */
    public function activate(): void
    {
        // Code to run when extension is activated
        // Run migrations, setup database tables, etc.
    }

    /**
     * Extension deactivation
     */
    public function deactivate(): void
    {
        // Code to run when extension is deactivated
        // Cleanup, remove hooks, etc.
    }

    /**
     * Register extension services
     */
    private function registerServices(): void
    {
        // Register services with DI container
    }

    /**
     * Register extension routes
     */
    private function registerRoutes(): void
    {
        // Register extension routes
        if (file_exists(__DIR__ . '/src/routes.php')) {
            require_once __DIR__ . '/src/routes.php';
        }
    }
}
PHP;

        $this->fileManager->writeFile("{$path}/{{EXTENSION_NAME}}.php", $classTemplate);

        // Add type-specific files based on template type
        switch ($type) {
            case 'api':
                $this->createApiTemplate($path);
                break;
            case 'middleware':
                $this->createMiddlewareTemplate($path);
                break;
            case 'service':
                $this->createServiceTemplate($path);
                break;
            case 'full':
                $this->createFullTemplate($path);
                break;
        }
    }

    private function createApiTemplate(string $path): void
    {
        $this->fileManager->createDirectory("{$path}/src/Controllers");

        // Create controller
        $controllerTemplate = <<<PHP
<?php

declare(strict_types=1);

namespace Extensions\\{{EXTENSION_NAME}}\\Controllers;

use Glueful\\Http\\Request;
use Glueful\\Http\\Response;
use Glueful\\Controllers\\BaseController;

/**
 * {{EXTENSION_NAME}} Controller
 * Handles HTTP requests for {{EXTENSION_NAME}} extension
 */
class {{EXTENSION_NAME}}Controller extends BaseController
{
    /**
     * Get extension information
     */
    public function index(Request \$request): Response
    {
        return \$this->json([
            'name' => '{{EXTENSION_NAME}}',
            'version' => '{{VERSION}}',
            'description' => '{{EXTENSION_DESCRIPTION}}',
            'status' => 'active'
        ]);
    }
    
    /**
     * Handle extension-specific operations
     */
    public function handle(Request \$request): Response
    {
        \$data = \$request->json();
        
        // Process request data
        \$result = [
            'success' => true,
            'message' => '{{EXTENSION_NAME}} operation completed',
            'data' => \$data
        ];
        
        return \$this->json(\$result);
    }
}
PHP;

        $this->fileManager->writeFile("{$path}/src/Controllers/{{EXTENSION_NAME}}Controller.php", $controllerTemplate);

        // Create routes file
        $routesTemplate = <<<PHP
<?php

declare(strict_types=1);

use Glueful\\Http\\Router;
use Extensions\\{{EXTENSION_NAME}}\\Controllers\\{{EXTENSION_NAME}}Controller;

/**
 * {{EXTENSION_NAME}} Extension Routes
 */

// API endpoints
Router::group('/{{EXTENSION_NAME|lower}}', function() {
    Router::get('/', [{{EXTENSION_NAME}}Controller::class, 'index']);
    Router::post('/handle', [{{EXTENSION_NAME}}Controller::class, 'handle']);
}, requiresAuth: true);
PHP;

        $this->fileManager->writeFile("{$path}/src/routes.php", $routesTemplate);
    }

    private function createMiddlewareTemplate(string $path): void
    {
        $this->fileManager->createDirectory("{$path}/middleware");

        $middlewareTemplate = <<<PHP
<?php

namespace {{EXTENSION_NAMESPACE}}\\Middleware;

use Glueful\\Http\\Request;
use Glueful\\Http\\Response;

/**
 * {{EXTENSION_NAME}} Middleware
 */
class {{EXTENSION_NAME}}Middleware
{
    /**
     * Process request
     */
    public function process(Request \$request, callable \$next): Response
    {
        // Pre-processing logic here
        
        \$response = \$next(\$request);
        
        // Post-processing logic here
        
        return \$response;
    }
}
PHP;

        $this->fileManager->writeFile("{$path}/middleware/{{EXTENSION_NAME}}Middleware.php", $middlewareTemplate);
    }

    private function createServiceTemplate(string $path): void
    {
        $this->fileManager->createDirectory("{$path}/services");

        $serviceTemplate = <<<PHP
<?php

namespace {{EXTENSION_NAMESPACE}}\\Services;

/**
 * {{EXTENSION_NAME}} Service
 */
class {{EXTENSION_NAME}}Service
{
    /**
     * Service initialization
     */
    public function __construct()
    {
        // Initialize service
    }

    /**
     * Main service method
     */
    public function process(): void
    {
        // Service logic here
    }
}
PHP;

        $this->fileManager->writeFile("{$path}/services/{{EXTENSION_NAME}}Service.php", $serviceTemplate);
    }

    private function createFullTemplate(string $path): void
    {
        $this->createApiTemplate($path);
        $this->createMiddlewareTemplate($path);
        $this->createServiceTemplate($path);

        // Create migrations directory for database schema changes
        $this->fileManager->createDirectory("{$path}/migrations");
        $this->fileManager->createDirectory("{$path}/tests");
        $this->fileManager->createDirectory("{$path}/docs");

        // Create sample migration
        $migrationTemplate = <<<PHP
<?php

declare(strict_types=1);

/**
 * Create {{EXTENSION_NAME}} tables migration
 * @package Extensions\\{{EXTENSION_NAME}}
 */
class Create{{EXTENSION_NAME}}Tables
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        // Create extension-specific tables here
    }
    
    /**
     * Reverse the migration
     */
    public function down(): void
    {
        // Drop extension tables
    }
}
PHP;

        $this->fileManager->writeFile(
            "{$path}/migrations/001_Create{{EXTENSION_NAME}}Tables.php",
            $migrationTemplate
        );
    }

    private function previewTemplate(string $templatePath, array $vars): void
    {
        $this->info('Template Preview:');
        $this->line('================');

        $this->info("\nTemplate Variables:");
        $this->table(['Variable', 'Value'], array_map(
            fn($key, $value) => [$key, $value],
            array_keys($vars),
            array_values($vars)
        ));

        $this->info("\nTemplate Structure:");
        $this->displayDirectoryTree($templatePath);

        $this->info("\nSample File Contents:");
        $this->displaySampleFiles($templatePath, $vars);
    }

    private function displayDirectoryTree(string $path, string $prefix = ''): void
    {
        if (!is_dir($path)) {
            return;
        }

        // Get files and directories
        $items = [];

        // Add directories
        $dirs = iterator_to_array($this->fileFinder->findDirectories($path, '*', 0));
        foreach ($dirs as $dir) {
            $items[] = ['name' => $dir->getBasename(), 'isDir' => true, 'path' => $dir->getPathname()];
        }

        // Add files using scandir for completeness
        $allItems = scandir($path);
        foreach ($allItems as $item) {
            if ($item !== '.' && $item !== '..' && is_file("{$path}/{$item}")) {
                $items[] = ['name' => $item, 'isDir' => false, 'path' => "{$path}/{$item}"];
            }
        }

        // Sort by name
        usort($items, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        foreach ($items as $index => $item) {
            $isLast = $index === count($items) - 1;
            $currentPrefix = $prefix . ($isLast ? '└── ' : '├── ');
            $this->line($currentPrefix . $item['name']);

            if ($item['isDir']) {
                $nextPrefix = $prefix . ($isLast ? '    ' : '│   ');
                $this->displayDirectoryTree($item['path'], $nextPrefix);
            }
        }
    }

    private function displaySampleFiles(string $templatePath, array $vars): void
    {
        $sampleFiles = ['extension.json'];

        foreach ($sampleFiles as $file) {
            $filePath = "{$templatePath}/{$file}";
            if ($this->fileManager->exists($filePath)) {
                $this->line("\n{$file}:");
                $content = $this->fileManager->readFile($filePath);
                $processedContent = $this->processTemplate($content, $vars);
                $this->line($processedContent);
            }
        }
    }

    private function generateFromTemplate(string $templatePath, string $targetPath, array $vars, bool $force): void
    {
        $this->info('Generating extension from template...');

        // Remove existing if force is enabled
        if ($force && $this->fileManager->exists($targetPath)) {
            $this->line('Removing existing extension...');
            $this->fileManager->remove($targetPath);
        }

        // Copy and process template
        $this->processTemplateDirectory($templatePath, $targetPath, $vars);

        $this->line('✓ Extension generated from template');
    }

    private function processTemplateDirectory(string $sourcePath, string $targetPath, array $vars): void
    {
        if (!is_dir($sourcePath)) {
            return;
        }

        $this->fileManager->createDirectory($targetPath);

        // Process all items in directory
        $items = scandir($sourcePath);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourceItem = "{$sourcePath}/{$item}";
            $targetItemName = $this->processTemplate($item, $vars);
            $targetItem = "{$targetPath}/{$targetItemName}";

            if (is_dir($sourceItem)) {
                $this->processTemplateDirectory($sourceItem, $targetItem, $vars);
            } else {
                $content = $this->fileManager->readFile($sourceItem);
                $processedContent = $this->processTemplate($content, $vars);
                $this->fileManager->writeFile($targetItem, $processedContent);
            }
        }
    }

    private function processTemplate(string $content, array $vars): string
    {
        foreach ($vars as $key => $value) {
            // Handle basic variable replacement
            $content = str_replace("{{$key}}", $value, $content);

            // Handle |lower filter
            $lowerPattern = '{{' . $key . '|lower}}';
            $content = str_replace($lowerPattern, strtolower($value), $content);
        }

        return $content;
    }

    private function displayCreationSummary(string $name, string $templateType, string $path): void
    {
        $this->line('');
        $this->info('Creation Summary:');
        $this->table(['Property', 'Value'], [
            ['Extension Name', $name],
            ['Template Type', $templateType],
            ['Extension Path', $path],
            ['Status', 'Generated (disabled)']
        ]);

        $this->line('');
        $this->info('Next steps:');
        $this->line("1. Review generated files in: {$path}");
        $this->line("2. Customize the extension logic");
        $this->line("3. Enable the extension: php glueful extensions:enable {$name}");
        $this->line("4. Test the extension functionality");
    }
}
