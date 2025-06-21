<?php

/**
 * Extension Management Routes
 *
 * This file defines routes for extension-related functionality including:
 * - Listing available extensions
 * - Enabling and disabling extensions
 * - Checking extension health status
 * - Managing extension dependencies
 * - Monitoring extension performance metrics
 *
 * All routes in this file are protected by admin authentication middleware
 * and require superuser permissions.
 */

use Glueful\Http\Router;
use Glueful\Controllers\ExtensionsController;
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

Router::group('/extensions', function () use ($container) {
    /**
     * @route GET /extensions
     * @tag Extensions
     * @summary List extensions
     * @description Retrieves a list of all available extensions with their status
     * @requiresAuth true
     * @response 200 application/json "List of extensions" {extensions:array=[{name:string="Extension name",
     *                                                      description:string="Extension description",
     *                                                      version:string="Extension version",
     *                                                      author:string="Extension author",
     *                                                      enabled:boolean="Whether extension is enabled"}]}
     * @response 403 application/json "Permission denied"
     */
    Router::get('/', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->getExtensions();
    });

    /**
     * @route GET /extensions/catalog
     * @tag Extensions Management
     * @summary Get synchronized extensions catalog
     * @description Retrieves the GitHub extensions catalog synchronized with local extension status,
     * including installation and enablement status for each extension
     * @requiresAuth true
     * @param installed query boolean false "Filter by installation status"
     * @param enabled query boolean false "Filter by enabled status"
     * @param status query string false "Filter by status: available, active, inactive"
     * @param tags query array false "Filter by tags (comma-separated)"
     * @param search query string false "Search in name, description, and tags"
     * @param min_rating query number false "Minimum rating filter"
     * @param publisher query string false "Filter by publisher"
     * @param useCache query boolean false "Whether to use cached catalog data (default: true)"
     * @response 200 application/json "Extensions catalog retrieved successfully" {
     *   data:object={
     *     extensions:array=[{
     *       name:string="Extension name",
     *       displayName:string="Extension display name",
     *       version:string="Extension version",
     *       publisher:string="Extension publisher",
     *       description:string="Extension description",
     *       repository:string="Repository URL",
     *       downloadUrl:string="Download URL",
     *       icon:string="Icon URL",
     *       readme:string="README URL",
     *       tags:array=[string]="Extension tags",
     *       rating:number="Extension rating",
     *       downloads:integer="Download count",
     *       lastUpdated:string="Last update timestamp",
     *       installed:boolean="Whether extension is installed locally",
     *       enabled:boolean="Whether extension is enabled",
     *       status:string="Extension status (available, active, inactive)",
     *       local_metadata:object="Local extension metadata if installed",
     *       actions_available:array=[string]="Available actions for this extension"
     *     }],
     *     metadata:object={
     *       source:string="Data source (github_catalog)",
     *       catalog_url:string="Catalog URL",
     *       synchronized_at:string="Synchronization timestamp",
     *       total_available:integer="Total extensions in catalog",
     *       total_after_filters:integer="Extensions after applying filters",
     *       summary:object={
     *         installed:integer="Number of installed extensions",
     *         enabled:integer="Number of enabled extensions",
     *         available_for_install:integer="Extensions available for installation",
     *         disabled:integer="Installed but disabled extensions"
     *       }
     *     }
     *   },
     *   request_filters:object="Applied filters",
     *   cache_used:boolean="Whether cache was used"
     * }
     * @response 400 application/json "Invalid filter parameters"
     * @response 403 application/json "Permission denied"
     * @response 500 application/json "Failed to fetch catalog or server error"
     */
    Router::get('/catalog', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->getCatalog();
    });

    /**
     * @route POST /extensions/enable
     * @tag Extensions
     * @summary Enable extension
     * @description Enables a specific extension
     * @requiresAuth true
     * @requestBody extension:string="Extension name" {required=extension}
     * @response 200 application/json "Extension enabled successfully"
     * @response 400 application/json "Invalid request format"
     * @response 403 application/json "Permission denied"
     * @response 404 application/json "Extension not found"
     * @response 500 application/json "Failed to enable extension"
     */
    Router::post('/enable', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->enableExtension($request);
    });

    /**
     * @route POST /extensions/disable
     * @tag Extensions
     * @summary Disable extension
     * @description Disables a specific extension
     * @requiresAuth true
     * @requestBody extension:string="Extension name" {required=extension}
     * @response 200 application/json "Extension disabled successfully"
     * @response 400 application/json "Invalid request format"
     * @response 403 application/json "Permission denied"
     * @response 404 application/json "Extension not found"
     * @response 500 application/json "Failed to disable extension"
     */
    Router::post('/disable', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->disableExtension($request);
    });

    /**
     * @route POST /extensions/{name}/health
     * @tag Extensions
     * @summary Get extension health
     * @description Checks the health status of a specific extension
     * @requiresAuth true
     * @requestBody extension:string="Extension name" {required=extension}
     * @response 200 application/json "Extension health status retrieved successfully"
     * @response 400 application/json "Invalid request format"
     * @response 403 application/json "Permission denied"
     * @response 404 application/json "Extension not found"
     * @response 500 application/json "Failed to get extension health"
     */
    Router::post('/{name}/health', function (array $params) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->getExtensionHealth($params);
    });

    /**
     * @route GET /extensions/dependencies
     * @tag Extensions
     * @summary Get extension dependencies
     * @description Retrieves the dependency graph for all extensions
     * @requiresAuth true
     * @response 200 application/json "Extension dependencies retrieved successfully"
     * @response 403 application/json "Permission denied"
     * @response 500 application/json "Failed to get extension dependencies"
     */
    Router::get('/dependencies', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->getExtensionDependencies();
    });

    /**
     * @route GET /extensions/metrics
     * @tag Extensions
     * @summary Get extension metrics
     * @description Retrieves resource usage metrics for enabled extensions
     * @requiresAuth true
     * @response 200 application/json "Extension metrics retrieved successfully"
     * @response 403 application/json "Permission denied"
     * @response 500 application/json "Failed to get extension metrics"
     */
    Router::get('/metrics', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->getExtensionMetrics();
    });

    /**
     * @route POST /extensions/delete
     * @tag Extensions
     * @summary Delete extension
     * @description Completely removes an extension from the filesystem
     * @requiresAuth true
     * @requestBody extension:string="Extension name" force:boolean="Force deletion even if enabled or core (optional)"
     *   {required=extension}
     * @response 200 application/json "Extension deleted successfully"
     * @response 400 application/json "Invalid request format or cannot delete extension"
     * @response 403 application/json "Permission denied"
     * @response 404 application/json "Extension not found"
     * @response 500 application/json "Failed to delete extension"
     */
    Router::post('/delete', function (Request $request) use ($container) {
        $controller = $container->get(ExtensionsController::class);
        return $controller->deleteExtension();
    });
}, requiresAuth: true);
