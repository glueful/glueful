<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\BaseExtension;
use Glueful\Extensions\Traits\ExtensionDocumentationTrait;

/**
 * Admin Extension
 *
 * @description Provides a comprehensive admin dashboard UI to visualize and manage the API Framework,
 *              monitor system health, and perform administrative actions through a user-friendly interface
 * @version 1.0.0
 * @author Glueful Extensions Team
 */
class Admin extends BaseExtension
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
}
