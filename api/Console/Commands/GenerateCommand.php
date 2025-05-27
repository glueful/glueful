<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;

/**
 * Code Generation Command System
 *
 * Handles code generation for various components:
 * - controller: Generate REST API controllers
 * - model: Generate database models (future)
 * - middleware: Generate custom middleware (future)
 * - service: Generate service classes (future)
 * - repository: Generate repository classes (future)
 *
 * @package Glueful\Console\Commands
 */
class GenerateCommand extends Command
{
    /**
     * Get Command Name
     */
    public function getName(): string
    {
        return 'generate';
    }

    /**
     * Get Command Description
     */
    public function getDescription(): string
    {
        return 'Generate code components from templates';
    }

    /**
     * Get Command Help
     */
    public function getHelp(): string
    {
        return <<<HELP
    Usage:
      generate <type> <name> [options]
    
    Available Generators:
      controller <name>        Generate a REST API controller
      model <name>            Generate a database model
      middleware <name>       Generate custom middleware
      service <name>          Generate a service class
      repository <name>       Generate a repository class
    
    Options:
      -h, --help              Display this help message
      --resource              Generate resource controller with full CRUD methods
      --api                   Generate API-only controller (no create/edit views)
      --force                 Overwrite existing files
    
    Examples:
      php glueful generate controller TaskController
      php glueful generate controller UserController --resource
      php glueful generate controller ApiController --api
      php glueful generate model User
      php glueful generate middleware AuthMiddleware
      php glueful generate service UserService
      php glueful generate repository UserRepository
      php glueful generate controller TaskController --force
    HELP;
    }

    /**
     * Execute Generation Command
     */
    public function execute(array $args = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $type = array_shift($args);

        return match ($type) {
            'controller' => $this->handleController($args),
            'model' => $this->handleModel($args),
            'middleware' => $this->handleMiddleware($args),
            'service' => $this->handleService($args),
            'repository' => $this->handleRepository($args),
            default => $this->handleUnknownType($type)
        };
    }

