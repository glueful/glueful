<?php
declare(strict_types=1);

use Glueful\Http\Router;
use Glueful\Http\Response;

/**
 * {{EXTENSION_NAME}} Routes
 */
Router::group('/{{SNAKE_CASE_NAME}}', function() {
    // Authentication endpoints
    Router::post('/authenticate', function($request) {
        $credentials = $request->getParsedBody();
        
        // Validate required fields
        if (!isset($credentials['username']) || !isset($credentials['password'])) {
            return Response::badRequest([
                'error' => 'Username and password are required'
            ]);
        }
        
        // Process authentication through your provider
        try {
            $authManager = \Glueful\Auth\AuthManager::getInstance();
            $result = $authManager->authenticate('{{LOWER_NAME}}', $credentials);
            
            return Response::ok([
                'success' => true,
                'token' => $result['token'] ?? null,
                'user' => $result['user'] ?? null
            ]);
        } catch (\Exception $e) {
            return Response::unauthorized([
                'error' => $e->getMessage()
            ]);
        }
    });
    
    Router::get('/status', function($request) {
        // Check authentication status
        return Response::ok([
            'authenticated' => true,
            'provider' => '{{EXTENSION_NAME}}',
            'status' => 'active'
        ]);
    });
    
    // Additional routes as needed
});