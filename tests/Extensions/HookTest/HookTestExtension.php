<?php

declare(strict_types=1);

namespace Tests\Extensions\HookTest;

use Glueful\Extensions\BaseExtension;

/**
 * Test Extension for testing hooks functionality
 *
 * This is a mock extension used to verify that hooks are properly called
 * during the extension initialization process.
 */
class HookTestExtension extends BaseExtension
{
    /**
     * Initialize the extension
     *
     * @return void
     */
    public static function initialize(): void
    {
        $GLOBALS['extension_hooks_called']['initialize'] = true;
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
     *
     * @return array Extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'HookTest Extension',
            'description' => 'Test extension for hook functionality',
            'version' => '1.0.0',
            'type' => 'test'
        ];
    }
}