    /**
     * Handle controller generation
     */
    private function handleController(array $args = []): int
    {
        if (empty($args[0])) {
            $this->error("Controller name is required. Use: php glueful generate controller <ControllerName>");
            return Command::FAILURE;
        }

        $controllerName = $args[0];
        $force = in_array('--force', $args);
        $resource = in_array('--resource', $args);
        $api = in_array('--api', $args);

        // Ensure proper naming convention
        if (!str_ends_with($controllerName, 'Controller')) {
            $controllerName .= 'Controller';
        }

        try {
            $filePath = $this->createController($controllerName, $resource, $api, $force);
            $this->info("Controller created successfully:");
            $this->info("  " . $filePath);

            // Provide helpful next steps
            $this->info("\nNext steps:");
            $this->info("1. Add routes for your controller in routes/ directory");
            $this->info("2. Implement your business logic in the controller methods");
            $this->info("3. Add validation rules as needed");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create controller: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle model generation
     */
    private function handleModel(array $args = []): int
    {
        if (empty($args[0])) {
            $this->error("Model name is required. Use: php glueful generate model <ModelName>");
            return Command::FAILURE;
        }

        $modelName = $args[0];
        $force = in_array('--force', $args);

        try {
            $filePath = $this->createModel($modelName, $force);
            $this->info("Model created successfully:");
            $this->info("  " . $filePath);

            $this->info("\nNext steps:");
            $this->info("1. Update the \$fillable array with your model's attributes");
            $this->info("2. Add any custom relationships or methods");
            $this->info("3. Consider creating a migration for this model");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create model: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle middleware generation
     */
    private function handleMiddleware(array $args = []): int
    {
        if (empty($args[0])) {
            $this->error("Middleware name is required. Use: php glueful generate middleware <MiddlewareName>");
            return Command::FAILURE;
        }

        $middlewareName = $args[0];
        $force = in_array('--force', $args);

        // Ensure proper naming convention
        if (!str_ends_with($middlewareName, 'Middleware')) {
            $middlewareName .= 'Middleware';
        }

        try {
            $filePath = $this->createMiddleware($middlewareName, $force);
            $this->info("Middleware created successfully:");
            $this->info("  " . $filePath);

            $this->info("\nNext steps:");
            $this->info("1. Implement your middleware logic in the process() method");
            $this->info("2. Register the middleware in your application");
            $this->info("3. Apply the middleware to routes as needed");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create middleware: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle service generation
     */
    private function handleService(array $args = []): int
    {
        if (empty($args[0])) {
            $this->error("Service name is required. Use: php glueful generate service <ServiceName>");
            return Command::FAILURE;
        }

        $serviceName = $args[0];
        $force = in_array('--force', $args);

        // Ensure proper naming convention
        if (!str_ends_with($serviceName, 'Service')) {
            $serviceName .= 'Service';
        }

        try {
            $filePath = $this->createService($serviceName, $force);
            $this->info("Service created successfully:");
            $this->info("  " . $filePath);

            $this->info("\nNext steps:");
            $this->info("1. Implement your business logic methods");
            $this->info("2. Add proper validation and error handling");
            $this->info("3. Consider injecting this service into your controllers");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create service: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle repository generation
     */
    private function handleRepository(array $args = []): int
    {
        if (empty($args[0])) {
            $this->error("Repository name is required. Use: php glueful generate repository <RepositoryName>");
            return Command::FAILURE;
        }

        $repositoryName = $args[0];
        $force = in_array('--force', $args);

        // Ensure proper naming convention
        if (!str_ends_with($repositoryName, 'Repository')) {
            $repositoryName .= 'Repository';
        }

        try {
            $filePath = $this->createRepository($repositoryName, $force);
            $this->info("Repository created successfully:");
            $this->info("  " . $filePath);

            $this->info("\nNext steps:");
            $this->info("1. Update the \$table property with your table name");
            $this->info("2. Customize the methods for your specific needs");
            $this->info("3. Consider using this repository in your services");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create repository: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle unknown generator type
     */
    private function handleUnknownType(string $type): int
    {
        $this->error("Unknown generator type: {$type}");
        $this->info("Available generators: controller, model, middleware, service, repository");
        $this->info("Use 'php glueful generate --help' for more information.");
        return Command::FAILURE;
    }

    /**
     * Create controller file from template
     */
    private function createController(
        string $controllerName,
        bool $resource = false,
        bool $api = false,
        bool $force = false
    ): string {
        $controllersDir = dirname(__DIR__, 2) . '/Controllers';
        $fileName = $controllerName . '.php';
        $filePath = $controllersDir . '/' . $fileName;

        if (!is_dir($controllersDir)) {
            mkdir($controllersDir, 0755, true);
        }

        if (file_exists($filePath) && !$force) {
            throw new \RuntimeException("Controller file already exists: {$fileName}. Use --force to overwrite.");
        }

        $content = $this->generateControllerContent($controllerName, $resource, $api);

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write controller file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Generate controller content from template
     */
    private function generateControllerContent(string $controllerName, bool $resource, bool $api): string
    {
        $templatePath = __DIR__ . '/../Templates/Generate/controller.php.tpl';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Controller template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        // Extract resource name from controller name
        $resourceName = $this->extractResourceName($controllerName);
        $description = $this->generateControllerDescription($controllerName, $resourceName, $resource, $api);

        // Replace template placeholders
        $content = str_replace([
            '{{CONTROLLER_NAME}}',
            '{{CONTROLLER_DESCRIPTION}}',
            '{{RESOURCE_NAME}}'
        ], [
            $controllerName,
            $description,
            $resourceName
        ], $template);

        // If API-only, remove view-related methods
        if ($api) {
            $content = $this->removeViewMethods($content);
        }

        // If not resource controller, keep only basic methods
        if (!$resource && !$api) {
            $content = $this->generateBasicController($controllerName, $resourceName, $description);
        }

        return $content;
    }

    /**
     * Extract resource name from controller name
     */
    private function extractResourceName(string $controllerName): string
    {
        $resourceName = str_replace('Controller', '', $controllerName);

        // Convert PascalCase to lowercase
        $resourceName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $resourceName));

        return $resourceName;
    }

    /**
     * Generate controller description
     */
    private function generateControllerDescription(
        string $controllerName,
        string $resourceName,
        bool $resource,
        bool $api
    ): string {
        $type = $api ? 'API' : ($resource ? 'Resource' : 'Basic');
        return "{$type} controller for managing {$resourceName} operations.";
    }

    /**
     * Remove view-related methods for API controllers
     */
    private function removeViewMethods(string $content): string
    {
        // Remove create and edit methods since APIs typically don't need form views
        $content = preg_replace(
            '/\/\*\*\s*\*\s*Show the form for creating.*?\*\/\s*public function create.*?\}\s*/s',
            '',
            $content
        );
        $content = preg_replace(
            '/\/\*\*\s*\*\s*Show the form for editing.*?\*\/\s*public function edit.*?\}\s*/s',
            '',
            $content
        );

        return $content;
    }

    /**
     * Generate basic controller (non-resource)
     */
    private function generateBasicController(string $controllerName, string $resourceName, string $description): string
    {
        return <<<PHP
<?php

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;

/**
 * {$controllerName}
 *
 * {$description}
 *
 * @package Glueful\Controllers
 */
class {$controllerName}
{
    /**
     * Handle the main action for this controller
     *
     * @param Request \$request
     * @return Response
     */
    public function index(Request \$request): Response
    {
        // TODO: Implement your controller logic
        return Response::json([
            'message' => 'Welcome to {$controllerName}',
            'data' => []
        ]);
    }

    /**
     * Handle a specific action
     *
     * @param Request \$request
     * @param string \$id
     * @return Response
     */
    public function show(Request \$request, string \$id): Response
    {
        // TODO: Implement show logic
        return Response::json([
            'message' => 'Show action for {$controllerName}',
            'id' => \$id
        ]);
    }
}
PHP;
    }

    /**
     * Create model file from template
     */
    private function createModel(string $modelName, bool $force = false): string
    {
        $modelsDir = dirname(__DIR__, 2) . '/Models';
        $fileName = $modelName . '.php';
        $filePath = $modelsDir . '/' . $fileName;

        if (!is_dir($modelsDir)) {
            mkdir($modelsDir, 0755, true);
        }

        if (file_exists($filePath) && !$force) {
            throw new \RuntimeException("Model file already exists: {$fileName}. Use --force to overwrite.");
        }

        $content = $this->generateModelContent($modelName);

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write model file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Create middleware file from template
     */
    private function createMiddleware(string $middlewareName, bool $force = false): string
    {
        $middlewareDir = dirname(__DIR__, 2) . '/Http/Middleware';
        $fileName = $middlewareName . '.php';
        $filePath = $middlewareDir . '/' . $fileName;

        if (!is_dir($middlewareDir)) {
            mkdir($middlewareDir, 0755, true);
        }

        if (file_exists($filePath) && !$force) {
            throw new \RuntimeException("Middleware file already exists: {$fileName}. Use --force to overwrite.");
        }

        $content = $this->generateMiddlewareContent($middlewareName);

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write middleware file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Create service file from template
     */
    private function createService(string $serviceName, bool $force = false): string
    {
        $servicesDir = dirname(__DIR__, 2) . '/Services';
        $fileName = $serviceName . '.php';
        $filePath = $servicesDir . '/' . $fileName;

        if (!is_dir($servicesDir)) {
            mkdir($servicesDir, 0755, true);
        }

        if (file_exists($filePath) && !$force) {
            throw new \RuntimeException("Service file already exists: {$fileName}. Use --force to overwrite.");
        }

        $content = $this->generateServiceContent($serviceName);

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write service file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Create repository file from template
     */
    private function createRepository(string $repositoryName, bool $force = false): string
    {
        $repositoryDir = dirname(__DIR__, 2) . '/Repository';
        $fileName = $repositoryName . '.php';
        $filePath = $repositoryDir . '/' . $fileName;

        if (!is_dir($repositoryDir)) {
            mkdir($repositoryDir, 0755, true);
        }

        if (file_exists($filePath) && !$force) {
            throw new \RuntimeException("Repository file already exists: {$fileName}. Use --force to overwrite.");
        }

        $content = $this->generateRepositoryContent($repositoryName);

        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write repository file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Generate model content from template
     */
    private function generateModelContent(string $modelName): string
    {
        $templatePath = __DIR__ . '/../Templates/Generate/model.php.tpl';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Model template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $tableName = $this->generateTableName($modelName);
        $description = "Data model for managing {$tableName} records.";

        return str_replace([
            '{{MODEL_NAME}}',
            '{{MODEL_DESCRIPTION}}',
            '{{TABLE_NAME}}'
        ], [
            $modelName,
            $description,
            $tableName
        ], $template);
    }

    /**
     * Generate middleware content from template
     */
    private function generateMiddlewareContent(string $middlewareName): string
    {
        $templatePath = __DIR__ . '/../Templates/Generate/middleware.php.tpl';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Middleware template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $description = "Custom middleware for request/response processing.";

        return str_replace([
            '{{MIDDLEWARE_NAME}}',
            '{{MIDDLEWARE_DESCRIPTION}}'
        ], [
            $middlewareName,
            $description
        ], $template);
    }

    /**
     * Generate service content from template
     */
    private function generateServiceContent(string $serviceName): string
    {
        $templatePath = __DIR__ . '/../Templates/Generate/service.php.tpl';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Service template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $resourceName = str_replace('Service', '', $serviceName);
        $resourceClass = $resourceName;
        $tableName = $this->generateTableName($resourceName);
        $description = "Business logic service for {$resourceName} operations.";

        return str_replace([
            '{{SERVICE_NAME}}',
            '{{SERVICE_DESCRIPTION}}',
            '{{RESOURCE_NAME}}',
            '{{RESOURCE_CLASS}}',
            '{{TABLE_NAME}}'
        ], [
            $serviceName,
            $description,
            strtolower($resourceName),
            $resourceClass,
            $tableName
        ], $template);
    }

    /**
     * Generate repository content from template
     */
    private function generateRepositoryContent(string $repositoryName): string
    {
        $templatePath = __DIR__ . '/../Templates/Generate/repository.php.tpl';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Repository template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $resourceName = str_replace('Repository', '', $repositoryName);
        $tableName = $this->generateTableName($resourceName);
        $description = "Database repository for {$resourceName} data access operations.";

        return str_replace([
            '{{REPOSITORY_NAME}}',
            '{{REPOSITORY_DESCRIPTION}}',
            '{{TABLE_NAME}}'
        ], [
            $repositoryName,
            $description,
            $tableName
        ], $template);
    }

    /**
     * Generate table name from class name
     */
    private function generateTableName(string $className): string
    {
        // Convert PascalCase to snake_case and pluralize
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));

        // Simple pluralization (just add 's' for now)
        if (!str_ends_with($tableName, 's')) {
            $tableName .= 's';
        }

        return $tableName;
    }
}
