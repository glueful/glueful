<?php

declare(strict_types=1);

namespace Glueful\Extensions\{{EXTENSION_NAME}}\Middleware;

use Glueful\Http\Middleware\MiddlewareInterface;

/**
 * {{EXTENSION_NAME}} Middleware
 * 
 * Example middleware for the {{EXTENSION_NAME}} extension
 */
class {{EXTENSION_NAME}}Middleware implements MiddlewareInterface
{
    public function handle($request, $next)
    {
        // Pre-processing logic here
        
        $response = $next($request);
        
        // Post-processing logic here
        
        return $response;
    }
}