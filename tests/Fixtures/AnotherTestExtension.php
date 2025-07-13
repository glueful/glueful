<?php

namespace Tests\Fixtures;

use Glueful\Extensions\BaseExtension;

/**
 * Second test extension fixture for unit testing
 */
class AnotherTestExtension extends BaseExtension
{
    /** @var bool Flag indicating if initialize was called */
    public static bool $initializeCalled = false;


    /** @var array Holds configuration values */
    public static array $config = [];

    /**
     * Reset all static properties to their default values
     */
    public static function reset(): void
    {
        self::$initializeCalled = false;
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
     * Check extension health
     *
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        return [
            'healthy' => true,
            'issues' => [],
            'metrics' => [
                'memory_usage' => memory_get_usage(true),
                'execution_time' => 0,
                'database_queries' => 0,
                'cache_usage' => 0
            ]
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
