<?php

namespace Glueful\Console\Commands\Generate;

use Glueful\Console\BaseCommand;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate Controller Command
 * - Interactive prompts for controller name validation
 * - Enhanced template management with better error handling
 * - Progress indicators for file creation
 * - Detailed validation with helpful error messages
 * - Better organization of generated files
 * - FileFinder and FileManager integration for safe file operations
 * @package Glueful\Console\Commands\Generate
 */
#[AsCommand(
    name: 'generate:controller',
    description: 'Generate a REST API controller from template'
)]
class ControllerCommand extends BaseCommand
{
    private FileFinder $fileFinder;
    private FileManager $fileManager;
    protected function configure(): void
    {
        $this->setDescription('Generate a REST API controller from template')
             ->setHelp('This command generates a controller class with optional resource or API-only methods.')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the controller to generate (e.g., TaskController)'
             )
             ->addOption(
                 'resource',
                 'r',
                 InputOption::VALUE_NONE,
                 'Generate resource controller with full CRUD methods'
             )
             ->addOption(
                 'api',
                 'a',
                 InputOption::VALUE_NONE,
                 'Generate API-only controller (no create/edit views)'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Overwrite existing files without confirmation'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $controllerName = $input->getArgument('name');
        $resource = $input->getOption('resource');
        $api = $input->getOption('api');
        $force = $input->getOption('force');

        // Validate and normalize controller name
        $controllerName = $this->normalizeControllerName($controllerName);

        if (!$this->validateControllerName($controllerName)) {
            return self::FAILURE;
        }

        try {
            $this->info("Generating controller: {$controllerName}");

            if ($resource && $api) {
                $this->warning('Both --resource and --api flags provided. Using --api only.');
                $resource = false;
            }

            $filePath = $this->createController($controllerName, $resource, $api, $force);

            $this->success("Controller created successfully!");
            $this->table(['Property', 'Value'], [
                ['File Path', $filePath],
                ['Controller Type', $this->getControllerType($resource, $api)],
                ['Methods Generated', $this->getMethodsDescription($resource, $api)]
            ]);

            $this->displayNextSteps();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create controller: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function normalizeControllerName(string $name): string
    {
        // Remove any file extension
        $name = preg_replace('/\.php$/', '', $name);

        // Ensure proper naming convention
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        // Ensure PascalCase
        return ucfirst($name);
    }

    private function validateControllerName(string $name): bool
    {
        if (empty($name)) {
            $this->error('Controller name cannot be empty.');
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*Controller$/', $name)) {
            $this->error('Controller name must be in PascalCase and end with "Controller".');
            $this->tip('Example: TaskController, UserController, ApiController');
            return false;
        }

        return true;
    }

    private function createController(string $controllerName, bool $resource, bool $api, bool $force): string
    {
        $controllersDir = dirname(__DIR__, 4) . '/Controllers';
        $fileName = $controllerName . '.php';
        $filePath = $controllersDir . '/' . $fileName;

        // Use FileManager for safe directory creation
        if (!$this->fileManager->exists($controllersDir)) {
            $this->fileManager->createDirectory($controllersDir);
        }

        // Check for existing files using FileFinder
        if ($this->fileManager->exists($filePath) && !$force) {
            if (!$this->confirm("Controller file already exists: {$fileName}. Overwrite?", false)) {
                throw new \Exception('Controller generation cancelled.');
            }
        }

        $this->info('Generating controller content...');
        $content = $this->generateControllerContent($controllerName, $resource, $api);

        $this->info('Writing controller file...');
        $success = $this->fileManager->writeFile($filePath, $content);

        if (!$success) {
            throw BusinessLogicException::operationNotAllowed(
                'code_generation',
                "Failed to write controller file: {$filePath}"
            );
        }

        return $filePath;
    }

    private function generateControllerContent(string $controllerName, bool $resource, bool $api): string
    {
        // Extract resource name from controller name
        $resourceName = $this->extractResourceName($controllerName);
        $description = $this->generateControllerDescription($controllerName, $resourceName, $resource, $api);

        // If not resource controller, generate basic controller
        if (!$resource && !$api) {
            return $this->generateBasicController($controllerName, $resourceName, $description);
        }

        // For resource/API controllers, we'd load from template
        // For now, generate basic structure
        return $this->generateResourceController($controllerName, $resourceName, $description, $resource, $api);
    }

    private function extractResourceName(string $controllerName): string
    {
        $resourceName = str_replace('Controller', '', $controllerName);

        // Convert PascalCase to lowercase with underscores
        $resourceName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $resourceName));

        return $resourceName;
    }

