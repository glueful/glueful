<?php

namespace Tests\Fixtures;

use Glueful\Extensions\BaseExtension;

/**
 * Test extension fixture for unit testing
 */
class TestExtension extends BaseExtension
{
    /** @var bool Flag indicating if initialize was called */
    public static bool $initializeCalled = false;


    /**
     * Reset all static flags to their default values
     */
    public static function reset(): void
    {
        self::$initializeCalled = false;
    }

    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        self::$initializeCalled = true;
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
            'name' => 'Test Extension',
            'description' => 'A test extension for unit testing',
            'version' => '1.0.0',
            'author' => 'Test Author',
            'requires' => [
                'glueful' => '>=0.10.0'
            ],
            'settings' => [
                'test_setting' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'A test setting'
                ]
            ]
        ];
    }
}
