<?php
declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Repository\{PermissionRepository, RoleRepository, UserRepository};
use Glueful\Helpers\{Request, ExtensionsManager};
use Glueful\Auth\{AuthBootstrap, TokenManager};
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\{Connection, QueryBuilder};
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Scheduler\JobScheduler;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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
    private RoleRepository $roleRepo;
    private PermissionRepository $permissionRepo;
    private UserRepository $userRepository;
    private SchemaManager $schemaManager;
    private QueryBuilder $queryBuilder;
    private MigrationManager $migrationManager;
    private ConfigController $configController;
    private JobScheduler $scheduler;
    private $authManager;

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->roleRepo = new RoleRepository();
        $this->permissionRepo = new PermissionRepository();

        $connection = new Connection();
        $this->schemaManager = $connection->getSchemaManager();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        $this->migrationManager = new MigrationManager();
        $this->configController = new ConfigController();

        $this->scheduler = new JobScheduler();
        $this->scheduler::getInstance();
        
        // Initialize auth system
        AuthBootstrap::initialize();
        $this->authManager = AuthBootstrap::getManager();
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
            
            // Create a Symfony request with credentials for authentication
            $request = new SymfonyRequest([], [], [], [], [], [], json_encode($credentials));
            $request->headers->set('Content-Type', 'application/json');
            
            // Authenticate using the admin authentication provider
            $userData = $this->authManager->authenticateWithProvider('admin', $request);
            
            if (!$userData) {
                return Response::error('Invalid credentials', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Log the admin access
            $this->authManager->logAccess($userData['user'], $request);
            
            return Response::ok($userData, 'Login successful')->send();

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
            $request = SymfonyRequest::createFromGlobals();
            $userData = $this->authenticate($request);
            
            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Extract token for terminating session
            $token = $userData['token'] ?? null;
            
            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }
            
            // Use TokenManager to revoke the session instead of the non-existent invalidateToken method
            $success = TokenManager::revokeSession($token);
            
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
            $columnsData = $data['columns'];
            
            // Convert columns array to the format expected by SchemaManager
            $columns = [];
            foreach ($columnsData as $column) {
                if (!isset($column['name']) || !isset($column['type'])) {
                    continue;
                }
                
                $columnName = $column['name'];
                $columnType = $column['type'];
                $options = $column['options'] ?? [];
                
                // Build column definition using the type directly from frontend
                $columnDef = $columnType;
                
                // Add PRIMARY KEY if specified
                if (isset($options['primary']) && $options['primary']) {
                    $columnDef .= " " . (is_string($options['primary']) ? $options['primary'] : "PRIMARY KEY");
                }
                
                // Add AUTO_INCREMENT if specified
                if (isset($options['autoIncrement']) && !empty($options['autoIncrement'])) {
                    $columnDef .= " " . (is_string($options['autoIncrement']) ? $options['autoIncrement'] : "AUTO_INCREMENT");
                }
                
                // Handle nullable property - now accepting direct SQL constraints
                if (isset($options['nullable'])) {
                    if (is_string($options['nullable'])) {
                        // If it's a string like "NULL" or "NOT NULL", use it directly
                        $columnDef .= " " . $options['nullable'];
                    } else {
                        // If it's a boolean, convert to appropriate SQL
                        $columnDef .= $options['nullable'] ? " NULL" : " NOT NULL";
                    }
                }
                // Add DEFAULT if provided
                if (isset($options['default']) && $options['default'] !== null && $options['default'] !== '') {
                    // Handle special DEFAULT value CURRENT_TIMESTAMP
                    if ($options['default'] === 'CURRENT_TIMESTAMP') {
                        $columnDef .= " DEFAULT CURRENT_TIMESTAMP";
                    } else {
                        $columnDef .= " DEFAULT " . (is_numeric($options['default']) ? $options['default'] : "'{$options['default']}'");
                    }
                }
                
                $columns[$columnName] = $columnDef;
            }

            // Build the schema operation with proper method chaining
            $schemaManager = $this->schemaManager->createTable($tableName, $columns);
            
            // Add indexes if provided
            if (isset($data['indexes']) && !empty($data['indexes'])) {
                // Make sure each index has the table property set
                $indexes = array_map(function($index) use ($tableName) {
                    if (!isset($index['table'])) {
                        $index['table'] = $tableName;
                    }
                    return $index;
                }, $data['indexes']);
                
                $schemaManager = $schemaManager->addIndex($indexes);
            }

            // Add foreign keys if provided
            if (isset($data['foreign_keys']) && !empty($data['foreign_keys'])) {
                // Make sure each foreign key has the table property set
                $foreignKeys = array_map(function($fk) use ($tableName) {
                    if (!isset($fk['table'])) {
                        $fk['table'] = $tableName;
                    }
                    return $fk;
                }, $data['foreign_keys']);
                
                $schemaManager->addForeignKey($foreignKeys);
            }

            return Response::ok([
                'table' => $tableName,
                'columns' => $columnsData,
                'indexes' => $data['indexes'] ?? [],
                'foreign_keys' => $data['foreign_keys'] ?? []
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
     * Add index(es) to an existing table
     * 
     * Allows adding a single index or multiple indexes in batch.
     * 
     * @return mixed HTTP response
     */
    public function addIndex(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!isset($data['index']) && !isset($data['indexes'])) {
                return Response::error('Index definition is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [];
            $failed = [];
            
            // Handle both single index and array of indexes
            $indexes = isset($data['indexes']) ? $data['indexes'] : [$data['index']];
            
            // Ensure we have an array
            if (!is_array($indexes)) {
                $indexes = [$indexes];
            }
            
            // Convert the simple index format to the format expected by SchemaManager
            $formattedIndexes = [];
            foreach ($indexes as $index) {
                if (!isset($index['column']) || !isset($index['type'])) {
                    $failed[] = $index['column'] ?? 'unnamed column';
                    continue;
                }
                
                // Create a properly formatted index definition that the SchemaManager expects
                $formattedIndex = [
                    'table' => $tableName,
                    'column' => $index['column'],
                    'type' => strtolower($index['type']) == 'unique' ? 'unique' : 'index'
                ];
                
                // Generate an index name if not provided
                if (!isset($index['name'])) {
                    $columnStr = is_array($index['column']) ? implode('_', $index['column']) : $index['column'];
                    $indexType = strtolower($index['type']) == 'unique' ? 'unq' : 'idx';
                    $formattedIndex['name'] = "{$tableName}_{$columnStr}_{$indexType}";
                } else {
                    $formattedIndex['name'] = $index['name'];
                }
                
                $formattedIndexes[] = $formattedIndex;
                
                try {
                    // Add the index
                    $success = $this->schemaManager->addIndex([$formattedIndex]);
                    
                    if ($success) {
                        $results[] = $formattedIndex['name'];
                    } else {
                        $failed[] = $formattedIndex['name'];
                    }
                } catch (\Exception $e) {
                    error_log("Failed to add index on column '{$index['column']}': " . $e->getMessage());
                    $failed[] = $formattedIndex['name'];
                }
            }
            
            // Check if all indexes were added successfully
            if (empty($failed)) {
                // All indexes were added successfully
                return Response::ok([
                    'table' => $tableName,
                    'indexes_added' => $results
                ], count($results) > 1 ? 'Indexes added successfully' : 'Index added successfully')->send();
            } else if (empty($results)) {
                // All indexes failed to add
                return Response::error(
                    'Failed to add index(es): ' . implode(', ', $failed), 
                    Response::HTTP_BAD_REQUEST
                )->send();
            } else {
                // Some indexes were added, but some failed
                return Response::ok([
                    'table' => $tableName,
                    'indexes_added' => $results,
                    'indexes_failed' => $failed
                ], 'Some indexes were added successfully, but others failed')->send();
            }
        } catch (\Exception $e) {
            error_log("Add index error: " . $e->getMessage());
            return Response::error(
                'Failed to add index(es): ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Add column(s) to existing table
     * 
     * Allows adding a single column or multiple columns in batch.
     * 
     * @return mixed HTTP response
     */
    public function addColumn(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!isset($data['column']) && !isset($data['columns'])) {
                return Response::error('Column details are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [];
            $failed = [];
            
            // Handle both single column and array of columns
            $columns = isset($data['columns']) ? $data['columns'] : [$data['column']];
            
            // Ensure we have an array
            if (!is_array($columns)) {
                $columns = [$columns];
            }
            
            // Add each column
            foreach ($columns as $column) {
                if (!isset($column['name']) || !isset($column['type'])) {
                    $failed[] = $column['name'] ?? 'unnamed column';
                    continue;
                }
                
                try {
                    $result = $this->schemaManager->addColumn(
                        $tableName,
                        $column['name'],
                        $column['type'],
                        $column['options'] ?? []
                    );
                    
                    if ($result['success'] ?? false) {
                        $results[] = $column['name'];
                    } else {
                        $failed[] = $column['name'];
                    }
                } catch (\Exception $e) {
                    error_log("Failed to add column '{$column['name']}': " . $e->getMessage());
                    $failed[] = $column['name'];
                }
            }
            
            // Check if all columns were added successfully
            if (empty($failed)) {
                // All columns were added successfully
                return Response::ok([
                    'table' => $tableName,
                    'columns_added' => $results
                ], count($results) > 1 ? 'Columns added successfully' : 'Column added successfully')->send();
            } else if (empty($results)) {
                // All columns failed to add
                return Response::error(
                    'Failed to add column(s): ' . implode(', ', $failed), 
                    Response::HTTP_BAD_REQUEST
                )->send();
            } else {
                // Some columns were added, but some failed
                return Response::ok([
                    'table' => $tableName,
                    'columns_added' => $results,
                    'columns_failed' => $failed
                ], 'Some columns were added successfully, but others failed')->send();
            }
        } catch (\Exception $e) {
            error_log("Add column error: " . $e->getMessage());
            return Response::error(
                'Failed to add column(s): ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Drop column(s) from table
     * 
     * Allows dropping a single column or multiple columns in batch.
     * 
     * @return mixed HTTP response
     */
    public function dropColumn(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!isset($data['column_name']) && !isset($data['column_names'])) {
                return Response::error('Column name(s) are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [];
            $failed = [];
            
            // Handle both single column and array of columns
            $columnNames = isset($data['column_names']) ? $data['column_names'] : [$data['column_name']];
            
            // Ensure we have an array
            if (!is_array($columnNames)) {
                $columnNames = [$columnNames];
            }
            
            // Drop each column
            foreach ($columnNames as $columnName) {
                try {
                    // Use the SchemaManager's dropColumn method
                    $result = $this->schemaManager->dropColumn($tableName, $columnName);
                    
                    if ($result['success'] ?? false) {
                        $results[] = $columnName;
                    } else {
                        $failed[] = $columnName;
                    }
                } catch (\Exception $e) {
                    error_log("Failed to drop column '$columnName': " . $e->getMessage());
                    $failed[] = $columnName;
                }
            }
            
            // Check if all columns were dropped successfully
            if (empty($failed)) {
                // All columns were dropped successfully
                return Response::ok([
                    'table' => $tableName,
                    'columns_dropped' => $results
                ], count($results) > 1 ? 'Columns dropped successfully' : 'Column dropped successfully')->send();
            } else if (empty($results)) {
                // All columns failed to drop
                return Response::error(
                    'Failed to drop column(s): ' . implode(', ', $failed), 
                    Response::HTTP_BAD_REQUEST
                )->send();
            } else {
                // Some columns were dropped, but some failed
                return Response::ok([
                    'table' => $tableName,
                    'columns_dropped' => $results,
                    'columns_failed' => $failed
                ], 'Some columns were dropped successfully, but others failed')->send();
            }
        } catch (\Exception $e) {
            error_log("Drop column error: " . $e->getMessage());
            return Response::error(
                'Failed to drop column(s): ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Drop index(es) from table
     * 
     * Allows dropping a single index or multiple indexes in batch.
     * 
     * @return mixed HTTP response
     */
    public function dropIndex(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!isset($data['index_name']) && !isset($data['index_names'])) {
                return Response::error('Index name(s) are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [];
            $failed = [];
            
            // Handle both single index and array of indexes
            $indexNames = isset($data['index_names']) ? $data['index_names'] : [$data['index_name']];
            
            // Ensure we have an array
            if (!is_array($indexNames)) {
                $indexNames = [$indexNames];
            }
            
            // Drop each index
            foreach ($indexNames as $indexName) {
                try {
                    // Use the SchemaManager's dropIndex method
                    $success = $this->schemaManager->dropIndex($tableName, $indexName);
                    
                    if ($success) {
                        $results[] = $indexName;
                    } else {
                        $failed[] = $indexName;
                    }
                } catch (\Exception $e) {
                    error_log("Failed to drop index '$indexName': " . $e->getMessage());
                    $failed[] = $indexName;
                }
            }
            
            // Check if all indexes were dropped successfully
            if (empty($failed)) {
                // All indexes were dropped successfully
                return Response::ok([
                    'table' => $tableName,
                    'indexes_dropped' => $results
                ], count($results) > 1 ? 'Indexes dropped successfully' : 'Index dropped successfully')->send();
            } else if (empty($results)) {
                // All indexes failed to drop
                return Response::error(
                    'Failed to drop index(es): ' . implode(', ', $failed), 
                    Response::HTTP_BAD_REQUEST
                )->send();
            } else {
                // Some indexes were dropped, but some failed
                return Response::ok([
                    'table' => $tableName,
                    'indexes_dropped' => $results,
                    'indexes_failed' => $failed
                ], 'Some indexes were dropped successfully, but others failed')->send();
            }
        } catch (\Exception $e) {
            error_log("Drop index error: " . $e->getMessage());
            return Response::error(
                'Failed to drop index(es): ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Drop foreign key constraint(s) from table
     * 
     * Allows dropping a single constraint or multiple constraints in batch.
     * 
     * @return mixed HTTP response
     */
    public function dropForeignKey(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!isset($data['constraint_name']) && !isset($data['constraint_names'])) {
                return Response::error('Constraint name(s) are required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [];
            $failed = [];
            
            // Handle both single constraint and array of constraints
            $constraintNames = isset($data['constraint_names']) ? $data['constraint_names'] : [$data['constraint_name']];
            
            // Ensure we have an array
            if (!is_array($constraintNames)) {
                $constraintNames = [$constraintNames];
            }
            
            // Drop each constraint
            foreach ($constraintNames as $constraintName) {
                try {
                    // Use the SchemaManager's dropForeignKey method
                    $success = $this->schemaManager->dropForeignKey($tableName, $constraintName);
                    
                    if ($success) {
                        $results[] = $constraintName;
                    } else {
                        $failed[] = $constraintName;
                    }
                } catch (\Exception $e) {
                    error_log("Failed to drop foreign key '$constraintName': " . $e->getMessage());
                    $failed[] = $constraintName;
                }
            }
            
            // Check if all constraints were dropped successfully
            if (empty($failed)) {
                // All constraints were dropped successfully
                return Response::ok([
                    'table' => $tableName,
                    'constraints_dropped' => $results
                ], count($results) > 1 ? 'Foreign key constraints dropped successfully' : 'Foreign key constraint dropped successfully')->send();
            } else if (empty($results)) {
                // All constraints failed to drop
                return Response::error(
                    'Failed to drop foreign key constraint(s): ' . implode(', ', $failed), 
                    Response::HTTP_BAD_REQUEST
                )->send();
            } else {
                // Some constraints were dropped, but some failed
                return Response::ok([
                    'table' => $tableName,
                    'constraints_dropped' => $results,
                    'constraints_failed' => $failed
                ], 'Some foreign key constraints were dropped successfully, but others failed')->send();
            }
        } catch (\Exception $e) {
            error_log("Drop foreign key error: " . $e->getMessage());
            return Response::error(
                'Failed to drop foreign key constraint(s): ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Add foreign key constraint(s) to an existing table
     * 
     * Allows adding a single foreign key or multiple foreign keys in batch.
     * The expected format is:
     * {
     *   "column": "id",
     *   "references": "uuid",
     *   "on": "users"
     * }
     * 
     * @return mixed HTTP response
     */
    public function addForeignKey(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (!isset($data['foreign_key']) && !isset($data['foreign_keys'])) {
                return Response::error('Foreign key definition is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [];
            $failed = [];
            
            // Handle both single foreign key and array of foreign keys
            $foreignKeys = isset($data['foreign_keys']) ? $data['foreign_keys'] : [$data['foreign_key']];
            
            // Ensure we have an array
            if (!is_array($foreignKeys)) {
                $foreignKeys = [$foreignKeys];
            }
            
            // Process each foreign key
            foreach ($foreignKeys as $fk) {
                if (!isset($fk['column']) || !isset($fk['references']) || !isset($fk['on'])) {
                    $failed[] = $fk['column'] ?? 'unnamed foreign key';
                    continue;
                }
                
                try {
                    // Convert the simple format to the format expected by SchemaManager
                    $formattedFk = [
                        'table' => $tableName,
                        'column' => $fk['column'],
                        'reference_table' => $fk['on'],
                        'reference_column' => $fk['references'],
                    ];
                    
                    // Generate a constraint name if not provided
                    if (!isset($fk['name'])) {
                        $formattedFk['name'] = "fk_{$tableName}_{$fk['column']}";
                    } else {
                        $formattedFk['name'] = $fk['name'];
                    }
                    
                    // Use the SchemaManager's addForeignKey method
                    $success = $this->schemaManager->addForeignKey([$formattedFk]);
                    
                    if ($success) {
                        $results[] = $formattedFk['name'];
                    } else {
                        $failed[] = $formattedFk['name'];
                    }
                } catch (\Exception $e) {
                    error_log("Failed to add foreign key for column '{$fk['column']}': " . $e->getMessage());
                    $failed[] = $fk['column'];
                }
            }
            
            // Check if all foreign keys were added successfully
            if (empty($failed)) {
                // All foreign keys were added successfully
                return Response::ok([
                    'table' => $tableName,
                    'constraints_added' => $results
                ], count($results) > 1 ? 'Foreign key constraints added successfully' : 'Foreign key constraint added successfully')->send();
            } else if (empty($results)) {
                // All foreign keys failed to add
                return Response::error(
                    'Failed to add foreign key(s): ' . implode(', ', $failed), 
                    Response::HTTP_BAD_REQUEST
                )->send();
            } else {
                // Some foreign keys were added, but some failed
                return Response::ok([
                    'table' => $tableName,
                    'constraints_added' => $results,
                    'constraints_failed' => $failed
                ], 'Some foreign key constraints were added successfully, but others failed')->send();
            }
        } catch (\Exception $e) {
            error_log("Add foreign key error: " . $e->getMessage());
            return Response::error(
                'Failed to add foreign key(s): ' . $e->getMessage(),
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
            return Response::ok($tables, 'Tables retrieved successfully')->send();

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
    public function getTableSize(?array $table): mixed
    {
        try {
            if (!isset($table['name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $size = $this->schemaManager->getTableSize($table['name']);
            
            return Response::ok([
                'table' => $table['name'],
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
    public function getTableData(?array $table): mixed
    {
        try {
            if (!isset($table['name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Set default values for pagination and filtering
            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['per_page'] ?? 25);
            
            // Build the query using QueryBuilder
            $results = $this->queryBuilder->select($table['name'], ['*'])
                ->orderBy(['id' => 'DESC'])
                ->paginate($page, $perPage);
            
            // Get detailed column metadata using SchemaManager
            $columns = $this->schemaManager->getTableColumns($table['name']);
            // error_log("Columns: " . json_encode($columns));

            $results['columns'] = $columns;
            // error_log("Results: " . json_encode($results));
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

    /**
     * Authenticate a request using multiple authentication methods
     *
     * @param SymfonyRequest $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticate(SymfonyRequest $request): ?array
    {
        // For admin routes, try admin provider first, then either jwt OR api_key (not both)
        $userData = $this->authManager->authenticateWithProvider('admin', $request);
        
        if (!$userData) {
            // If admin auth fails, try jwt
            $userData = $this->authManager->authenticateWithProvider('jwt', $request);
            
            // If jwt fails, try api_key as a last resort
            if (!$userData) {
                $userData = $this->authManager->authenticateWithProvider('api_key', $request);
            }
        }
        
        return $userData;
    }
    
    /**
     * Check if user is authorized to perform admin actions
     *
     * @param array $userData User data from authentication
     * @return bool True if user is authorized
     */
    private function isAuthorized(array $userData): bool
    {
        // Check if user is an admin
        if (!isset($userData['uuid'])) {
            return false;
        }
        
        // Verify user has superuser role
        return $this->roleRepo->userHasRole($userData['uuid'], 'superuser');
    }

    /**
     * Execute a raw SQL query
     * 
     * Executes a raw SQL query against the database and returns the results.
     * Limited to admin users with appropriate permissions.
     * 
     * @return mixed HTTP response
     */
    public function executeQuery(): mixed
    {
        try {
            $data = Request::getPostData();
            
            if (!isset($data['query'])) {
                return Response::error('SQL query is required', Response::HTTP_BAD_REQUEST)->send();
            }

            // Get the SQL query from the request
            $sql = trim($data['query']);
            $params = $data['params'] ?? [];

            // Safety checks
            if (empty($sql)) {
                return Response::error('SQL query cannot be empty', Response::HTTP_BAD_REQUEST)->send();
            }

            // Prevent destructive operations if the safety flag is not set
            $isSafeQuery = $data['allow_write'] ?? false;
            $firstWord = strtoupper(explode(' ', $sql)[0]);
            if (!$isSafeQuery && in_array($firstWord, ['DELETE', 'TRUNCATE', 'DROP', 'ALTER', 'UPDATE', 'INSERT'])) {
                return Response::error(
                    'Write operations require explicit allow_write flag for safety',
                    Response::HTTP_FORBIDDEN
                )->send();
            }

            // Log the query attempt for security purposes
            error_log("Admin SQL query execution: " . substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''));

            // Execute the query and get results
            $results = $this->queryBuilder->rawQuery($sql, $params);

            // For write operations, get the affected rows count
            $isReadOperation = in_array($firstWord, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN']);
            $message = $isReadOperation 
                ? 'Query executed successfully' 
                : 'Query executed successfully, ' . count($results) . ' rows affected';

            $responseData = [
                'query' => $sql,
                'results' => $results,
                'count' => count($results)
            ];
            
            return Response::ok($responseData, $message)->send();

        } catch (\PDOException $e) {
            error_log("SQL Error: " . $e->getMessage());
            return Response::error(
                'SQL Error: ' . $e->getMessage(),
                Response::HTTP_BAD_REQUEST
            )->send();
        } catch (\Exception $e) {
            error_log("Execute query error: " . $e->getMessage());
            return Response::error(
                'Failed to execute query: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get columns information for a specific table
     * 
     * @param array $params Route parameters containing table name
     * @return mixed HTTP response with column metadata
     */
    public function getColumns(array $params): mixed
    {
        try {
            if (!isset($params['name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $params['name'];
            
            // Get detailed column metadata using SchemaManager
            $columns = $this->schemaManager->getTableColumns($tableName);
            
            if (empty($columns)) {
                return Response::error("No columns found or table '$tableName' does not exist", Response::HTTP_NOT_FOUND)->send();
            }

            return Response::ok([
                'table' => $tableName,
                'columns' => $columns
            ], 'Table columns retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get columns error: " . $e->getMessage());
            return Response::error(
                'Failed to get table columns: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}