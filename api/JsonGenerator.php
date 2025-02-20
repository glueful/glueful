<?php
declare(strict_types=1);
namespace Glueful\Api;
require_once __DIR__ . '/bootstrap.php';

use Glueful\Api\Library\{
    Utils, 
    Permission, 
    QueryAction, 
    MySQLQueryBuilder,
    DocGenerator
};

/**
 * JSON Definition Generator for API
 * 
 * Generates JSON definition files for database tables and API documentation.
 * These definitions describe the structure and behavior of API endpoints
 * and database interactions.
 */
class JsonGenerator {
    private bool $runFromConsole;
    private array $generatedFiles = [];
    private string $dbResource;
    

    /**
     * Constructor
     * 
     * Initializes generator with console detection and directory setup.
     * 
     * @param bool $runFromConsole Force console mode
     */
    public function __construct(bool $runFromConsole = false) {

        $this->runFromConsole = $runFromConsole || $this->isConsole();
        
        $dbConfig = config('database');
        $this->dbResource = array_key_first(array_filter($dbConfig, 'is_array'));
        
        $dir = config('paths.json_definitions');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
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
     */
    public function generate(?string $specificDatabase = null,?string $tableName = null): void {
        $this->generateDatabaseDefinitions($specificDatabase);

        if($tableName){
            $this->generateTableDefinition($specificDatabase, $tableName);
        }

        
        if (\config('security.permissions_enabled') === TRUE) {
            $this->setupAdministratorRole();
        }

        if (\config('app.docs_enabled')) {
            $this->log("Step 3: Starting API docs generation...");
            $this->generateApiDocs();
        }
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
        
        $settings = config("database.$dbResource");
        $db = Utils::getMySQLConnection($settings);
        // Quote the table name properly to prevent SQL injection
        $quotedTableName = "`" . str_replace("`", "``", $tableName) . "`";
        $stmt = $db->query("DESCRIBE $quotedTableName");
        $fields = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
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
                    ['name' => 'role_id'],
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
     */
    public function generateApiDocs(): void 
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
        $dbConfig = config('database');
        $dbResource = $targetDb ?? $this->dbResource;
        
        if (empty($dbConfig) || !isset($dbConfig[$dbResource])) {
            throw new \RuntimeException("No valid database configuration found for: $dbResource");
        }

        $settings = $dbConfig[$dbResource];
        
        try {
            $db = Utils::getMySQLConnection($settings);
            $this->log("--- Generating JSON: dbres=$dbResource ---");

            $tables = $db->query("SHOW TABLES");
            while ($table = $tables->fetch(\PDO::FETCH_NUM)) {
                $this->generateTableDefinition($dbResource, $table[0]);
            }
        } catch (\Exception $e) {
            $this->log("Error processing database: " . $e->getMessage());
        }
    }

    /**
     * Set up administrator role and permissions
     * 
     * Creates or updates administrator role with full permissions.
     */
    private function setupAdministratorRole(): void {
        $this->log("--- Creating Administrator Role ---");
        
        $roleId = $this->getOrCreateAdminRole();
        $this->updateAdminPermissions($roleId);
    }

    /**
     * Get or create administrator role
     * 
     * @return string|int Role ID
     * @throws \Exception On database errors
     */
    private function getOrCreateAdminRole(): string|int {
        $settings = config("database.{$this->dbResource}");
        
        try {
            $db = Utils::getMySQLConnection($settings);
            
            // Create both roles and permissions configurations if they don't exist
            if (!file_exists(config('paths.json_definitions') . "{$this->dbResource}.roles.json")) {
                $this->createRolesConfig($this->dbResource);
            }
            if (!file_exists(config('paths.json_definitions') . "{$this->dbResource}.permissions.json")) {
                $this->createPermissionsConfig($this->dbResource);
            }
            
            $stmt = $db->prepare("SELECT * FROM roles WHERE name = :name LIMIT 1");
            $stmt->execute(['name' => 'Administrator']);
            $adminRole = $stmt->fetch();

            $roleId = $adminRole['id'] ?? null;
            
            if (!$roleId) {
                $roleId = $this->createAdminRole();
                $this->log("--- Administrator role created ---");
            } else {
                $this->log("--- Administrator role already exists ---");
            }
            
            return $roleId;
            
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
        $param = [
            'name' => 'Administrator',
            'description' => 'This is the Administrator role'
        ];

        $rolesFile = config('paths.json_definitions') . 'users.roles.json';
        $roles = json_decode(file_get_contents($rolesFile), true);
        
        $result = MySQLQueryBuilder::query(
            MySQLQueryBuilder::prepare(QueryAction::INSERT, $roles, $param, null)
        );

        return $result['id'];
    }

    /**
     * Update administrator permissions
     * 
     * @param string|int $roleId Administrator role ID
     */
    private function updateAdminPermissions(string|int $roleId): void {
        $this->log("--- Assigning/Updating Administrator Permissions ---");
        $this->updateCorePermissions($roleId);
        $this->updateExtensionPermissions($roleId);
        $this->updateUIModelPermissions($roleId);
    }

    /**
     * Load and initialize API extensions
     * 
     * @param string|int $roleId Administrator role ID
     */
    private function updateCorePermissions(string|int $roleId): void {
        foreach (glob(config('paths.json_definitions') . "*.json") as $file) {
            $parts = explode('.', basename($file));
            if (count($parts) !== 3) continue; // Skip if not in format: dbname.tablename.json
            
            // Extract database and table name from filename (e.g., "primary.users.json")
            $dbName = $parts[0];
            $tableName = $parts[1];
            
            $model = "api.$dbName.$tableName"; // Create model in format "dbname.tablename"
            
            try {
                $this->assignPermissions($roleId, $model);
                $this->log($model);
            } catch (\Exception $e) {
                $this->log("Failed to assign core permission: " . $e->getMessage());
            }
        }
    }

    /**
     * Assign permissions to role
     * 
     * @param string|int $roleId Role ID
     * @param string $model Permission model identifier
     * @throws \Exception On database errors
     */
    private function assignPermissions(string|int $roleId, string $model): void 
    {
        if ($this->permissionExists($roleId, $model)) {
            return;
        }
        
        $permissions = implode('', Permission::getAll());
        $settings = config("database.{$this->dbResource}");
        
        try {
            $db = Utils::getMySQLConnection($settings);
            $stmt = $db->prepare(
                "INSERT INTO permissions (role_id, model, permissions) VALUES (:roleId, :model, :permissions)"
            );
            
            $result = $stmt->execute([
                ':roleId' => $roleId,
                ':model' => $model,
                ':permissions' => $permissions
            ]);
            
            $this->log("Permissions assigned: " . ($result ? "success" : "failed"));
        } catch (\Exception $e) {
            $this->log("Error assigning permissions: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update extension-specific permissions
     * 
     * @param string|int $roleId Administrator role ID
     */
    private function updateExtensionPermissions(string|int $roleId): void {
        $this->log("--- Assigning/Updating Administrator Extension Permissions ---");
        foreach ($this->getExtensionPaths() as $path) {
            $this->processExtensionFile($path, $roleId);
        }
    }

    /**
     * Update UI model permissions
     * 
     * @param string|int $roleId Administrator role ID
     */
    private function updateUIModelPermissions(string|int $roleId): void {
        global $uiModels;
        $this->log("--- Assigning/Updating Administrator UI Model Permissions ---");

        if (!empty($uiModels)) {
            foreach ($uiModels as $view) {
                $this->assignPermissions($roleId, "ui.$view");
                $this->log("ui.$view");
            }
        }
    }

    /**
     * Check if permission exists
     * 
     * @param string|int $roleId Role ID
     * @param string $model Model to check
     * @return bool True if permission exists
     * @throws \Exception On database errors
     */
    private function permissionExists(string|int $roleId, string $model): bool
    {
        try {
            $settings = config("database.{$this->dbResource}");
            $db = Utils::getMySQLConnection($settings);
            $stmt = $db->prepare(
                "SELECT 1 FROM permissions WHERE role_id = :roleId AND model = :model LIMIT 1"
            );
            
            $stmt->execute([
                ':roleId' => $roleId,
                ':model' => $model
            ]);
            
            return (bool)$stmt->fetch();
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

    /**
     * Process extension file for permissions
     * 
     * @param string $path Extension file path
     * @param string|int $roleId Role ID to assign permissions
     */
    private function processExtensionFile(string $path, string|int $roleId): void {
        $path = str_replace("\\", "/", $path);
        $parts = explode('/', $path);
        $filename = end($parts);
        $function = current(explode('.', $filename));
        $action = prev($parts);

        if (!file_exists($path)) return;

        $extension = file_get_contents($path);
        if (!str_contains($extension, 'extends') || !str_contains($extension, 'Extensions')) {
            return;
        }

        $model = "api.ext.$action.$function";
        $this->assignPermissions($roleId, $model);
        $this->log($model);
    }
}

// Run the generator
$generator = new JsonGenerator();
$generator->generate();
?>