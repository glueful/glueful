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
    MetricsController
};
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

// Controllers will be resolved from the DI container when routes are called
// This ensures proper dependency injection and lazy loading

Router::group('/admin', function () use ($container) {

    /**
     * @route GET /admin
     * @tag Admin Interface
     * @summary Load Admin UI
     * @description Loads and renders the main admin interface welcome page
     * @requiresAuth false
     * @response 200 text/html "Admin interface HTML page"
     * @response 404 "Admin UI file not found"
     * @response 500 "Failed to load Admin UI"
     */
    Router::get('/', function () use ($container) {
        $adminController = $container->get(AdminController::class);
        return $adminController->adminUI();
    });

    Router::group('/db', function () use ($container) {

        Router::post('/query', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->executeQuery($request);
        });

        /**
         * @route GET /admin/db/stats
         * @tag Database
         * @summary Get comprehensive database statistics
         * @description Retrieves detailed statistics for all tables in the database including size, schema,
         * row counts, and other metrics
         * @requiresAuth true
         * @response 200 application/json "Database statistics retrieved successfully" {
         *   tables:array=[{
         *     table_name:string="Table name",
         *     schema:string="Database schema",
         *     size:integer="Size in bytes",
         *     rows:integer="Row count",
         *     avg_row_size:integer="Average row size in bytes"
         *   }],
         *   total_tables:integer="Total number of tables"
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
         * @tag Database
         * @summary Get all database tables
         * @description Retrieves a list of all tables in the database
         * @requiresAuth true
         * @response 200 application/json "List of tables" {
         *   tables:array=[{name:string="Table name", rows:integer="Row count", created:string="Creation date"}]
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/tables', function (Request $request) use ($container) {
            $dbController = $container->get(DatabaseController::class);
            return $dbController->getTables();
        });

        /**
         * @route POST /admin/db/table/create
         * @tag Database
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
         * @tag Database
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
         * @tag Database
         * @summary Get database table size
         * @description Retrieves the size of the specified table in the database
         * @requiresAuth true
         * @requestBody name:string="Table name" {required=name}
         * @response 200 application/json "Table size information" {
         *   name:string="Table name",
         *   size:string="Size (formatted)",
         *   bytes:integer="Size in bytes",
         *   rows:integer="Row count"
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
         * @tag Database
         * @summary Get comprehensive table metadata
         * @description Retrieves detailed metadata about a database table including size, row count, columns,
         *              indexes, engine, and timestamps
         * @requiresAuth true
         * @param name path string true "Table name"
         * @response 200 application/json "Table metadata" {
         *   name:string="Table name",
         *   rows:integer="Number of rows",
         *   size:integer="Table size in bytes",
         *   columns:integer="Number of columns",
         *   indexes:integer="Number of indexes",
         *   engine:string="Storage engine",
         *   created:string="Creation timestamp",
         *   updated:string="Last update timestamp",
         *   collation:string="Table collation",
         *   comment:string="Table comment",
         *   auto_increment:integer="Auto increment value",
         *   avg_row_length:integer="Average row length",
         *   data_length:integer="Data length",
         *   index_length:integer="Index length",
         *   data_free:integer="Free space"
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
         * @tag Database
         * @summary Get table data
         * @description Retrieves data from the specified table with pagination
         * @requiresAuth true
         * @param name path string true "Table name"
         * @response 200 application/json "Table data" {
         *   data:array=[{id:integer="Row ID"}],
         *   total:integer="Total number of rows",
         *   page:integer="Current page",
         *   limit:integer="Items per page"
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         * @tag Database
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
         *   imported:integer="Number of records imported",
         *   failed:integer="Number of failed records",
         *   errors:array=[string="Error messages for failed records"]
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
         * @tag Database
         * @summary Bulk delete records from table
         * @description Deletes multiple records from the specified table by their IDs
         * @requiresAuth true
         * @param name path string true "Table name"
         * @requestBody ids:array=[string|number] "Array of record IDs to delete" {required=ids}
         * @response 200 application/json "Records deleted successfully" {
         *   deleted:integer="Number of records deleted",
         *   ids:array=[string|number]="Array of deleted record IDs"
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
         * @tag Database
         * @summary Bulk update records in table
         * @description Updates multiple records in the specified table by their IDs
         * @requiresAuth true
         * @param name path string true "Table name"
         * @requestBody ids:array=[string|number] "Array of record IDs to update"
         *              data:object="Data to update for each record"
         *              {required=ids,data}
         * @response 200 application/json "Records updated successfully" {
         *   updated:integer="Number of records updated",
         *   ids:array=[string|number]="Array of updated record IDs",
         *   data:object="Data that was updated"
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
         * @tag Database
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
         *   table_name:string="Table name",
         *   current_schema:object="Current table schema",
         *   proposed_changes:array="Array of proposed changes",
         *   preview:object={
         *     sql:array="SQL statements to be executed",
         *     warnings:array="Potential warnings and risks",
         *     estimated_duration:integer="Estimated execution time in seconds",
         *     safe_to_execute:boolean="Whether changes are safe to execute",
         *     generated_at:string="Preview generation timestamp"
         *   }
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
         * @tag Database
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
         *   table_name:string="Table name",
         *   format:string="Export format used",
         *   schema:object="Exported schema definition",
         *   exported_at:string="Export timestamp",
         *   metadata:object={
         *     export_size:integer="Size of exported data in bytes",
         *     format_version:string="Schema format version"
         *   }
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
         * @tag Database
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
         *   table_name:string="Table name",
         *   format:string="Import format used",
         *   result:object={
         *     success:boolean="Import success status",
         *     changes:array="List of changes made",
         *     imported_at:string="Import timestamp"
         *   }
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
         * @tag Database
         * @summary Get schema change history for a table
         * @description Retrieves the history of schema changes for a specific table from audit logs and migrations
         * @requiresAuth true
         * @param table query string true "Table name"
         * @param limit query integer false "Number of records to return (default: 50)"
         * @param offset query integer false "Number of records to skip (default: 0)"
         * @response 200 application/json "Schema history retrieved successfully" {
         *   table_name:string="Table name",
         *   schema_changes:array=[{
         *     id:string="Change ID",
         *     action:string="Action performed",
         *     context:object="Change context and details",
         *     created_at:string="Change timestamp",
         *     user_agent:string="User agent that made the change",
         *     ip_address:string="IP address of the requester"
         *   }],
         *   migrations:array=[{
         *     migration:string="Migration name",
         *     executed_at:string="Execution timestamp"
         *   }],
         *   pagination:object={
         *     limit:integer="Records limit",
         *     offset:integer="Records offset",
         *     total:integer="Total available records"
         *   },
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
         * @tag Database
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
         * @tag Migrations
         * @summary List all migrations
         * @description Retrieves a list of all database migrations and their status
         * @requiresAuth true
         * @response 200 application/json
         *          "List of migrations"
         *          {migrations:array=[{
         *              id:integer="Migration ID",
         *              name:string="Migration name",
         *              batch:integer="Migration batch",
         *              executed_at:string="Execution timestamp"
         *          }]}
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $migrationsController = $container->get(MigrationsController::class);
            return $migrationsController->getMigrations($request);
        });

        /**
         * @route GET /admin/migrations/pending
         * @tag Migrations
         * @summary List pending migrations
         * @description Retrieves a list of all pending database migrations
         * @requiresAuth true
         * @response 200 application/json "List of pending migrations" {
         *   migrations:array=[{
         *     name:string="Migration name",
         *     path:string="Migration file path"
         *   }]
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
         * @tag Jobs
         * @summary List all scheduled jobs
         * @description Retrieves a list of all scheduled jobs and their status
         * @requiresAuth true
         * @response 200 application/json "List of scheduled jobs" {jobs:array=[{id:integer="Job ID",
         * name:string="Job name", command:string="Job command", schedule:string="Job schedule",
         * next_run:string="Next scheduled run", last_run:string="Last run timestamp",
         * status:string="Job status"}]}
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->getScheduledJobs($request);
        });

        /**
         * @route POST /admin/jobs/run-due
         * @tag Jobs
         * @summary Run due jobs
         * @description Runs all jobs that are due to be executed
         * @requiresAuth true
         * @response 200 application/json "Jobs executed" {
         *   executed:array=[{
         *     id:integer="Job ID",
         *     name:string="Job name",
         *     status:string="Execution status"
         *   }]
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::post('/run-due', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->runDueJobs($request);
        });

        /**
         * @route POST /admin/jobs/run-all
         * @tag Jobs
         * @summary Run all jobs
         * @description Runs all scheduled jobs regardless of their schedule
         * @requiresAuth true
         * @response 200 application/json "Jobs executed" {
         *   executed:array=[{
         *     id:integer="Job ID",
         *     name:string="Job name",
         *     status:string="Execution status"
         *   }]
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::post('/run-all', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->runAllJobs($request);
        });

        /**
         * @route POST /admin/jobs/run
         * @tag Jobs
         * @summary Run specific job
         * @description Runs a specific job regardless of its schedule
         * @requiresAuth true
         * @requestBody id:integer="Job ID" {required=id}
         * @response 200 application/json "Job executed" {
         *   id:integer="Job ID",
         *   name:string="Job name",
         *   status:string="Execution status"
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Job not found"
         */
        Router::post('/run', function (Request $request) use ($container) {
            $jobsController = $container->get(JobsController::class);
            return $jobsController->runJob($request);
        });

        /**
         * @route POST /admin/jobs/create-job
         * @tag Jobs
         * @summary Create new job
         * @description Creates a new scheduled job
         * @requiresAuth true
         * @requestBody name:string="Job name"
         *              command:string="Job command"
         *              schedule:string="Cron schedule expression"
         *              enabled:boolean="Whether job is enabled initially"
         * {required=name,command,schedule}
         * @response 201 application/json "Job created" {id:integer="Job ID", name:string="Job name"}
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
         * @tag Configuration
         * @summary List all configuration files
         * @description Retrieves a list of all available configuration files
         * @requiresAuth true
         * @response 200 application/json "List of configuration files" {
         *   configs:array=[{name:string="Config filename", path:string="Config file path"}]
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($container) {
            $adminController = $container->get(AdminController::class);
            return $adminController->getAllConfigs($request);
        });

        /**
         * @route GET /admin/configs/{filename}
         * @tag Configuration
         * @summary Get configuration file
         * @description Retrieves the contents of a specific configuration file
         * @requiresAuth true
         * @param filename path string true "Configuration filename"
         * @response 200 application/json "Configuration file content" {
         *   name:string="Config filename",
         *   content:object="Configuration data"
         * }
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Configuration file not found"
         */
        Router::get('/{filename}', function (array $params) use ($container) {
            $adminController = $container->get(AdminController::class);
            return $adminController->getConfig($params['filename']);
        });

        /**
         * @route PUT /admin/configs/{filename}
         * @tag Configuration
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
         * @tag Configuration
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
         * @tag API Monitoring
         * @summary Get API metrics
         * @description Retrieves comprehensive metrics about API usage including endpoint performance,
         * request volumes, error rates, and rate limiting information
         * @requiresAuth true
         * @response 200 application/json "API metrics retrieved successfully" {
         *   endpoints:array,
         *   total_requests:integer,
         *   avg_response_time:number,
         *   error_rate:number
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
         * @tag API Monitoring
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
         * @tag System Monitoring
         * @summary Get system health metrics
         * @description Retrieves comprehensive metrics about the system's health including PHP information,
         * database status, file system metrics, memory usage, cache status, extension status, and more
         * @requiresAuth true
         * @response 200 application/json "System health metrics retrieved successfully" {
         *   php:object,
         *   memory:object,
         *   database:object,
         *   file_system:object,
         *   cache:object,
         *   extensions:object,
         *   server_load:object
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
     * @tag Dashboard
     * @summary Get comprehensive dashboard data
     * @description Retrieves all dashboard data in a single request including database stats,
     * system health, migrations, extensions, permissions, roles, jobs, and API metrics
     * @requiresAuth true
     * @response 200 application/json "Dashboard data retrieved successfully" {
     *   database:object="Database statistics and table information",
     *   system_health:object="System health metrics and status",
     *   migrations:object="Migration status and pending count",
     *   extensions:object="Extension statistics and status",
     *   permissions:object="RBAC permissions data and statistics",
     *   roles:object="RBAC roles data and statistics",
     *   jobs:object="Scheduled jobs information",
     *   api_metrics:object="API performance and usage metrics",
     *   timestamp:string="Data collection timestamp"
     * }
     * @response 403 application/json "Permission denied"
     * @response 500 application/json "Server error"
     */
    Router::get('/dashboard', function (Request $request) use ($container) {
        $adminController = $container->get(AdminController::class);
        return $adminController->getDashboardData($request);
    }, requiresAdminAuth: true);
});
