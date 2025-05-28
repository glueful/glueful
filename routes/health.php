<?php

use Glueful\Http\Router;
use Glueful\Controllers\HealthController;

// Get the container from the global app() helper
$container = app();

// Health check routes
Router::group('/health', function () use ($container) {
    /**
     * @route GET /health
     * @summary System Health Check
     * @description Get overall system health status including database, cache, extensions, and configuration
     * @tag Health
     * @response 200 application/json "System is healthy" {
     *   status:string="Overall health status (ok|warning|error)",
     *   timestamp:string="ISO timestamp of check",
     *   version:string="Application version",
     *   environment:string="Application environment",
     *   checks:{
     *     database:{
     *       status:string="Database status",
     *       message:string="Database status message",
     *       driver:string="Database driver name",
     *       migrations_applied:integer="Number of applied migrations"
     *     },
     *     cache:{
     *       status:string="Cache status",
     *       message:string="Cache status message",
     *       driver:string="Cache driver name"
     *     },
     *     extensions:{
     *       status:string="Extensions status",
     *       message:string="Extensions status message",
     *       loaded:array="List of loaded extensions"
     *     },
     *     config:{
     *       status:string="Configuration status",
     *       message:string="Configuration status message",
     *       environment:string="Application environment"
     *     }
     *   }
     * }
     * @response 503 application/json "System is unhealthy" {
     *   status:string="error",
     *   timestamp:string="ISO timestamp of check",
     *   checks:object="Individual check results"
     * }
     */
    Router::get('/', function() use ($container) {
        $healthController = $container->get(HealthController::class);
        return $healthController->index();
    });

    /**
     * @route GET /health/database
     * @summary Database Health Check
     * @description Check database connectivity and functionality using QueryBuilder abstraction
     * @tag Health
     * @response 200 application/json "Database is healthy" {
     *   status:string="Database status (ok|warning|error)",
     *   message:string="Database status message",
     *   driver:string="Database driver name",
     *   migrations_applied:integer="Number of applied migrations",
     *   connectivity_test:boolean="Connectivity test result"
     * }
     * @response 503 application/json "Database is unhealthy" {
     *   status:string="error",
     *   message:string="Error message",
     *   type:string="Error type"
     * }
     */
    Router::get('/database', function() use ($container) {
        $healthController = $container->get(HealthController::class);
        return $healthController->database();
    });

    /**
     * @route GET /health/cache
     * @summary Cache Health Check
     * @description Check cache connectivity and functionality
     * @tag Health
     * @response 200 application/json "Cache is healthy" {
     *   status:string="Cache status (ok|disabled|error)",
     *   message:string="Cache status message",
     *   driver:string="Cache driver name",
     *   operations:string="Operations status"
     * }
     * @response 503 application/json "Cache is unhealthy" {
     *   status:string="error",
     *   message:string="Error message"
     * }
     */
    Router::get('/cache', function() use ($container) {
        $healthController = $container->get(HealthController::class);
        return $healthController->cache();
    });
});
