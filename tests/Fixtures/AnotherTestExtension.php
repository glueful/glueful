<?php
namespace Tests\Fixtures;

use Glueful\Extensions;

/**
 * Second test extension fixture for unit testing
 */
class AnotherTestExtension extends Extensions
{
    /** @var bool Flag indicating if initialize was called */
    public static bool $initializeCalled = false;
    
    /** @var bool Flag indicating if registerServices was called */
    public static bool $registerServicesCalled = false;
    
    /** @var array Holds configuration values */
    public static array $config = [];
    
    /**
     * Reset all static properties to their default values
     */
    public static function reset(): void
    {
        self::$initializeCalled = false;
        self::$registerServicesCalled = false;
        self::$config = [];
    }
    
    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        self::$initializeCalled = true;
        
        // Load configuration
        $config = include dirname(__DIR__, 2) . '/config/extensions.php';
        if (isset($config['config']['AnotherTestExtension'])) {
            self::$config = $config['config']['AnotherTestExtension'];
        }
    }
    
    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        self::$registerServicesCalled = true;
    }
    
    /**
     * Process extension request
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        return [
            'status' => 'success',
            'name' => 'another-test-extension',
            'config' => self::$config
        ];
    }
    
    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'Another Test Extension',
            'description' => 'A second test extension for unit testing',
            'version' => '1.0.0',
            'author' => 'Test Author',
            'requires' => [
                'glueful' => '>=0.10.0',
                'TestExtension' => '>=1.0.0'
            ],
            'settings' => [
                'enabled_feature' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Enable the feature'
                ],
                'api_key' => [
                    'type' => 'string',
                    'default' => '',
                    'description' => 'API key for external service'
                ]
            ]
        ];
    }
}
