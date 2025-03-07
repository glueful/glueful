<?php
declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Repository\{PermissionRepository, RoleRepository, UserRepository};
use Glueful\Helpers\{Request, ExtensionsManager};
use Glueful\Auth\AuthenticationService;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\{Connection, QueryBuilder};
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Scheduler\JobScheduler;

// // Get all configs
// GET /admin/configs

// // Get single config
// POST /admin/config
// {
//     "filename": "app.php"
// }

// // Update config
// POST /admin/config/update
// {
//     "filename": "app.php",
//     "config": {
//         "key": "value"
//     }
// }

// // Create config
// POST /admin/config/create
// {
//     "filename": "newconfig.php",
//     "config": {
//         "key": "value"
//     }
// }
// // Get all jobs
// POST /admin/jobs
// {
//     "page": 1,
//     "per_page": 25
// }

// // Get filtered jobs
// POST /admin/jobs
// {
//     "page": 1,
//     "per_page": 25,
//     "status": "reserved"  // or "available" or "pending"
// }
class AdminController {
    private AuthenticationService $authService;
    private RoleRepository $roleRepo;
    private PermissionRepository $permissionRepo;
    private UserRepository $userRepository;
    private array $adminPermissions;
    private SchemaManager $schemaManager;
    private QueryBuilder $queryBuilder;
    private MigrationManager $migrationManager;
    private ConfigController $configController;
    private JobScheduler $scheduler;


    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->authService = new AuthenticationService();
        $this->roleRepo = new RoleRepository();
        $this->permissionRepo = new PermissionRepository();

        $connection = new Connection();
        $this->schemaManager = $connection->getSchemaManager();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        $this->migrationManager = new MigrationManager();
        $this->configController = new ConfigController();

