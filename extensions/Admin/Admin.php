<?php

    declare(strict_types=1);

    namespace Glueful\Extensions;

/**
 * Admin Extension
 *
 * @description Provides a comprehensive admin dashboard UI to visualize and manage the API Framework,
 *              monitor system health, and perform administrative actions through a user-friendly interface
 * @version 1.0.0
 * @author Glueful Extensions Team
 */
class Admin extends \Glueful\Extensions
{
    /**
     * Extension configuration
     */
    private static array $config = [];

    /**
     * Initialize extension
     *
     * Called when the extension is loaded
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/config.php')) {
            self::$config = require __DIR__ . '/config.php';
        }

        // Additional initialization code here
    }

    /**
     * Get the extension's service provider
     *
     * @return \Glueful\DI\Interfaces\ServiceProviderInterface
     */
    public static function getServiceProvider(): \Glueful\DI\Interfaces\ServiceProviderInterface
    {
        return new \Glueful\Extensions\Admin\AdminServiceProvider();
    }

    /**
     * Register extension-provided middleware
     *
     * @return void
     */
    public static function registerMiddleware(): void
    {
        // Register middleware here
    }

    /**
     * Process extension request
     *
     * Main request handler for extension endpoints.
     *
     * @param array $getParams Query parameters
     * @param array $postParams Post data
     * @return array Extension response
     */
    public static function process(array $getParams, array $postParams): array
    {
        // Example implementation of the process method
        $action = $getParams['action'] ?? 'default';

        return match ($action) {
            'greet' => [
                'success' => true,
                'code' => 200,
                'data' => [
                    'message' => self::greet($getParams['name'] ?? 'World')
                ]
            ],
            'default' => [
                'success' => true,
                'code' => 200,
                'data' => [
                    'extension' => 'Admin',
                    'message' => 'Extension is working properly'
                ]
            ],
            default => [
                'success' => false,
                'code' => 400,
                'error' => 'Unknown action: ' . $action
            ]
        };
    }

    /**
     * Get extension metadata
     *
     * This method follows the Glueful Extension Metadata Standard.
     *
     * @return array Extension metadata for admin interface and marketplace
     */
    public static function getMetadata(): array
    {
        return [
            // Required fields
            'name' => 'Admin',
            'description' => 'Provides a comprehensive admin dashboard UI to visualize and manage the API Framework, ' .
                             'monitor system health, and perform administrative actions through a ' .
                             'user-friendly interface',
            'version' => '0.18.0',
            'author' => 'Glueful Extensions Team',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => [],
                'dependencies' => []
            ],

            // Optional fields - uncomment and customize as needed
            // 'homepage' => 'https://example.com/Admin',
            // 'documentation' => 'https://docs.example.com/extensions/Admin',
            // 'license' => 'MIT',
            // 'keywords' => ['keyword1', 'keyword2', 'keyword3'],
            // 'category' => 'utilities',

            'features' => [
                'Interactive API visualization dashboard with metrics and analytics',
                'System health monitoring and performance tracking',
                'Extension management with activation/deactivation capabilities',
                'Database migrations and schema management',
                'User and permission management interface',
                'API testing and endpoint exploration tools'
            ],

            'compatibility' => [
                'browsers' => ['Chrome', 'Firefox', 'Safari', 'Edge'],
                'environments' => ['production', 'development'],
                'conflicts' => []
            ],

            'settings' => [
                'configurable' => true,
                'has_admin_ui' => false,
                'setup_required' => false,
                'default_config' => [
                    // Default configuration values
                    'setting1' => 'default_value',
                    'setting2' => true
                ]
            ],

            'support' => [
                'email' => 'your.email@example.com',
                'issues' => 'https://github.com/yourusername/Admin/issues'
            ]
        ];
    }

    /**
     * Get extension dependencies
     *
     * Returns a list of other extensions this extension depends on.
     *
     * @return array List of extension dependencies
     */
    public static function getDependencies(): array
    {
        // By default, get dependencies from metadata
        $metadata = self::getMetadata();
        return $metadata['requires']['extensions'] ?? [];
    }

    /**
     * Check environment-specific configuration
     *
     * Determines if the extension should be enabled in the current environment.
     *
     * @param string $environment Current environment (dev, staging, production)
     * @return bool Whether the extension should be enabled in this environment
     */
    public static function isEnabledForEnvironment(string $environment): bool
    {
        // By default, enable in all environments
        // Override this method to enable only in specific environments
        return true;
    }

    /**
     * Validate extension health
     *
     * Checks if the extension is functioning correctly.
     *
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];

        // Example health check - verify config is loaded correctly
        if (empty(self::$config) && file_exists(__DIR__ . '/config.php')) {
            $healthy = false;
            $issues[] = 'Configuration could not be loaded properly';
        }

        // Add your own health checks here

        return [
            'healthy' => $healthy,
            'issues' => $issues,
            'metrics' => [
                'memory_usage' => memory_get_usage(true),
                'execution_time' => 0, // You could track this with microtime()
                'database_queries' => 0, // Track queries if your extension uses the database
                'cache_usage' => 0 // Track cache usage if applicable
            ]
        ];
    }

    /**
     * Get extension resource usage
     *
     * Returns information about resources used by this extension.
     *
     * @return array Resource usage metrics
     */
    public static function getResourceUsage(): array
    {
        // Customize with your own resource metrics
        return [
            'memory_usage' => memory_get_usage(true),
            'execution_time' => 0,
            'database_queries' => 0,
            'cache_usage' => 0
        ];
    }

    /**
     * Get extension configuration
     *
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Set extension configuration
     *
     * @param array $config New configuration
     * @return void
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Example extension method
     *
     * @param string $name Name parameter
     * @return string Greeting message
     */
    public static function greet(string $name): string
    {
        return "Hello, {$name}! Welcome to the Admin extension.";
    }
}
