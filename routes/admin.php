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
use Glueful\Controllers\AdminController;
use Symfony\Component\HttpFoundation\Request;

$controller = new AdminController();

Router::group('/admin', function() use ($controller) {
    // Public routes - outside of any middleware
    Router::post('/login', function (Request $request) use ($controller){
        return $controller->login($request);
    });

    Router::post('/logout', function (Request $request) use ($controller){
        return $controller->logout($request);
    });

    Router::group('/db', function() use ($controller) {
        Router::get('/tables', function (Request $request) use ($controller){
            return $controller->getTables($request);
        });

        Router::post('/table/create', function (Request $request) use ($controller){
            return $controller->createTable($request);
        });

        Router::post('/table/drop', function (Request $request) use ($controller){
            return $controller->dropTable($request);
        });

        Router::get('/table/size', function (Request $request) use ($controller){
            return $controller->getTableSize($request);
        });

        Router::get('/table/{name}', function (array $params) use ($controller){
            // $params = $request->getRouteParams();
            return $controller->getTableData($params);
        });

        Router::post('/table/column/add', function (Request $request) use ($controller){
            return $controller->addColumn($request);
        });

        Router::post('/table/column/drop', function (Request $request) use ($controller){
            return $controller->dropColumn($request);
        });
    }, requiresAdminAuth: true);

    
    Router::group('/extensions', function() use ($controller) {
        Router::get('/', function (Request $request) use ($controller){
            return $controller->getExtensions($request);
        });

        Router::post('/enable', function (Request $request) use ($controller){
            return $controller->enableExtension($request);
        });

        Router::post('/disable', function (Request $request) use ($controller){
            return $controller->disableExtension($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/migrations', function() use ($controller) {
        Router::get('/', function (Request $request) use ($controller){
            return $controller->getMigrations($request);
        });

        Router::get('/pending', function (Request $request) use ($controller){
            return $controller->getPendingMigrations($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/jobs', function() use ($controller) {
        Router::get('/', function (Request $request) use ($controller){
            return $controller->getScheduledJobs($request);
        });

        Router::post('/run-due', function (Request $request) use ($controller){
            return $controller->runDueJobs($request);
        });

        Router::post('/run-all', function (Request $request) use ($controller){
            return $controller->runAllJobs($request);
        });

        Router::post('/run', function (Request $request) use ($controller){
            return $controller->runJob($request);
        });

        Router::post('/create-job', function (Request $request) use ($controller){
            return $controller->createJob($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/configs', function() use ($controller) {
        Router::get('/', function (Request $request) use ($controller){
            return $controller->getAllConfigs($request);
        });

        Router::get('/{filename}', function (Request $request) use ($controller){
            return $controller->getConfig($request);
        });

        Router::put('/{filename}', function (Request $request) use ($controller){
            return $controller->updateConfig($request);
        });

        Router::post('/create', function (Request $request) use ($controller){
            return $controller->createConfig($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/permissions', function() use ($controller) {
        Router::get('/', function (Request $request) use ($controller){
            return $controller->getPermissions($request);
        });

        Router::post('/create', function (Request $request) use ($controller) {
            return $controller->createPermission($request);
        });
        
        Router::put('/update', function (Request $request) use ($controller) {
            return $controller->updatePermission($request);
        });
        
        Router::post('/assign-to-role', function (Request $request) use ($controller) {
            return $controller->assignPermissionsToRole($request);
        });
        
        Router::put('/update-role-permissions', function (Request $request) use ($controller) {
            return $controller->updateRolePermissions($request);
        });
    }, requiresAdminAuth: true);

    Router::group('/roles', function() use ($controller) {
        Router::post('/assign-to-user', function (Request $request) use ($controller) {
            return $controller->assignRolesToUser($request);
        });
        
        Router::put('/remove-user-roles', function (Request $request) use ($controller) {
            return $controller->removeUserRole($request);
        });
    }, requiresAdminAuth: true);
    
});

