<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Connection;
use Glueful\Services\FileFinder;

/**
 * JSON Definition Generator for API
 *
 * Generates JSON definition files for database tables and API documentation.
 * These definitions describe the structure and behavior of API endpoints
 * and database interactions.
 */
class ApiDefinitionGenerator
{
    private bool $runFromConsole;
    private array $generatedFiles = [];
    private string $dbResource;
    private SchemaBuilderInterface $schema;
    private Connection $db;

    /**
     * Constructor
     *
     * Initializes generator with console detection and directory setup.
     *
     * @param bool $runFromConsole Force console mode
     */
    public function __construct(bool $runFromConsole = false)
    {

        $this->runFromConsole = $runFromConsole || $this->isConsole();
        $this->log("Starting JSON Definition Generator...");
        $this->dbResource = $this->getDatabaseRole();

        $this->db = new Connection();
        $this->schema = $this->db->getSchemaBuilder();

        $dir = config('app.paths.database_json_definitions');
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
    public function generate(
        ?string $specificDatabase = null,
        ?string $tableName = null,
        bool $forceGenerate = false
    ): void {
        $this->generateDatabaseDefinitions($specificDatabase);

        if ($tableName) {
            $this->generateTableDefinition($specificDatabase, $tableName);
        }

        $this->log("Starting API docs generation...");
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
    private function generateTableDefinition(string $dbResource, string $tableName): void
    {
        $filename = \config('app.paths.database_json_definitions') . "$dbResource.$tableName.json";

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
    private function generateApiFieldName(string $fieldName): string
    {
        return $fieldName;
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
        $definitionsPath = config('app.paths.database_json_definitions');
        $definitionsDocPath = config('app.paths.api_docs') . 'api-doc-json-definitions/';

        // Process API doc definition files using FileFinder
        $fileFinder = container()->get(FileFinder::class);
        $finder = $fileFinder->createFinder();

        if (is_dir($definitionsDocPath)) {
            $docFiles = $finder->files()->in($definitionsDocPath)->name('*.json');
            foreach ($docFiles as $file) {
                try {
                    $docGenerator->generateFromDocJson($file->getPathname());
                    $this->log("Processed Custom API doc for: " . $file->getFilename());
                } catch (\Exception $e) {
                    $this->log("Error processing doc definition {$file->getPathname()}: " . $e->getMessage());
                }
            }
        }

        // Process table definition files
        $finder = $fileFinder->createFinder();
        $definitionFiles = $finder->files()->in($definitionsPath)->name('*.json');
        foreach ($definitionFiles as $file) {
            $parts = explode('.', basename($file->getFilename()));
            if (count($parts) !== 3) {
                continue; // Skip if not in format: dbname.tablename.json
            }

            try {
                $docGenerator->generateFromJson($file->getPathname());
                $this->log("Processed Table API doc for: " . $file->getFilename());
            } catch (\Exception $e) {
                $this->log("Error processing table definition {$file->getPathname()}: " . $e->getMessage());
            }
        }

        // Dynamically generate documentation for extensions with route files
        try {
            $extensionDocsDir = config('app.paths.api_docs') . 'api-doc-json-definitions/extensions';

            // Create the extensions documentation directory if it doesn't exist
            if (!is_dir($extensionDocsDir)) {
                mkdir($extensionDocsDir, 0755, true);
            }

            // Create the routes documentation directory if it doesn't exist
            $routesDocsDir = config('app.paths.api_docs') . 'api-doc-json-definitions/routes';
            if (!is_dir($routesDocsDir)) {
                mkdir($routesDocsDir, 0755, true);
            }

            // Use the CommentsDocGenerator to auto-generate docs from route files
            $extDocGen = new CommentsDocGenerator();

            if ($forceGenerate) {
                $this->log("Forcing generation of extension documentation...");

                // If forcing generation, handle each extension separately
                $extensionDirs = $fileFinder->findExtensions(dirname(__DIR__) . '/extensions');
                $generatedFiles = [];

                foreach ($extensionDirs as $extDir) {
                    $extName = $extDir->getFilename();
                    $routeFile = $extDir->getPathname() . '/routes.php';

                    if (file_exists($routeFile)) {
                        $docFile = $extDocGen->generateForExtension($extName, $routeFile, true);
                        if ($docFile) {
                            $generatedFiles[] = $docFile;
                        }
                    }
                }

                // Force generation for main routes
                $routeFiles = $fileFinder->findRouteFiles([dirname(__DIR__) . '/routes']);
                foreach ($routeFiles as $routeFileObj) {
                    $routeFile = $routeFileObj->getPathname();
                    $routeName = $routeFileObj->getBasename('.php');
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
        $outputPath = config('app.paths.api_docs') . 'swagger.json';

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

    /**
     * Generate JSON definitions for all tables in database
     *
     * @param string|null $targetDb Specific database to process
     * @throws \RuntimeException If database configuration is invalid
     */
    private function generateDatabaseDefinitions(?string $targetDb): void
    {
        try {
            $dbResource = $targetDb ?? $this->dbResource;
            $this->log("--- Generating JSON: dbres=$dbResource ---");

            // Get all tables from the database
            $tables = $this->schema->getTables();

            foreach ($tables as $table) {
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
                    } elseif (isset($col['Field'])) {
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

    private function generateTableDefinitionFromColumns(string $dbResource, string $tableName, array $columns): void
    {
        $filename = \config('app.paths.database_json_definitions') . "$dbResource.$tableName.json";

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
}
