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
use Glueful\Controllers\{
    AdminController,
    DatabaseController,
    MigrationsController,
    JobsController,
    PermissionsController,
    MetricsController
};
use Symfony\Component\HttpFoundation\Request;

$adminController = new AdminController();
$dbController = new DatabaseController();
$migrationsController = new MigrationsController();
$jobsController = new JobsController();
$permissionsController = new PermissionsController();
$metricsController = new MetricsController();


Router::group('/admin', function () use (
    $adminController,
    $dbController,
    $migrationsController,
    $jobsController,
    $permissionsController,
    $metricsController
) {
    /**
     * @route POST /admin/login
     * @tag Authentication
     * @summary Admin login
     * @description Authenticates an admin user and creates a session
     * @requiresAuth false
     * @requestBody username:string="Admin username" password:string="Admin password" {required=username,password}
     * @response 200 application/json "Login successful" {
     *   token:string="Authentication token",
     *   user:object={id:integer="User ID", username:string="Username", email:string="Email address"}
     * }
     * @response 401 application/json "Invalid credentials"
     * @response 429 application/json "Too many login attempts"
     */
    Router::post('/login', function (Request $request) use ($adminController) {
        return $adminController->login($request);
    });

    /**
     * @route POST /admin/logout
     * @tag Authentication
     * @summary Admin logout
     * @description Ends an admin user session
     * @requiresAuth false
     * @response 200 application/json "Logout successful"
     * @response 401 application/json "Not authenticated"
     */
    Router::post('/logout', function (Request $request) use ($adminController) {
        return $adminController->logout($request);
    });

    Router::group('/db', function () use ($dbController) {

        Router::post('/query', function (Request $request) use ($dbController) {
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
        Router::get('/stats', function (Request $request) use ($dbController) {
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
        Router::get('/tables', function (Request $request) use ($dbController) {
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
        Router::post('/table/create', function (Request $request) use ($dbController) {
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
        Router::post('/table/drop', function (Request $request) use ($dbController) {
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
            function (array $params) use ($dbController) {
                return $dbController->getTableSize($params);
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
        Router::get('/table/{name}', function (array $params) use ($dbController) {
            // $params = $request->getRouteParams();
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
        Router::post('/table/column/add', function (Request $request) use ($dbController) {
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
        Router::post('/table/column/drop', function (Request $request) use ($dbController) {
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
        Router::post('/table/index/drop', function (Request $request) use ($dbController) {
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
        Router::post('/table/foreign-key/drop', function (Request $request) use ($dbController) {
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
        Router::get('/table/{name}/columns', function (array $params) use ($dbController) {
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
            function (Request $request) use ($dbController) {
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
        Router::post('/table/foreign-key/add', function (Request $request) use ($dbController) {
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
            function (Request $request) use ($dbController) {
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
        Router::post('/table/column/drop-batch', function (Request $request) use ($dbController) {
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
        Router::post('/table/index/drop-batch', function (Request $request) use ($dbController) {
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
        Router::post('/table/foreign-key/drop-batch', function (Request $request) use ($dbController) {
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
        Router::post('/table/index/add-batch', function (Request $request) use ($dbController) {
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
            function (Request $request) use ($dbController) {
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
        Router::post('/table/schema/update', function (Request $request) use ($dbController) {
            return $dbController->updateTableSchema($request);
        });
    }, requiresAdminAuth: true);

    // Extensions routes have been moved to routes/extensions.php

    Router::group('/migrations', function () use ($migrationsController) {
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
        Router::get('/', function (Request $request) use ($migrationsController) {
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
        Router::get('/pending', function (Request $request) use ($migrationsController) {
            return $migrationsController->getPendingMigrations($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/jobs', function () use ($jobsController) {
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
        Router::get('/', function (Request $request) use ($jobsController) {
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
        Router::post('/run-due', function (Request $request) use ($jobsController) {
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
        Router::post('/run-all', function (Request $request) use ($jobsController) {
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
        Router::post('/run', function (Request $request) use ($jobsController) {
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
        Router::post('/create-job', function (Request $request) use ($jobsController) {
            return $jobsController->createJob($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/configs', function () use ($adminController) {
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
        Router::get('/', function (Request $request) use ($adminController) {
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
        Router::get('/{filename}', function (Request $request) use ($adminController) {
            return $adminController->getConfig($request);
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
        Router::put('/{filename}', function (Request $request) use ($adminController) {
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
        Router::post('/create', function (Request $request) use ($adminController) {
            return $adminController->createConfig($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/permissions', function () use ($permissionsController) {
        /**
         * @route GET /admin/permissions
         * @tag Permissions
         * @summary List all permissions
         * @description Retrieves a list of all available permissions
         * @requiresAuth true
         * @response 200 application/json "List of permissions" {
         *   permissions:array=[{
         *     id:integer="Permission ID",
         *     name:string="Permission name",
         *     description:string="Permission description"
         *   }]
         * }
         * @response 403 application/json "Permission denied"
         */
        Router::get('/', function (Request $request) use ($permissionsController) {
            return $permissionsController->getPermissions($request);
        });

        /**
         * @route POST /admin/permissions/create
         * @tag Permissions
         * @summary Create permission
         * @description Creates a new permission
         * @requiresAuth true
         * @requestBody name:string="Permission name" description:string="Permission description" {required=name}
         * @response 201 application/json "Permission created" {
         *   id:integer="Permission ID",
         *   name:string="Permission name"
         * }
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 409 application/json "Permission already exists"
         */
        Router::post('/create', function (Request $request) use ($permissionsController) {
            return $permissionsController->createPermission($request);
        });

        /**
         * @route PUT /admin/permissions/update
         * @tag Permissions
         * @summary Update permission
         * @description Updates an existing permission
         * @requiresAuth true
         * @requestBody id:integer="Permission ID"
         *              name:string="Permission name"
         *              description:string="Permission description"
         *              {required=id}
         * @response 200 application/json "Permission updated"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Permission not found"
         */
        Router::put('/update', function (Request $request) use ($permissionsController) {
            return $permissionsController->updatePermission($request);
        });

        /**
         * @route POST /admin/permissions/assign-to-role
         * @tag Permissions
         * @summary Assign permissions to role
         * @description Assigns one or more permissions to a role
         * @requiresAuth true
         * @requestBody roleId:integer="Role ID"
         *              permissionIds:array=[integer="Permission ID"]
         *              {required=roleId,permissionIds}
         * @response 200 application/json "Permissions assigned to role"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role or permission not found"
         */
        Router::post('/assign-to-role', function (Request $request) use ($permissionsController) {
            return $permissionsController->assignPermissionsToRole($request);
        });

        /**
         * @route PUT /admin/permissions/update-role-permissions
         * @tag Permissions
         * @summary Update role permissions
         * @description Updates the permissions assigned to a role
         * @requiresAuth true
         * @requestBody roleId:integer="Role ID"
         *              permissionIds:array=[integer="Permission ID"]
         *              {required=roleId,permissionIds}
         * @response 200 application/json "Role permissions updated"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "Role not found"
         */
        Router::put('/update-role-permissions', function (Request $request) use ($permissionsController) {
            return $permissionsController->updateRolePermissions($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/roles', function () use ($permissionsController) {
        /**
         * @route POST /admin/roles/assign-to-user
         * @tag Roles
         * @summary Assign roles to user
         * @description Assigns one or more roles to a user
         * @requiresAuth true
         * @requestBody userId:integer="User ID" roleIds:array=[integer="Role ID"] {required=userId,roleIds}
         * @response 200 application/json "Roles assigned to user"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "User or role not found"
         */
        Router::post('/assign-to-user', function (Request $request) use ($permissionsController) {
            return $permissionsController->assignRolesToUser($request);
        });

        /**
         * @route PUT /admin/roles/remove-user-roles
         * @tag Roles
         * @summary Remove roles from user
         * @description Removes one or more roles from a user
         * @requiresAuth true
         * @requestBody userId:integer="User ID" roleIds:array=[integer="Role ID"] {required=userId,roleIds}
         * @response 200 application/json "Roles removed from user"
         * @response 400 application/json "Invalid request format"
         * @response 403 application/json "Permission denied"
         * @response 404 application/json "User or role not found"
         */
        Router::put('/remove-user-roles', function (Request $request) use ($permissionsController) {
            return $permissionsController->removeUserRole($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/system', function () use ($metricsController) {
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
        Router::get('/api-metrics', function (Request $request) use ($metricsController) {
            return $metricsController->getApiMetrics($request);
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
        Router::post('/api-metrics/reset', function (Request $request) use ($metricsController) {
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
        Router::get(
            '/health',
            function (Request $request) use ($metricsController) {
                return $metricsController->systemHealth();
            }
        );
    }, requiresAdminAuth: true);
});