         $this->scheduler = new JobScheduler();
        $this->scheduler::getInstance();
       
    }

    /**
     * Admin login endpoint
     * 
     * Authenticates admin users and verifies superuser role before creating session.
     * 
     * @return mixed HTTP response
     */
    public function login()
    {
        try {
            $credentials = Request::getPostData();

            if (!isset($credentials['username']) || !isset($credentials['password'])) {
                return Response::error('Username and password are required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (filter_var($credentials['username'], FILTER_VALIDATE_EMAIL)) {
                return Response::error('Email login not supported', Response::HTTP_BAD_REQUEST)->send();
                $user = $this->userRepository->findByEmail($credentials['username']);
            } else {
                $user = $this->userRepository->findByUsername($credentials['username']);
            }

            // Check if user has superuser role
            $userId = $user['uuid'];
            if (!$userId) {
                return Response::error('User does not exist', Response::HTTP_NOT_FOUND)->send();
            }
            // Check if user has superuser role
            if (!$this->roleRepo->userHasRole($userId, 'superuser')) {
                // Log unauthorized admin access attempt
                error_log("Unauthorized access attempt by user ID: $userId");
                return Response::error('Insufficient privileges', Response::HTTP_FORBIDDEN)->send();
            }
             // First authenticate the user
             $authResult = $this->authService->authenticate($credentials);
             if (!$authResult) {
                 return Response::error('Invalid credentials', Response::HTTP_UNAUTHORIZED)->send();
             }

            $authResult['user']['is_admin'] = true;
            return Response::ok($authResult, 'Login successful')->send();

        } catch (\Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return Response::error(
                'Login failed: ' . ($e->getMessage()),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

     /**
     * User logout
     * 
     * Terminates user session and invalidates tokens.
     * 
     * @return mixed HTTP response
     */
    public function logout()
    {
        try {
            $token = $this->authService->extractTokenFromRequest();
            
            if (!$token) {
                return Response::error('No token provided', Response::HTTP_BAD_REQUEST)->send();
            }
            
            $success = $this->authService->terminateSession($token);
            
            if ($success) {
                return Response::ok(null, 'Logged out successfully')->send();
            }
            
            return Response::error('Logout failed', Response::HTTP_BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Logout failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create new database table
     * 
     * @return mixed HTTP response
     */
    public function createTable(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name']) || !isset($data['columns'])) {
                return Response::error('Table name and columns are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $columns = $data['columns'];

            $result = $this->schemaManager->createTable($tableName, $columns);

            if (!$result['success']) {
                return Response::error($result['message'], Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok([
                'table' => $tableName,
                'columns' => $columns
            ], 'Table created successfully')->send();

        } catch (\Exception $e) {
            error_log("Create table error: " . $e->getMessage());
            return Response::error(
                'Failed to create table: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Drop database table
     */
    public function dropTable(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->schemaManager->dropTable($data['table_name']);

            if (!$result['success']) {
                return Response::error($result['message'], Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok(null, 'Table dropped successfully')->send();

        } catch (\Exception $e) {
            error_log("Drop table error: " . $e->getMessage());
            return Response::error(
                'Failed to drop table: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Add column to existing table
     */
    public function addColumn(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name']) || !isset($data['column'])) {
                return Response::error('Table name and column details are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->schemaManager->addColumn(
                $data['table_name'],
                $data['column']['name'],
                $data['column']['type'],
                $data['column']['options'] ?? []
            );

            if (!$result['success']) {
                return Response::error($result['message'], Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok([
                'table' => $data['table_name'],
                'column' => $data['column']
            ], 'Column added successfully')->send();

        } catch (\Exception $e) {
            error_log("Add column error: " . $e->getMessage());
            return Response::error(
                'Failed to add column: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Drop column from table
     */
    public function dropColumn(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name']) || !isset($data['column_name'])) {
                return Response::error('Table and column names are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->schemaManager->dropColumn(
                $data['table_name'],
                $data['column_name']
            );

            if (!$result['success']) {
                return Response::error($result['message'], Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok(null, 'Column dropped successfully')->send();

        } catch (\Exception $e) {
            error_log("Drop column error: " . $e->getMessage());
            return Response::error(
                'Failed to drop column: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get list of all tables
     */
    public function getTables(): mixed
    {
        try {
            $tables = $this->schemaManager->getTables();
            return Response::ok(['tables' => $tables], 'Tables retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get tables error: " . $e->getMessage());
            return Response::error(
                'Failed to get tables: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get table size information
     */
    public function getTableSize(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $size = $this->schemaManager->getTableSize($data['table_name']);
            
            return Response::ok([
                'table' => $data['table_name'],
                'size' => $size
            ], 'Table size retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get table size error: " . $e->getMessage());
            return Response::error(
                'Failed to get table size: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Fetch paginated data from a table
     * 
     * @return mixed HTTP response
     */
    public function getTableData(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Set default values for pagination and filtering
            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['per_page'] ?? 25);
            
            // Build the query using QueryBuilder
            $results = $this->queryBuilder->select($data['table_name'], ['*'])
                ->orderBy(['created_at' => 'DESC'])
                ->paginate($page, $perPage);
            

            return Response::ok($results, 'Data retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get table data error: " . $e->getMessage());
            return Response::error(
                'Failed to get table data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get all permissions with pagination
     * 
     * @return mixed HTTP response
     */
    public function getPermissions(): mixed
    {
        try {
            $data = Request::getPostData();
            
            // Set default values for pagination and filtering
            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['per_page'] ?? 25);
            
            // Build query for permissions
            $results = $this->queryBuilder
            ->join('roles', 'role_permissions.role_uuid = roles.uuid', 'INNER') // Ensure the JOIN is applied
            ->select('role_permissions', [
                'role_permissions.model',
                'role_permissions.permissions',
                'roles.name'
            ])
            ->paginate($page, $perPage);

            return Response::ok($results, 'Permissions retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get permissions error: " . $e->getMessage());
            return Response::error(
                'Failed to get permissions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get all extensions with pagination and status
     * 
     * @return mixed HTTP response
     */
    public function getExtensions(): mixed
    {
        try {
            $extensions = ExtensionsManager::getLoadedExtensions();
            $extensionData = [];
            $extensionConfigFile = ExtensionsManager::getConfigPath();
            $enabledExtensions = ExtensionsManager::getEnabledExtensions($extensionConfigFile);
            if (empty($extensions)) {
                return Response::ok([], 'No extensions found')->send();
            }
            
            foreach ($extensions as $extension) {
                $reflection = new \ReflectionClass($extension);
                $shortName = $reflection->getShortName();
                $isEnabled = in_array($shortName, $enabledExtensions);
                $extensionData[] = [
                    'name' => $shortName,
                    'description' => ExtensionsManager::getExtensionMetadata($shortName, 'description'),
                    'version' => ExtensionsManager::getExtensionMetadata($shortName, 'version'),
                    'author' => ExtensionsManager::getExtensionMetadata($shortName, 'author'),
                    'status' => $isEnabled ? 'enabled' : 'disabled',
                ];
            }
            return Response::ok($extensionData, 'Extensions retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get extensions error: " . $e->getMessage());
            return Response::error(
                'Failed to get extensions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Enable an extension
     * 
     * @return mixed HTTP response
     */
    public function enableExtension(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['extension'])) {
                return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!ExtensionsManager::extensionExists($data['extension'])) {
                return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
            }

            $success = ExtensionsManager::enableExtension($data['extension']);

            if (!$success) {
                return Response::error('Failed to enable extension', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(
                ['extension' => $data['extension']], 
                'Extension enabled successfully'
            )->send();

        } catch (\Exception $e) {
            error_log("Enable extension error: " . $e->getMessage());
            return Response::error(
                'Failed to enable extension: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Disable an extension
     * 
     * @return mixed HTTP response
     */
    public function disableExtension(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['extension'])) {
                return Response::error('Extension name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!ExtensionsManager::extensionExists($data['extension'])) {
                return Response::error('Extension not found', Response::HTTP_NOT_FOUND)->send();
            }

            $success = ExtensionsManager::disableExtension($data['extension']);

            if (!$success) {
                return Response::error('Failed to disable extension', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(
                ['extension' => $data['extension']], 
                'Extension disabled successfully'
            )->send();

        } catch (\Exception $e) {
            error_log("Disable extension error: " . $e->getMessage());
            return Response::error(
                'Failed to disable extension: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get all database migrations with status
     * 
     * @return mixed HTTP response
     */
    public function getMigrations(): mixed
    {
        try {
            $data = Request::getPostData();
            
            // Set default values for pagination and filtering
            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['per_page'] ?? 25);
            
            // Get migrations from schema manager
            $results = $this->queryBuilder->select('migrations', [
                'migrations.id',
                'migrations.migration',
                'migrations.batch',
                'migrations.applied_at',
                'migrations.checksum',
                'migrations.description'
            ])
            ->orderBy(['applied_at' => 'DESC'])
            ->paginate($page, $perPage);

            return Response::ok($results, 'Migrations retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get migrations error: " . $e->getMessage());
            return Response::error(
                'Failed to get migrations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get pending migrations that haven't been executed
     * 
     * @return mixed HTTP response
     */
    public function getPendingMigrations(): mixed
    {
        try {
            // Get all available migration files
            $pendingMigrations = $this->migrationManager->getPendingMigrations();

            // Format the response data
            $formattedMigrations = array_map(function($migration) {
                return [
                    'name' => basename($migration),
                    'status' => 'pending',
                    'migration_file' => $migration
                ];
            }, $pendingMigrations);

            return Response::ok([
                'pending_count' => count($pendingMigrations),
                'migrations' => $formattedMigrations
            ], 'Pending migrations retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get pending migrations error: " . $e->getMessage());
            return Response::error(
                'Failed to get pending migrations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get all configurations
     */
    public function getAllConfigs(): mixed
    {
        try {
            $configs = $this->configController->getConfigs();
            return Response::ok($configs,'Configurations retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get configs error: " . $e->getMessage());
            return Response::error(
                'Failed to get configurations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get configuration by filename
     */
    public function getConfig($filename): mixed
    {
        try {
            if (!isset($filename)) {
                return Response::error('Filename is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $config = $this->configController->getConfigByFile($filename);
            
            if ($config === null) {
                return Response::error('Configuration file not found', Response::HTTP_NOT_FOUND)->send();
            }

            return Response::ok(['config' => $config], 'Configuration retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get config error: " . $e->getMessage());
            return Response::error(
                'Failed to get configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update configuration
     */
    public function updateConfig(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['filename']) || !isset($data['config'])) {
                return Response::error('Filename and configuration data are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $success = $this->configController->updateConfig($data['filename'], $data['config']);
            
            if (!$success) {
                return Response::error('Failed to update configuration', Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok(null, 'Configuration updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update config error: " . $e->getMessage());
            return Response::error(
                'Failed to update configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create new configuration
     */
    public function createConfig(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['filename']) || !isset($data['config'])) {
                return Response::error('Filename and configuration data are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $success = $this->configController->createConfig($data['filename'], $data['config']);
            
            if (!$success) {
                return Response::error('Failed to create configuration', Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok(null, 'Configuration created successfully')->send();
        } catch (\Exception $e) {
            error_log("Create config error: " . $e->getMessage());
            return Response::error(
                'Failed to create configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get all jobs with pagination and filtering
     * 
     * @return mixed HTTP response
     */
    public function getScheduledJobs(): mixed
    {
        try {
            $data = Request::getPostData();
            
            // Build base query
            $jobs = $this->scheduler->getJobs();

            if (empty($jobs)) {
                return Response::ok([], 'No jobs found')->send();
            }
           

            return Response::ok($jobs, 'Jobs retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get jobs error: " . $e->getMessage());
            return Response::error(
                'Failed to get jobs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Run all due jobs
     * 
     * @return mixed HTTP response
     */
    public function runDueJobs(): mixed
    {
        try {
            $this->scheduler->runDueJobs();
            return Response::ok(null, 'Scheduled tasks completed')->send();
        } catch (\Exception $e) {
            error_log("Run due jobs error: " . $e->getMessage());
            return Response::error(
                'Failed to run due jobs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }


    public function runAllJobs(): mixed
    {
        try {
            $this->scheduler->runAllJobs();
            return Response::ok(null, 'All scheduled tasks completed')->send();
        } catch (\Exception $e) {
            error_log("Run all jobs error: " . $e->getMessage());
            return Response::error(
                'Failed to run all jobs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }


    public function runJob($jobName): mixed
    {
        try {
            $this->scheduler->runJob($jobName);
            return Response::ok(null, 'Scheduled task completed')->send();
        } catch (\Exception $e) {
            error_log("Run job error: " . $e->getMessage());
            return Response::error(
                'Failed to run job: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    public function createJob(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['job_name']) || !isset($data['job_data'])) {
                return Response::error('Job name and data are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $this->scheduler->register($data['job_name'], $data['job_data']);
            return Response::ok(null, 'Scheduled task created')->send();
        } catch (\Exception $e) {
            error_log("Create job error: " . $e->getMessage());
            return Response::error(
                'Failed to create job: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    public function getRoles (): mixed
    {
        try {
            $roles = $this->roleRepo->getRoles();
            return Response::ok($roles, 'Roles retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get roles error: " . $e->getMessage());
            return Response::error(
                'Failed to get roles: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    public function getBaseUrl(): mixed
    {
        try {
            $baseUrl = config('paths.api_base_url');
            $cdn = config('paths.cdn');

            $result = [
                'base_url' => $baseUrl,
                'cdn' => $cdn
            ];

            return Response::ok($result, 'Base URL retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get base URL error: " . $e->getMessage());
            return Response::error(
                'Failed to get base URL: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create a new permission
     */
    public function createPermission(): mixed 
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['model']) || !isset($data['permissions']) || !is_array($data['permissions'])) {
                return Response::error('Model name and permissions array are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->permissionRepo->createPermission(
                $data['model'],
                $data['permissions'],
                $data['description'] ?? null
            );

            return Response::ok($result, 'Permission created successfully')->send();
        } catch (\Exception $e) {
            error_log("Create permission error: " . $e->getMessage());
            return Response::error(
                'Failed to create permission: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update an existing permission
     */
    public function updatePermission():mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['model']) || !isset($data['permissions']) ) {
                return Response::error('Model name and permissions are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $result = $this->permissionRepo->updatePermission(
                $data['uuid'],
                $data
            );

            return Response::ok($result, 'Permission updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update permission error: " . $e->getMessage());
            return Response::error(
                'Failed to update permission: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Assign permissions to a role
     */
    public function assignPermissionsToRole(): mixed
    {
      try {
        $data = Request::getPostData();
        $result = $this->permissionRepo->assignRolePermission($data['role_uuid'],$data['model'], $data['permissions']);
        return Response::ok($result, 'Permissions assigned to role successfully')->send();
      } catch (\Exception $e) {
        error_log("Assing permissions to role error: " . $e->getMessage());
        return Response::error(
            'Assing permissions to role error: ' . $e->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        )->send();
      }
    }

    public function updateRolePermission(): mixed
    {
        try {
            $data = Request::getPostData();
            $result = $this->permissionRepo->updateRolePermission($data['role_uuid'], $data['model'], $data['permissions'],);
            return Response::ok($result, 'Role permissions updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update role permissions error: " . $e->getMessage());
            return Response::error(
                'Update role permissions error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    public function removeRolePermission(): mixed
    {
        try {
            $data = Request::getPostData();
            $roleUuid = $data['role_uuid'];
            $model = $data['model'];
            $result = $this->permissionRepo->removeRolePermission($roleUuid, $model);
            return Response::ok($result, 'Role permissions removed successfully')->send();
        } catch (\Exception $e) {
            error_log("Remove role permissions error: " . $e->getMessage());
            return Response::error(
                'Remove role permissions error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Assign roles to a user
     */
    public function assignRolesToUser():mixed
    {
        try {
            $data = Request::getPostData();
            $result = $this->roleRepo->assignRole($data['user_uuid'], $data['role_uuid']);
            return Response::ok($result, 'Role assigned to user successfully')->send();
        } catch (\Exception $e) {
            error_log("Assign roles to user error: " . $e->getMessage());
            return Response::error(
                'Assign roles to user error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update user's roles
     */
    public function removeUserRole():mixed
    {
        try {
            $data = Request::getPostData();
            $result = $this->roleRepo->unassignRole($data['user_uuid'], $data['role_uuid']);
            return Response::ok($result, 'Role removed from user successfully')->send();
        } catch (\Exception $e) {
            error_log("Remove user role error: " . $e->getMessage());
            return Response::error(
                'Remove user role error: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update role's permissions
     */
    public function updateRolePermissions():mixed
    {
        return[];
    }
}