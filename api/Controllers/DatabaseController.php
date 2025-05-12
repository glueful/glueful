<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\{Connection, QueryBuilder};

/**
 * Database Controller
 *
 * Handles all database-related operations including:
 * - Table creation and management
 * - Column operations
 * - Index operations
 * - Foreign key constraints
 * - Schema updates
 * - Database statistics
 * - Query execution
 *
 * @package Glueful\Controllers
 */
class DatabaseController
{
    private SchemaManager $schemaManager;
    private QueryBuilder $queryBuilder;

    /**
     * Initialize Database Controller
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->schemaManager = $connection->getSchemaManager();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
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
                    $autoIncValue = is_string($options['autoIncrement'])
                        ? $options['autoIncrement']
                        : "AUTO_INCREMENT";
                    $columnDef .= " " . $autoIncValue;
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
                        $defaultValue = is_numeric($options['default'])
                            ? $options['default']
                            : "'{$options['default']}'";
                        $columnDef .= " DEFAULT " . $defaultValue;
                    }
                }

                $columns[$columnName] = $columnDef;
            }

            // Build the schema operation with proper method chaining
            $schemaManager = $this->schemaManager->createTable($tableName, $columns);

            // Add indexes if provided
            if (isset($data['indexes']) && !empty($data['indexes'])) {
                // Make sure each index has the table property set
                $indexes = array_map(function ($index) use ($tableName) {
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
                $foreignKeys = array_map(function ($fk) use ($tableName) {
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

            $results['columns'] = $columns;
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
                $errorMsg = "No columns found or table '$tableName' does not exist";
                return Response::error($errorMsg, Response::HTTP_NOT_FOUND)->send();
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
            } elseif (empty($results)) {
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
            } elseif (empty($results)) {
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
            } elseif (empty($results)) {
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
            } elseif (empty($results)) {
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
                return Response::ok(
                    [
                    'table' => $tableName,
                    'constraints_added' => $results
                    ],
                    count($results) > 1
                        ? 'Foreign key constraints added successfully'
                        : 'Foreign key constraint added successfully'
                )->send();
            } elseif (empty($results)) {
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
            $hasMultipleConstraints = isset($data['constraint_names']);
            $constraintNames = $hasMultipleConstraints
                ? $data['constraint_names']
                : [$data['constraint_name']];

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
                return Response::ok(
                    [
                    'table' => $tableName,
                    'constraints_dropped' => $results
                    ],
                    count($results) > 1
                        ? 'Foreign key constraints dropped successfully'
                        : 'Foreign key constraint dropped successfully'
                )->send();
            } elseif (empty($results)) {
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
     * Update table schema with multiple operations
     *
     * Processes multiple schema changes in a single request:
     * - Add/delete columns
     * - Add/delete indexes
     * - Add/delete foreign keys
     *
     * @return mixed HTTP response
     */
    public function updateTableSchema(): mixed
    {
        try {
            $data = Request::getPostData();

            if (!isset($data['table_name'])) {
                return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $tableName = $data['table_name'];
            $results = [
                'added_columns' => [],
                'deleted_columns' => [],
                'added_indexes' => [],
                'deleted_indexes' => [],
                'added_foreign_keys' => [],
                'deleted_foreign_keys' => [],
                'failed_operations' => []
            ];

            // Process column deletions first to avoid constraint conflicts
            if (!empty($data['deleted_columns'])) {
                foreach ($data['deleted_columns'] as $column) {
                    try {
                        $success = $this->schemaManager->dropColumn($tableName, $column);
                        if ($success) {
                            $results['deleted_columns'][] = $column;
                        } else {
                            $results['failed_operations'][] = "Failed to delete column: $column";
                        }
                    } catch (\Exception $e) {
                        error_log("Failed to delete column '$column': " . $e->getMessage());
                        $results['failed_operations'][] = "Failed to delete column: $column - " . $e->getMessage();
                    }
                }
            }

            // Process index deletions
            if (!empty($data['deleted_indexes'])) {
                foreach ($data['deleted_indexes'] as $index) {
                    try {
                        $success = $this->schemaManager->dropIndex($tableName, $index);
                        if ($success) {
                            $results['deleted_indexes'][] = $index;
                        } else {
                            $results['failed_operations'][] = "Failed to delete index: $index";
                        }
                    } catch (\Exception $e) {
                        error_log("Failed to delete index '$index': " . $e->getMessage());
                        $results['failed_operations'][] = "Failed to delete index: $index - " . $e->getMessage();
                    }
                }
            }

            // Process foreign key deletions
            if (!empty($data['deleted_foreign_keys'])) {
                foreach ($data['deleted_foreign_keys'] as $constraintName) {
                    try {
                        $success = $this->schemaManager->dropForeignKey($tableName, $constraintName);
                        if ($success) {
                            $results['deleted_foreign_keys'][] = $constraintName;
                        } else {
                            $results['failed_operations'][] = "Failed to delete foreign key: $constraintName";
                        }
                    } catch (\Exception $e) {
                        $errorMsg = "Failed to delete foreign key '$constraintName': " . $e->getMessage();
                        error_log($errorMsg);
                        $results['failed_operations'][] = "Failed to delete foreign key: $constraintName - " .
                            $e->getMessage();
                    }
                }
            }

            // Process new columns
            if (!empty($data['columns'])) {
                foreach ($data['columns'] as $column) {
                    if (!isset($column['name']) || !isset($column['type'])) {
                        $results['failed_operations'][] = "Invalid column definition";
                        continue;
                    }

                    try {
                        $columnDef = ['type' => $column['type']];

                        // Handle column options
                        if (isset($column['options'])) {
                            if (isset($column['options']['nullable'])) {
                                $columnDef['nullable'] = $column['options']['nullable'] === 'NULL';
                            }
                            if (isset($column['options']['default'])) {
                                $columnDef['default'] = $column['options']['default'];
                            }
                        }

                        $success = $this->schemaManager->addColumn($tableName, $column['name'], $columnDef);

                        if ($success) {
                            $results['added_columns'][] = $column['name'];
                        } else {
                            $results['failed_operations'][] = "Failed to add column: " . $column['name'];
                        }
                    } catch (\Exception $e) {
                        $columnName = $column['name'];
                        error_log("Failed to add column '$columnName': " . $e->getMessage());
                        $results['failed_operations'][] = "Failed to add column: $columnName - " . $e->getMessage();
                    }
                }
            }

            // Process new indexes
            if (!empty($data['indexes'])) {
                $formattedIndexes = array_map(function ($index) use ($tableName) {
                    return [
                        'table' => $tableName,
                        'column' => $index['column'],
                        'type' => $index['type'],
                        // Generate a standard index name if not provided
                        'name' => $index['name'] ?? sprintf(
                            "%s_%s_%s",
                            $tableName,
                            is_array($index['column']) ? implode('_', $index['column']) : $index['column'],
                            strtolower($index['type']) === 'unique' ? 'unq' : 'idx'
                        )
                    ];
                }, $data['indexes']);

                try {
                    $success = $this->schemaManager->addIndex($formattedIndexes);
                    if ($success) {
                        $results['added_indexes'] = array_map(function ($index) {
                            return $index['name'];
                        }, $formattedIndexes);
                    } else {
                        $results['failed_operations'][] = "Failed to add indexes";
                    }
                } catch (\Exception $e) {
                    error_log("Failed to add indexes: " . $e->getMessage());
                    $results['failed_operations'][] = "Failed to add indexes: " . $e->getMessage();
                }
            }

            // Process new foreign keys
            if (!empty($data['foreign_keys'])) {
                foreach ($data['foreign_keys'] as $fk) {
                    if (!isset($fk['column']) || !isset($fk['references']) || !isset($fk['on'])) {
                        $results['failed_operations'][] = "Invalid foreign key definition";
                        continue;
                    }

                    try {
                        $fkDef = [
                            'table' => $tableName,
                            'column' => $fk['column'],
                            'references' => $fk['references'],
                            'on' => $fk['on'],
                        ];

                        $success = $this->schemaManager->addForeignKey([$fkDef]);
                        if ($success) {
                            $results['added_foreign_keys'][] = $fkDef['name'] ?? "fk_{$tableName}_{$fk['column']}";
                        } else {
                            $results['failed_operations'][] = "Failed to add foreign key on column: " . $fk['column'];
                        }
                    } catch (\Exception $e) {
                        $columnName = $fk['column'];
                        error_log("Failed to add foreign key on column '$columnName': " . $e->getMessage());
                        $errorMsg = "Failed to add foreign key: $columnName - " . $e->getMessage();
                        $results['failed_operations'][] = $errorMsg;
                    }
                }
            }

            // Return appropriate response based on results
            if (empty($results['failed_operations'])) {
                return Response::ok($results, 'Table schema updated successfully')->send();
            } else {
                // Some operations failed, but others might have succeeded
                return Response::ok(
                    $results,
                    'Some schema update operations completed with warnings'
                )->send();
            }
        } catch (\Exception $e) {
            error_log("Update table schema error: " . $e->getMessage());
            return Response::error(
                'Failed to update table schema: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get comprehensive database statistics
     *
     * Returns database statistics including tables with their schemas, sizes, row counts,
     * and other metrics useful for database monitoring and visualization.
     *
     * @return mixed HTTP response
     */
    public function getDatabaseStats(): mixed
    {
        try {
            // Get list of all tables with schema information
            $tables = $this->schemaManager->getTables(true); // Pass true to include schema info

            if (empty($tables)) {
                return Response::ok(['tables' => []], 'No tables found in database')->send();
            }

            $tableData = [];

            // Get size information for each table
            foreach ($tables as $table) {
                // Check if the result includes schema information already
                $tableName = is_array($table) ? $table['name'] : $table;
                // Default to 'public' schema if not specified
                $schema = is_array($table) && isset($table['schema']) ? $table['schema'] : 'public';

                try {
                    $size = $this->schemaManager->getTableSize($tableName);
                    $rowCount = $this->schemaManager->getTableRowCount($tableName);

                    $tableData[] = [
                        'table_name' => $tableName,
                        'schema' => $schema,
                        'size' => $size,
                        'rows' => $rowCount,
                        'avg_row_size' => $rowCount > 0 ? round($size / $rowCount) : 0
                    ];
                } catch (\Exception $e) {
                    // If we can't get size for a specific table, include it with null values
                    error_log("Error getting size for table {$tableName}: " . $e->getMessage());
                    $tableData[] = [
                        'table_name' => $tableName,
                        'schema' => $schema,
                        'size' => null,
                        'rows' => null,
                        'avg_row_size' => null,
                        'error' => 'Failed to retrieve size information'
                    ];
                }
            }

            // Sort tables by size in descending order
            usort($tableData, function ($a, $b) {
                // Handle null values in comparison
                if ($a['size'] === null) {
                    return 1;
                }
                if ($b['size'] === null) {
                    return -1;
                }
                return $b['size'] <=> $a['size'];
            });

            return Response::ok([
                'tables' => $tableData,
                'total_tables' => count($tables)
            ], 'Database statistics retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get database stats error: " . $e->getMessage());
            return Response::error(
                'Failed to get database statistics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int|float $bytes Number of bytes
     * @param int $precision Precision of rounding
     * @return string Formatted size with unit
     */
    private function formatBytes($bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max((float)$bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
