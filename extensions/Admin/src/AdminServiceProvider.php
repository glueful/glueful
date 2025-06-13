<?php

declare(strict_types=1);

namespace Glueful\Extensions\Admin;

use Glueful\DI\Providers\ExtensionServiceProvider;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Extensions\Admin\AdminController;

/**
 * Admin Extension Service Provider
 *
 * Registers all services and controllers for the Admin extension
 */
class AdminServiceProvider extends ExtensionServiceProvider
{
    /**
     * Get the extension name
     */
    public function getName(): string
    {
        return 'Admin';
    }

    /**
     * Get the extension version
     */
    public function getVersion(): string
    {
        return '0.18.0';
    }

    /**
     * Get the extension description
     */
    public function getDescription(): string
    {
        return 'Provides a comprehensive admin dashboard UI to visualize and manage the API Framework';
    }

    /**
     * Register services with the container
     */
    public function register(ContainerInterface $container): void
    {
        // Register Admin-specific controllers
        // AdminController is specific to the Admin extension
        $this->bindController($container, AdminController::class);
        // Note: The following controllers are already registered by ControllerServiceProvider:
        // - AuthController
        // - PermissionsController
        // - MetricsController
        // - DatabaseController
        // - MigrationsController
        // - JobsController

        // Register any Admin-specific services here
        // Example:
        // $this->bindService($container, AdminService::class, function($container) {
        //     return new AdminService(
        //         $container->get(Connection::class)
        //     );
        // }, true); // true for singleton
    }

    /**
     * Boot the extension
     */
    public function boot(ContainerInterface $container): void
    {
        // Any boot logic that needs to run after all services are registered
        // For example, registering event listeners, etc.
    }

    /**
     * Register extension routes
     */
    public function routes(): void
    {
        // Include the routes file
        $routesFile = __DIR__ . '/routes.php';
        if (file_exists($routesFile)) {
            require_once $routesFile;
        } else {
            throw new \RuntimeException("Admin extension routes file not found: {$routesFile}");
        }
    }

    /**
     * Get extension dependencies
     */
    public function getDependencies(): array
    {
        // Admin extension has no dependencies on other extensions
        return [];
    }
}
