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
//TODO: Add middleware to check if user is authenticated (requiresAuth in Router)
Router::group('/admin',function() use ($controller) {
    Router::post('/login', function (Request $request) use ($controller){
        return $controller->login($request);
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

        Router::get('/table/data', function (Request $request) use ($controller){
            return $controller->getTableData($request);
        });

        Router::post('/table/column/add', function (Request $request) use ($controller){
            return $controller->addColumn($request);
        });

        Router::post('/table/column/drop', function (Request $request) use ($controller){
            return $controller->dropColumn($request);
        });
    });

    Router::get('/permissions', function (Request $request) use ($controller){
        return $controller->getPermissions($request);
    });

    Router::get('/extensions', function (Request $request) use ($controller){
        return $controller->getExtensions($request);
    });

    Router::post('/extension/enable', function (Request $request) use ($controller){
        return $controller->enableExtension($request);
    });

    Router::post('/extension/disable', function (Request $request) use ($controller){
        return $controller->disableExtension($request);
    });

    Router::get('/migrations', function (Request $request) use ($controller){
        return $controller->getMigrations($request);
    });

    Router::get('/migrations/pending', function (Request $request) use ($controller){
        return $controller->getPendingMigrations($request);
    });

    Router::get('/jobs', function (Request $request) use ($controller){
        return $controller->getScheduledJobs($request);
    });

    Router::post('/jobs/run-due', function (Request $request) use ($controller){
        return $controller->runDueJobs($request);
    });

    Router::post('/jobs/run-all', function (Request $request) use ($controller){
        return $controller->runAllJobs($request);
    });
    
    Router::post('/job/run', function (Request $request) use ($controller){
        return $controller->runJob($request);
    });

    Router::post('/job/create-job', function (Request $request) use ($controller){
        return $controller->createJob($request);
    });

    Router::get('/configs', function (Request $request) use ($controller){
        return $controller->getAllConfigs($request);
    });

    Router::get('/configs/{filename}', function (Request $request) use ($controller){
        return $controller->getConfig($request);
    });

    Router::put('/configs/{filename}', function (Request $request) use ($controller){
        return $controller->updateConfig($request);
    });

    Router::post('/configs/create', function (Request $request) use ($controller){
        return $controller->createConfig($request);
    });

});

