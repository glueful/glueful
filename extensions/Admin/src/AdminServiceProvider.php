<?php

declare(strict_types=1);

namespace Glueful\Extensions\Admin;

use Glueful\DI\ServiceProviders\BaseExtensionServiceProvider;
use Glueful\Extensions\Admin\AdminController;

/**
 * Admin Extension Service Provider
 *
 * Registers all services and controllers for the Admin extension
 */
class AdminServiceProvider extends BaseExtensionServiceProvider
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
    protected function registerExtensionServices(): void
    {
        // Register Admin-specific controllers
        // AdminController is specific to the Admin extension
        $this->singleton(AdminController::class);

        // Note: The following controllers are already registered by ControllerServiceProvider:
        // - AuthController
        // - PermissionsController
        // - MetricsController
        // - DatabaseController
        // - MigrationsController
        // - JobsController

        // Register any Admin-specific services here
        // Example:
        // $this->service(AdminService::class, AdminService::class, [$this->ref(Connection::class)]);
    }

    /**
     * Boot the extension
     */
    public function boot(\Glueful\DI\Container $container): void
    {
        // Any boot logic that needs to run after all services are registered
        // For example, registering event listeners, etc.

        // Generate env.json for the SPA
        $this->generateEnvConfig();
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

    /**
     * Generate env.json configuration file for the SPA
     */
    private function generateEnvConfig(): void
    {
        $baseUrl = config('app.paths.api_base_url');
        $appName = config('app.name');
        $domain = config('app.paths.domain');
        $dbEngine = config('database.engine');
        $db = config('database.' . $dbEngine . '.db');
        $docsUrl = config('app.paths.api_docs_url');

        $data = [
            'appName' => $appName,
            'domain' => $domain,
            'apiBaseUrl' => $baseUrl,
            'dbEngine' => $dbEngine,
            'db' => $db,
            'apiDocsUrl' => $docsUrl,
        ];

        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Set the filename to always be "env.json" in the extension's public directory
        $filename = dirname(__DIR__) . "/public/env.json";

        // Write JSON data to a file (this will overwrite any existing file)
        file_put_contents($filename, $jsonData);
    }
}
