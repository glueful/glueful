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

class AdminController {
    private AuthenticationService $authService;
    private RoleRepository $roleRepo;
    private PermissionRepository $permissionRepo;
    private UserRepository $userRepository;
    private array $adminPermissions;
    private SchemaManager $schemaManager;
    private QueryBuilder $queryBuilder;
    private MigrationManager $migrationManager;

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->authService = new AuthenticationService();
        $this->roleRepo = new RoleRepository();
        $this->permissionRepo = new PermissionRepository();

        $connection = new Connection();
        $this->schemaManager = $connection->getSchemaManager();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        $this->migrationManager = new MigrationManager();
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
            
            // Basic validation
            if (!isset($credentials['email']) || !isset($credentials['password'])) {
                return Response::error('Email and password are required', Response::HTTP_BAD_REQUEST)->send();
            }

           
            $user = $this->userRepository->findByEmail($credentials['username']);
            // Check if user has superuser role
            $userId = $user['uuid'];
            if (!$userId) {
                return Response::error('User not found', Response::HTTP_NOT_FOUND)->send();
            }
            // Check if user has superuser role
            if (!$this->roleRepo->userHasRole($userId, 'superuser')) {
                // Log unauthorized admin access attempt
                error_log("Unauthorized  access attempt by user ID: $userId");
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
            $results =$this->queryBuilder->select('role_permissions', [
                'role_permissions.model',
                'role_permissions.permissions',
            ])
            ->join('roles', 'role_permissions.role_uuid = roles.uuid', 'LEFT')
            ->where(['roles.name' => 'superuser'])
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
                'migrations.executed_at',
                'migrations.status',
                'migrations.description'
            ])
            ->orderBy(['executed_at' => 'DESC'])
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
}