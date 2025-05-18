<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * {{EXTENSION_NAME}} Extension
 *
 * @description {{EXTENSION_DESCRIPTION}}
 * @version 1.0.0
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
        if (file_exists(__DIR__ . '/config.php')) {
            self::$config = require __DIR__ . '/config.php';
        }
    }
    
    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
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
                'message' => '{{EXTENSION_NAME}} is working properly'
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
        
        // Add your health checks here
        
        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }
}