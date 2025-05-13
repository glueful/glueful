<?php
declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Auth\AuthBootstrap;
use Glueful\Extensions\{{EXTENSION_NAME}}\Providers\{{EXTENSION_NAME}}AuthProvider;
use Glueful\Helpers\ExtensionsManager;

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
        
        // Register authentication provider
        self::registerAuthProvider();
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
        // Handle extension-specific requests
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
            'type' => 'optional', // 'core' or 'optional'
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => []
            ],
            'category' => 'authentication',
            'features' => [
                'Custom authentication provider',
                'API endpoints for authentication',
                'Integration with Glueful authentication system'
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
        
        // Check configuration
        if (empty(self::$config)) {
            $healthy = false;
            $issues[] = 'Missing configuration file';
        }
        
        // Check provider class exists
        if (!class_exists('Glueful\Extensions\{{EXTENSION_NAME}}\Providers\{{EXTENSION_NAME}}AuthProvider')) {
            $healthy = false;
            $issues[] = '{{EXTENSION_NAME}}AuthProvider class not found';
        }
        
        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }
    
    /**
     * Register authentication provider
     */
    private static function registerAuthProvider(): void
    {
        // Get Auth bootstrap
        $authBootstrap = AuthBootstrap::getInstance();
        
        // Register the authentication provider
        $authBootstrap->registerAuthProvider(
            '{{LOWER_NAME}}',
            {{EXTENSION_NAME}}AuthProvider::class
        );
    }
}