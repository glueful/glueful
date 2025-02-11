<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mapi\Api\Library\{
    Utils, 
    Permission, 
    QueryAction, 
    MySQLQueryBuilder,
    DocGenerator
};


class JsonGenerator {
    private bool $runFromConsole;
    private string $endOfLine;
    private array $generatedFiles = [];

    public function __construct(bool $runFromConsole = false) {
        $this->runFromConsole = $runFromConsole;
        $this->endOfLine = $runFromConsole ? "\n" : "<br/>";
        
        // Use global namespace for config function
        $dir = config('paths.json_definitions');
        error_log("JsonGenerator path: $dir");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function generate(?string $specificDatabase = null): void {
        $this->checkSessionConfig();
        $this->generateDatabaseDefinitions($specificDatabase);
        
        if (\config('security.permissions_enabled') === TRUE) {
            $this->setupAdministratorRole();
        }

        if (\config('app.docs_enabled')) {
            $this->generateApiDocs();
        }
    }

    private function generateTableDefinition(string $dbResource, string $tableName): void {
        $filename = \config('paths.json_definitions') . "$dbResource.$tableName.json";
        
        // Skip if we've already generated this file
        if (isset($this->generatedFiles[$filename])) {
            return;
        }
        
        $resource = Utils::getMySQLResource($dbResource);
        $fields = $resource->query("DESCRIBE $tableName");
        
        $config = [
            'table' => [
                'name' => $tableName,
                'fields' => []
            ],
            'access' => [
                'mode' => 'rw'
            ]
        ];

        while ($field = $fields->fetch_assoc()) {
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

    private function generateApiFieldName(string $fieldName): string {
        return $fieldName;
    }

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

    private function generateApiDocs(): void 
    {
        $this->log("Generating API Documentation...");
        
        $docGenerator = new DocGenerator();
        $definitionsPath = config('paths.json_definitions');
        
        // Process all JSON definition files
        foreach (glob($definitionsPath . "*.json") as $file) {
            $parts = explode('.', basename($file));
            if (count($parts) !== 3) continue; // Skip if not in format: dbname.tablename.json
            
            $dbResource = $parts[0];
            try {
                $docGenerator->generateFromJson($dbResource, $file);
                $this->log("Processed API doc for: " . basename($file));
            } catch (\Exception $e) {
                $this->log("Error processing {$file}: " . $e->getMessage());
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

    private function log(string $message): void {
        echo $message . $this->endOfLine;
    }

    private function checkSessionConfig(): void {
        if (!file_exists(config('paths.json_definitions') . "sessions.json")) {
            $this->log("-------------------------------------------------");
            $this->log("sessions.json not found in json definitions");
            $this->log("-------------------------------------------------");
        }
    }

    private function generateDatabaseDefinitions(?string $targetDb): void {
        global $databaseServer;

        if (empty($databaseServer)) {
            throw new \RuntimeException("No database configuration found");
        }

        foreach ($databaseServer as $dbResource => $settings) {
            if ($targetDb && $targetDb !== $dbResource) {
                continue;
            }

            Utils::createMySQLResource($dbResource);
            $this->log("--- Generating JSON: dbres=$dbResource ---");

            $resource = Utils::getMySQLResource($dbResource);
            
            if ($resource instanceof \mysqli) {
                $tables = mysqli_query($resource, "SHOW TABLES");
                while ($table = mysqli_fetch_array($tables, MYSQLI_NUM)) {
                    $this->generateTableDefinition($dbResource, $table[0]);
                }
            } else if ($resource instanceof \PDO) {
                $tables = $resource->query("SHOW TABLES");
                while ($table = $tables->fetch(\PDO::FETCH_NUM)) {
                    $this->generateTableDefinition($dbResource, $table[0]);
                }
            }
        }
    }

    private function setupAdministratorRole(): void {
        $this->log("--- Creating Administrator Role ---");
        
        $roleId = $this->getOrCreateAdminRole();
        $this->updateAdminPermissions($roleId);
    }

    private function getOrCreateAdminRole(): string|int {
        global $databaseResource, $databaseServer;
        
        // Store current database resource
        $currentResource = $databaseResource ?? 'primary';
        
        // Ensure 'users' database is configured
        if (!isset($databaseServer['users'])) {
            // Use current database if 'users' is not configured
            $dbResource = $currentResource;
        } else {
            $dbResource = 'users';
        }
        
        $databaseResource = $dbResource;
        Utils::createMySQLResource($dbResource);

        $param = ['name' => 'Administrator'];

        $rolesFile = config('paths.json_definitions') . $databaseResource . '.roles.json';
        
        // Create roles config if it doesn't exist
        if (!file_exists($rolesFile)) {
            $this->createRolesConfig($databaseResource);
        }
        
        $roles = json_decode(file_get_contents($rolesFile), true);
        
        $adminRole = MySQLQueryBuilder::query(
            MySQLQueryBuilder::prepare(QueryAction::SELECT, $roles, $param, null)
        );

        $roleId = $adminRole[0]['id'] ?? null;

        if (!$roleId) {
            $roleId = $this->createAdminRole();
            $this->log("--- Administrator role created ---");
        } else {
            $this->log("--- Administrator role already exists ---");
        }

        // Restore original database resource
        if ($currentResource !== $dbResource) {
            $databaseResource = $currentResource;
            Utils::createMySQLResource($currentResource);
        }
        
        return $roleId;
    }

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

    private function updateAdminPermissions(string|int $roleId): void {
        $this->log("--- Assigning/Updating Administrator Permissions ---");
        $this->updateCorePermissions($roleId);
        $this->updateExtensionPermissions($roleId);
        $this->updateUIModelPermissions($roleId);
    }

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

    private function assignPermissions(string $roleId, string $model): void 
    {
        if ($this->permissionExists($roleId, $model)) {
            return;
        }
        
        $permissions = implode('', Permission::getAll());
        $query = "INSERT INTO permissions (role_id, model, permissions) VALUES ('$roleId', '$model', '$permissions')";
        
        try {
            $result = MySQLQueryBuilder::query($query);
            $this->log("Query result: " . print_r($result, true));
        } catch (\Exception $e) {
            $this->log("Error assigning permissions: " . $e->getMessage());
            throw $e;
        }
    }

    private function updateExtensionPermissions(string|int $roleId): void {
        $this->log("--- Assigning/Updating Administrator Extension Permissions ---");
        foreach ($this->getExtensionPaths() as $path) {
            $this->processExtensionFile($path, $roleId);
        }
    }

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

    private function permissionExists(string $roleId, string $model): bool
    {
        $query = "SELECT 1 FROM permissions WHERE role_id = '$roleId' AND model = '$model' LIMIT 1";
        
        try {
            $result = MySQLQueryBuilder::query($query);
            return !empty($result);
        } catch (\Exception $e) {
            $this->log("Error checking permissions: " . $e->getMessage());
            throw $e;
        }
    }

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