<?php
declare(strict_types=1);

namespace Glueful;

/**
 * Base Extensions Class
 * 
 * Abstract base class for all API extensions. Provides common functionality
 * and defines the extension lifecycle methods.
 * 
 * Extensions can implement:
 * - initialize() - Setup logic run when extension is loaded
 * - registerServices() - Register extension services with the service container
 * - registerMiddleware() - Register extension middleware with the middleware pipeline
 * 
 * @package Glueful
 */
abstract class Extensions implements IExtensions 
{
    /**
     * Initialize extension
     * 
     * This method is called by ExtensionsManager when loading extensions.
     * Extensions should override this method to perform setup tasks.
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Override in child classes
    }
    
    /**
     * Register extension-provided services
     * 
     * This method is called by ExtensionsManager when loading extensions.
     * Extensions should override this method to register their services
     * with the application's service container.
     * 
     * @return void
     */
    public static function registerServices(): void
    {
        // Override in child classes
    }
    
    /**
     * Register extension-provided middleware
     * 
     * This method is called by ExtensionsManager when loading extensions.
     * Extensions should override this method to register middleware
     * with the application's middleware pipeline.
     * 
     * @return void
     */
    public static function registerMiddleware(): void
    {
        // Override in child classes
    }

    /**
     * Process extension request
     * 
     * Main request handler for extension endpoints.
     * Should be overridden by child classes to implement specific logic.
     * 
     * @param array $getParams Query parameters
     * @param array $postParams Post data
     * @return array Extension response
     */
    public static function process(array $getParams, array $postParams): array
    {
        return [];
    }
}
?>