<?php
declare(strict_types=1);
namespace Glueful;
require_once __DIR__ . '/bootstrap.php';

use Glueful\Permissions\Permission;
use Glueful\Helpers\Utils;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * JSON Definition Generator for API
 * 
 * Generates JSON definition files for database tables and API documentation.
 * These definitions describe the structure and behavior of API endpoints
 * and database interactions.
 */
class ApiDefinitionGenerator {
    private bool $runFromConsole;
    private array $generatedFiles = [];
    private string $dbResource;
    private SchemaManager $schema;
    private QueryBuilder $db;

    /**
     * Constructor
     * 
     * Initializes generator with console detection and directory setup.
     * 
     * @param bool $runFromConsole Force console mode
     */
    public function __construct(bool $runFromConsole = false) {

        $this->runFromConsole = $runFromConsole || $this->isConsole();
        $this->log("Starting JSON Definition Generator...");
        $this->dbResource = $this->getDatabaseRole();

        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $this->schema = $connection->getSchemaManager();
        
        $dir = config('paths.json_definitions');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Get the current database role from configuration
     * 
     * @return string The database role (e.g., 'primary')
     */
    private function getDatabaseRole(): string
    {
        $engine = config('database.engine', 'mysql');
        return config("database.{$engine}.role", 'primary');
    }

     /**
     * Log messages with proper line endings
     * 
     * Handles both console and web output formats.
     * 
     * @param string $message Message to log
     */
    private function log(string $message): void 
    {
        if ($this->runFromConsole) {
            // Start output buffering if not already started
            if (!ob_get_level()) {
                ob_start();
            }
            
            // For CLI, write directly to STDOUT
            echo $message . PHP_EOL;
            
            // Flush output buffer and send to browser
            ob_flush();
            flush();
        } else {
            // For web interface
            echo $message . "<br/>";
        }
    }

    /**
     * Check if running in console mode
     * 
     * @return bool True if running from command line
     */
    private function isConsole(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Generate JSON definitions
     * 
     * Main method to generate all necessary JSON definition files.
     * 
     * @param string|null $specificDatabase Target specific database
     * @param string|null $tableName Generate for specific table only
     * @param bool $forceGenerate Force generation even if manual files exist
     */
    public function generate(?string $specificDatabase = null, ?string $tableName = null, bool $forceGenerate = false): void {
        $this->generateDatabaseDefinitions($specificDatabase);

        if($tableName){
            $this->generateTableDefinition($specificDatabase, $tableName);
        }

        
        if (\config('security.permissions_enabled') === TRUE) {
            $this->setupAdministratorRole();
        }

        $this->log("Step 3: Starting API docs generation...");
        $this->generateApiDocs($forceGenerate);
    }

    /**
     * Generate JSON definition for a single table
     * 
     * Creates API definition file for specified database table.
     * 
     * @param string $dbResource Database identifier
     * @param string $tableName Table to generate for
     */
    private function generateTableDefinition(string $dbResource, string $tableName): void {
        $filename = \config('paths.json_definitions') . "$dbResource.$tableName.json";
        
        if (isset($this->generatedFiles[$filename])) {
            return;
        }

        // Get table structure using SchemaManager
        $fields = $this->schema->getTableColumns($tableName);
        
        $config = [
            'table' => [
                'name' => $tableName,
                'fields' => []
            ],
            'access' => [
                'mode' => 'rw'
            ]
        ];

        foreach ($fields as $field) {
            $config['table']['fields'][] = [
                'name' => $field['Field'],
                'api_field' => $this->generateApiFieldName($field['Field']),
                'type' => $field['Type'],
                'nullable' => $field['Null'] === 'YES'
            ];
        }

        file_put_contents(
            $filename, 
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        $this->generatedFiles[$filename] = true;
        $this->log("Generated: $dbResource.$tableName.json");
    }

    /**
     * Convert database field name to API field name
     * 
     * Maps database column names to API-friendly names.
     * 
     * @param string $fieldName Database field name
     * @return string API field name
     */
    private function generateApiFieldName(string $fieldName): string {
        return $fieldName;
    }

    /**
     * Create permissions configuration file
     * 
     * Generates JSON definition for permissions table.
     * 
     * @param string $dbResource Database identifier
     */
    private function createPermissionsConfig(string $dbResource): void {
        $config = [
            'table' => [
                'name' => 'permissions',
                'fields' => [
                    ['name' => 'id'],
                    ['name' => 'uuid'],
                    ['name' => 'role_uuid'],
                    ['name' => 'model'],
                    ['name' => 'permissions'],
                    ['name' => 'created_at'],
                    ['name' => 'updated_at']
                ]
            ],
            'access' => [
                'mode' => 'rw'
            ]
        ];

        $path = config('paths.json_definitions') . "$dbResource.permissions.json";
        file_put_contents(
            $path, 
            json_encode($config, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Generate API documentation
     * 
     * Creates OpenAPI/Swagger documentation from JSON definitions.
     * 
     * @param bool $forceGenerate Force generation even if manual files exist
     */
    public function generateApiDocs(bool $forceGenerate = false): void 
    {
        $this->log("Generating API Documentation...");
        
        $docGenerator = new DocGenerator();
        $definitionsPath = config('paths.json_definitions');
        $definitionsDocPath = config('paths.api_docs') . 'api-doc-json-definitions/';


        // Process API doc definition files
        if (is_dir($definitionsDocPath)) {
            foreach (glob($definitionsDocPath . "*.json") as $file) {
                try {
                    $docGenerator->generateFromDocJson($file);
                    $this->log("Processed Custom API doc for: " . basename($file));
                } catch (\Exception $e) {
                    $this->log("Error processing doc definition {$file}: " . $e->getMessage());
                }
            }
        }
        
        // Process table definition files
        foreach (glob($definitionsPath . "*.json") as $file) {
            $parts = explode('.', basename($file));
            if (count($parts) !== 3) continue; // Skip if not in format: dbname.tablename.json
            
            try {
                $docGenerator->generateFromJson($file);
                $this->log("Processed Table API doc for: " . basename($file));
            } catch (\Exception $e) {
                $this->log("Error processing table definition {$file}: " . $e->getMessage());
            }
        }

        // Dynamically generate documentation for extensions with route files
        try {
            $extensionDocsDir = config('paths.api_docs') . 'api-doc-json-definitions/extensions';
            
            // Create the extensions documentation directory if it doesn't exist
            if (!is_dir($extensionDocsDir)) {
                mkdir($extensionDocsDir, 0755, true);
            }
            
            // Create the routes documentation directory if it doesn't exist
            $routesDocsDir = config('paths.api_docs') . 'api-doc-json-definitions/routes';
            if (!is_dir($routesDocsDir)) {
                mkdir($routesDocsDir, 0755, true);
            }
            
            // Use the CommentsDocGenerator to auto-generate docs from route files
            $extDocGen = new CommentsDocGenerator();
            
            if ($forceGenerate) {
                $this->log("Forcing generation of extension documentation...");
                
                // If forcing generation, handle each extension separately
                $extensionDirs = array_filter(glob(dirname(__DIR__) . '/extensions' . '/*'), 'is_dir');
                $generatedFiles = [];
                
                foreach ($extensionDirs as $extDir) {
                    $extName = basename($extDir);
                    $routeFile = $extDir . '/routes.php';
                    
                    if (file_exists($routeFile)) {
                        $docFile = $extDocGen->generateForExtension($extName, $routeFile, true);
                        if ($docFile) {
                            $generatedFiles[] = $docFile;
                        }
                    }
                }
                
                // Force generation for main routes
                $routeFiles = glob(dirname(__DIR__) . '/routes/*.php');
                foreach ($routeFiles as $routeFile) {
                    $routeName = basename($routeFile, '.php');
                    $docFile = $extDocGen->generateForRouteFile($routeName, $routeFile, true);
                    if ($docFile) {
                        $generatedFiles[] = $docFile;
                    }
                }
            } else {
                // Normal generation for extensions
                $generatedExtFiles = $extDocGen->generateAll();
                
                if (!empty($generatedExtFiles)) {
                    $this->log("Dynamically generated documentation for " . count($generatedExtFiles) . " extensions");
                    foreach ($generatedExtFiles as $file) {
                        $this->log("Generated: " . basename($file));
                    }
                } else {
                    $this->log("No extension route files found for documentation generation");
                }
            }
            
            // Process the generated extension documentation 
            $docGenerator->generateFromExtensions($extensionDocsDir);
            $this->log("Processed extension API documentation");
            
            // Process the generated routes documentation
            $docGenerator->generateFromRoutes($routesDocsDir);
            $this->log("Processed main routes API documentation");
        } catch (\Exception $e) {
            $this->log("Error generating documentation: " . $e->getMessage());
        }

        // Generate and save Swagger JSON
        $swaggerJson = $docGenerator->getSwaggerJson();
        $outputPath = config('paths.api_docs') . 'swagger.json';
        
        // Ensure the docs directory exists
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }
        
        if (file_put_contents($outputPath, $swaggerJson)) {
            $this->log("API documentation generated successfully at: $outputPath");
        } else {
            $this->log("Failed to write API documentation to: $outputPath");
        }
    }

    // private function log(string $message): void {
    //     if ($this->runFromConsole) {
    //         fwrite(STDOUT, $message . PHP_EOL);
    //     } else {
    //         echo $message . "<br/>";
    //     }
    // }

    /**
     * Generate JSON definitions for all tables in database
     * 
     * @param string|null $targetDb Specific database to process
     * @throws \RuntimeException If database configuration is invalid
     */
    private function generateDatabaseDefinitions(?string $targetDb): void {
        try {
            $dbResource = $targetDb ?? $this->dbResource;
            $this->log("--- Generating JSON: dbres=$dbResource ---");

            // Get all tables from the database
            $tables = $this->schema->getTables();

            foreach ($tables as $table) {
                // Get column information using SchemaManager
                $columns = $this->schema->getTableColumns($table);

                $fields = [];
                foreach ($columns as $columnName => $col) {
                    // Handle different format of column data from SchemaManager
                    // Extract key information and normalize to expected format
                    if (isset($col['name'])) {
                        // Format when column data uses 'name', 'type', etc. keys
                        $fields[] = [
                            'Field' => $col['name'],
                            'Type' => $col['type'] ?? '',
                            'Null' => isset($col['nullable']) && $col['nullable'] ? 'YES' : 'NO'
                        ];
                    } else if (isset($col['Field'])) {
                        // Old format that already has 'Field', 'Type', 'Null' keys
                        $fields[] = $col;
                    } else {
                        // Use column name as fallback when the structure is unexpected
                        $fields[] = [
                            'Field' => $columnName,
                            'Type' => is_string($col) ? $col : 'VARCHAR',
                            'Null' => 'NO'
                        ];
                    }
                }
                
                $this->generateTableDefinitionFromColumns($dbResource, $table, $fields);
            }
        } catch (\Exception $e) {
            $this->log("Error processing database: " . $e->getMessage());
        }
    }

    private function generateTableDefinitionFromColumns(string $dbResource, string $tableName, array $columns): void {
        $filename = \config('paths.json_definitions') . "$dbResource.$tableName.json";
        
        if (isset($this->generatedFiles[$filename])) {
            return;
        }
        
        $config = [
            'table' => [
                'name' => $tableName,
                'fields' => []
            ],
            'access' => [
                'mode' => 'rw'
            ]
        ];

        foreach ($columns as $field) {
            $config['table']['fields'][] = [
                'name' => $field['Field'],
                'api_field' => $this->generateApiFieldName($field['Field']),
                'type' => $field['Type'],
                'nullable' => $field['Null'] === 'YES'
            ];
        }

        file_put_contents(
            $filename, 
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        $this->generatedFiles[$filename] = true;
        $this->log("Generated: $dbResource.$tableName.json");
    }

    /**
     * Set up administrator role and permissions
     * 
     * Creates or updates administrator role with full permissions.
     */
    private function setupAdministratorRole(): void {
        $this->log("--- Creating Superuser Role ---");
        
        $roleUuid = $this->getOrCreateAdminRole();
        $this->updateAdminPermissions($roleUuid);
    }

    /**
     * Get or create administrator role
     * 
     * @return string|int Role ID
     * @throws \Exception On database errors
     */
    private function getOrCreateAdminRole(): string 
    {
        try {
            // Create both roles and permissions configurations if they don't exist
            if (!file_exists(config('paths.json_definitions') . "{$this->dbResource}.roles.json")) {
                $this->createRolesConfig($this->dbResource);
            }
            if (!file_exists(config('paths.json_definitions') . "{$this->dbResource}.permissions.json")) {
                $this->createPermissionsConfig($this->dbResource);
            }
            
           // Get admin role using QueryBuilder
           $adminRole = $this->db
           ->select('roles', ['uuid'])
           ->where(['name' => 'superuser'])
           ->limit(1)
           ->get();

            $roleUuid = $adminRole[0]['uuid'] ?? null;
            
            if (!$roleUuid) {
                $roleUuid = $this->createAdminRole();
                $this->log("--- Superuser role created ---");
            } else {
                $this->log("--- Superuser role already exists ---");
            }
            
            return $roleUuid;
            
        } catch (\Exception $e) {
            $this->log("Error in getOrCreateAdminRole: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create roles configuration file
     * 
     * @param string $dbResource Database identifier
     */
    private function createRolesConfig(string $dbResource): void {
        $config = [
            'table' => [
                'name' => 'roles',
                'fields' => [
                    ['name' => 'id'],
                    ['name' => 'uuid'],
                    ['name' => 'name'],
                    ['name' => 'description'],
                    ['name' => 'created_at'],
                    ['name' => 'updated_at']
                ]
            ],
            'access' => [
                'mode' => 'rw'
            ]
        ];

        $path = config('paths.json_definitions') . "$dbResource.roles.json";
        file_put_contents(
            $path, 
            json_encode($config, JSON_PRETTY_PRINT)
        );
        
        $this->log("Created roles configuration: $path");
    }

    /**
     * Create administrator role in database
     * 
     * @return string|int New role ID
     */
    private function createAdminRole(): string|int {
        try {
            // Generate UUID for new role
            $roleUuid = Utils::generateNanoID();
            
            // Insert role using SchemaManager
            $result = $this->db->insert(
                'roles',
                [
                    'uuid' => $roleUuid,
                    'name' => 'superuser',
                    'description' => 'Full system access',
                    'status' => 'active'
                ]
            ) > 0;
            
            if (!$result) {
                throw new \RuntimeException('Failed to create administrator role');
            }

            // Get the role ID using the UUID
            $roleData = $this->db->select(
                'roles',
                ['uuid'],
            )->where(['uuid' => $roleUuid])->get();

            if (empty($roleData)) {
                throw new \RuntimeException('Failed to retrieve role ID');
            }
            
            return $roleData[0]['id'];
            
        } catch (\Exception $e) {
            $this->log("Failed to create admin role: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update administrator permissions
     * 
     * @param string|int $roleId Administrator role ID
     */
    private function updateAdminPermissions(string $roleUuid): void 
{
    $this->log("--- Assigning/Updating Superuser Permissions ---");
    
    try {

        // Collect all permissions using separate functions
        $allPermissions = array_merge(
            $this->collectCorePermissions($roleUuid),
            $this->collectExtensionPermissions($roleUuid),
            $this->collectUIModelPermissions($roleUuid)
        );

        // echo "Permissions: ";
        // print_r($allPermissions);
        // exit;

        if (!empty($allPermissions)) {
            $this->log("Found " . count($allPermissions) . " permissions to assign");
            
            // Use transaction through QueryBuilder
            $this->db->transaction(function($qb) use ($allPermissions) {
                $insertCount = 0;
                foreach ($allPermissions as $permission) {
                    $this->log($permission['model']);
                    
                    // Use upsert to handle potential duplicates
                    if ($qb->upsert(
                        'role_permissions',
                        [$permission],
                        ['permissions'] // Update permissions if record exists
                    ) > 0) {
                        $insertCount++;
                    }
                }
                
                if ($insertCount > 0) {
                    $this->log("Successfully assigned $insertCount permissions");
                    return true;
                } else {
                    $this->log("No permissions were inserted");
                    return false;
                }
            });

        } else {
            $this->log("No new permissions to assign");
        }
    } catch (\Exception $e) {
        $this->log("Error updating permissions: " . $e->getMessage());
        throw $e;
    }
}

    /**
     * Collect core permissions for JSON definition files
     */
    private function collectCorePermissions(string $roleUuid): array 
    {
        $permissions = [];
        foreach (glob(config('paths.json_definitions') . "*.json") as $file) {
            $parts = explode('.', basename($file));
            if (count($parts) !== 3) continue;
            
            $model = "api.{$parts[0]}.{$parts[1]}";
            if (!$this->permissionExists($roleUuid, $model)) {
                $permissions[] = [
                    'uuid' => Utils::generateNanoID(),
                    'role_uuid' => $roleUuid,
                    'model' => $model,
                    'permissions' => implode('', Permission::getAll())
                ];
            }
        }
        return $permissions;
    }

    /**
     * Collect extension-based permissions
     */
    private function collectExtensionPermissions(string $roleUuid): array 
    {
        $permissions = [];
        foreach ($this->getExtensionPaths() as $path) {
            $path = str_replace("\\", "/", $path);
            $parts = explode('/', $path);
            $filename = end($parts);
            $function = current(explode('.', $filename));
            $action = prev($parts);
            
            if (!file_exists($path)) continue;
            
            $extension = file_get_contents($path);
            if (!str_contains($extension, 'extends') || !str_contains($extension, 'Extensions')) {
                continue;
            }
            
            $model = "api.ext.$action.$function";
            if (!$this->permissionExists($roleUuid, $model)) {
                $permissions[] = [
                    'uuid' => Utils::generateNanoID(),
                    'role_uuid' => $roleUuid,
                    'model' => $model,
                    'permissions' => implode('', Permission::getAll())
                ];
            }
        }
        return $permissions;
    }

    /**
     * Collect UI model permissions
     */
    private function collectUIModelPermissions(string $roleUuid): array 
    {
        $permissions = [];
        global $uiModels;
        
        if (!empty($uiModels)) {
            foreach ($uiModels as $view) {
                $model = "ui.$view";
                if (!$this->permissionExists($roleUuid, $model)) {
                    $permissions[] = [
                        'uuid' => Utils::generateNanoID(),
                        'role_uuid' => $roleUuid,
                        'model' => $model,
                        'permissions' => implode('', Permission::getAll())
                    ];
                }
            }
        }
        return $permissions;
    }

    /**
     * Check if permission exists
     * 
     * @param string|int $roleId Role ID
     * @param string $model Model to check
     * @return bool True if permission exists
     * @throws \Exception On database errors
     */
    private function permissionExists(string $roleUuid, string $model): bool
    {
        try {
            // Check permissions using role UUID directly
            $permissions = $this->db->select(
                'role_permissions',
                ['id'],
            )->where(['role_uuid' => $roleUuid, 'model' => $model])->limit(1)->get();
            
            return !empty($permissions);
        } catch (\Exception $e) {
            $this->log("Error checking permissions: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get extension file paths
     * 
     * @return array Extension file paths
     */
    private function getExtensionPaths(): array {
        $paths = [];
        foreach ([config('paths.api_extensions'), config('paths.project_extensions')] as $dir) {
            if (is_dir($dir)) {
                $paths = [...$paths, ...$this->scanDirectory($dir)];
            } else {
                $this->log(strtoupper("--- Extensions Directory does not exist ---"));
            }
        }
        return $paths;
    }

    /**
     * Recursively scan directory for files
     * 
     * @param string $dir Directory to scan
     * @param array $results Accumulated results
     * @return array File paths
     */
    private function scanDirectory(string $dir, array &$results = []): array {
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $path = realpath($dir . DIRECTORY_SEPARATOR . $file);
            if (is_dir($path)) {
                $this->scanDirectory($path, $results);
            } elseif ($file[0] !== '.') {
                $results[] = $path;
            }
        }
        return $results;
    }
}

// Run the generator
$generator = new ApiDefinitionGenerator();
$generator->generate();
?>