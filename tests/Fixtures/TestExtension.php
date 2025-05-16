<?php
namespace Tests\Fixtures;

use Glueful\Extensions;

/**
 * Test extension fixture for unit testing
 */
class TestExtension extends Extensions
{
    /** @var bool Flag indicating if initialize was called */
    public static bool $initializeCalled = false;

    /** @var bool Flag indicating if registerServices was called */
    public static bool $registerServicesCalled = false;

    /** @var bool Flag indicating if registerMiddleware was called */
    public static bool $registerMiddlewareCalled = false;

    /**
     * Reset all static flags to their default values
     */
    public static function reset(): void
    {
        self::$initializeCalled = false;
        self::$registerServicesCalled = false;
        self::$registerMiddlewareCalled = false;
    }

    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        self::$initializeCalled = true;
    }

    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        self::$registerServicesCalled = true;
    }

    /**
     * Register extension-provided middleware
     */
    public static function registerMiddleware(): void
    {
        self::$registerMiddlewareCalled = true;
    }

    /**
     * Process extension request
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        return [
            'status' => 'success',
            'name' => 'test-extension'
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
