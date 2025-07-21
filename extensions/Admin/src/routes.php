<?php

/**
 * Admin Area Routes
 *
 * This file defines routes for admin-specific functionality including:
 * - User/role management
 * - System configuration
 * - Database operations
 * - Content management
 * - Extension management
 * - Job scheduling
 *
 * All routes in this file are protected by admin authentication middleware
 * and require superuser permissions.
 */

use Glueful\Http\Router;
use Glueful\Extensions\Admin\AdminController;
use Glueful\Controllers\{
    DatabaseController,
    MigrationsController,
    JobsController,
    MetricsController,
    ConfigController,
    UsersController
};
use Glueful\Helpers\RequestHelper;
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

// Controllers will be resolved from the DI container when routes are called
// This ensures proper dependency injection and lazy loading

Router::group('/admin', function () use ($container) {

    Router::group('/db', function () use ($container) {

        Router::post('/query', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->executeQuery($request);
        });

        /**
         * @route GET /admin/db/stats
         * @tag Admin - Database Management
         * @summary Get comprehensive database statistics
         * @description Retrieves detailed statistics for all tables in the database including size, schema,
         * row counts, and other metrics
         * @requiresAuth true
         * @response 200 application/json "Database statistics retrieved successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     tables:array=[{
         *       table_name:string="Table name",
         *       schema:string="Database schema",
         *       size:integer="Size in bytes",
         *       rows:integer="Row count",
         *       avg_row_size:integer="Average row size in bytes"
         *     }],
         *     total_tables:integer="Total number of tables"
         *   },
         * }
         * @response 403 application/json "Permission denied"
         * @response 500 application/json "Server error"
         */
        Router::get('/stats', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->getDatabaseStats($request);
        });

        /**
         * @route GET /admin/db/tables
         * @tag Admin - Database Management
         * @summary Get all database tables
         * @description Retrieves a list of all tables in the database
         * @requiresAuth true
         * @response 200 application/json "List of tables" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     tables:array=[{name:string="Table name", rows:integer="Row count", created:string="Creation date"}]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/tables', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->getTables();
        });

        /**
         * @route POST /admin/db/table/create
         * @tag Admin - Table Operations
         * @summary Create new database table
         * @description Creates a new table in the database with specified columns
         * @requiresAuth true
         * @requestBody name:string="Table name"
         *              columns:array=[{
         *                name:string="Column name",
         *                type:string="Column type",
         *                nullable:boolean="Whether column can be null",
         *                default:string="Default value"
         *              }] {required=name,columns}
         * @response 201 application/json "Table created successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 409 application/json "Table already exists"
         */
        Router::post('/table/create', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->createTable($request);
        });

        /**
         * @route POST /admin/db/table/drop
         * @tag Admin - Table Operations
         * @summary Drop database table
         * @description Deletes a table from the database
         * @requiresAuth true
         * @requestBody name:string="Table name"
         *             confirm:boolean="Confirmation flag to drop the table" {required=name,confirm}
         * @response 200 application/json "Table dropped successfully"
         * @response 400 application/json "Invalid request format or missing confirmation"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::post('/table/drop', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropTable($request);
        });

        /**
         * @route GET /admin/db/table/size
         * @tag Admin - Database Management
         * @summary Get database table size
         * @description Retrieves the size of the specified table in the database
         * @requiresAuth true
         * @requestBody name:string="Table name" {required=name}
         * @response 200 application/json "Table size information" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     name:string="Table name",
         *     size:string="Size (formatted)",
         *     bytes:integer="Size in bytes",
         *     rows:integer="Row count"
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::get(
            '/table/{name}/size',
            function (array $params) use ($container) {
                $dbController = $container->get(DatabaseController::class);
                return $dbController->getTableSize($params);
            }
        );

        /**
         * @route GET /admin/db/table/{name}/metadata
         * @tag Admin - Database Management
         * @summary Get comprehensive table metadata
         * @description Retrieves detailed metadata about a database table including size, row count, columns,
         *              indexes, engine, and timestamps
         * @requiresAuth true
         * @param name path string true "Table name"
         * @response 200 application/json "Table metadata" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     name:string="Table name",
         *     rows:integer="Number of rows",
         *     size:integer="Table size in bytes",
         *     columns:integer="Number of columns",
         *     indexes:integer="Number of indexes",
         *     engine:string="Storage engine",
         *     created:string="Creation timestamp",
         *     updated:string="Last update timestamp",
         *     collation:string="Table collation",
         *     comment:string="Table comment",
         *     auto_increment:integer="Auto increment value",
         *     avg_row_length:integer="Average row length",
         *     data_length:integer="Data length",
         *     index_length:integer="Index length",
         *     data_free:integer="Free space"
         *   },
         * }
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::get(
            '/table/{name}/metadata',
            function (array $params) use ($container) {
                $dbController = $container->get(DatabaseController::class);
                return $dbController->getTableMetadata($params);
            }
        );

        /**
         * @route GET /admin/db/table/{name}
         * @tag Admin - Database Management
         * @summary Get table data
         * @description Retrieves data from the specified table with pagination
         * @requiresAuth true
         * @param name path string true "Table name"
         * @param page query integer false "Page number for pagination (default: 1)"
         * @param per_page query integer false "Number of items per page (default: 25)"
         * @param search query string false "Search term to filter table data"
         * @param sort_by query string false "Column to sort by"
         * @param sort_order query string false "Sort order (asc or desc, default: asc)"
         * @response 200 application/json "Table data retrieved successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:array=[{}],
         *   current_page:integer="Current page number",
         *   per_page:integer="Items per page",
         *   total:integer="Total number of rows in table",
         *   last_page:integer="Last page number",
         *   has_more:boolean="Whether there are more pages",
         *   from:integer="Starting record number",
         *   to:integer="Ending record number"
         * }
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::get('/table/{name}', function (array $params) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->getTableData($params);
        });

        /**
         * @route POST /admin/db/table/column/add
         * @tag Admin - Table Operations
         * @summary Add column to table
         * @description Adds a new column to an existing database table
         * @requiresAuth true
         * @requestBody table:string="Table name" column:object={name:string="Column name",
         *               type:string="Column type", nullable:boolean="Whether column can be null",
         * column can be null", default:string="Default value"} {required=table,column}
         * @response 200 application/json "Column added successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         * @response 409 application/json "Column already exists"
         */
        Router::post('/table/column/add', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->addColumn($request);
        });

        /**
         * @route POST /admin/db/table/column/drop
         * @tag Admin - Table Operations
         * @summary Drop column from table
         * @description Removes a column from an existing database table
         * @requiresAuth true
         * @requestBody table:string="Table name"
         *             column:string="Column name"
         *             confirm:boolean="Confirmation flag to drop the column"
         *             {required=table,column,confirm}
         * @response 200 application/json "Column dropped successfully"
         * @response 400 application/json "Invalid request format or missing confirmation"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table or column not found"
         */
        Router::post('/table/column/drop', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropColumn($request);
        });

        /**
         * @route POST /admin/db/table/index/drop
         * @tag Admin - Table Operations
         * @summary Drop index from table
         * @description Removes an index from an existing database table
         * @requiresAuth true
         * @requestBody table_name:string="Table name" index_name:string="Index name" {required=table_name,index_name}
         * @response 200 application/json "Index dropped successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table or index not found"
         */
        Router::post('/table/index/drop', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropIndex($request);
        });

        /**
         * @route POST /admin/db/table/foreign-key/drop
         * @tag Admin - Table Operations
         * @summary Drop foreign key from table
         * @description Removes a foreign key constraint from an existing database table
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              constraint_name:string="Constraint name"
         *              {required=table_name,constraint_name}
         * @response 200 application/json "Foreign key constraint dropped successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table or constraint not found"
         */
        Router::post('/table/foreign-key/drop', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropForeignKey($request);
        });

        /**
         * @route GET /admin/db/table/{name}/columns
         * @tag Admin - Database Management
         * @summary Get table columns
         * @description Retrieves column metadata for a specific table
         * @requiresAuth true
         * @param name path string true "Table name"
         * @response 200 application/json "Table columns" {table:string="Table name",
         * columns:array=[{name:string="Column name",
         * type:string="Column type", nullable:boolean="Whether column can be null", default:string="Default value"}]}
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::get('/table/{name}/columns', function (array $params) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->getColumns($params);
        });

        /**
         * @route POST /admin/db/table/index/add
         * @tag Admin - Table Operations
         * @summary Add index to table
         * @description Adds a new index to an existing database table
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *             index:object={column:string="Column name", type:string="Index type (INDEX or UNIQUE)"}
         *             {required=table_name,index}
         * @response 200 application/json "Index added successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         * @response 409 application/json "Index already exists"
         */
        Router::post(
            '/table/index/add',
            function (Request $request) use ($container) {
                $dbController = $container->get(DatabaseController::class);
                return $dbController->addIndex($request);
            }
        );

        /**
         * @route POST /admin/db/table/foreign-key/add
         * @tag Admin - Table Operations
         * @summary Add foreign key to table
         * @description Adds a new foreign key constraint to an existing database table
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              foreign_key:object={column:string="Column name",
         *                                 references:string="Referenced column",
         *                                 on:string="Referenced table"}
         * {required=table_name,foreign_key}
         * @response 200 application/json "Foreign key constraint added successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         * @response 409 application/json "Foreign key constraint already exists"
         */
        Router::post('/table/foreign-key/add', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->addForeignKey($request);
        });

        /**
         * @route POST /admin/db/table/column/add-batch
         * @tag Admin - Table Operations
         * @summary Add multiple columns to table
         * @description Adds multiple columns to an existing database table in a single operation
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              columns:array=[{
         *                name:string="Column name",
         *                type:string="Column type",
         *                options:object={
         *                  nullable:boolean="Whether column can be null",
         *                  default:string="Default value"
         *                }
         *              }]
         *              {required=table_name,columns}
         * @response 200 application/json "Columns added successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::post(
            '/table/column/add-batch',
            function (Request $request) use ($container) {
                $dbController = $container->get(DatabaseController::class);
                return $dbController->addColumn($request);
            }
        );

        /**
         * @route POST /admin/db/table/column/drop-batch
         * @tag Admin - Table Operations
         * @summary Drop multiple columns from table
         * @description Removes multiple columns from an existing database table in a single operation
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              column_names:array=[string="Column name"]
         *              {required=table_name,column_names}
         * @response 200 application/json "Columns dropped successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table or column not found"
         */
        Router::post('/table/column/drop-batch', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropColumn($request);
        });

        /**
         * @route POST /admin/db/table/index/drop-batch
         * @tag Admin - Table Operations
         * @summary Drop multiple indexes from table
         * @description Removes multiple indexes from an existing database table in a single operation
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              index_names:array=[string="Index name"]
         *              {required=table_name,index_names}
         * @response 200 application/json "Indexes dropped successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table or index not found"
         */
        Router::post('/table/index/drop-batch', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropIndex($request);
        });

        /**
         * @route POST /admin/db/table/foreign-key/drop-batch
         * @tag Admin - Table Operations
         * @summary Drop multiple foreign keys from table
         * @description Removes multiple foreign key constraints from an existing database table in a single operation
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              constraint_names:array=[string="Constraint name"]
         *              {required=table_name,constraint_names}
         * @response 200 application/json "Foreign key constraints dropped successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table or constraint not found"
         */
        Router::post('/table/foreign-key/drop-batch', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->dropForeignKey($request);
        });

        /**
         * @route POST /admin/db/table/index/add-batch
         * @tag Admin - Table Operations
         * @summary Add multiple indexes to table
         * @description Adds multiple indexes to an existing database table in a single operation
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              indexes:array=[{column:string="Column name", type:string="Index type (INDEX or UNIQUE)"}]
         *              {required=table_name,indexes}
         * @response 200 application/json "Indexes added successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::post('/table/index/add-batch', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->addIndex($request);
        });

        /**
         * @route POST /admin/db/table/foreign-key/add-batch
         * @tag Admin - Table Operations
         * @summary Add multiple foreign keys to table
         * @description Adds multiple foreign key constraints to an existing database table in a single operation
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              foreign_keys:array=[{
         *                column:string="Column name",
         *                references:string="Referenced column",
         *                on:string="Referenced table"
         *              }]
         *              {required=table_name,foreign_keys}
         * @response 200 application/json "Foreign key constraints added successfully"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         * @response 409 application/json "Foreign key constraint already exists"
         */
        Router::post(
            '/table/foreign-key/add-batch',
            function (Request $request) use ($container) {
                $dbController = $container->get(DatabaseController::class);
                return $dbController->addForeignKey($request);
            }
        );

        /**
         * @route POST /admin/db/table/schema/update
         * @tag Admin - Schema Management
         * @summary Update table schema
         * @description Updates table schema with multiple operations including adding/removing columns,
         *              indexes, and foreign keys
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              columns:array=[{name:string,type:string,options:object}]
         *              indexes:array=[{column:string,type:string}]
         *              foreign_keys:array=[{column:string,references:string,on:string}]
         *              deleted_columns:array=[string] deleted_indexes:array=[string]
         *              deleted_foreign_keys:array=[string] {required=table_name}
         * @response 200 application/json "Schema updated successfully"
         *              {added_columns:array,deleted_columns:array,added_indexes:array,
         *              deleted_indexes:array,
         *              added_foreign_keys:array,deleted_foreign_keys:array}
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::post('/table/schema/update', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->updateTableSchema($request);
        });

        /**
         * @route POST /admin/db/tables/{name}/import
         * @tag Admin - Data Operations
         * @summary Import data into table
         * @description Imports data from CSV into the specified table with column mapping and options
         * @requiresAuth true
         * @param name path string true "Table name"
         * @requestBody data:array=[object="Row data"]
         *              options:object={
         *                skipFirstRow:boolean="Skip first row if it contains headers",
         *                updateExisting:boolean="Update existing records by ID",
         *                skipErrors:boolean="Skip rows with errors and continue"
         *              }
         *              {required=data}
         * @response 200 application/json "Data imported successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     imported:integer="Number of records imported",
         *     failed:integer="Number of failed records",
         *     errors:array=[string="Error messages for failed records"]
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::post('/tables/{name}/import', function (array $params) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->importTableData($params);
        });

        /**
         * @route DELETE /admin/db/tables/{name}/bulk-delete
         * @tag Admin - Data Operations
         * @summary Bulk delete records from table
         * @description Deletes multiple records from the specified table by their IDs
         * @requiresAuth true
         * @param name path string true "Table name"
         * @requestBody ids:array=[string|number] "Array of record IDs to delete" {required=ids}
         * @response 200 application/json "Records deleted successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     deleted:integer="Number of records deleted",
         *     ids:array=[string|number]="Array of deleted record IDs"
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::delete('/tables/{name}/bulk-delete', function (array $params) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->bulkDelete($params);
        });

        /**
         * @route PUT /admin/db/tables/{name}/bulk-update
         * @tag Admin - Data Operations
         * @summary Bulk update records in table
         * @description Updates multiple records in the specified table by their IDs
         * @requiresAuth true
         * @param name path string true "Table name"
         * @requestBody ids:array=[string|number] "Array of record IDs to update"
         *              data:object="Data to update for each record"
         *              {required=ids,data}
         * @response 200 application/json "Records updated successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     updated:integer="Number of records updated",
         *     ids:array=[string|number]="Array of updated record IDs",
         *     updated_data:object="Data that was updated"
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::put('/tables/{name}/bulk-update', function (array $params) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->bulkUpdate($params);
        });

        /**
         * @route POST /admin/db/preview-schema-changes
         * @tag Admin - Schema Management
         * @summary Preview schema changes before applying
         * @description Generates a preview of schema changes including SQL statements, warnings, and
         *              estimated execution time
         * @requiresAuth true
         * @requestBody table_name:string="Table name"
         *              changes:array=[{
         *                type:string="Change type (add_column, drop_column, modify_column, add_index, drop_index)",
         *                column_name:string="Column name (for column operations)",
         *                column_type:string="Column type (for add_column)",
         *                index_name:string="Index name (for index operations)",
         *                columns:array="Column names (for index operations)",
         *                options:object="Additional options"
         *              }]
         *              {required=table_name,changes}
         * @response 200 application/json "Schema preview generated successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     table_name:string="Table name",
         *     current_schema:object="Current table schema",
         *     proposed_changes:array="Array of proposed changes",
         *     preview:{
         *       sql:array="SQL statements to be executed",
         *       warnings:array="Potential warnings and risks",
         *       estimated_duration:integer="Estimated execution time in seconds",
         *       safe_to_execute:boolean="Whether changes are safe to execute",
         *       generated_at:string="Preview generation timestamp"
         *     }
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::post('/preview-schema-changes', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->previewSchemaChanges($request);
        });

        /**
         * @route GET/POST /admin/db/export-schema
         * @tag Admin - Schema Management
         * @summary Export table schema in specified format
         * @description Exports a table's schema in various formats (JSON, SQL, YAML, PHP) for backup or
         *              migration purposes
         * @requiresAuth true
         * @param table query string true "Table name (for GET)"
         * @param format query string false "Export format: json, sql, yaml, php (default: json)"
         * @requestBody table:string="Table name (for POST)"
         *              format:string="Export format: json, sql, yaml, php (default: json)"
         *              {required=table}
         * @response 200 application/json "Schema exported successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     table_name:string="Table name",
         *     format:string="Export format used",
         *     schema:object="Exported schema definition",
         *     exported_at:string="Export timestamp",
         *     metadata:{
         *       export_size:integer="Size of exported data in bytes",
         *       format_version:string="Schema format version"
         *     }
         *   },
         * }
         * @response 400 application/json "Invalid request format or unsupported format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Table not found"
         */
        Router::get('/export-schema', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->exportSchema($request);
        });

        Router::post('/export-schema', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->exportSchema($request);
        });

        /**
         * @route POST /admin/db/import-schema
         * @tag Admin - Schema Management
         * @summary Import table schema from provided definition
         * @description Imports a table schema from various formats (JSON, SQL, YAML, PHP) with validation and options
         * @requiresAuth true
         * @requestBody table_name:string="Target table name"
         *              schema:object="Schema definition to import"
         *              format:string="Schema format: json, sql, yaml, php (default: json)"
         *              options:object={
         *                validate_only:boolean="Only validate schema without importing",
         *                recreate:boolean="Drop and recreate table if it exists"
         *              }
         *              {required=table_name,schema}
         * @response 200 application/json "Schema imported successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     table_name:string="Table name",
         *     format:string="Import format used",
         *     result:{
         *       import_success:boolean="true",
         *       changes:array="List of changes made",
         *       imported_at:string="Import timestamp"
         *     }
         *   },
         * }
         * @response 400 application/json "Invalid schema format or validation errors"
         * @response 403 application/json "Permission denied"
         * @response 500 application/json "Import operation failed"
         */
        Router::post('/import-schema', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->importSchema($request);
        });

        /**
         * @route GET /admin/db/schema-history
         * @tag Admin - Schema Management
         * @summary Get schema change history for a table
         * @description Retrieves the history of schema changes for a specific table from audit logs and migrations
         * @requiresAuth true
         * @param table query string true "Table name"
         * @param limit query integer false "Number of records to return (default: 50)"
         * @param offset query integer false "Number of records to skip (default: 0)"
         * @response 200 application/json "Schema history retrieved successfully" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *   data:{
         *     table_name:string="Table name",
         *     schema_changes:array=[{
         *       id:string="Change ID",
         *       action:string="Action performed",
         *       context:object="Change context and details",
         *       created_at:string="Change timestamp",
         *       user_agent:string="User agent that made the change",
         *       ip_address:string="IP address of the requester",
         *       executed_at:string="Execution timestamp"
         *     }],
         *     migrations:array=[{
         *       migration:string="Migration name",
         *       executed_at:string="Execution timestamp"
         *     }]
         *   },
         *   current_page:integer="Current page number",
         *   per_page:integer="Items per page",
         *   total:integer="Total number of records",
         *   last_page:integer="Last page number",
         *   has_more:boolean="Whether there are more pages",
         *   from:integer="Starting record number",
         *   to:integer="Ending record number",
         *   retrieved_at:string="History retrieval timestamp"
         * }
         * @response 400 application/json "Invalid request parameters"
         * @response 403 application/json "Permission denied"
         * @response 500 application/json "Failed to retrieve history"
         */
        Router::get('/schema-history', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->getSchemaHistory($request);
        });

        /**
         * @route POST /admin/db/revert-schema-change
         * @tag Admin - Schema Management
         * @summary Revert a schema change
         * @description Reverts a previous schema change by executing the reverse operations
         * @requiresAuth true
         * @requestBody change_id:string="ID of the change to revert"
         *              confirm:boolean="Confirmation flag to execute revert (default: false for preview)"
         *              {required=change_id}
         * @response 200 application/json "Schema change reverted or preview generated" {
         *   change_id:string="Original change ID",
         *   original_change:object="Details of the original change",
         *   revert_operations:array="Operations to be performed for revert",
         *   preview_only:boolean="Whether this is a preview or actual execution",
         *   result:object="Revert execution results (when confirmed)",
         *   reverted_at:string="Revert timestamp (when confirmed)"
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Change not found"
         * @response 500 application/json "Revert operation failed"
         */
        Router::post('/revert-schema-change', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->revertSchemaChange($request);
        });
    }, [], false, true); // requiresAdminAuth: true

    // Extensions routes have been moved to routes/extensions.php

    Router::group('/migrations', function () use ($container) {
        /**
         * @route GET /admin/migrations
         * @tag Admin - Migrations
         * @summary List all migrations
         * @description Retrieves a list of all database migrations and their status
         * @requiresAuth true
         * @response 200 application/json "List of migrations" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     migrations:array=[{id:integer="Migration ID",
         *     name:string="Migration name", batch:integer="Migration batch",
         *     executed_at:string="Execution timestamp"}]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $migrationsController = $container->get(MigrationsController::class);
            return $migrationsController->getMigrations($request);
        });

        /**
         * @route GET /admin/migrations/pending
         * @tag Admin - Migrations
         * @summary List pending migrations
         * @description Retrieves a list of all pending database migrations
         * @requiresAuth true
         * @response 200 application/json "List of pending migrations" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     migrations:array=[{
         *       name:string="Migration name",
         *       path:string="Migration file path"
         *     }]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/pending', function (Request $request) use ($container) {
            $migrationsController = $container->get(MigrationsController::class);
            return $migrationsController->getPendingMigrations($request);
        });
    }, [], false, true); // requiresAdminAuth: true

    Router::group('/jobs', function () use ($container) {
        /**
         * @route GET /admin/jobs
         * @tag Admin - Jobs
         * @summary List all scheduled jobs
         * @description Retrieves a list of all scheduled jobs and their status
         * @requiresAuth true
         * @response 200 application/json "List of scheduled jobs" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     jobs:array=[{id:integer="Job ID",
         *     name:string="Job name", command:string="Job command", schedule:string="Job schedule",
         *     next_run:string="Next scheduled run", last_run:string="Last run timestamp",
         *     status:string="Job status"}]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->getScheduledJobs($request);
        });

        /**
         * @route POST /admin/jobs/run-due
         * @tag Admin - Jobs
         * @summary Run due jobs
         * @description Runs all jobs that are due to be executed
         * @requiresAuth true
         * @response 200 application/json "Jobs executed" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     executed:array=[{
         *       id:integer="Job ID",
         *       name:string="Job name",
         *       status:string="Execution status"
         *     }]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::post('/run-due', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->runDueJobs($request);
        });

        /**
         * @route POST /admin/jobs/run-all
         * @tag Admin - Jobs
         * @summary Run all jobs
         * @description Runs all scheduled jobs regardless of their schedule
         * @requiresAuth true
         * @response 200 application/json "Jobs executed" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     executed:array=[{
         *       id:integer="Job ID",
         *       name:string="Job name",
         *       status:string="Execution status"
         *     }]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::post('/run-all', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->runAllJobs($request);
        });

        /**
         * @route POST /admin/jobs/run
         * @tag Admin - Jobs
         * @summary Run specific job
         * @description Runs a specific job regardless of its schedule
         * @requiresAuth true
         * @requestBody id:integer="Job ID" {required=id}
         * @response 200 application/json "Job executed" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     id:integer="Job ID",
         *     name:string="Job name",
         *     status:string="Execution status"
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Job not found"
         */
        Router::post('/run', function (Request $request) use ($container) {
            $postData = RequestHelper::getRequestData();
            $jobsController = $container->get(JobsController::class);
            return $jobsController->runJob($postData['job']);
        });

        /**
         * @route POST /admin/jobs/create-job
         * @tag Admin - Jobs
         * @summary Create new job
         * @description Creates a new scheduled job
         * @requiresAuth true
         * @requestBody name:string="Job name"
         *              command:string="Job command"
         *              schedule:string="Cron schedule expression"
         *              enabled:boolean="Whether job is enabled initially"
         * {required=name,command,schedule}
         * @response 201 application/json "Job created" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     id:integer="Job ID", name:string="Job name"
         *   },
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         */
        Router::post('/create-job', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->createJob($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/configs', function () use ($container) {
        /**
         * @route GET /admin/configs
         * @tag Admin - Configuration
         * @summary List all configuration files
         * @description Retrieves a list of all available configuration files
         * @requiresAuth true
         * @response 200 application/json "List of configuration files" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     configs:array=[{name:string="Config filename", path:string="Config file path"}]
         *   },
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $configController = $container->get(ConfigController::class);
            return $configController->getConfigs($request);
        });

        /**
         * @route GET /admin/configs/{filename}
         * @tag Admin - Configuration
         * @summary Get configuration file
         * @description Retrieves the contents of a specific configuration file
         * @requiresAuth true
         * @param filename path string true "Configuration filename"
         * @response 200 application/json "Configuration file content" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     name:string="Config filename",
         *     content:object="Configuration data"
         *   },
         * }
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Configuration file not found"
         */
        Router::get('/{filename}', function (array $params) use ($container) {
            $configController = $container->get(ConfigController::class);
            return $configController->getConfigByFile($params['filename']);
        });

        /**
         * @route PUT /admin/configs/{filename}
         * @tag Admin - Configuration
         * @summary Update configuration file
         * @description Updates the contents of a specific configuration file
         * @requiresAuth true
         * @param filename path string true "Configuration filename"
         * @requestBody content:object="Configuration data to update" {required=content}
         * @response 200 application/json "Configuration updated"
         * @response 400 application/json "Invalid configuration data"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Configuration file not found"
         */
        Router::put('/{filename}', function (Request $request) use ($container) {
            $adminController = $container->get(AdminController::class);
            return $adminController->updateConfig($request);
        });

        /**
         * @route POST /admin/configs/create
         * @tag Admin - Configuration
         * @summary Create configuration file
         * @description Creates a new configuration file
         * @requiresAuth true
         * @requestBody name:string="Config filename" content:object="Configuration data" {required=name,content}
         * @response 201 application/json "Configuration file created"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 409 application/json "Configuration file already exists"
         */
        Router::post('/create', function (Request $request) use ($container) {
            $adminController = $container->get(AdminController::class);
            return $adminController->createConfig($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/system', function () use ($container) {
        /**
         * @route GET /admin/system/api-metrics
         * @tag Admin - System Monitoring
         * @summary Get API metrics
         * @description Retrieves comprehensive metrics about API usage including endpoint performance,
         * request volumes, error rates, and rate limiting information
         * @requiresAuth true
         * @response 200 application/json "API metrics retrieved successfully" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *   data:{
         *     endpoints:array=[{
         *       path:string="API endpoint path",
         *       method:string="HTTP method",
         *       total_requests:integer="Total number of requests",
         *       avg_response_time:number="Average response time in ms",
         *       error_count:integer="Number of errors",
         *       last_accessed:string="Last access timestamp"
         *     }],
         *     total_requests:integer="Total requests across all endpoints",
         *     avg_response_time:number="Overall average response time",
         *     error_rate:number="Overall error rate percentage"
         *   },
         * }
         * @response 403 application/json "Permission denied"
         * @response 500 application/json "Server error"
         */
        Router::get('/api-metrics', function (Request $request) use ($container) {
            $metricsController = $container->get(MetricsController::class);
            return $metricsController->getApiMetrics();
        });

        /**
         * @route POST /admin/system/api-metrics/reset
         * @tag Admin - System Monitoring
         * @summary Reset API metrics
         * @description Resets all collected API metrics data
         * @requiresAuth true
         * @response 200 application/json "API metrics reset successfully"
         * @response 403 application/json "Permission denied"
         * @response 500 application/json "Server error"
         */
        Router::post('/api-metrics/reset', function (Request $request) use ($container) {
            $metricsController = $container->get(MetricsController::class);
            return $metricsController->resetApiMetrics($request);
        });

        /**
         * @route GET /admin/system/health
         * @tag Admin - System Monitoring
         * @summary Get system health metrics
         * @description Retrieves comprehensive metrics about the system's health including PHP information,
         * database status, file system metrics, memory usage, cache status, extension status, and more
         * @requiresAuth true
         * @response 200 application/json "System health metrics retrieved successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:{
         *     php:object,
         *     memory:object,
         *     database:object,
         *     file_system:object,
         *     cache:object,
         *     extensions:object,
         *     server_load:object
         *   },
         * }
         * @response 403 application/json "Permission denied"
         * @response 500 application/json "Server error"
         */
        Router::get('/health', function (Request $request) use ($container) {
            $metricsController = $container->get(MetricsController::class);
            return $metricsController->systemHealth();
        });
    }, requiresAdminAuth: true);

    /**
     * @route GET /admin/dashboard
     * @tag Admin - Dashboard
     * @summary Get comprehensive dashboard data
     * @description Retrieves all dashboard data in a single request including database stats,
     * system health, migrations, extensions, permissions, roles, jobs, and API metrics
     * @requiresAuth true
     * @response 200 application/json "Dashboard data retrieved successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     database:object="Database statistics and table information",
     *     system_health:object="System health metrics and status",
     *     migrations:object="Migration status and pending count",
     *     extensions:object="Extension statistics and status",
     *     permissions:object="RBAC permissions data and statistics",
     *     roles:object="RBAC roles data and statistics",
     *     jobs:object="Scheduled jobs information",
     *     api_metrics:object="API performance and usage metrics",
     *     timestamp:string="Data collection timestamp"
     *   },
     * }
     * @response 403 application/json "Permission denied"
     * @response 500 application/json "Server error"
     */
    Router::get('/dashboard', function (Request $request) use ($container) {
        $adminController = $container->get(AdminController::class);
        return $adminController->getDashboardData($request);
    }, requiresAdminAuth: true);

    // User Management Routes (moved from routes/users.php)
    Router::group('/users', function () use ($container) {
        /**
         * @route GET /admin/users
         * @tag Admin - User Management
         * @summary List all users
         * @description Retrieves a paginated list of users (role functionality moved to RBAC extension)
         * @requiresAuth true
         * @query page integer "Page number (default: 1)"
         * @query per_page integer "Items per page (default: 25)"
         * @query search string "Search term for username/email"
         * @query status string "Filter by status (active/inactive/suspended)"
         * @query role_id string "Filter by role UUID"
         * @query sort string "Sort field (default: created_at)"
         * @query order string "Sort order ASC/DESC (default: DESC)"
         * @query include_deleted boolean "Include soft-deleted users"
         * @response 200 application/json "List of users with roles" {
         *   success:boolean="true",
         *   data:array=[{
         *     uuid:string,
         *     username:string,
         *     email:string,
         *     status:string,
         *     created_at:string,
         *     last_login_date:string,
         *     roles:array=[{uuid:string,name:string}]
         *   }],
         *   current_page:integer="Current page number",
         *   per_page:integer="Items per page",
         *   total:integer="Total number of users",
         *   last_page:integer="Last page number",
         *   has_more:boolean="Whether there are more pages",
         *   from:integer="Starting record number",
         *   to:integer="Ending record number"
         * }
         */
        Router::get('/', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->index($request);
        });

        /**
         * @route GET /admin/users/stats
         * @tag Admin - User Monitoring
         * @summary Get user statistics
         * @description Retrieves user statistics for dashboard
         * @requiresAuth true
         * @query period string "Time period (7days/30days/90days/year, default: 30days)"
         * @response 200 application/json "User statistics retrieved successfully" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *   data:{
         *     total_users:integer="Total number of users (excluding deleted)",
         *     active_users:integer="Number of active users",
         *     by_status:{
         *       active:integer="Number of active users",
         *       inactive:integer="Number of inactive users",
         *       suspended:integer="Number of suspended users",
         *       pending:integer="Number of pending users"
         *     },
         *     new_users_7days:integer="New users in last 7 days (when period=7days)",
         *     new_users_30days:integer="New users in last 30 days (when period=30days)",
         *     new_users_90days:integer="New users in last 90 days (when period=90days)",
         *     new_users_year:integer="New users in last year (when period=year)"
         *   },
         * }
         * @response 401 "Unauthorized"
         * @response 403 "Forbidden - insufficient permissions"
         */
        Router::get('/stats', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->stats($request);
        });

        /**
         * @route GET /admin/users/search
         * @tag Admin - User Monitoring
         * @summary Advanced user search
         * @description Search users with advanced filters
         * @requiresAuth true
         * @query q string "Search query"
         * @query status string "Filter by status"
         * @query role string "Filter by role name"
         * @query created_after string "Filter by creation date"
         * @query created_before string "Filter by creation date"
         * @query last_login_after string "Filter by last login"
         * @query last_login_before string "Filter by last login"
         * @query has_permission string "Filter by permission"
         * @query page integer "Page number (default: 1)"
         * @query per_page integer "Items per page (default: 25)"
         * @response 200 application/json "User search results with pagination" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *     data:array=[{
         *       uuid:string="User unique identifier",
         *       username:string="Username",
         *       email:string="User email address",
         *       status:string="User status (active, inactive, suspended, pending)",
         *       created_at:string="User creation timestamp",
         *       last_login_date:string="Last login timestamp",
         *       roles:array=[{
         *         uuid:string="Role unique identifier",
         *         name:string="Role name",
         *         slug:string="Role slug",
         *         level:integer="Role level",
         *         is_system:boolean="System role flag",
         *         assigned_at:string="Role assignment timestamp"
         *       }]
         *     }],
         *     current_page:integer="Current page number",
         *     per_page:integer="Items per page",
         *     total:integer="Total number of matching users",
         *     last_page:integer="Last page number",
         *     from:integer="First item number on current page",
         *     to:integer="Last item number on current page"
         * }
         * @response 400 "Bad request - invalid search parameters"
         * @response 401 "Unauthorized"
         * @response 403 "Forbidden - insufficient permissions"
         */
        Router::get('/search', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->search($request);
        });

        /**
         * @route GET /admin/users/export
         * @tag Admin - User Operations
         * @summary Export users
         * @description Export users to CSV or JSON format
         * @requiresAuth true
         * @query format string "Export format (csv/json, default: csv)"
         * @query status string "Filter by status (active/inactive/suspended/pending)"
         * @query role string "Filter by role name"
         * @query include_deleted boolean "Include soft-deleted users (default: false)"
         * @response 200 application/json "User export data (JSON format)" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *   data:array=[{
         *     uuid:string="User unique identifier",
         *     username:string="Username",
         *     email:string="User email address",
         *     status:string="User status (active, inactive, suspended, pending)",
         *     created_at:string="User creation timestamp",
         *     last_login_date:string="Last login timestamp",
         *     roles:array=[{
         *       uuid:string="Role unique identifier",
         *       name:string="Role name",
         *       slug:string="Role slug",
         *       level:integer="Role level",
         *       is_system:boolean="System role flag",
         *       assigned_at:string="Role assignment timestamp"
         *     }],
         *     profile:{
         *       first_name:string="User first name",
         *       last_name:string="User last name",
         *       phone:string="User phone number",
         *       address:string="User address",
         *       bio:string="User biography"
         *     },
         *   }]
         * }
         * @response 200 text/csv "User export file (CSV format)"
         * @response 400 "Bad request - role filtering requires RBAC extension"
         * @response 401 "Unauthorized"
         * @response 403 "Forbidden - insufficient permissions"
         */
        Router::get('/export', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->export($request);
        });

        /**
         * @route POST /admin/users/import
         * @tag Admin - User Operations
         * @summary Import users
         * @description Import users from CSV or JSON file
         * @requiresAuth true
         * @requestBody file:file="Import file" format:string="File format (csv/json)"
         *              update_existing:boolean="Update existing users"
         *              send_welcome_email:boolean="Send welcome emails"
         *              default_password:string="Default password for new users"
         *              {required=file}
         * @response 200 application/json "Import results"
         */
        Router::post('/import', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->import($request);
        });

        /**
         * @route POST /admin/users/bulk
         * @tag Admin - User Operations
         * @summary Bulk operations
         * @description Perform bulk operations on multiple users
         * @requiresAuth true
         * @requestBody action:string="Action (delete/restore/activate/deactivate/suspend)"
         *              user_ids:array="Array of user UUIDs"
         *              note:string="Role operations moved to RBAC extension"
         *              {required=action,user_ids}
         * @response 200 application/json "Operation results"
         */
        Router::post('/bulk', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->bulk($request);
        });

        /**
         * @route GET /admin/users/{uuid}
         * @tag Admin - User Management
         * @summary Get user details
         * @description Retrieves detailed information about a specific user including roles and profile data
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @response 200 application/json "User details retrieved successfully" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *   data:{
         *     uuid:string="User UUID",
         *     username:string="Username",
         *     email:string="Email address",
         *     status:string="User status (active/inactive/suspended/pending)",
         *     created_at:string="Account creation timestamp",
         *     last_login_date:string="Last login timestamp",
         *     roles:array=[{
         *       uuid:string="Role UUID",
         *       name:string="Role name",
         *       slug:string="Role slug",
         *       level:integer="Role level",
         *       is_system:boolean="Whether role is system-defined",
         *       assigned_at:string="Role assignment timestamp"
         *     }],
         *     profile:{
         *       first_name:string="First name",
         *       last_name:string="Last name",
         *       bio:string="User biography",
         *       avatar_url:string="Profile avatar URL",
         *       phone:string="Phone number",
         *       timezone:string="User timezone",
         *       language:string="Preferred language",
         *       updated_at:string="Profile last updated timestamp"
         *     },
         *   },
         * }
         * @response 404 application/json "User not found"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/{uuid}', function (array $params, Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->show($params);
        });

        /**
         * @route POST /admin/users
         * @tag Admin - User Management
         * @summary Create new user
         * @description Creates a new user (role assignment via RBAC extension)
         * @requiresAuth true
         * @requestBody username:string="Username" email:string="Email address"
         *              password:string="Password" status:string="Status (active/inactive)"
         *              roles:array="Role UUIDs" profile:object="Profile data"
         *              {required=username,email,password}
         * @response 201 application/json "Created user"
         * @response 422 application/json "Validation errors"
         */
        Router::post('/', function (Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->create($request);
        });

        /**
         * @route PUT /admin/users/{uuid}
         * @tag Admin - User Management
         * @summary Update user
         * @description Updates user information (roles managed by RBAC extension)
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @requestBody username:string="Username" email:string="Email address"
         *              status:string="Status" password:string="New password"
         *              roles:array="Role UUIDs" profile:object="Profile data"
         * @response 200 application/json "Updated user"
         * @response 404 application/json "User not found"
         * @response 422 application/json "Validation errors"
         */
        Router::put('/{uuid}', function (array $params, Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->update($params, $request);
        });

        /**
         * @route DELETE /admin/users/{uuid}
         * @tag Admin - User Management
         * @summary Delete user
         * @description Soft deletes a user
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @response 200 application/json "User deleted"
         * @response 404 application/json "User not found"
         */
        Router::delete('/{uuid}', function (array $params) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->delete($params);
        });

        /**
         * @route POST /admin/users/{uuid}/restore
         * @tag Admin - User Operations
         * @summary Restore user
         * @description Restores a soft-deleted user
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @response 200 application/json "User restored"
         * @response 404 application/json "User not found"
         */
        Router::post('/{uuid}/restore', function (array $params) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->restore($params);
        });

        /**
         * @route GET /admin/users/{uuid}/activity
         * @tag Admin - User Monitoring
         * @summary Get user activity
         * @description Retrieves user activity log with pagination and filtering options
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @param page query integer false "Page number for pagination (default: 1)"
         * @param per_page query integer false "Number of items per page (default: 25)"
         * @param type query string false "Filter by activity type (login, logout, action, etc.)"
         * @param date_from query string false "Filter activities from this date (YYYY-MM-DD)"
         * @param date_to query string false "Filter activities to this date (YYYY-MM-DD)"
         * @response 200 application/json "User activity log retrieved successfully" {
         *   success:boolean="true",
         *   message:string="Success message",
         *   data:array=[{
         *     action:string="Action performed",
         *     entity_type:string="Type of entity affected",
         *     entity_id:string="ID of entity affected",
         *     old_values:object="Previous values before change",
         *     new_values:object="New values after change",
         *     created_at:string="Activity timestamp",
         *     user_id:string="User UUID who performed the action"
         *   }],
         *   current_page:integer="Current page number",
         *   per_page:integer="Items per page",
         *   total:integer="Total number of activities",
         *   last_page:integer="Last page number",
         *   has_more:boolean="Whether there are more pages",
         *   from:integer="Starting record number",
         *   to:integer="Ending record number"
         * }
         * @response 404 application/json "User not found"
         * @response 403 application/json "Permission denied"
         */
        Router::get('/{uuid}/activity', function (array $params, Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->activity($params, $request);
        });

        /**
         * @route GET /admin/users/{uuid}/sessions
         * @tag Admin - User Sessions
         * @summary Get user sessions
         * @description Retrieves all active sessions for a user
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @response 200 application/json "User sessions retrieved successfully" {
         *   success:boolean="Success status",
         *   message:string="Success message",
         *   data:array=[{
         *     session_id:string="Session unique identifier",
         *     access_token:string="Masked access token (first 8 chars + ...)",
         *     status:string="Session status (active)",
         *     provider:string="Authentication provider",
         *     ip_address:string="Client IP address",
         *     user_agent:string="Client user agent string",
         *     created_at:string="Session creation timestamp",
         *     last_activity:string="Last activity timestamp",
         *     last_token_refresh:string="Last token refresh timestamp",
         *     access_expires_at:string="Access token expiration timestamp",
         *     refresh_expires_at:string="Refresh token expiration timestamp"
         *   }]
         * }
         * @response 401 "Unauthorized"
         * @response 403 "Forbidden - insufficient permissions"
         * @response 404 "User not found"
         */
        Router::get('/{uuid}/sessions', function (array $params) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->sessions($params);
        });

        /**
         * @route DELETE /admin/users/{uuid}/sessions
         * @tag Admin - User Sessions
         * @summary Terminate user sessions
         * @description Terminates all or specific user sessions
         * @requiresAuth true
         * @param uuid path string true "User UUID"
         * @requestBody session_id:string="Specific session ID to terminate"
         * @response 200 application/json "Sessions terminated"
         * @response 404 application/json "User not found"
         */
        Router::delete('/{uuid}/sessions', function (array $params, Request $request) use ($container) {
            $usersController = $container->get(UsersController::class);
            return $usersController->terminateSessions($params, $request);
        });
    }, requiresAdminAuth: true);
});
