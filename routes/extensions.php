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

Router::group('/extensions', function() use ($container) {
    /**
     * @route GET /extensions
     * @tag Extensions
     * @summary List extensions
     * @description Retrieves a list of all available extensions with their status
     * @requiresAuth true
     * @response 200 application/json "List of extensions" {extensions:array=[{name:string="Extension name", description:string="Extension description", version:string="Extension version", author:string="Extension author", enabled:boolean="Whether extension is enabled"}]}
     * @response 403 application/json "Permission denied"
     */
    Router::get('/', function (Request $request) use ($container){
        $controller = $container->get(ExtensionsController::class);
        return $controller->getExtensions();
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
    Router::post('/enable', function (Request $request) use ($container){
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
    Router::post('/disable', function (Request $request) use ($container){
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
    Router::post('/{name}/health', function (array $params) use ($container){
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
    Router::get('/dependencies', function (Request $request) use ($container){
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
    Router::get('/metrics', function (Request $request) use ($container){
        $controller = $container->get(ExtensionsController::class);
        return $controller->getExtensionMetrics();
    });

    /**
     * @route POST /extensions/delete
     * @tag Extensions
     * @summary Delete extension
     * @description Completely removes an extension from the filesystem
     * @requiresAuth true
     * @requestBody extension:string="Extension name" force:boolean="Force deletion even if enabled or core (optional)" {required=extension}
     * @response 200 application/json "Extension deleted successfully"
     * @response 400 application/json "Invalid request format or cannot delete extension"
     * @response 403 application/json "Permission denied"
     * @response 404 application/json "Extension not found"
     * @response 500 application/json "Failed to delete extension"
     */
    Router::post('/delete', function (Request $request) use ($container){
        $controller = $container->get(ExtensionsController::class);
        return $controller->deleteExtension();
    });
});