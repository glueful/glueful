<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Http\{Response, Router};

/**
 * Base Extensions Class
 * 
 * Abstract base class for all API extensions. Provides common functionality
 * and structure for extending the API with custom endpoints and features.
 */
abstract class Extensions implements IExtensions 
{
    /**
     * Initialize extension routes
     * 
     * Register extension-specific routes with the API router.
     * Each extension should override this method to define its routes.
     * 
     * @param Router $router The API router instance
     * @return array Route registration results
     */
    public static function initializeRoutes(Router $router): array 
    {
        return [];
    }

    /**
     * Generate success response
     * 
     * Creates standardized success response format.
     * 
     * @param array $data Response payload
     * @param int $code HTTP status code
     * @return array Formatted success response
     */
    protected static function respond(array $data, int $code = 200): array 
    {
        return [
            'success' => $code === 200,
            'code' => $code,
            'data' => $data
        ];
    }

    /**
     * Generate error response
     * 
     * Creates standardized error response format.
     * 
     * @param string $message Error message
     * @param int $code HTTP error code
     * @return array Formatted error response
     */
    protected static function error(string $message, int $code = 400): array 
    {
        return [
            'success' => false,
            'code' => $code,
            'error' => $message
        ];
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