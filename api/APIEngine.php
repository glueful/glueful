<?php
declare(strict_types=1);
namespace Glueful;

use PDO;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;
/**
 * API Engine - Core Service Layer
 * 
 * Provides a comprehensive bridge between API endpoints and data operations.
 * This engine powers all data access, authentication, and resource management
 * throughout the Glueful platform.
 * 
 * Key capabilities:
 * - Dynamic data operations using JSON definitions
 * - Permission and access control handling
 * - Database connection lifecycle management
 * - Query building and execution
 * 
 * The engine uses configuration-driven data access with automatic:
 * - Permission checking
 * - Query building
 * - Resource resolution
 * - Error handling
 * 
 * @package Glueful
 */
class APIEngine{
    /** @var PDO Active database connection */
    private static PDO $db;
    
    /** @var DatabaseDriver Database driver implementation */
    private static DatabaseDriver $driver;
    
    /** @var string Current database resource */
    private static string $currentResource;

    /**
     * Initialize the API Engine
     * 
     * Sets up required components for engine operation:
     * - Database connections
     * - Configuration loading
     * - Resource initialization
     * 
     * This is called automatically when the class is first loaded.
     */
    public static function initialize(): void 
    {
        self::initializeDatabase();
    }

    /**
     * Initialize database connection
     * 
     * Creates and configures the database connection:
     * - Establishes PDO connection
     * - Sets up database driver
     * - Configures connection parameters
     * - Stores connection state
     * 
     * @throws \RuntimeException If database connection fails
     */
    private static function initializeDatabase(): void 
    {
        try {
            // Create database connection
            $connection = new Connection();
            
            // Store connection and driver
            self::$db = $connection->getPDO();
            self::$driver = $connection->getDriver();
            
            // Set current database resource
            self::$currentResource = Utils::getDatabaseRole();;
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to initialize database: " . $e->getMessage());
        }
    }

