<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * {{EXTENSION_NAME}} Extension (Basic)
 *
 * Simple extension template for getting started with Glueful extensions.
 *
 * @description {{EXTENSION_DESCRIPTION}}
 * @version 1.0.0
 * @author {{AUTHOR_NAME}}
 */
class {{EXTENSION_NAME}} extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];
    
    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/src/config.php')) {
            self::$config = require __DIR__ . '/src/config.php';
        }
    }
    
    /**
     * Register extension-provided services
     */
    public static function registerServices($container = null): void
    {
        // Register any services provided by this extension
    }
    
    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // Register any middleware components
    }
    
    /**
     * Process extension requests
     * 
     * @param array $queryParams GET parameters
     * @param array $bodyParams POST parameters
     * @return array Response data
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        return [
            'success' => true,
            'data' => [
                'extension' => '{{EXTENSION_NAME}}',
                'message' => '{{EXTENSION_NAME}} is working properly',
                'version' => '1.0.0'
            ]
        ];
    }
    
    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => '{{EXTENSION_NAME}}',
            'description' => '{{EXTENSION_DESCRIPTION}}',
            'version' => '1.0.0',
            'author' => '{{AUTHOR_NAME}}',
            'type' => '{{EXTENSION_TYPE}}',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => []
            ]
        ];
    }
    
    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];
        
        // Basic health checks
        if (!isset(self::$config['enabled']) || !self::$config['enabled']) {
            $healthy = false;
            $issues[] = 'Extension is disabled in configuration';
        }
        
        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }

    // Optional methods - uncomment and implement as needed:
    
    // public static function getEventListeners(): array
    // {
    //     return [];
    // }
    
    // public static function validateSecurity(): array
    // {
    //     return [
    //         'permissions' => [],
    //         'sandbox' => false,
    //         'network_access' => false,
    //         'database_access' => false
    //     ];
    // }
    
    // public static function getAssets(): array
    // {
    //     return [
    //         'css' => [],
    //         'js' => [],
    //         'images' => []
    //     ];
    // }
}