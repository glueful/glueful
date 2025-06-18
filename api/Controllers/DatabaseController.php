<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\{QueryBuilder};
use Glueful\Logging\AuditEvent;
use Glueful\Http\SecureErrorResponse;

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
    private QueryBuilder $queryBuilder;

    /**
     * Initialize Database Controller
     */
    public function __construct(
        ?\Glueful\Repository\RepositoryFactory $repositoryFactory = null,
        ?\Glueful\Auth\AuthenticationManager $authManager = null,
        ?\Glueful\Logging\AuditLogger $auditLogger = null,
        ?\Symfony\Component\HttpFoundation\Request $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);

        $connection = $this->getConnection();
        $this->schemaManager = $connection->getSchemaManager();
        $this->queryBuilder = $this->getQueryBuilder();
    }

    /**
     * Create new database table
     *
     * @return mixed HTTP response
     */
    public function createTable(): mixed
    {
        // Permission check - table creation is a critical operation
        $this->requirePermission('database.tables.create', 'database');

        // Rate limiting for table creation
        $this->rateLimitResource('database.tables', 'write', 5, 300);

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

            // Audit log table creation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'table_created',
                AuditEvent::SEVERITY_WARNING,
                [
                    'table_name' => $tableName,
                    'columns' => $columnsData,
                    'indexes' => $data['indexes'] ?? [],
                    'foreign_keys' => $data['foreign_keys'] ?? [],
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            return Response::ok([
                'table' => $tableName,
                'columns' => $columnsData,
                'indexes' => $data['indexes'] ?? [],
                'foreign_keys' => $data['foreign_keys'] ?? []
            ], 'Table created successfully')->send();
    }

    /**
     * Drop database table
     */
    public function dropTable(): mixed
    {
        // Permission check - table deletion is critical
        $this->requirePermission('database.tables.drop', 'database');

        // Very strict rate limiting for table deletion
        $this->rateLimitResource('database.tables', 'delete', 2, 600);

        // Require low risk behavior for destructive operations
        $this->requireLowRiskBehavior(0.3, 'table_deletion');

        $data = Request::getPostData();

        if (!isset($data['table_name'])) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $result = $this->schemaManager->dropTable($data['table_name']);
        if (!$result) {
            return Response::error('Failed to drop table', Response::HTTP_BAD_REQUEST)->send();
        }

        // Audit log table deletion
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'table_dropped',
            AuditEvent::SEVERITY_ERROR, // High severity for destructive operation
            [
                'table_name' => $data['table_name'],
                'user_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        return Response::ok(null, 'Table dropped successfully')->send();
    }

    /**
     * Get list of all tables
     */
    public function getTables(?bool $includeSchema = false): mixed
    {
        // Permission check for viewing database structure
        $this->requirePermission('database.structure.read', 'database');

        // Standard rate limiting for read operations
        $this->rateLimitResource('database.structure', 'read');

        $data = $this->cacheResponse(
            'tables_list',
            fn() => $this->schemaManager->getTables(),
            3600, // 1 hour cache
            ['database', 'tables']
        );

        return Response::ok($data, 'Tables retrieved successfully')->send();
    }

    /**
     * Get table size information
     */
    public function getTableSize(?array $table): mixed
    {
        // Permission check for reading table information
        $this->requirePermission('database.structure.read', 'database');

        // Rate limiting for table operations
        $this->rateLimitResource('database.tables', 'read');

        if (!isset($table['name'])) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $data = $this->cacheResponse(
            'table_size_' . $table['name'],
            function () use ($table) {
                $size = $this->schemaManager->getTableSize($table['name']);
                return [
                    'table' => $table['name'],
                    'size' => $size
                ];
            },
            1800, // 30 minutes cache
            ['database', 'table_' . $table['name']]
        );

        return Response::ok($data, 'Table size retrieved successfully')->send();
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
     * @return mixed HTTP response
     */
    public function getTableMetadata(?array $table): mixed
    {
        // Permission check for reading table metadata
        $this->requirePermission('database.structure.read', 'database');

        // Rate limiting for metadata operations
        $this->rateLimitResource('database.metadata', 'read');

        if (!isset($table['name'])) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
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

            $data = $this->cacheResponse(
                'table_metadata_' . $tableName,
                function () use ($metadata) {
                    return $metadata;
                },
                1800, // 30 minutes cache
                ['database', 'table_' . $tableName, 'metadata']
            );
            return Response::ok($data, 'Table metadata retrieved successfully')->send();
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
     * @return mixed HTTP response
     */
    public function getTableData(?array $table): mixed
    {
        // Permission check for reading table data
        $datatest = $this->currentUser;
        error_log('Checking permissions for database.data.read' . json_encode($datatest));
        $this->requirePermission('database.data.read', 'database');

        // Rate limiting for data access
        $this->rateLimitResource('database.data', 'read');

        if (!isset($table['name'])) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }
            $request = new Request();

            // Get request data
            $queryParams = $request->getQueryParams();
            // Set default values for pagination and filtering
            $page = (int)($queryParams['page'] ?? 1);
            $perPage = (int)($queryParams['per_page'] ?? 25);

            // Search parameters
            $searchTerm = $queryParams['search'] ?? null;
            $searchFields = $queryParams['search_fields'] ?? [];

            // Advanced filters
            $filters = isset($queryParams['filters']) ? json_decode($queryParams['filters'], true) : [];

            // Sorting parameters
            $orderBy = $queryParams['order_by'] ?? 'id';
            $orderDir = strtoupper($queryParams['order_dir'] ?? 'DESC');

            // Get table columns for auto-detection of searchable fields
            $columns = $this->schemaManager->getTableColumns($table['name']);

            // Auto-detect searchable columns if not specified
        if ($searchTerm && empty($searchFields)) {
            $searchFields = $this->detectSearchableColumns($columns);
        }

            // Build the query using QueryBuilder
            $query = $this->queryBuilder->select($table['name'], ['*']);

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
            $query->orderBy([$orderBy => $orderDir]);

            // Execute query with pagination
            $results = $query->paginate($page, $perPage);

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
        return Response::ok($results, 'Data retrieved successfully')->send();
    }

    /**
     * Get columns information for a specific table
     *
     * @param array $params Route parameters containing table name
     * @return mixed HTTP response with column metadata
     */
    public function getColumns(array $params): mixed
    {
        // Permission check for reading column information
        $this->requirePermission('database.structure.read', 'database');

        // Rate limiting for column information
        $this->rateLimitResource('database.columns', 'read');

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

            $data =  $this->cacheResponse(
                'table_columns_' . $tableName,
                function () use ($tableName, $columns) {
                    return [
                        'table' => $tableName,
                        'columns' => $columns
                    ];
                },
                3600, // 1 hour cache
                ['database', 'table_' . $tableName, 'columns']
            );
            return Response::ok($data, 'Table columns retrieved successfully')->send();
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
        // Permission check for adding columns
        $this->requirePermission('database.columns.add', 'database');

        // Rate limiting for column operations
        $this->rateLimitResource('database.columns', 'write', 10, 300);

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
        // Permission check for dropping columns
        $this->requirePermission('database.columns.drop', 'database');

        // Strict rate limiting for column deletion
        $this->rateLimitResource('database.columns', 'delete', 5, 600);

        // Require low risk behavior for destructive operations
        $this->requireLowRiskBehavior(0.4, 'column_deletion');

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
            // Use the SchemaManager's dropColumn method
            $result = $this->schemaManager->dropColumn($tableName, $columnName);

            if ($result['success'] ?? false) {
                $results[] = $columnName;
            } else {
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
        // Permission check for adding indexes
        $this->requirePermission('database.indexes.create', 'database');

        // Rate limiting for index operations
        $this->rateLimitResource('database.indexes', 'write', 10, 300);

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
                $indexType = strtolower($index['type']) === 'unique' ? 'unq' : 'idx';
                $formattedIndex['name'] = "{$tableName}_{$columnStr}_{$indexType}";
            } else {
                $formattedIndex['name'] = $index['name'];
            }

            $formattedIndexes[] = $formattedIndex;

            // Add the index
            $this->schemaManager->addIndex([$formattedIndex]);
            // If we get here, no exception was thrown, so it succeeded
            $results[] = $formattedIndex['name'];
        }

        // Check if all indexes were added successfully
        if (empty($failed)) {
            // Audit log index creation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'indexes_added',
                AuditEvent::SEVERITY_INFO,
                [
                    'table_name' => $tableName,
                    'indexes_added' => $results,
                    'total_indexes' => count($results),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

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
            // Audit log partial index creation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'indexes_added_partial',
                AuditEvent::SEVERITY_WARNING,
                [
                    'table_name' => $tableName,
                    'indexes_added' => $results,
                    'indexes_failed' => $failed,
                    'success_count' => count($results),
                    'failed_count' => count($failed),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            // Some indexes were added, but some failed
            return Response::ok([
                'table' => $tableName,
                'indexes_added' => $results,
                'indexes_failed' => $failed
            ], 'Some indexes were added successfully, but others failed')->send();
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
        // Permission check for dropping indexes
        $this->requirePermission('database.indexes.drop', 'database');

        // Strict rate limiting for index deletion
        $this->rateLimitResource('database.indexes', 'delete', 5, 600);

        // Require low risk behavior for destructive operations
        $this->requireLowRiskBehavior(0.4, 'index_deletion');

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
            // Use the SchemaManager's dropIndex method
            $success = $this->schemaManager->dropIndex($tableName, $indexName);

            if ($success) {
                $results[] = $indexName;
            } else {
                $failed[] = $indexName;
            }
        }

        // Check if all indexes were dropped successfully
        if (empty($failed)) {
            // Audit log index deletion
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'indexes_dropped',
                AuditEvent::SEVERITY_WARNING, // Higher severity for destructive operation
                [
                    'table_name' => $tableName,
                    'indexes_dropped' => $results,
                    'total_indexes' => count($results),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

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
            // Audit log partial index deletion
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'indexes_dropped_partial',
                AuditEvent::SEVERITY_ERROR, // High severity for partial destructive operation
                [
                    'table_name' => $tableName,
                    'indexes_dropped' => $results,
                    'indexes_failed' => $failed,
                    'success_count' => count($results),
                    'failed_count' => count($failed),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            // Some indexes were dropped, but some failed
            return Response::ok([
                'table' => $tableName,
                'indexes_dropped' => $results,
                'indexes_failed' => $failed
            ], 'Some indexes were dropped successfully, but others failed')->send();
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
        // Permission check for adding foreign keys
        $this->requirePermission('database.foreign_keys.create', 'database');

        // Rate limiting for foreign key operations
        $this->rateLimitResource('database.foreign_keys', 'write', 8, 600);

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
        }

        // Check if all foreign keys were added successfully
        if (empty($failed)) {
            // Audit log foreign key creation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'foreign_keys_added',
                AuditEvent::SEVERITY_INFO,
                [
                    'table_name' => $tableName,
                    'constraints_added' => $results,
                    'total_constraints' => count($results),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

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
            // Audit log partial foreign key creation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'foreign_keys_added_partial',
                AuditEvent::SEVERITY_WARNING,
                [
                    'table_name' => $tableName,
                    'constraints_added' => $results,
                    'constraints_failed' => $failed,
                    'success_count' => count($results),
                    'failed_count' => count($failed),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            // Some foreign keys were added, but some failed
            return Response::ok([
                'table' => $tableName,
                'constraints_added' => $results,
                'constraints_failed' => $failed
            ], 'Some foreign key constraints were added successfully, but others failed')->send();
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
        // Permission check for dropping foreign keys
        $this->requirePermission('database.foreign_keys.drop', 'database');

        // Strict rate limiting for foreign key deletion
        $this->rateLimitResource('database.foreign_keys', 'delete', 5, 900);

        // Require low risk behavior for destructive operations
        $this->requireLowRiskBehavior(0.4, 'foreign_key_deletion');

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
            // Use the SchemaManager's dropForeignKey method
            $success = $this->schemaManager->dropForeignKey($tableName, $constraintName);

            if ($success) {
                $results[] = $constraintName;
            } else {
                $failed[] = $constraintName;
            }
        }

        // Check if all constraints were dropped successfully
        if (empty($failed)) {
            // Audit log foreign key deletion
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'foreign_keys_dropped',
                AuditEvent::SEVERITY_WARNING, // Higher severity for destructive operation
                [
                    'table_name' => $tableName,
                    'constraints_dropped' => $results,
                    'total_constraints' => count($results),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

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
            // Audit log partial foreign key deletion
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_ADMIN,
                'foreign_keys_dropped_partial',
                AuditEvent::SEVERITY_ERROR, // High severity for partial destructive operation
                [
                    'table_name' => $tableName,
                    'constraints_dropped' => $results,
                    'constraints_failed' => $failed,
                    'success_count' => count($results),
                    'failed_count' => count($failed),
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp()
                ]
            );

            // Some constraints were dropped, but some failed
            return Response::ok([
                'table' => $tableName,
                'constraints_dropped' => $results,
                'constraints_failed' => $failed
            ], 'Some foreign key constraints were dropped successfully, but others failed')->send();
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
        // Critical permission check - raw SQL execution
        $this->requirePermission('database.query.execute', 'database');

        // Very strict rate limiting for raw SQL execution
        $this->rateLimitResource('database.query', 'execute', 3, 300);

        // Require very low risk behavior for SQL execution
        $this->requireLowRiskBehavior(0.2, 'raw_sql_execution');

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

        // Execute the query and get results
        $results = $this->queryBuilder->rawQuery($sql, $params);

        // For write operations, get the affected rows count
        $isReadOperation = in_array($firstWord, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN']);
        $message = $isReadOperation
            ? 'Query executed successfully'
            : 'Query executed successfully, ' . count($results) . ' rows affected';

        // Audit log raw SQL execution
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_ADMIN,
            'raw_sql_executed',
            $isReadOperation ? AuditEvent::SEVERITY_INFO : AuditEvent::SEVERITY_WARNING,
            [
                'sql' => $sql,
                'operation_type' => $firstWord,
                'is_read_operation' => $isReadOperation,
                'affected_rows' => count($results),
                'user_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp()
            ]
        );

        $responseData = [
            'query' => $sql,
            'results' => $results,
            'count' => count($results)
        ];

        return Response::ok($responseData, $message)->send();
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
        // Permission check for schema modification
        $this->requirePermission('database.structure.write', 'database');

        // Very strict rate limiting for schema updates
        $this->rateLimitResource('database.schema', 'write', 3, 600);

        // Require low risk behavior for complex schema operations
        $this->requireLowRiskBehavior(0.3, 'schema_update');

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
                    $success = $this->schemaManager->dropColumn($tableName, $column);
                    if ($success) {
                        $results['deleted_columns'][] = $column;
                    } else {
                        $results['failed_operations'][] = "Failed to delete column: $column";
                    }
                }
            }

            // Process index deletions
            if (!empty($data['deleted_indexes'])) {
                foreach ($data['deleted_indexes'] as $index) {
                    $success = $this->schemaManager->dropIndex($tableName, $index);
                    if ($success) {
                        $results['deleted_indexes'][] = $index;
                    } else {
                        $results['failed_operations'][] = "Failed to delete index: $index";
                    }
                }
            }

            // Process foreign key deletions
            if (!empty($data['deleted_foreign_keys'])) {
                foreach ($data['deleted_foreign_keys'] as $constraintName) {
                    $success = $this->schemaManager->dropForeignKey($tableName, $constraintName);
                    if ($success) {
                        $results['deleted_foreign_keys'][] = $constraintName;
                    } else {
                        $results['failed_operations'][] = "Failed to delete foreign key: $constraintName";
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
                    $results['failed_operations'][] = "Failed to add indexes - database constraint error";
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
                        $results['failed_operations'][] = "Failed to add foreign key on column: $columnName";
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
    }

    /**
     * Get comprehensive database statistics
     *
     * Returns database statistics including tables with their schemas, sizes, row counts,
     * and other metrics useful for database monitoring and visualization.
     *
     * @return mixed HTTP response
     */
    /**
     * Get database statistics data with caching (for internal use)
     *
     * @return array Database statistics data
     */
    public function getDatabaseStatsData(): array
    {
        return $this->cacheResponse(
            'database_stats',
            function () {
                // Get list of all tables with schema information
                $tables = $this->schemaManager->getTables();
                error_log("Retrieved " . count($tables) . " tables from the database");

                if (empty($tables)) {
                    return ['tables' => [], 'total_tables' => 0];
                }

                $tableData = [];

                // Get size information for each table
                foreach ($tables as $table) {
                    // Check if the result includes schema information already
                    $tableName = is_array($table) ? $table['name'] : $table;
                    // Default to 'public' schema if not specified
                    $schema = is_array($table) && isset($table['schema']) ? $table['schema'] : 'public';

                    $size = $this->schemaManager->getTableSize($tableName);
                    $rowCount = $this->schemaManager->getTableRowCount($tableName);

                    $tableData[] = [
                        'table_name' => $tableName,
                        'schema' => $schema,
                        'size' => $size,
                        'rows' => $rowCount,
                        'avg_row_size' => $rowCount > 0 ? round($size / $rowCount) : 0
                    ];
                }

                // Sort tables by size in descending order
                usort($tableData, function ($a, $b) {
                    // Handle zero values in comparison (put zero-size tables at the end)
                    if ($a['size'] === 0) {
                        return 1;
                    }
                    if ($b['size'] === 0) {
                        return -1;
                    }
                    return $b['size'] <=> $a['size'];
                });

                return [
                    'tables' => $tableData,
                    'total_tables' => count($tables)
                ];
            },
            900, // 15 minutes cache
            ['database', 'statistics']
        );
    }

    public function getDatabaseStats(): mixed
    {
        // Permission check for database statistics
        $this->requirePermission('database.structure.read', 'database');

        // Rate limiting for database statistics
        $this->rateLimitResource('database.stats', 'read');

        // Use the data method that includes caching
        $data = $this->getDatabaseStatsData();

        return Response::ok($data, 'Database statistics retrieved successfully')->send();
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
     * @return mixed HTTP response
     */
    public function importTableData($params): mixed
    {
        // Permission check for data import
        $this->requirePermission('database.data.import', 'database');

        // Strict rate limiting for import operations
        $this->rateLimitResource('database.data', 'import', 3, 900);

        // Require low risk behavior for bulk operations
        $this->requireLowRiskBehavior(0.5, 'data_import');

        if (!is_array($params)) {
            return Response::error('Invalid parameters', Response::HTTP_BAD_REQUEST)->send();
        }
        $tableName = $params['name'] ?? null;
        if (!$tableName) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Get request data
        $data = Request::getPostData();

        if (!is_array($data)) {
            return Response::error(
                'Invalid request format. Expected JSON object.',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        if (!isset($data['data']) || !is_array($data['data'])) {
            return Response::error(
                'Import data is required and must be an array',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        $importData = $data['data'];
        $options = $data['options'] ?? [];

        // Import options with defaults
        $skipFirstRow = $options['skipFirstRow'] ?? false;

        // Check if table exists
        if (!$this->schemaManager->tableExists($tableName)) {
            return Response::error("Table '{$tableName}' does not exist", Response::HTTP_NOT_FOUND)->send();
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
        $batchSize = 100; // Optimal batch size for most databases

        $imported = 0;
        $failed = 0;

        // Process data in batches with transactions
        $batches = array_chunk($importData, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            // Start transaction for this batch
            $this->queryBuilder->beginTransaction();

            $batchImported = 0;

            foreach ($batch as $globalIndex => $row) {
                $filteredRow = $this->validateAndFilterRow($row, $columnNames);
                if ($filteredRow === null) {
                    $failed++;
                    continue;
                }

                // Handle update existing records
                if ($updateExisting && isset($filteredRow['id'])) {
                    $this->upsertRecord($tableName, $filteredRow);
                } else {
                    $this->queryBuilder->insert($tableName, $filteredRow);
                }

                $batchImported++;
            }

            // Commit transaction for this batch
            $this->queryBuilder->commit();
            $imported += $batchImported;
        }

        $result = [
        'imported' => $imported,
        'failed' => $failed,
        'batches_processed' => $totalBatches
        ];

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
        $batchSize = 250; // Larger batch size for large imports

        // Increase PHP execution time for large imports
        set_time_limit(300); // 5 minutes

        // Use memory-efficient processing
        ini_set('memory_limit', '512M');

        $imported = 0;
        $failed = 0;

        $batches = array_chunk($importData, $batchSize);
        $totalBatches = count($batches);
        $totalRecords = count($importData);

        foreach ($batches as $batch) {
            $this->queryBuilder->beginTransaction();

            // Build bulk insert for better performance
            $validRows = [];
            foreach ($batch as $row) {
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
        }

        $result = [
        'imported' => $imported,
        'failed' => $failed,
        'total_records' => $totalRecords,
        'batches_processed' => $totalBatches,
        'processing_method' => 'large_import_optimized'
        ];

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
     * @return mixed HTTP response
     */
    public function bulkDelete(array $params): mixed
    {
        // Permission check for bulk deletion
        $this->requirePermission('database.data.delete', 'database');

        // Very strict rate limiting for bulk deletion
        $this->rateLimitResource('database.data', 'delete', 2, 1200);

        // Require very low risk behavior for bulk deletion
        $this->requireLowRiskBehavior(0.2, 'bulk_deletion');

        if (!is_array($params)) {
            return Response::error('Invalid parameters', Response::HTTP_BAD_REQUEST)->send();
        }

        $tableName = $params['name'] ?? null;
        if (!$tableName) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Get request data
        $data = Request::getPostData();

        if (!is_array($data)) {
            return Response::error(
                'Invalid request format. Expected JSON object.',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            return Response::error(
                'IDs array is required and cannot be empty',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        $ids = $data['ids'];

        // Soft delete parameters
        $softDelete = $data['soft_delete'] ?? false;
        $statusColumn = $data['status_column'] ?? 'status';
        $deletedValue = $data['deleted_value'] ?? 'deleted';

        // Validate that all IDs are scalar values
        foreach ($ids as $id) {
            if (!is_scalar($id)) {
                return Response::error(
                    'All IDs must be scalar values',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }
        }

        // Check if table exists
        if (!$this->schemaManager->tableExists($tableName)) {
            return Response::error("Table '{$tableName}' does not exist", Response::HTTP_NOT_FOUND)->send();
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

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        if ($softDelete) {
            // Perform soft delete by updating status column
            $sql = $this->buildBulkSoftDeleteQuery($tableName, $statusColumn, $placeholders);
            $values = array_merge([$deletedValue], $ids);
            $stmt = $this->queryBuilder->executeQuery($sql, $values);
            $affectedCount = $stmt->rowCount();

            $this->queryBuilder->commit();

            return Response::ok([
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

            return Response::ok([
            'deleted' => $deletedCount,
            'ids' => $ids
            ], "Successfully deleted {$deletedCount} record(s)")->send();
        }
    }

    /**
     * Bulk update records in a table
     *
     * @param array $params Route parameters containing table name
     * @return mixed HTTP response
     */
    public function bulkUpdate(array $params): mixed
    {
        // Permission check for bulk updates
        $this->requirePermission('database.data.write', 'database');

        // Strict rate limiting for bulk updates
        $this->rateLimitResource('database.data', 'write', 5, 600);

        // Require low risk behavior for bulk operations
        $this->requireLowRiskBehavior(0.4, 'bulk_update');

        if (!is_array($params)) {
            return Response::error('Invalid parameters', Response::HTTP_BAD_REQUEST)->send();
        }

        $tableName = $params['name'] ?? null;
        if (!$tableName) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Get request data
        $data = Request::getPostData();

        if (!is_array($data)) {
            return Response::error(
                'Invalid request format. Expected JSON object.',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            return Response::error(
                'IDs array is required and cannot be empty',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        if (!isset($data['data']) || !is_array($data['data']) || empty($data['data'])) {
            return Response::error(
                'Update data is required and cannot be empty',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        $ids = $data['ids'];
        $updateData = $data['data'];

        // Validate that all IDs are scalar values
        foreach ($ids as $id) {
            if (!is_scalar($id)) {
                return Response::error(
                    'All IDs must be scalar values',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }
        }

        // Check if table exists
        if (!$this->schemaManager->tableExists($tableName)) {
            return Response::error("Table '{$tableName}' does not exist", Response::HTTP_NOT_FOUND)->send();
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
            return Response::error(
                'No valid columns found in update data',
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        // Perform bulk update using transaction
        $this->queryBuilder->beginTransaction();

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

        return Response::ok([
        'updated' => $updatedCount,
        'ids' => $ids,
        'data' => $filteredUpdateData
        ], "Successfully updated {$updatedCount} record(s)")->send();
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
     * @return mixed HTTP response
     */
    public function previewSchemaChanges(): mixed
    {
        // Permission check for schema preview
        $this->requirePermission('database.structure.read', 'database');

        // Rate limiting for schema preview operations
        $this->rateLimitResource('database.schema', 'read', 15, 300);

        $data = Request::getPostData();

        if (!isset($data['table_name'])) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        if (!isset($data['changes'])) {
            return Response::error('Changes array is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $tableName = $data['table_name'];
        $changes = $data['changes'];

        // Validate table exists
        if (!$this->schemaManager->tableExists($tableName)) {
            return Response::error("Table '{$tableName}' does not exist", Response::HTTP_NOT_FOUND)->send();
        }

        // Get current schema for comparison
        $currentSchema = $this->schemaManager->getTableSchema($tableName);

        // Generate preview of changes
        $preview = $this->schemaManager->generateChangePreview($tableName, $changes);

        return Response::ok([
        'table_name' => $tableName,
        'current_schema' => $currentSchema,
        'proposed_changes' => $changes,
        'preview' => $preview,
        'generated_at' => date('Y-m-d H:i:s')
        ])->send();
    }

    /**
     * Export table schema in specified format
     *
     * @return mixed HTTP response
     */
    public function exportSchema(): mixed
    {
        // Permission check for schema export
        $this->requirePermission('database.schema.export', 'database');

        // Rate limiting for export operations
        $this->rateLimitResource('database.schema', 'export', 5, 300);

        $params = $_GET;
        $data = Request::getPostData();

        // Support both GET and POST
        $tableName = $params['table'] ?? $data['table'] ?? null;
        $format = $params['format'] ?? $data['format'] ?? 'json';

        if (!$tableName) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Validate table exists
        if (!$this->schemaManager->tableExists($tableName)) {
            return Response::error("Table '{$tableName}' does not exist", Response::HTTP_NOT_FOUND)->send();
        }

        // Validate format
        $supportedFormats = ['json', 'sql', 'yaml', 'php'];
        if (!in_array($format, $supportedFormats)) {
            return Response::error(
                "Unsupported format '{$format}'. Supported formats: " . implode(', ', $supportedFormats),
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        // Export schema
        $schema = $this->schemaManager->exportTableSchema($tableName, $format);

        // Log export action using existing audit system
        $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
        $auditLogger->audit(
            \Glueful\Logging\AuditEvent::CATEGORY_ADMIN,
            'schema_export',
            \Glueful\Logging\AuditEvent::SEVERITY_INFO,
            [
                'table' => $tableName,
                'format' => $format,
                'exported_at' => date('Y-m-d H:i:s'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]
        );

        return Response::ok([
            'table_name' => $tableName,
            'format' => $format,
            'schema' => $schema,
            'exported_at' => date('Y-m-d H:i:s'),
            'metadata' => [
            'export_size' => strlen(json_encode($schema)),
            'format_version' => '1.0'
            ]
        ])->send();
    }

    /**
     * Import table schema from provided definition
     *
     * @return mixed HTTP response
     */
    public function importSchema(): mixed
    {
        // Permission check for schema import
        $this->requirePermission('database.schema.import', 'database');

        // Very strict rate limiting for schema import
        $this->rateLimitResource('database.schema', 'import', 2, 600);

        // Require low risk behavior for schema changes
        $this->requireLowRiskBehavior(0.3, 'schema_import');

        $data = Request::getPostData();

        if (!isset($data['table_name'])) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        if (!isset($data['schema'])) {
            return Response::error('Schema definition is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $tableName = $data['table_name'];
        $schema = $data['schema'];
        $format = $data['format'] ?? 'json';
        $options = $data['options'] ?? [];

        // Validate schema before import
        $validation = $this->schemaManager->validateSchema($schema, $format);
        if (!$validation['valid']) {
            return Response::error(
                'Invalid schema: ' . implode(', ', $validation['errors']),
                Response::HTTP_BAD_REQUEST
            )->send();
        }

        // Check if validation-only mode
        if ($options['validate_only'] ?? false) {
            return Response::ok([
            'table_name' => $tableName,
            'validation' => $validation,
            'validated_at' => date('Y-m-d H:i:s')
            ])->send();
        }

        // Import schema
        $result = $this->schemaManager->importTableSchema($tableName, $schema, $format, $options);

        // Log import action using existing audit system
        $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
        $auditLogger->audit(
            \Glueful\Logging\AuditEvent::CATEGORY_ADMIN,
            'schema_import',
            \Glueful\Logging\AuditEvent::SEVERITY_WARNING, // Higher severity for imports
            [
                'table' => $tableName,
                'format' => $format,
                'options' => $options,
                'changes_applied' => $result['changes'] ?? [],
                'imported_at' => date('Y-m-d H:i:s'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]
        );

        return Response::ok([
            'table_name' => $tableName,
            'format' => $format,
            'result' => $result,
            'imported_at' => date('Y-m-d H:i:s')
        ])->send();
    }

    /**
     * Get schema change history for a table
     *
     * @return mixed HTTP response
     */
    public function getSchemaHistory(): mixed
    {
        // Permission check for audit log access
        $this->requirePermission('database.audit.read', 'database');

        // Rate limiting for audit operations
        $this->rateLimitResource('database.audit', 'read', 20, 300);

        $params = $_GET;
        $tableName = $params['table'] ?? null;
        $limit = (int)($params['limit'] ?? 50);
        $offset = (int)($params['offset'] ?? 0);

        if (!$tableName) {
            return Response::error('Table name is required', Response::HTTP_BAD_REQUEST)->send();
        }

        // Query audit logs for schema-related events
        $historyEvents = $this->getSchemaAuditLogs($tableName, $limit, $offset);

        // Get migration history from migrations table
        $migrationHistory = $this->getMigrationHistory($tableName);

        return Response::ok([
        'table_name' => $tableName,
        'schema_changes' => $historyEvents,
        'migrations' => $migrationHistory,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total' => count($historyEvents)
        ],
        'retrieved_at' => date('Y-m-d H:i:s')
        ])->send();
    }

    /**
     * Revert a schema change
     *
     * @return mixed HTTP response
     */
    public function revertSchemaChange(): mixed
    {
        // Permission check for schema reversion - admin only
        $this->requirePermission('database.admin', 'database');

        // Very strict rate limiting for schema reversion
        $this->rateLimitResource('database.admin', 'revert', 1, 1800);

        // Require very low risk behavior for critical operations
        $this->requireLowRiskBehavior(0.2, 'schema_revert');

        $data = Request::getPostData();

        if (!isset($data['change_id'])) {
            return Response::error('Change ID is required', Response::HTTP_BAD_REQUEST)->send();
        }

        $changeId = $data['change_id'];
        $confirm = $data['confirm'] ?? false;

        // Get the original change from audit logs
        $originalChange = $this->getAuditLogById($changeId);

        if (!$originalChange) {
            return Response::error('Change not found', Response::HTTP_NOT_FOUND)->send();
        }

        // Generate revert operations
        $revertOps = $this->schemaManager->generateRevertOperations($originalChange);

        // If not confirmed, return preview
        if (!$confirm) {
            return Response::ok([
            'change_id' => $changeId,
            'original_change' => $originalChange,
            'revert_operations' => $revertOps,
            'preview_only' => true,
            'message' => 'Preview of revert operations. Set confirm=true to execute.'
            ])->send();
        }

        // Execute revert operations
        $result = $this->schemaManager->executeRevert($revertOps);

        // Log revert action
        $auditLogger = \Glueful\Logging\AuditLogger::getInstance();
        $auditLogger->audit(
            \Glueful\Logging\AuditEvent::CATEGORY_ADMIN,
            'schema_revert',
            \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
            [
                'original_change_id' => $changeId,
                'reverted_operations' => $revertOps,
                'revert_result' => $result,
                'reverted_at' => date('Y-m-d H:i:s'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]
        );

        return Response::ok([
            'change_id' => $changeId,
            'result' => $result,
            'reverted_at' => date('Y-m-d H:i:s')
        ])->send();
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
    }

    /**
     * Get migration history for a table
     *
     * @param string $tableName
     * @return array
     */
    private function getMigrationHistory(string $tableName): array
    {
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
    }

    /**
     * Get audit log by ID
     *
     * @param string $changeId
     * @return array|null
     */
    private function getAuditLogById(string $changeId): ?array
    {
        // Database-agnostic query - LIMIT works the same across MySQL, PostgreSQL, and SQLite
        $sql = "SELECT * FROM audit_logs WHERE id = ? LIMIT 1";
        $stmt = $this->queryBuilder->executeQuery($sql, [$changeId]);
        $result = $stmt->fetchAll();
        return $result[0] ?? null;
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