    /**
     * Retrieve data from database
     * 
     * Executes read operations (list, view, count) against the database:
     * - Loads appropriate resource definition
     * - Builds and executes query
     * - Processes and formats results
     * - Handles pagination and filtering
     * 
     * @param string $function Resource/model name to query
     * @param string $action Operation type (list|view|count)
     * @param array $param Query parameters and options
     * @param array|null $filter Additional filter conditions
     * @return array Query results
     * @throws \RuntimeException If data retrieval fails
     */
    public static function getData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        return self::processData($function, $action, $param, $filter);
    }

    /**
     * Save data to database
     * 
     * Executes write operations (insert, update, delete) against the database:
     * - Loads appropriate resource definition
     * - Validates input data
     * - Builds and executes query
     * - Returns operation results
     * 
     * @param string $function Resource/model name to modify
     * @param string $action Operation type (insert|update|delete)
     * @param array $param Data to save and operation parameters
     * @return array Operation result including affected records or created IDs
     * @throws \RuntimeException If data save operation fails
     */
    public static function saveData(string $function, string $action, array $param): array 
    {
        return self::processData($function, $action, $param);
    }

    /**
     * Process all data operations
     * 
     * Central method for handling both read and write operations:
     * - Loads resource definition
     * - Sets up query builder
     * - Configures pagination and sorting
     * - Applies filters and conditions
     * - Executes appropriate query type
     * - Formats and returns results
     * 
     * @param string $function Resource/model name
     * @param string $action Operation type
     * @param array $param Operation parameters
     * @param array|null $filter Additional filter conditions
     * @return array Operation results
     * @throws \RuntimeException If data processing fails
     */
    private static function processData(string $function, string $action, array $param, ?array $filter = null): array 
    {
        $definition = self::loadDefinition($function);
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        try {
            // Handle pagination configuration
            $paginationEnabled = config('pagination.enabled', true);
            $usePagination = $param['paginate'] ?? $paginationEnabled;
            $page = max(1, (int)($param['page'] ?? 1));
            $perPage = $usePagination ? min(
                config('pagination.max_size', 100),
                max(1, (int)($param['per_page'] ?? config('pagination.default_size', 25)))
            ) : null;

            // Handle sorting
            $sort = $param['sort'] ?? 'created_at';
            $order = strtolower($param['order'] ?? 'desc');
            $order = in_array($order, ['asc', 'desc']) ? $order : 'desc';

            // Process filters
            if ($filter) {
                $conditions = [];
                foreach ($filter as $field => $value) {
                    if (is_array($value)) {
                        // Complex conditions handled by QueryBuilder's where methods
                        foreach ($value as $operator => $val) {
                            $conditions[$field] = [$operator => $val];
                        }
                    } else {
                        $conditions[$field] = $value;
                    }
                }
                $param['conditions'] = $conditions;
            }

            // Handle paginated list actions
            if ($action === 'list' && $usePagination) {
                return $queryBuilder->select(
                    $definition['table']['name'],
                    explode(',', $param['fields'] ?? '*')
                )
                ->where($param['conditions'] ?? [])
                ->orderBy($param['orderBy'] ?? [])
                ->paginate($page, $perPage);
            }

            // Handle other actions
            $result = self::executeQuery(
                $action, 
                $definition, 
                $param
            );

            return $result;

        } catch (\Exception $e) {
            throw new \RuntimeException("Data processing failed: " . $e->getMessage());
        }
    }

    /**
     * Execute specific database query
     * 
     * Constructs and executes database query based on action type:
     * - Selects appropriate query builder method
     * - Maps parameters to query conditions
     * - Executes query against database
     * - Formats results based on operation type
     * 
     * @param string $action Operation type to execute
     * @param array $definition Resource definition from JSON
     * @param array $params Operation parameters
     * @return array Query results
     * @throws \RuntimeException If query execution fails
     */
    private static function executeQuery(string $action, array $definition, array $params): array 
    {
        try {
            $queryBuilder = new QueryBuilder(self::$db, self::$driver);
            
            $result = match($action) {
                'list', 'view' => $queryBuilder
                    ->select(
                        $definition['table']['name'],
                        explode(',', $params['fields'] ?? '*')
                    )
                    ->where($params['where'] ?? [])
                    ->orderBy($params['orderBy'] ?? [])
                    ->limit($params['limit'] ?? null)
                    ->get(),
                    
                'count' => [['total' => $queryBuilder
                    ->count($definition['table']['name'], $params['where'] ?? [])]],
                    
                'save' => [
                    'uuid' => $queryBuilder->insert($definition['table']['name'], $params) ? 
                        self::getLastInsertedUUID(self::$db, $definition['table']['name']) : 
                        null
                ],
                
                'update' => [
                    'affected' => $queryBuilder->upsert(
                        $definition['table']['name'],
                        [array_merge(
                            ['uuid' => $params['uuid']],
                            $params['data'] ?? []
                        )],
                        array_keys($params['data'] ?? [])
                    )
                ],
                
                'delete' => [
                    'affected' => $queryBuilder
                        ->delete(
                            $definition['table']['name'],
                            $params['where'] ?? [],
                            true
                        ) ? 1 : 0
                ],
                
                default => []
            };
            
            return $result;
        } catch (\Exception $e) {
            throw new \RuntimeException("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Retrieve UUID of last inserted record
     * 
     * Gets the UUID of the most recently inserted record in a table.
     * Handles database-specific details for UUID retrieval.
     * 
     * @param \PDO|null $db Database connection
     * @param string $table Table name to query
     * @return string UUID of last inserted record
     * @throws \RuntimeException If UUID retrieval fails
     */
    private static function getLastInsertedUUID(?\PDO $db, string $table): string 
    {
        $connection = new Connection();
        $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        try {
            return $queryBuilder->lastInsertId($table, 'uuid');
        } catch (\Exception $e) {
            error_log("Failed to get last inserted UUID: " . $e->getMessage());
            throw new \RuntimeException("Failed to retrieve UUID for new record: " . $e->getMessage());
        }
    }

    /**
     * Get resource definition
     * 
     * Retrieves JSON definition for a specific resource model:
     * - Locates definition file
     * - Parses JSON structure
     * - Validates definition format
     * 
     * @param string $function Resource name to load definition for
     * @return array|null Resource definition or null if not found
     */
    protected static function getDefinition(string $function): ?array
    {
        return self::loadDefinition($function);
    }

    /**
     * Load resource definition from JSON file
     * 
     * Finds and parses JSON definition for a resource:
     * - Resolves file path based on current resource context
     * - Checks file existence
     * - Parses JSON structure
     * - Validates definition integrity
     * 
     * @param string $function Resource name
     * @return array Parsed definition
     * @throws \RuntimeException If definition cannot be loaded or is invalid
     */
    private static function loadDefinition(string $function): array 
    {
        $resource = self::$currentResource;
        $path = config('paths.json_definitions') . $resource . '.' . $function . '.json';
        
        if (!file_exists($path)) {
            // Use standard HTTP code 404 instead of Response::HTTP_NOT_FOUND
            throw new \RuntimeException(
                "The definition $resource.$function.json does not exist",
                404
            );
        }

        $definition = json_decode(file_get_contents($path), true);
        if (!$definition) {
            throw new \RuntimeException("Invalid JSON definition for $function");
        }

        return $definition;
    }
}

// Initialize engine automatically when file is included
APIEngine::initialize();