    private function generateControllerDescription(
        string $controllerName,
        string $resourceName,
        bool $resource,
        bool $api
    ): string {
        $type = $api ? 'API' : ($resource ? 'Resource' : 'Basic');
        return "{$type} controller for managing {$resourceName} operations.";
    }

    private function generateBasicController(string $controllerName, string $resourceName, string $description): string
    {
        return <<<PHP
<?php

namespace Glueful\Controllers;

use Glueful\Controllers\BaseController;
use Glueful\Http\Response;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Glueful\Exceptions\NotFoundException;
use Glueful\Exceptions\ValidationException;
use Glueful\Helpers\ValidationHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * {$controllerName}
 * {$description}
 * @package Glueful\Controllers
 */
class {$controllerName} extends BaseController
{
    public function __construct(
        ?RepositoryFactory \$repositoryFactory = null,
        ?AuthenticationManager \$authManager = null,
        ?AuditLogger \$auditLogger = null,
        ?Request \$request = null
    ) {
        parent::__construct(\$repositoryFactory, \$authManager, \$auditLogger, \$request);
    }

    /**
     * Handle the main action for this controller
     * @route GET /api/{$resourceName}
     * @param Request \$request
     * @return Response
     */
    public function index(Request \$request): Response
    {
        try {
            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'list');

            // TODO: Implement your controller logic
            \$data = [
                'message' => 'Welcome to {$controllerName}',
                'data' => []
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'list', 'info', [
                'controller' => '{$controllerName}',
                'action' => 'index'
            ]);

            return Response::ok(\$data)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    /**
     * Handle a specific action
     * @route GET /api/{$resourceName}/{id}
     * @param array \$params
     * @return Response
     */
    public function show(array \$params): Response
    {
        try {
            \$id = \$params['id'] ?? null;
            
            if (!\$id) {
                throw new ValidationException('ID parameter is required');
            }

            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'read');

            // TODO: Implement show logic
            \$data = [
                'message' => 'Show action for {$controllerName}',
                'id' => \$id
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'show', 'info', [
                'controller' => '{$controllerName}',
                'action' => 'show',
                'id' => \$id
            ]);

            return Response::ok(\$data)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }

    private function handleException(\Exception \$e): Response
    {
        if (\$e instanceof ValidationException) {
            return Response::error(\$e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
        }
        
        if (\$e instanceof NotFoundException) {
            return Response::error(\$e->getMessage(), Response::HTTP_NOT_FOUND)->send();
        }

        // Log unexpected errors
        \$this->asyncAudit('{$resourceName}', 'error', 'error', [
            'controller' => '{$controllerName}',
            'error' => \$e->getMessage()
        ]);

        return Response::error('Internal server error', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
}
PHP;
    }

    private function generateResourceController(
        string $controllerName,
        string $resourceName,
        string $description,
        bool $resource,
        bool $api
    ): string {
        $methods = $this->generateResourceMethods($resourceName, $api);

        return <<<PHP
<?php

namespace Glueful\Controllers;

use Glueful\Controllers\BaseController;
use Glueful\Http\Response;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Glueful\Exceptions\NotFoundException;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Helpers\ValidationHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * {$controllerName}
 * {$description}
 * @package Glueful\Controllers
 */
class {$controllerName} extends BaseController
{
    public function __construct(
        ?RepositoryFactory \$repositoryFactory = null,
        ?AuthenticationManager \$authManager = null,
        ?AuditLogger \$auditLogger = null,
        ?Request \$request = null
    ) {
        parent::__construct(\$repositoryFactory, \$authManager, \$auditLogger, \$request);
    }

{$methods}

    private function handleException(\Exception \$e): Response
    {
        if (\$e instanceof ValidationException) {
            return Response::error(\$e->getMessage(), Response::HTTP_BAD_REQUEST)->send();
        }
        
        if (\$e instanceof NotFoundException) {
            return Response::error(\$e->getMessage(), Response::HTTP_NOT_FOUND)->send();
        }

        if (\$e instanceof BusinessLogicException) {
            return Response::error(\$e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY)->send();
        }

        // Log unexpected errors
        \$this->asyncAudit('{$resourceName}', 'error', 'error', [
            'controller' => '{$controllerName}',
            'error' => \$e->getMessage()
        ]);

        return Response::error('Internal server error', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
}
PHP;
    }

    private function generateResourceMethods(string $resourceName, bool $api): string
    {
        $methods = [
            $this->generateIndexMethod($resourceName),
            $this->generateShowMethod($resourceName),
            $this->generateStoreMethod($resourceName),
            $this->generateUpdateMethod($resourceName),
            $this->generateDestroyMethod($resourceName)
        ];

        if (!$api) {
            array_splice($methods, 2, 0, [$this->generateCreateMethod($resourceName)]);
            array_splice($methods, 4, 0, [$this->generateEditMethod($resourceName)]);
        }

        return implode("\n\n", $methods);
    }

    private function generateIndexMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Display a listing of {$resourceName} resources
     * @route GET /api/{$resourceName}
     * @param Request \$request
     * @return Response
     */
    public function index(Request \$request): Response
    {
        try {
            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'list');

            // TODO: Implement pagination and filtering
            \$page = (int) \$request->query->get('page', 1);
            \$perPage = (int) \$request->query->get('per_page', 20);
            
            // TODO: Get repository and fetch data
            // \$repository = \$this->repositoryFactory->getRepository('{$resourceName}');
            // \$result = \$repository->paginate(\$page, \$perPage);

            \$data = [
                'data' => [],
                'pagination' => [
                    'page' => \$page,
                    'per_page' => \$perPage,
                    'total' => 0
                ]
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'list', 'info', [
                'page' => \$page,
                'per_page' => \$perPage
            ]);

            return Response::ok(\$data)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    private function generateShowMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Display the specified {$resourceName} resource
     * @route GET /api/{$resourceName}/{id}
     * @param array \$params
     * @return Response
     */
    public function show(array \$params): Response
    {
        try {
            \$id = \$params['id'] ?? null;
            
            if (!\$id) {
                throw new ValidationException('ID parameter is required');
            }

            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'read');

            // TODO: Get repository and fetch data
            // \$repository = \$this->repositoryFactory->getRepository('{$resourceName}');
            // \$resource = \$repository->findById(\$id);
            // if (!\$resource) {
            //     throw new NotFoundException('{$resourceName} not found');
            // }

            \$data = [
                'id' => \$id,
                // Add resource data here
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'show', 'info', [
                'id' => \$id
            ]);

            return Response::ok(\$data)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    private function generateCreateMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Show the form for creating a new {$resourceName} resource
     *
     * @param Request \$request
     * @return Response
     */
    public function create(Request \$request): Response
    {
        // TODO: Return create form view
        return Response::json([
            'message' => 'Create {$resourceName} form',
            'form_fields' => []
        ]);
    }
PHP;
    }

    private function generateStoreMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Store a newly created {$resourceName} resource
     * @route POST /api/{$resourceName}
     * @param Request \$request
     * @return Response
     */
    public function store(Request \$request): Response
    {
        try {
            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'create');

            // TODO: Require authentication
            // \$this->requirePermission('{$resourceName}.create', '{$resourceName}');

            // Get and validate input data
            \$data = \$request->toArray();
            
            // TODO: Define validation rules
            \$rules = [
                // 'name' => 'required|string|max:255',
                // 'email' => 'required|email|unique:{$resourceName}s,email',
            ];
            
            // Validate input
            \$validatedData = ValidationHelper::validateAndSanitize(\$data, \$rules);

            // TODO: Create the resource
            // \$repository = \$this->repositoryFactory->getRepository('{$resourceName}');
            // \$resource = \$repository->create(\$validatedData);

            \$responseData = [
                'message' => ucfirst('{$resourceName}') . ' created successfully',
                'data' => \$validatedData
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'create', 'info', [
                'data' => \$validatedData
            ]);

            return Response::created(\$responseData)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    private function generateEditMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Show the form for editing the specified {$resourceName} resource
     *
     * @param Request \$request
     * @param string \$id
     * @return Response
     */
    public function edit(Request \$request, string \$id): Response
    {
        // TODO: Return edit form view
        return Response::json([
            'message' => 'Edit {$resourceName} form',
            'id' => \$id,
            'form_fields' => []
        ]);
    }
PHP;
    }

    private function generateUpdateMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Update the specified {$resourceName} resource
     * @route PUT /api/{$resourceName}/{id}
     * @param array \$params
     * @param Request \$request
     * @return Response
     */
    public function update(array \$params, Request \$request): Response
    {
        try {
            \$id = \$params['id'] ?? null;
            
            if (!\$id) {
                throw new ValidationException('ID parameter is required');
            }

            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'update');

            // TODO: Require authentication
            // \$this->requirePermission('{$resourceName}.update', '{$resourceName}');

            // Get and validate input data
            \$data = \$request->toArray();
            
            // TODO: Define validation rules
            \$rules = [
                // 'name' => 'string|max:255',
                // 'email' => 'email|unique:{$resourceName}s,email,' . \$id,
            ];
            
            // Validate input
            \$validatedData = ValidationHelper::validateAndSanitize(\$data, \$rules);

            // TODO: Update the resource
            // \$repository = \$this->repositoryFactory->getRepository('{$resourceName}');
            // \$resource = \$repository->findById(\$id);
            // if (!\$resource) {
            //     throw new NotFoundException('{$resourceName} not found');
            // }
            // \$updatedResource = \$repository->update(\$id, \$validatedData);

            \$responseData = [
                'message' => ucfirst('{$resourceName}') . ' updated successfully',
                'id' => \$id,
                'data' => \$validatedData
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'update', 'info', [
                'id' => \$id,
                'data' => \$validatedData
            ]);

            return Response::ok(\$responseData)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    private function generateDestroyMethod(string $resourceName): string
    {
        return <<<PHP
    /**
     * Remove the specified {$resourceName} resource
     * @route DELETE /api/{$resourceName}/{id}
     * @param array \$params
     * @return Response
     */
    public function destroy(array \$params): Response
    {
        try {
            \$id = \$params['id'] ?? null;
            
            if (!\$id) {
                throw new ValidationException('ID parameter is required');
            }

            // Apply rate limiting
            \$this->rateLimitResource('{$resourceName}', 'delete');

            // TODO: Require authentication
            // \$this->requirePermission('{$resourceName}.delete', '{$resourceName}');

            // TODO: Delete the resource
            // \$repository = \$this->repositoryFactory->getRepository('{$resourceName}');
            // \$resource = \$repository->findById(\$id);
            // if (!\$resource) {
            //     throw new NotFoundException('{$resourceName} not found');
            // }
            // \$repository->delete(\$id);

            \$responseData = [
                'message' => ucfirst('{$resourceName}') . ' deleted successfully',
                'id' => \$id
            ];

            // Log audit trail
            \$this->asyncAudit('{$resourceName}', 'delete', 'warning', [
                'id' => \$id
            ]);

            return Response::ok(\$responseData)->send();
        } catch (\Exception \$e) {
            return \$this->handleException(\$e);
        }
    }
PHP;
    }

    private function getControllerType(bool $resource, bool $api): string
    {
        if ($api) {
            return 'API Controller';
        }
        if ($resource) {
            return 'Resource Controller';
        }
        return 'Basic Controller';
    }

    private function getMethodsDescription(bool $resource, bool $api): string
    {
        if ($api) {
            return 'index, show, store, update, destroy (API only)';
        }
        if ($resource) {
            return 'index, create, store, show, edit, update, destroy';
        }
        return 'index, show (basic methods)';
    }

    private function displayNextSteps(): void
    {
        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Add routes for your controller in routes/ directory');
        $this->line('2. Uncomment and implement repository logic');
        $this->line('3. Define validation rules in the TODO sections');
        $this->line('4. Set up authentication and permissions if required');
        $this->line('5. Register controller in DI container (ControllerServiceProvider)');
        $this->line('6. Test your endpoints and verify audit logging');
        $this->line('');
        $this->info('Generated controller follows Glueful patterns:');
        $this->line('• Extends BaseController with full feature set');
        $this->line('• Includes rate limiting, audit logging, and error handling');
        $this->line('• Uses repository pattern and dependency injection');
        $this->line('• Follows enterprise authentication and authorization');
    }

    private function initializeServices(): void
    {
        $this->fileFinder = $this->getService(FileFinder::class);
        $this->fileManager = $this->getService(FileManager::class);
    }
}
