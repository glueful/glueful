<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\Traits\ExtensionDocumentationTrait;

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
    use ExtensionDocumentationTrait;

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
     * Check extension health
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
