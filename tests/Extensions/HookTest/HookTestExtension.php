<?php

declare(strict_types=1);

namespace Tests\Extensions\HookTest;

use Glueful\Extensions;

/**
 * Test Extension for testing hooks functionality
 *
 * This is a mock extension used to verify that hooks are properly called
 * during the extension initialization process.
 */
class HookTestExtension extends Extensions
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
     * Register services provided by this extension
     * 
     * @return void
     */
    public static function registerServices(): void
    {
        $GLOBALS['extension_hooks_called']['registerServices'] = true;
    }
    
    /**
     * Register middleware provided by this extension
     * 
     * @return void
     */
    public static function registerMiddleware(): void
    {
        $GLOBALS['extension_hooks_called']['registerMiddleware'] = true;
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
    
    /**
     * Get extension dependencies
     * 
     * @return array List of extension dependencies
     */
    public static function getDependencies(): array
    {
        return [];
    }
}
