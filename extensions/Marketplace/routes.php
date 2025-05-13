<?php
declare(strict_types=1);

use Glueful\Http\Router;
use Glueful\Http\Response;
use Glueful\Auth\JwtGuard;

/**
 * Marketplace Routes
 * 
 * These routes define the API endpoints for the Marketplace extension.
 */

// Middleware to ensure users are authenticated
$authMiddleware = function($request, $next) {
    if (!JwtGuard::isAuthenticated($request)) {
        return Response::unauthorized([
            'error' => 'Authentication required'
        ]);
    }
    
    return $next($request);
};

Router::group('/{{SNAKE_CASE_NAME}}', function() {
    // Get extension status and information
    Router::get('/', function($request) {
        $extension = \Glueful\Helpers\ExtensionsManager::findExtension('Marketplace');
        
        if (!$extension) {
            return Response::serverError([
                'error' => 'Extension not available'
            ]);
        }
        
        $metadata = $extension::getMetadata();
        $health = $extension::checkHealth();
        
        return Response::ok([
            'name' => $metadata['name'],
            'description' => $metadata['description'],
            'version' => $metadata['version'],
            'healthy' => $health['healthy'],
            'status' => $health['healthy'] ? 'operational' : 'issues detected'
        ]);
    });
    
    // Process extension request with custom action
    Router::post('/process', function($request) {
        $body = $request->getParsedBody();
        $action = $body['action'] ?? 'default';
        
        $extension = \Glueful\Helpers\ExtensionsManager::findExtension('Marketplace');
        
        if (!$extension) {
            return Response::serverError([
                'error' => 'Extension not available'
            ]);
        }
        
        $result = $extension::process(
            ['action' => $action],
            $body
        );
        
        if (!($result['success'] ?? false)) {
            return Response::badRequest([
                'error' => $result['error'] ?? 'Processing failed'
            ]);
        }
        
        return Response::ok($result['data'] ?? []);
    });
    
    // Configuration endpoint (for admin access only)
    Router::get('/config', function($request) {
        // Check if user has admin permissions
        if (!JwtGuard::hasPermission($request, 'admin.settings')) {
            return Response::forbidden([
                'error' => 'Admin permissions required'
            ]);
        }
        
        $extension = \Glueful\Helpers\ExtensionsManager::findExtension('Marketplace');
        
        if (!$extension) {
            return Response::serverError([
                'error' => 'Extension not available'
            ]);
        }
        
        // Get config (implementation specific)
        $config = $extension::getConfig();
        
        return Response::ok([
            'config' => $config
        ]);
    });
    
    // Custom action endpoint (example)
    Router::get('/custom-action/{id}', function($request, $id) {
        // Process request with ID parameter
        
        return Response::ok([
            'id' => $id,
            'processed' => true,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    });
    
})->middleware($authMiddleware, ['except' => []]);