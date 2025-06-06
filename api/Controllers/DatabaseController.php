<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request as RequestHelper;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Interfaces\Permission\PermissionStandards;
use Glueful\Exceptions\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

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
class DatabaseController extends BaseController
{
    private SchemaManager $schemaManager;

    /**
     * Initialize Database Controller
     */
    public function __construct()
    {
        parent::__construct();
        $connection = $this->getConnection();
        $this->schemaManager = $connection->getSchemaManager();
    }

    /**
     * Create new database table
     *
     * @return Response HTTP response
     */
    public function createTable(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'create_table',
            'endpoint' => '/admin/db/table/create'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name', 'columns']);

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

            $this->schemaManager->addForeignKey($foreignKeys);
        }

        return $this->successResponse([
            'table' => $tableName,
            'columns' => $columnsData,
            'indexes' => $data['indexes'] ?? [],
            'foreign_keys' => $data['foreign_keys'] ?? []
        ], 'Table created successfully');
    }

    /**
     * Drop database table
     */
    public function dropTable(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'drop_table',
            'endpoint' => '/admin/db/table/drop'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        // Drop the table
        $result = $this->schemaManager->dropTable($data['table_name']);
        if (!$result) {
            throw new \RuntimeException('Failed to drop table');
        }

        return $this->successResponse(null, 'Table dropped successfully');
    }

    /**
     * Get list of all tables
     */
    public function getTables(?bool $includeSchema = false): Response
    {
        // Check permission to view database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'list_tables',
            'endpoint' => '/admin/db/tables'
        ]);

        $tables = $this->schemaManager->getTables();
        return $this->successResponse($tables, 'Tables retrieved successfully');
    }

    /**
     * Get table size information
     */
    public function getTableSize(?array $table): Response
    {
        // Check permission to view database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'view_table_size',
            'endpoint' => '/admin/db/table/{name}/size'
        ]);

        // Validate table parameter
        if (!isset($table['name'])) {
            throw new \InvalidArgumentException('Table name is required');
        }

        $size = $this->schemaManager->getTableSize($table['name']);

        return $this->successResponse([
            'table' => $table['name'],
            'size' => $size
        ], 'Table size retrieved successfully');
    }

    /**
     * Get comprehensive table metadata
     *
     * Returns detailed information about a table including:
     * - Row count
     * - Table size
     * - Column count
     * - Index count
     * - Storage engine
     * - Creation time
     * - Last update time
     *
     * @param array|null $table Table data with 'name' key
     * @return Response HTTP response
     */
    public function getTableMetadata(?array $table): Response
    {
        // Check permission to view database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'view_table_metadata',
            'endpoint' => '/admin/db/table/{name}/metadata'
        ]);

        // Validate table parameter
        if (!isset($table['name'])) {
            throw new \InvalidArgumentException('Table name is required');
        }

        $tableName = $table['name'];

        // Get basic table information
        $tableSize = $this->schemaManager->getTableSize($tableName);
        $columns = $this->schemaManager->getTableColumns($tableName);

        // Get indexes using raw query
        $indexes = $this->queryBuilder->rawQuery(
            "SHOW INDEX FROM `{$tableName}`"
        );

        // Get table status information (engine, creation time, etc.)
        $tableStatus = $this->queryBuilder->rawQuery(
            "SHOW TABLE STATUS WHERE Name = ?",
            [$tableName]
        );

        $status = !empty($tableStatus) ? $tableStatus[0] : [];

        // Format the metadata response
        $metadata = [
            'name' => $tableName,
            'rows' => $status['Rows'] ?? 0,
            'size' => $tableSize,
            'columns' => count($columns),
            'indexes' => count($indexes),
            'engine' => $status['Engine'] ?? 'Unknown',
            'created' => $status['Create_time'] ?? null,
            'updated' => $status['Update_time'] ?? null,
            'collation' => $status['Collation'] ?? null,
            'comment' => $status['Comment'] ?? '',
            'auto_increment' => $status['Auto_increment'] ?? null,
            'avg_row_length' => $status['Avg_row_length'] ?? 0,
            'data_length' => $status['Data_length'] ?? 0,
            'index_length' => $status['Index_length'] ?? 0,
            'data_free' => $status['Data_free'] ?? 0
        ];

        return $this->successResponse($metadata, 'Table metadata retrieved successfully');
    }

    /**
     * Fetch paginated data from a table with search and filtering
     *
     * Supports:
     * - Full-text search across multiple columns
     * - Advanced filtering with operators
     * - Pagination
     * - Sorting
     *
     * Query parameters:
     * - page: Page number (default: 1)
     * - per_page: Records per page (default: 25)
     * - search: Search term for text search
     * - search_fields: Array of column names to search in
     * - filters: Advanced filters object with operator support
     * - order_by: Column to sort by (default: id)
     * - order_dir: Sort direction ASC/DESC (default: DESC)
     *
     * @return Response HTTP response
     */
    public function getTableData(?array $table): Response
    {
        try {
            // Validate table parameter
            if (!isset($table['name'])) {
                return $this->errorResponse('Table name is required', Response::HTTP_BAD_REQUEST);
            }

            $tableName = $table['name'];

            // Check permission to view table data
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.read', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'get_table_data',
                'endpoint' => '/admin/db/table/{name}',
                'table' => $tableName
            ]);

            // Check if table exists
            if (!$this->schemaManager->tableExists($tableName)) {
                throw new NotFoundException("Table '{$tableName}' does not exist");
            }

            // Get request parameters using BaseController methods
            $queryParams = $this->getQueryParams($request);

            // Parse pagination parameters
            $pagination = $this->parsePaginationParams($queryParams);

            // Parse sorting parameters
            $sorting = $this->parseSortParams($queryParams);

            // Search parameters
            $searchTerm = $queryParams['search'] ?? null;
            $searchFields = $queryParams['search_fields'] ?? [];

            // Advanced filters
            $filters = isset($queryParams['filters']) ? json_decode($queryParams['filters'], true) : [];

            // Get table columns for auto-detection of searchable fields
            $columns = $this->schemaManager->getTableColumns($tableName);

            // Auto-detect searchable columns if not specified
            if ($searchTerm && empty($searchFields)) {
                $searchFields = $this->detectSearchableColumns($columns);
            }

            // Build the query using QueryBuilder
            $query = $this->queryBuilder->select($tableName, ['*']);

            // Apply search if provided
            if ($searchTerm && !empty($searchFields)) {
                // Validate search fields exist in table
                $validSearchFields = $this->validateSearchFields($searchFields, $columns);
                if (!empty($validSearchFields)) {
                    $query->search($validSearchFields, $searchTerm);
                }
            }

            // Apply advanced filters if provided
            if (!empty($filters)) {
                // Validate filter fields exist in table
                $validFilters = $this->validateFilters($filters, $columns);
                if (!empty($validFilters)) {
                    $query->advancedWhere($validFilters);
                }
            }

            // Apply sorting
            $query->orderBy($sorting['order_by']);

            // Execute query with pagination
            $results = $query->paginate($pagination['page'], $pagination['per_page']);

            // Resolve foreign key display labels
            if (!empty($results['data'])) {
                $results['data'] = $this->resolveForeignKeyDisplayLabels($results['data'], $columns);
            }

            // Add column metadata to results
            $results['columns'] = $columns;

            // Add search metadata if search was performed
            if ($searchTerm) {
                $results['search'] = [
                    'term' => $searchTerm,
                    'fields' => $searchFields,
                    'fields_used' => $validSearchFields ?? []
                ];
            }

            // Add filter metadata if filters were applied
            if (!empty($filters)) {
                $results['filters'] = [
                    'applied' => $validFilters ?? [],
                    'requested' => $filters
                ];
            }

            return $this->successResponse($results, 'Data retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'get table data');
        }
    }

    /**
     * Get columns information for a specific table
     *
     * @param array $params Route parameters containing table name
     * @return Response HTTP response with column metadata
     */
    public function getColumns(array $params): Response
    {
        try {
            // Check permission to view database schema
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'get_columns',
                'endpoint' => '/admin/db/table/{name}/columns'
            ]);

            if (!isset($params['name'])) {
                return $this->validationErrorResponse('Table name is required');
            }

            $tableName = $params['name'];

            // Get detailed column metadata using SchemaManager
            $columns = $this->schemaManager->getTableColumns($tableName);

            if (empty($columns)) {
                return $this->notFoundResponse("No columns found or table '$tableName' does not exist");
            }

            return $this->successResponse([
                'table' => $tableName,
                'columns' => $columns
            ], 'Table columns retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieve table columns');
        }
    }

    /**
     * Add column(s) to existing table
     *
     * Allows adding a single column or multiple columns in batch.
     *
     * @return Response HTTP response
     */
    public function addColumn(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'add_column',
            'endpoint' => '/admin/db/table/column/add'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        if (!isset($data['column']) && !isset($data['columns'])) {
            throw new \InvalidArgumentException('Column details are required');
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
                // Create column definition array with merged options
                $columnDef = ['type' => $column['type']];
                if (isset($column['options']) && is_array($column['options'])) {
                    $columnDef = array_merge($columnDef, $column['options']);
                }

                $result = $this->schemaManager->addColumn(
                    $tableName,
                    $column['name'],
                    $columnDef
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
            return $this->successResponse([
                'table' => $tableName,
                'columns_added' => $results
            ], count($results) > 1 ? 'Columns added successfully' : 'Column added successfully');
        } elseif (empty($results)) {
            throw new \RuntimeException('Failed to add column(s): ' . implode(', ', $failed));
        } else {
            return $this->successResponse([
                'table' => $tableName,
                'columns_added' => $results,
                'columns_failed' => $failed
            ], 'Some columns were added successfully, but others failed');
        }
    }

    /**
     * Drop column(s) from table
     *
     * Allows dropping a single column or multiple columns in batch.
     *
     * @return Response HTTP response
     */
    public function dropColumn(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'drop_column',
            'endpoint' => '/admin/db/table/column/drop'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        if (!isset($data['column_name']) && !isset($data['column_names'])) {
            throw new \InvalidArgumentException('Column name(s) are required');
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
            return $this->successResponse([
                'table' => $tableName,
                'columns_dropped' => $results
            ], count($results) > 1 ? 'Columns dropped successfully' : 'Column dropped successfully');
        } elseif (empty($results)) {
            throw new \RuntimeException('Failed to drop column(s): ' . implode(', ', $failed));
        } else {
            return $this->successResponse([
                'table' => $tableName,
                'columns_dropped' => $results,
                'columns_failed' => $failed
            ], 'Some columns were dropped successfully, but others failed');
        }
    }

    /**
     * Add index(es) to an existing table
     *
     * Allows adding a single index or multiple indexes in batch.
     *
     * @return Response HTTP response
     */
    public function addIndex(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'add_index',
            'endpoint' => '/admin/db/table/index/add'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        if (!isset($data['index']) && !isset($data['indexes'])) {
            throw new \InvalidArgumentException('Index definition is required');
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
                $indexType = strtolower($index['type']) === 'unique' ? 'unq' : 'idx';
                $formattedIndex['name'] = "{$tableName}_{$columnStr}_{$indexType}";
            } else {
                $formattedIndex['name'] = $index['name'];
            }

            $formattedIndexes[] = $formattedIndex;

            try {
                // Add the index
                $this->schemaManager->addIndex([$formattedIndex]);
                // If we get here, no exception was thrown, so it succeeded
                $results[] = $formattedIndex['name'];
            } catch (\Exception $e) {
                error_log("Failed to add index on column '{$index['column']}': " . $e->getMessage());
                $failed[] = $formattedIndex['name'];
            }
        }

        // Check if all indexes were added successfully
        if (empty($failed)) {
            return $this->successResponse([
                'table' => $tableName,
                'indexes_added' => $results
            ], count($results) > 1 ? 'Indexes added successfully' : 'Index added successfully');
        } elseif (empty($results)) {
            throw new \RuntimeException('Failed to add index(es): ' . implode(', ', $failed));
        } else {
            return $this->successResponse([
                'table' => $tableName,
                'indexes_added' => $results,
                'indexes_failed' => $failed
            ], 'Some indexes were added successfully, but others failed');
        }
    }

    /**
     * Drop index(es) from table
     *
     * Allows dropping a single index or multiple indexes in batch.
     *
     * @return Response HTTP response
     */
    public function dropIndex(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'drop_index',
            'endpoint' => '/admin/db/table/index/drop'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        if (!isset($data['index_name']) && !isset($data['index_names'])) {
            throw new \InvalidArgumentException('Index name(s) are required');
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
            return $this->successResponse([
                'table' => $tableName,
                'indexes_dropped' => $results
            ], count($results) > 1 ? 'Indexes dropped successfully' : 'Index dropped successfully');
        } elseif (empty($results)) {
            throw new \RuntimeException('Failed to drop index(es): ' . implode(', ', $failed));
        } else {
            return $this->successResponse([
                'table' => $tableName,
                'indexes_dropped' => $results,
                'indexes_failed' => $failed
            ], 'Some indexes were dropped successfully, but others failed');
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
     * @return Response HTTP response
     */
    public function addForeignKey(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'add_foreign_key',
            'endpoint' => '/admin/db/table/foreign-key/add'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        if (!isset($data['foreign_key']) && !isset($data['foreign_keys'])) {
            throw new \InvalidArgumentException('Foreign key definition is required');
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
                 $this->schemaManager->addForeignKey([$formattedFk]);

                $results[] = $formattedFk['name'];
            } catch (\Exception $e) {
                error_log("Failed to add foreign key for column '{$fk['column']}': " . $e->getMessage());
                $failed[] = $fk['column'];
            }
        }

        // Check if all foreign keys were added successfully
        if (empty($failed)) {
            $message = count($results) > 1
                ? 'Foreign key constraints added successfully'
                : 'Foreign key constraint added successfully';

            return $this->successResponse([
                'table' => $tableName,
                'constraints_added' => $results
            ], $message);
        } elseif (empty($results)) {
            throw new \RuntimeException('Failed to add foreign key(s): ' . implode(', ', $failed));
        } else {
            return $this->successResponse([
                'table' => $tableName,
                'constraints_added' => $results,
                'constraints_failed' => $failed
            ], 'Some foreign key constraints were added successfully, but others failed');
        }
    }

    /**
     * Drop foreign key constraint(s) from table
     *
     * Allows dropping a single constraint or multiple constraints in batch.
     *
     * @return Response HTTP response
     */
    public function dropForeignKey(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'drop_foreign_key',
            'endpoint' => '/admin/db/table/foreign-key/drop'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

        if (!isset($data['constraint_name']) && !isset($data['constraint_names'])) {
            throw new \InvalidArgumentException('Constraint name(s) are required');
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
            return $this->successResponse([
                'table' => $tableName,
                'constraints_dropped' => $results
            ], count($results) > 1
                ? 'Foreign key constraints dropped successfully'
                : 'Foreign key constraint dropped successfully');
        } elseif (empty($results)) {
            throw new \RuntimeException('Failed to drop foreign key constraint(s): ' . implode(', ', $failed));
        } else {
            return $this->successResponse([
                'table' => $tableName,
                'constraints_dropped' => $results,
                'constraints_failed' => $failed
            ], 'Some foreign key constraints were dropped successfully, but others failed');
        }
    }

    /**
     * Execute a raw SQL query
     *
     * Executes a raw SQL query against the database and returns the results.
     * Limited to admin users with appropriate permissions.
     *
     * @return Response HTTP response
     */
    public function executeQuery(): Response
    {
        // Check permission to execute raw SQL queries
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.execute', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'execute_query',
            'endpoint' => '/admin/db/query'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['query']);

        // Get the SQL query from the request
        $sql = trim($data['query']);
        $params = $data['params'] ?? [];

        // Safety checks
        if (empty($sql)) {
            throw new \InvalidArgumentException('SQL query cannot be empty');
        }

        // Prevent destructive operations if the safety flag is not set
        $isSafeQuery = $data['allow_write'] ?? false;
        $firstWord = strtoupper(explode(' ', $sql)[0]);
        if (!$isSafeQuery && in_array($firstWord, ['DELETE', 'TRUNCATE', 'DROP', 'ALTER', 'UPDATE', 'INSERT'])) {
            throw new \InvalidArgumentException('Write operations require explicit allow_write flag for safety');
        }

        // Log the query attempt for security purposes
        error_log("Admin SQL query execution: " . substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''));

        try {
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

            return $this->successResponse($responseData, $message);
        } catch (\PDOException $e) {
            error_log("SQL Error: " . $e->getMessage());
            throw new \RuntimeException('SQL Error: ' . $e->getMessage());
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
     * @return Response HTTP response
     */
    public function updateTableSchema(): Response
    {
        // Check permission to manage database schema
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'update_table_schema',
            'endpoint' => '/admin/db/table/schema/update'
        ]);

        // Get and validate request data
        $data = RequestHelper::getPostData();
        $this->validateRequired($data, ['table_name']);

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
                // The method returns the schema manager instance (self), not a boolean
                $this->schemaManager->addIndex($formattedIndexes);
                // Since no exception was thrown, consider it successful
                $results['added_indexes'] = array_map(function ($index) {
                    return $index['name'];
                }, $formattedIndexes);
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
                    // Generate a constraint name if not provided
                    $fkName = isset($fk['name']) ? $fk['name'] : "fk_{$tableName}_{$fk['column']}";
                    $fkDef = [
                        'table' => $tableName,
                        'column' => $fk['column'],
                        'references' => $fk['references'],
                        'on' => $fk['on'],
                        'name' => $fkName
                    ];

                    $this->schemaManager->addForeignKey([$fkDef]);
                    // If no exception thrown, consider it successful
                    $results['added_foreign_keys'][] = $fkName;
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
            return $this->successResponse($results, 'Table schema updated successfully');
        } else {
            // Some operations failed, but others might have succeeded
            return $this->successResponse(
                $results,
                'Some schema update operations completed with warnings'
            );
        }
    }

    /**
     * Get comprehensive database statistics
     *
     * Returns database statistics including tables with their schemas, sizes, row counts,
     * and other metrics useful for database monitoring and visualization.
     *
     * @return Response HTTP response
     */
    public function getDatabaseStats(): Response
    {
        // Check permission to view database statistics
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'get_database_stats',
            'endpoint' => '/admin/db/stats'
        ]);

        // Get list of all tables with schema information
        $tables = $this->schemaManager->getTables(); // No parameters needed

        if (empty($tables)) {
            return $this->successResponse(['tables' => []], 'No tables found in database');
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

        return $this->successResponse([
            'tables' => $tableData,
            'total_tables' => count($tables)
        ], 'Database statistics retrieved successfully');
    }

    /**
     * Import data into a database table
     *
     * Handles bulk import of data into the specified table with options for:
     * - Skipping first row if it contains headers
     * - Updating existing records by matching ID
     * - Skipping rows with errors and continuing
     *
     * @param array $params Route parameters containing table name
     * @return Response HTTP response
     */
    public function importTableData($params): Response
    {
        try {
            // Check permission to import data
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'import_table_data',
                'endpoint' => '/admin/db/tables/{name}/import'
            ]);

            if (!is_array($params)) {
                throw new \InvalidArgumentException('Invalid parameters');
            }
            $tableName = $params['name'] ?? null;
            if (!$tableName) {
                throw new \InvalidArgumentException('Table name is required');
            }

            // Get and validate request data
            $data = RequestHelper::getPostData();

            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid request format. Expected JSON object.');
            }

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new \InvalidArgumentException('Import data is required and must be an array');
            }

            $importData = $data['data'];
            $options = $data['options'] ?? [];

            // Import options with defaults
            $skipFirstRow = $options['skipFirstRow'] ?? false;
            $updateExisting = $options['updateExisting'] ?? false;
            $skipErrors = $options['skipErrors'] ?? true;

            // Check if table exists
            if (!$this->schemaManager->tableExists($tableName)) {
                throw new NotFoundException("Table '{$tableName}' does not exist");
            }

            // Get table columns to validate import data
            $tableColumns = $this->schemaManager->getTableColumns($tableName);
            $columnNames = array_column($tableColumns, 'name');

            // Skip first row if it contains headers and option is enabled
            if ($skipFirstRow && !empty($importData)) {
                array_shift($importData);
            }

            // Check if we should use background processing for large imports
            if (count($importData) > 500) {
                return $this->processLargeImport($tableName, $importData, $options, $columnNames);
            }

            // Process with optimized batch transactions for smaller imports
            return $this->processBatchImport($tableName, $importData, $options, $columnNames);
        } catch (\Exception $e) {
            return $this->handleException($e, 'import table data');
        }
    }

    /**
     * Process batch import with transactions for optimal performance
     *
     * @param string $tableName
     * @param array $importData
     * @param array $options
     * @param array $columnNames
     * @return mixed
     */
    private function processBatchImport(string $tableName, array $importData, array $options, array $columnNames): mixed
    {
        $updateExisting = $options['updateExisting'] ?? false;
        $skipErrors = $options['skipErrors'] ?? true;
        $batchSize = 100; // Optimal batch size for most databases

        $imported = 0;
        $failed = 0;
        $errors = [];

        // Process data in batches with transactions
        $batches = array_chunk($importData, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            try {
                // Start transaction for this batch
                $this->queryBuilder->beginTransaction();

                $batchImported = 0;
                $batchFailed = 0;

                foreach ($batch as $globalIndex => $row) {
                    $rowNumber = ($batchIndex * $batchSize) + $globalIndex + 1;

                    try {
                        $filteredRow = $this->validateAndFilterRow($row, $columnNames);
                        if ($filteredRow === null) {
                            $batchFailed++;
                            $failed++;
                            if (!$skipErrors) {
                                $errors[] = "Row {$rowNumber}: Invalid row data";
                            }
                            continue;
                        }

                        // Handle update existing records
                        if ($updateExisting && isset($filteredRow['id'])) {
                            $this->upsertRecord($tableName, $filteredRow);
                        } else {
                            $this->queryBuilder->insert($tableName, $filteredRow);
                        }

                        $batchImported++;
                    } catch (\Exception $e) {
                        $batchFailed++;
                        $failed++;
                        $error = "Row {$rowNumber}: " . $e->getMessage();
                        $errors[] = $error;

                        if (!$skipErrors) {
                            // Rollback transaction and stop processing
                            $this->queryBuilder->rollback();
                            return Response::error(
                                "Import failed at row {$rowNumber}: " . $e->getMessage(),
                                Response::HTTP_BAD_REQUEST
                            )->send();
                        }
                    }
                }

                // Commit transaction for this batch
                $this->queryBuilder->commit();
                $imported += $batchImported;
            } catch (\Exception $e) {
                // Rollback transaction on batch failure
                try {
                    $this->queryBuilder->rollback();
                } catch (\Exception $rollbackError) {
                    error_log("Rollback failed: " . $rollbackError->getMessage());
                }

                $failed += count($batch);
                $batchError = "Batch " . ($batchIndex + 1) . " failed: " . $e->getMessage();
                $errors[] = $batchError;

                if (!$skipErrors) {
                    return Response::error(
                        "Import failed at batch " . ($batchIndex + 1) . ": " . $e->getMessage(),
                        Response::HTTP_BAD_REQUEST
                    )->send();
                }
            }
        }

        $result = [
            'imported' => $imported,
            'failed' => $failed,
            'batches_processed' => $totalBatches
        ];

        if (!empty($errors)) {
            $result['errors'] = array_slice($errors, 0, 50); // Limit error messages
        }

        $message = "Import completed. {$imported} records imported";
        if ($failed > 0) {
            $message .= ", {$failed} failed";
        }

        return Response::ok($result, $message)->send();
    }

    /**
     * Process large imports (placeholder for background job implementation)
     *
     * @param string $tableName
     * @param array $importData
     * @param array $options
     * @param array $columnNames
     * @return mixed
     */
    private function processLargeImport(string $tableName, array $importData, array $options, array $columnNames): mixed
    {
        // For now, process large imports with larger batch sizes and optimizations
        $updateExisting = $options['updateExisting'] ?? false;
        $skipErrors = $options['skipErrors'] ?? true;
        $batchSize = 250; // Larger batch size for large imports

        // Increase PHP execution time for large imports
        set_time_limit(300); // 5 minutes

        // Use memory-efficient processing
        ini_set('memory_limit', '512M');

        $imported = 0;
        $failed = 0;
        $errors = [];

        $batches = array_chunk($importData, $batchSize);
        $totalBatches = count($batches);
        $totalRecords = count($importData);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $this->queryBuilder->beginTransaction();

                // Build bulk insert for better performance
                $validRows = [];
                foreach ($batch as $globalIndex => $row) {
                    $rowNumber = ($batchIndex * $batchSize) + $globalIndex + 1;

                    try {
                        $filteredRow = $this->validateAndFilterRow($row, $columnNames);

                        if ($filteredRow !== null) {
                            if ($updateExisting && isset($filteredRow['id'])) {
                                // Handle updates individually for now
                                $this->upsertRecord($tableName, $filteredRow);
                                $imported++;
                            } else {
                                $validRows[] = $filteredRow;
                            }
                        } else {
                            $failed++;
                            if (!$skipErrors) {
                                $errors[] = "Row {$rowNumber}: Invalid row data";
                            }
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        $error = "Row {$rowNumber}: " . $e->getMessage();
                        $errors[] = $error;

                        if (!$skipErrors) {
                            $this->queryBuilder->rawQuery('ROLLBACK');
                            return Response::error(
                                "Import failed at row {$rowNumber}: " . $e->getMessage(),
                                Response::HTTP_BAD_REQUEST
                            )->send();
                        }
                    }
                }

                // Bulk insert valid rows
                if (!empty($validRows)) {
                    $this->bulkInsert($tableName, $validRows);
                    $imported += count($validRows);
                }

                $this->queryBuilder->commit();

                // Memory cleanup
                unset($batch, $validRows);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            } catch (\Exception $e) {
                try {
                    $this->queryBuilder->rawQuery('ROLLBACK');
                } catch (\Exception $rollbackError) {
                    error_log("Rollback failed: " . $rollbackError->getMessage());
                }

                $failed += isset($batch) ? count($batch) : $batchSize;
                $batchError = "Batch " . ($batchIndex + 1) . " failed: " . $e->getMessage();
                $errors[] = $batchError;

                if (!$skipErrors) {
                    return Response::error(
                        "Import failed at batch " . ($batchIndex + 1) . ": " . $e->getMessage(),
                        Response::HTTP_BAD_REQUEST
                    )->send();
                }
            }
        }

        $result = [
            'imported' => $imported,
            'failed' => $failed,
            'total_records' => $totalRecords,
            'batches_processed' => $totalBatches,
            'processing_method' => 'large_import_optimized'
        ];

        if (!empty($errors)) {
            $result['errors'] = array_slice($errors, 0, 100); // More errors for large imports
        }

        $message = "Large import completed. {$imported} records imported";
        if ($failed > 0) {
            $message .= ", {$failed} failed";
        }

        return Response::ok($result, $message)->send();
    }

    /**
     * Validate and filter row data
     *
     * @param mixed $row
     * @param array $columnNames
     * @return array|null
     */
    private function validateAndFilterRow($row, array $columnNames): ?array
    {
        if (empty($row) || !is_array($row)) {
            return null;
        }

        $filteredRow = [];
        foreach ($row as $column => $value) {
            if (!is_string($column)) {
                continue;
            }
            if (in_array($column, $columnNames)) {
                $filteredRow[$column] = $value;
            }
        }

        return empty($filteredRow) ? null : $filteredRow;
    }

    /**
     * Insert or update record using database-agnostic upsert
     *
     * @param string $tableName
     * @param array $data
     * @return void
     */
    private function upsertRecord(string $tableName, array $data): void
    {
        // Get all columns except 'id' for update on duplicate
        $updateColumns = array_filter(array_keys($data), function ($column) {
            return $column !== 'id';
        });

        $this->queryBuilder->upsert($tableName, [$data], $updateColumns);
    }

    /**
     * Bulk insert multiple records using database-agnostic method
     *
     * @param string $tableName
     * @param array $rows
     * @return void
     */
    private function bulkInsert(string $tableName, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $this->queryBuilder->insertBatch($tableName, $rows);
    }

    /**
     * Bulk delete records from a table
     *
     * @param array $params Route parameters containing table name
     * @return Response HTTP response
     */
    public function bulkDelete(array $params): Response
    {
        // Check permission to delete data
        $request = Request::createFromGlobals();
        $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
            'action' => 'bulk_delete_data',
            'endpoint' => '/admin/db/tables/{name}/bulk-delete'
        ]);

        if (!is_array($params)) {
            throw new \InvalidArgumentException('Invalid parameters');
        }

        $tableName = $params['name'] ?? null;
        if (!$tableName) {
            throw new \InvalidArgumentException('Table name is required');
        }

        // Get and validate request data
        $data = RequestHelper::getPostData();

        if (!is_array($data)) {
            return Response::error('Invalid request format. Expected JSON object.', Response::HTTP_BAD_REQUEST)->send();
        }

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            throw new \InvalidArgumentException('IDs array is required and cannot be empty');
        }

        $ids = $data['ids'];

        // Soft delete parameters
        $softDelete = $data['soft_delete'] ?? false;
        $statusColumn = $data['status_column'] ?? 'status';
        $deletedValue = $data['deleted_value'] ?? 'deleted';

        // Validate that all IDs are scalar values
        foreach ($ids as $id) {
            if (!is_scalar($id)) {
                throw new \InvalidArgumentException('All IDs must be scalar values');
            }
        }

        // Check if table exists
        if (!$this->schemaManager->tableExists($tableName)) {
            throw new NotFoundException("Table '{$tableName}' does not exist");
        }

            // If soft delete is requested, validate that the status column exists
        if ($softDelete) {
            $tableColumns = $this->schemaManager->getTableColumns($tableName);
            $columnNames = array_column($tableColumns, 'name');

            if (!in_array($statusColumn, $columnNames)) {
                return Response::error(
                    "Column '{$statusColumn}' does not exist in table '{$tableName}'. Cannot perform soft delete.",
                    Response::HTTP_BAD_REQUEST
                )->send();
            }
        }

            // Perform bulk delete/update using transaction
            $this->queryBuilder->beginTransaction();

        try {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            if ($softDelete) {
                // Perform soft delete by updating status column
                $sql = $this->buildBulkSoftDeleteQuery($tableName, $statusColumn, $placeholders);
                $values = array_merge([$deletedValue], $ids);
                $stmt = $this->queryBuilder->executeQuery($sql, $values);
                $affectedCount = $stmt->rowCount();

                $this->queryBuilder->commit();

                return $this->successResponse([
                    'soft_deleted' => $affectedCount,
                    'ids' => $ids,
                    'status_column' => $statusColumn,
                    'deleted_value' => $deletedValue
                ], "Successfully soft deleted {$affectedCount} record(s)")->send();
            } else {
                // Perform hard delete
                $sql = $this->buildBulkDeleteQuery($tableName, $placeholders);
                $stmt = $this->queryBuilder->executeQuery($sql, $ids);
                $deletedCount = $stmt->rowCount();

                $this->queryBuilder->commit();

                return $this->successResponse([
                    'deleted' => $deletedCount,
                    'ids' => $ids
                ], "Successfully deleted {$deletedCount} record(s)");
            }
        } catch (\Exception $e) {
            $this->queryBuilder->rollback();
            throw $e;
        }
    }

    /**
     * Bulk update records in a table
     *
     * @param array $params Route parameters containing table name
     * @return Response HTTP response
     */
    public function bulkUpdate(array $params): Response
    {
        try {
            // Check permission to modify database records
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'bulk_update',
                'endpoint' => '/admin/db/tables/{name}/bulk-update'
            ]);

            if (!is_array($params)) {
                return $this->validationErrorResponse('Invalid parameters');
            }

            $tableName = $params['name'] ?? null;
            if (!$tableName) {
                return $this->validationErrorResponse('Table name is required');
            }

            // Get request data
            $data = RequestHelper::getPostData();

            if (!is_array($data)) {
                return $this->validationErrorResponse('Invalid request format. Expected JSON object.');
            }

            // Validate required fields
            $this->validateRequired($data, ['ids', 'data']);

            if (!is_array($data['ids']) || empty($data['ids'])) {
                return $this->validationErrorResponse('IDs array is required and cannot be empty');
            }

            if (!is_array($data['data']) || empty($data['data'])) {
                return $this->validationErrorResponse('Update data is required and cannot be empty');
            }

            $ids = $data['ids'];
            $updateData = $data['data'];

            // Validate that all IDs are scalar values
            foreach ($ids as $id) {
                if (!is_scalar($id)) {
                    return $this->validationErrorResponse('All IDs must be scalar values');
                }
            }

            // Check if table exists
            if (!$this->schemaManager->tableExists($tableName)) {
                return $this->notFoundResponse("Table '{$tableName}' does not exist");
            }

            // Get table columns to validate update data
            $tableColumns = $this->schemaManager->getTableColumns($tableName);
            $columnNames = array_column($tableColumns, 'name');

            // Filter update data to only include valid columns
            $filteredUpdateData = [];
            foreach ($updateData as $column => $value) {
                if (in_array($column, $columnNames)) {
                    $filteredUpdateData[$column] = $value;
                }
            }

            if (empty($filteredUpdateData)) {
                return $this->validationErrorResponse('No valid columns found in update data');
            }

            // Perform bulk update using transaction
            $this->queryBuilder->beginTransaction();

            try {
                // Build SET clause and WHERE IN clause using database-agnostic identifiers
                $setClauses = [];
                $values = [];
                foreach ($filteredUpdateData as $column => $value) {
                    $setClauses[] = $column . ' = ?';
                    $values[] = $value;
                }

                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $sql = $this->buildBulkUpdateQuery($tableName, $setClauses, $placeholders);

                // Combine values and ids for the query
                $allValues = array_merge($values, $ids);

                $stmt = $this->queryBuilder->executeQuery($sql, $allValues);
                $updatedCount = $stmt->rowCount();

                $this->queryBuilder->commit();

                return $this->successResponse([
                    'updated' => $updatedCount,
                    'ids' => $ids,
                    'data' => $filteredUpdateData
                ], "Successfully updated {$updatedCount} record(s)");
            } catch (\Exception $e) {
                $this->queryBuilder->rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->handleException($e, 'bulk update records');
        }
    }

    /**
     * Wrap identifier using the DatabaseDriver's wrapIdentifier method
     *
     * @param string $identifier
     * @return string
     */
    private function wrapIdentifier(string $identifier): string
    {
        // Use the actual DatabaseDriver's wrapIdentifier method
        // This ensures we use the same logic as QueryBuilder
        $connection = $this->getConnection();
        $driver = $connection->getDriver();

        return $driver->wrapIdentifier($identifier);
    }

    /**
     * Build database-agnostic bulk delete query
     *
     * @param string $tableName
     * @param string $placeholders
     * @return string
     */
    private function buildBulkDeleteQuery(string $tableName, string $placeholders): string
    {
        // Get database-agnostic identifier wrapping
        $wrappedTableName = $this->wrapIdentifier($tableName);
        $wrappedIdColumn = $this->wrapIdentifier('id');

        return "DELETE FROM {$wrappedTableName} WHERE {$wrappedIdColumn} IN ({$placeholders})";
    }

    /**
     * Build database-agnostic bulk update query
     *
     * @param string $tableName
     * @param array $setClauses
     * @param string $placeholders
     * @return string
     */
    private function buildBulkUpdateQuery(string $tableName, array $setClauses, string $placeholders): string
    {
        // Get database-agnostic identifier wrapping
        $wrappedTableName = $this->wrapIdentifier($tableName);
        $wrappedIdColumn = $this->wrapIdentifier('id');

        // Wrap column names in SET clauses
        $wrappedSetClauses = [];
        foreach ($setClauses as $clause) {
            // Extract column name from "column = ?" format
            if (preg_match('/^(\w+)\s*=\s*\?$/', $clause, $matches)) {
                $columnName = $matches[1];
                $wrappedSetClauses[] = $this->wrapIdentifier($columnName) . ' = ?';
            } else {
                $wrappedSetClauses[] = $clause; // Fallback for complex clauses
            }
        }

        return "UPDATE {$wrappedTableName} SET " . implode(', ', $wrappedSetClauses) .
           " WHERE {$wrappedIdColumn} IN ({$placeholders})";
    }

    /**
     * Build database-agnostic bulk soft delete query
     *
     * @param string $tableName
     * @param string $statusColumn
     * @param string $placeholders
     * @return string
     */
    private function buildBulkSoftDeleteQuery(string $tableName, string $statusColumn, string $placeholders): string
    {
        // Get database-agnostic identifier wrapping
        $wrappedTableName = $this->wrapIdentifier($tableName);
        $wrappedStatusColumn = $this->wrapIdentifier($statusColumn);
        $wrappedIdColumn = $this->wrapIdentifier('id');

        return "UPDATE {$wrappedTableName} SET {$wrappedStatusColumn} = ? " .
           "WHERE {$wrappedIdColumn} IN ({$placeholders})";
    }

    // Method formatBytes has been removed as it was unused

    /**
     * Preview schema changes before applying them
     *
     * Generates a preview of what changes would be made including:
     * - SQL statements to be executed
     * - Potential warnings and risks
     * - Estimated execution time
     *
     * @return Response HTTP response
     */
    public function previewSchemaChanges(): Response
    {
        try {
            // Check permission to view database schema
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'preview_schema_changes',
                'endpoint' => '/admin/db/preview-schema-changes'
            ]);

            // Get request data
            $data = RequestHelper::getPostData();

            // Validate required fields
            $this->validateRequired($data, ['table_name', 'changes']);

            $tableName = $data['table_name'];
            $changes = $data['changes'];

            // Validate table exists
            if (!$this->schemaManager->tableExists($tableName)) {
                return $this->notFoundResponse("Table '{$tableName}' does not exist");
            }

            // Get current schema for comparison
            $currentSchema = $this->schemaManager->getTableSchema($tableName);

            // Generate preview of changes
            $preview = $this->schemaManager->generateChangePreview($tableName, $changes);

            return $this->successResponse([
                'table_name' => $tableName,
                'current_schema' => $currentSchema,
                'proposed_changes' => $changes,
                'preview' => $preview,
                'generated_at' => date('Y-m-d H:i:s')
            ], 'Schema change preview generated successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'generate schema preview');
        }
    }

    /**
     * Export table schema in specified format
     *
     * @return Response HTTP response
     */
    public function exportSchema(): Response
    {
        try {
            // Check permission to view database schema
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'export_schema',
                'endpoint' => '/admin/db/export-schema'
            ]);

            // Get request data using BaseController methods
            $queryParams = $this->getQueryParams($request);
            $requestData = RequestHelper::getPostData();

            // Support both GET and POST
            $tableName = $queryParams['table'] ?? $requestData['table'] ?? null;
            $format = $queryParams['format'] ?? $requestData['format'] ?? 'json';

            if (!$tableName) {
                return $this->validationErrorResponse('Table name is required');
            }

            // Validate table exists
            if (!$this->schemaManager->tableExists($tableName)) {
                return $this->notFoundResponse("Table '{$tableName}' does not exist");
            }

            // Validate format
            $supportedFormats = ['json', 'sql', 'yaml', 'php'];
            if (!in_array($format, $supportedFormats)) {
                return $this->validationErrorResponse(
                    "Unsupported format '{$format}'. Supported formats: " . implode(', ', $supportedFormats)
                );
            }

            // Export schema
            $schema = $this->schemaManager->exportTableSchema($tableName, $format);

            // Log export action using BaseController audit logger
            $this->logAuditEvent(
                'admin',
                'schema_export',
                \Glueful\Logging\AuditEvent::SEVERITY_INFO,
                [
                    'table' => $tableName,
                    'format' => $format,
                    'exported_at' => date('Y-m-d H:i:s'),
                    'user_agent' => $this->getUserAgent($request),
                    'ip_address' => $this->getClientIp($request)
                ]
            );

            return $this->successResponse([
                'table_name' => $tableName,
                'format' => $format,
                'schema' => $schema,
                'exported_at' => date('Y-m-d H:i:s'),
                'metadata' => [
                    'export_size' => strlen(json_encode($schema)),
                    'format_version' => '1.0'
                ]
            ], "Schema exported successfully in {$format} format");
        } catch (\Exception $e) {
            return $this->handleException($e, 'export schema');
        }
    }

    /**
     * Import table schema from provided definition
     *
     * @return Response HTTP response
     */
    public function importSchema(): Response
    {
        try {
            // Check permission to manage database schema
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'import_schema',
                'endpoint' => '/admin/db/import-schema'
            ]);

            // Get request data using BaseController method
            $data = RequestHelper::getPostData();

            // Validate required fields
            $this->validateRequired($data, ['table_name', 'schema']);

            $tableName = $data['table_name'];
            $schema = $data['schema'];
            $format = $data['format'] ?? 'json';
            $options = $data['options'] ?? [];

            // Validate schema before import
            $validation = $this->schemaManager->validateSchema($schema, $format);
            if (!$validation['valid']) {
                return $this->validationErrorResponse(
                    'Invalid schema: ' . implode(', ', $validation['errors'])
                );
            }

            // Check if validation-only mode
            if ($options['validate_only'] ?? false) {
                return $this->successResponse([
                    'table_name' => $tableName,
                    'validation' => $validation,
                    'validated_at' => date('Y-m-d H:i:s')
                ], 'Schema validation completed successfully');
            }

            // Import schema
            $result = $this->schemaManager->importTableSchema($tableName, $schema, $format, $options);

            // Log import action using BaseController audit logger
            $this->logAuditEvent(
                'admin',
                'schema_import',
                \Glueful\Logging\AuditEvent::SEVERITY_WARNING, // Higher severity for imports
                [
                    'table' => $tableName,
                    'format' => $format,
                    'options' => $options,
                    'changes_applied' => $result['changes'] ?? [],
                    'imported_at' => date('Y-m-d H:i:s'),
                    'user_agent' => $this->getUserAgent($request),
                    'ip_address' => $this->getClientIp($request)
                ]
            );

            return $this->successResponse([
                'table_name' => $tableName,
                'format' => $format,
                'result' => $result,
                'imported_at' => date('Y-m-d H:i:s')
            ], 'Schema imported successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'import schema');
        }
    }

    /**
     * Get schema change history for a table
     *
     * @return Response HTTP response
     */
    public function getSchemaHistory(): Response
    {
        try {
            // Check permission to view database schema
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.view', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'get_schema_history',
                'endpoint' => '/admin/db/schema-history'
            ]);

            // Get query parameters using BaseController method
            $queryParams = $this->getQueryParams($request);

            $tableName = $queryParams['table'] ?? null;

            if (!$tableName) {
                return $this->validationErrorResponse('Table name is required');
            }

            // Parse pagination parameters using BaseController helper
            $pagination = $this->parsePaginationParams($queryParams, 50, 200);

            // Query audit logs for schema-related events
            $historyEvents = $this->getSchemaAuditLogs($tableName, $pagination['limit'], $pagination['offset']);

            // Get migration history from migrations table
            $migrationHistory = $this->getMigrationHistory($tableName);

            // Build pagination metadata using BaseController helper
            $paginationMeta = $this->buildPaginationMeta(
                $pagination['page'],
                $pagination['per_page'],
                count($historyEvents)
            );

            return $this->successResponse([
                'table_name' => $tableName,
                'schema_changes' => $historyEvents,
                'migrations' => $migrationHistory,
                'pagination' => $paginationMeta,
                'retrieved_at' => date('Y-m-d H:i:s')
            ], 'Schema history retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieve schema history');
        }
    }

    /**
     * Revert a schema change
     *
     * @return Response HTTP response
     */
    public function revertSchemaChange(): Response
    {
        try {
            // Check permission to manage database schema
            $request = Request::createFromGlobals();
            $this->checkPermission($request, 'database.manage', PermissionStandards::CATEGORY_SYSTEM, [
                'action' => 'revert_schema_change',
                'endpoint' => '/admin/db/revert-schema-change'
            ]);

            // Get request data using
             $data = RequestHelper::getPostData();

            // Validate required fields
            $this->validateRequired($data, ['change_id']);

            $changeId = $data['change_id'];
            $confirm = $data['confirm'] ?? false;

            // Get the original change from audit logs
            $originalChange = $this->getAuditLogById($changeId);

            if (!$originalChange) {
                return $this->notFoundResponse('Change not found');
            }

            // Generate revert operations
            $revertOps = $this->schemaManager->generateRevertOperations($originalChange);

            // If not confirmed, return preview
            if (!$confirm) {
                return $this->successResponse([
                    'change_id' => $changeId,
                    'original_change' => $originalChange,
                    'revert_operations' => $revertOps,
                    'preview_only' => true,
                    'message' => 'Preview of revert operations. Set confirm=true to execute.'
                ], 'Schema revert preview generated successfully');
            }

            // Execute revert operations
            $result = $this->schemaManager->executeRevert($revertOps);

            // Log revert action using BaseController audit logger
            $this->logAuditEvent(
                'admin',
                'schema_revert',
                \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                [
                    'original_change_id' => $changeId,
                    'reverted_operations' => $revertOps,
                    'revert_result' => $result,
                    'reverted_at' => date('Y-m-d H:i:s'),
                    'user_agent' => $this->getUserAgent($request),
                    'ip_address' => $this->getClientIp($request)
                ]
            );

            return $this->successResponse([
                'change_id' => $changeId,
                'result' => $result,
                'reverted_at' => date('Y-m-d H:i:s')
            ], 'Schema change reverted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'revert schema change');
        }
    }

    /**
     * Get schema audit logs for a specific table
     *
     * @param string $tableName
     * @param int $limit
     * @param int $offset
     * @return array
     */
    private function getSchemaAuditLogs(string $tableName, int $limit, int $offset): array
    {
        try {
            // Use database-agnostic approach - fetch schema-related logs and filter in PHP
            // This avoids database-specific JSON functions like JSON_EXTRACT (MySQL) or jsonb operators (PostgreSQL)
            $sql = "SELECT * FROM audit_logs 
                    WHERE category = ? 
                    AND (action LIKE ? OR action LIKE ? OR action LIKE ? OR action LIKE ?)
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";

            $params = [
            'admin',
            'schema_%',
            '%_column',
            '%_index',
            '%_foreign_key',
            $limit,
            $offset
            ];

            $stmt = $this->queryBuilder->executeQuery($sql, $params);
            $allLogs = $stmt->fetchAll();

            // Filter logs by table name using PHP JSON parsing (database-agnostic)
            $filteredLogs = [];
            foreach ($allLogs as $log) {
                if (!empty($log['context'])) {
                    $context = json_decode($log['context'], true);
                    if (
                        is_array($context) &&
                        isset($context['table']) &&
                        $context['table'] === $tableName
                    ) {
                        $filteredLogs[] = $log;
                    }
                }
            }

            return $filteredLogs;
        } catch (\Exception $e) {
            error_log("Error fetching schema audit logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get migration history for a table
     *
     * @param string $tableName
     * @return array
     */
    private function getMigrationHistory(string $tableName): array
    {
        try {
            // Database-agnostic query - LIKE operator works the same across MySQL, PostgreSQL, and SQLite
            $sql = "SELECT * FROM migrations 
                    WHERE migration LIKE ? OR migration LIKE ?
                    ORDER BY executed_at DESC";

            $patterns = [
            "%{$tableName}%",
            "%create_{$tableName}%"
            ];

            $stmt = $this->queryBuilder->executeQuery($sql, $patterns);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log("Error fetching migration history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get audit log by ID
     *
     * @param string $changeId
     * @return array|null
     */
    private function getAuditLogById(string $changeId): ?array
    {
        try {
            // Database-agnostic query - LIMIT works the same across MySQL, PostgreSQL, and SQLite
            $sql = "SELECT * FROM audit_logs WHERE id = ? LIMIT 1";
            $stmt = $this->queryBuilder->executeQuery($sql, [$changeId]);
            $result = $stmt->fetchAll();
            return $result[0] ?? null;
        } catch (\Exception $e) {
            error_log("Error fetching audit log: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Auto-detect searchable columns based on data types
     *
     * Identifies columns suitable for text search:
     * - VARCHAR, TEXT, CHAR types
     * - Excludes system columns (id, uuid, created_at, etc.)
     * - Excludes password/token columns for security
     *
     * @param array $columns Column metadata from SchemaManager
     * @return array Array of searchable column names
     */
    private function detectSearchableColumns(array $columns): array
    {
        $searchableTypes = ['varchar', 'text', 'char', 'tinytext', 'mediumtext', 'longtext'];
        $excludedColumns = [
        'id', 'uuid', 'password', 'token', 'secret', 'api_key',
        'created_at', 'updated_at', 'deleted_at'
        ];
        $searchableColumns = [];

        foreach ($columns as $column) {
            $columnName = $column['name'];
            $columnType = strtolower($column['type']);

            // Skip excluded columns
            if (in_array(strtolower($columnName), $excludedColumns)) {
                continue;
            }

            // Skip columns that might contain sensitive data
            if (preg_match('/(password|token|secret|key|hash)$/i', $columnName)) {
                continue;
            }

            // Check if column type is searchable
            foreach ($searchableTypes as $searchableType) {
                if (strpos($columnType, $searchableType) !== false) {
                    $searchableColumns[] = $columnName;
                    break;
                }
            }
        }

        return $searchableColumns;
    }

    /**
     * Validate search fields exist in table columns
     *
     * Ensures requested search fields are valid columns in the table
     * to prevent SQL errors and injection attempts.
     *
     * @param array $searchFields Requested search field names
     * @param array $columns Table column metadata
     * @return array Valid search fields that exist in the table
     */
    private function validateSearchFields(array $searchFields, array $columns): array
    {
        $columnNames = array_column($columns, 'name');
        $validFields = [];

        foreach ($searchFields as $field) {
            if (in_array($field, $columnNames)) {
                $validFields[] = $field;
            }
        }

        return $validFields;
    }

    /**
     * Validate and sanitize advanced filters
     *
     * Ensures filter fields exist in table and operators are valid.
     * Prevents SQL injection by validating column names and operators.
     *
     * @param array $filters Raw filter data from request
     * @param array $columns Table column metadata
     * @return array Validated and sanitized filters
     */
    private function validateFilters(array $filters, array $columns): array
    {
        $columnNames = array_column($columns, 'name');
        $validFilters = [];

        // Valid operators for security
        $validOperators = [
        '=', '!=', '<>', '>', '<', '>=', '<=',
        'like', 'ilike', 'in', 'between',
        'gt', 'gte', 'lt', 'lte', 'ne'
        ];

        foreach ($filters as $field => $condition) {
            // Validate field exists in table
            if (!in_array($field, $columnNames)) {
                continue;
            }

            if (is_array($condition)) {
                $validCondition = [];
                foreach ($condition as $operator => $value) {
                    // Validate operator
                    if (in_array(strtolower($operator), $validOperators)) {
                        $validCondition[$operator] = $value;
                    }
                }
                if (!empty($validCondition)) {
                    $validFilters[$field] = $validCondition;
                }
            } else {
                // Simple equality condition
                $validFilters[$field] = $condition;
            }
        }

        return $validFilters;
    }

    /**
     * Resolve foreign key fields to include human-readable display labels
     *
     * @param array $records The data records from the query
     * @param array $columns Column metadata from SchemaManager
     * @return array Records with added foreign key display labels
     */
    private function resolveForeignKeyDisplayLabels(array $records, array $columns): array
    {
        // Get foreign key columns
        $foreignKeyColumns = array_filter($columns, function ($col) {
            return !empty($col['relationships']);
        });

        if (empty($foreignKeyColumns)) {
            return $records; // No foreign keys to resolve
        }

        // Group foreign keys by referenced table for efficient querying
        $foreignKeyGroups = [];
        foreach ($foreignKeyColumns as $column) {
            $relationship = $column['relationships'][0]; // Use first relationship
            $referencedTable = $relationship['references_table'];

            if (!isset($foreignKeyGroups[$referencedTable])) {
                $foreignKeyGroups[$referencedTable] = [
                'columns' => [],
                'values' => []
                ];
            }

            $foreignKeyGroups[$referencedTable]['columns'][] = [
            'local_column' => $column['name'],
            'referenced_column' => $relationship['references_column']
            ];
        }

        // Collect all foreign key values
        foreach ($records as $record) {
            foreach ($foreignKeyGroups as $table => &$group) {
                foreach ($group['columns'] as $columnMap) {
                    $value = $record[$columnMap['local_column']];
                    if ($value !== null && !in_array($value, $group['values'])) {
                        $group['values'][] = $value;
                    }
                }
            }
        }

        // Fetch display labels for each referenced table
        $displayLabels = [];
        foreach ($foreignKeyGroups as $referencedTable => $group) {
            if (count($group['values']) === 0) {
                continue;
            }

            try {
                // Get referenced table columns for smart labeling
                $referencedColumns = $this->schemaManager->getTableColumns($referencedTable);

                // Fetch records from referenced table
                $placeholders = implode(',', array_fill(0, count($group['values']), '?'));
                $referencedColumn = $group['columns'][0]['referenced_column'];

                $sql = "SELECT * FROM `{$referencedTable}` WHERE `{$referencedColumn}` IN ({$placeholders})";
                $stmt = $this->queryBuilder->executeQuery($sql, $group['values']);
                $referencedRecords = $stmt->fetchAll();

                // Generate smart labels for each record
                foreach ($referencedRecords as $referencedRecord) {
                    $label = $this->generateSmartLabel($referencedRecord, $referencedColumns);
                    $displayLabels[$referencedTable][$referencedRecord[$referencedColumn]] = $label;
                }
            } catch (\Exception $e) {
                error_log("Error resolving foreign key labels for {$referencedTable}: " . $e->getMessage());
            }
        }

        // Add display labels to records
        foreach ($records as &$record) {
            foreach ($foreignKeyColumns as $column) {
                $relationship = $column['relationships'][0];
                $referencedTable = $relationship['references_table'];
                $referencedColumn = $relationship['references_column'];
                $localColumn = $column['name'];

                $value = $record[$localColumn];
                if ($value !== null && isset($displayLabels[$referencedTable][$value])) {
                    $record[$localColumn . '_display'] = $displayLabels[$referencedTable][$value];
                }
            }
        }

        return $records;
    }

    /**
     * Generate smart display label using the same logic as frontend
     *
     * @param array $record The database record
     * @param array $columns Column metadata for the table
     * @return string Human-readable display label
     */
    private function generateSmartLabel(array $record, array $columns): string
    {
        $displayableColumns = $this->getDisplayableColumnsForLabels($columns);
        $bestFields = $this->selectBestDisplayFields($displayableColumns, $record);

        if (count($bestFields) === 1) {
            return (string)$bestFields[0]['value'];
        } elseif (count($bestFields) >= 2) {
            return implode(' - ', array_map(function ($f) {
                return $f['value'];
            }, $bestFields));
        }

        // Fallback: First two non-system fields
        $nonSystemValues = [];
        foreach ($record as $key => $value) {
            if (
                !in_array($key, ['id', 'created_at', 'updated_at', 'deleted_at']) &&
                !str_contains($key, 'password') &&
                $value !== null && $value !== ''
            ) {
                $nonSystemValues[] = $value;
                if (count($nonSystemValues) >= 2) {
                    break;
                }
            }
        }

        return implode(' - ', $nonSystemValues) ?: 'Record #' . ($record['id'] ?? 'Unknown');
    }

    /**
     * Filter columns suitable for display (same logic as frontend)
     *
     * @param array $columns Column metadata
     * @return array Filtered columns suitable for display
     */
    private function getDisplayableColumnsForLabels(array $columns): array
    {
        return array_filter($columns, function ($col) {
            // Exclude system/technical fields
            if (
                str_contains($col['name'], '_at') ||
                str_contains($col['name'], 'password') ||
                str_contains($col['name'], 'token') ||
                str_contains($col['name'], 'user_agent') ||
                str_contains($col['name'], 'ip_address') ||
                $col['extra'] === 'auto_increment'
            ) {
                return false;
            }

            // Prefer text fields for display
            if (str_contains($col['type'], 'varchar') || str_contains($col['type'], 'text')) {
                return true;
            }

            // Include unique identifiers
            if ($col['is_unique'] && !$col['is_primary']) {
                return true;
            }

            return false;
        });
    }

    /**
     * Score and select best fields for display (same logic as frontend)
     *
     * @param array $columns Filtered displayable columns
     * @param array $record The database record
     * @return array Top scored fields for display
     */
    private function selectBestDisplayFields(array $columns, array $record): array
    {
        $scoredFields = [];

        foreach ($columns as $col) {
            $score = 0;

            // High scores for name-like fields
            if (preg_match('/^(name|title|label|display_name)$/i', $col['name'])) {
                $score += 100;
            }
            if (preg_match('/name$/i', $col['name'])) {
                $score += 80;
            }

            // Medium scores for unique identifiers
            if ($col['is_unique']) {
                $score += 60;
            }
            if (preg_match('/^(username|email|code|slug)$/i', $col['name'])) {
                $score += 50;
            }

            // Bonus for varchar fields
            if (str_contains($col['type'], 'varchar')) {
                $score += 20;
            }

            // Penalties
            if (str_contains($col['type'], '512') || str_contains($col['type'], '1000')) {
                $score -= 10;
            }
            if (preg_match('/^(uuid|guid|hash)$/i', $col['name'])) {
                $score -= 30;
            }

            // Check if field has meaningful data
            $value = $record[$col['name']] ?? null;
            if (empty($value) || (is_string($value) && trim($value) === '')) {
                $score = 0;
            }

            if ($score > 0) {
                $scoredFields[] = ['column' => $col, 'score' => $score, 'value' => $value];
            }
        }

        // Sort by score and return top 2
        usort($scoredFields, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        return array_slice($scoredFields, 0, 2);
    }
}
