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

// use Glueful\Http\Router;
// use Glueful\Controllers\AdminController;

// $controller = new AdminController();

// // Admin Authentication
// Router::post('/api/admin/login', [AdminController::class, 'login']);

// // Database Management
// Router::post('/api/admin/db/tables', [AdminController::class, 'getTables']);
// Router::post('/api/admin/db/table/create', [AdminController::class, 'createTable']);
// Router::post('/api/admin/db/table/drop', [AdminController::class, 'dropTable']);
// Router::post('/api/admin/db/table/size', [AdminController::class, 'getTableSize']);
// Router::post('/api/admin/db/table/data', [AdminController::class, 'getTableData']);
// Router::post('/api/admin/db/table/column/add', [AdminController::class, 'addColumn']);
// Router::post('/api/admin/db/table/column/drop', [AdminController::class, 'dropColumn']);

// // Permissions Management
// Router::post('/api/admin/permissions', [AdminController::class, 'getPermissions']);

// // Extension Management
// Router::post('/api/admin/extensions', [AdminController::class, 'getExtensions']);
// Router::post('/api/admin/extension/enable', [AdminController::class, 'enableExtension']);
// Router::post('/api/admin/extension/disable', [AdminController::class, 'disableExtension']);

// // Migration Management
// Router::post('/api/admin/migrations', [AdminController::class, 'getMigrations']);
// Router::post('/api/admin/migrations/pending', [AdminController::class, 'getPendingMigrations']);

// // Scheduled Jobs Management
// Router::post('/api/admin/jobs', [AdminController::class, 'getScheduledJobs']);
// Router::post('/api/admin/jobs/run-due', [AdminController::class, 'runDueJobs']);
// Router::post('/api/admin/jobs/run', [AdminController::class, 'runJob']);
