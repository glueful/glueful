<?php
declare(strict_types=1);

use Glueful\Http\Router;
use Glueful\Http\Response;
use Glueful\Auth\JwtGuard;

/**
 * {{EXTENSION_NAME}} Routes
 * 
 * These routes provide payment gateway functionality.
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
    // List available payment gateways
    Router::get('/gateways', function($request) {
        $extension = \Glueful\Helpers\ExtensionsManager::findExtension('{{EXTENSION_NAME}}');
        
        if (!$extension) {
            return Response::serverError([
                'error' => 'Payment extension not available'
            ]);
        }
        
        $result = $extension::process(['action' => 'get-gateways'], []);
        
        return Response::ok($result['data'] ?? []);
    });
    
    // Process payment
    Router::post('/process', function($request) {
        $body = $request->getParsedBody();
        $gateway = $body['gateway'] ?? '';
        
        if (empty($gateway)) {
            return Response::badRequest([
                'error' => 'Payment gateway is required'
            ]);
        }
        
        $extension = \Glueful\Helpers\ExtensionsManager::findExtension('{{EXTENSION_NAME}}');
        
        if (!$extension) {
            return Response::serverError([
                'error' => 'Payment extension not available'
            ]);
        }
        
        $result = $extension::process(
            ['action' => 'process-payment', 'gateway' => $gateway],
            $body
        );
        
        if (!($result['success'] ?? false)) {
            return Response::badRequest([
                'error' => $result['error'] ?? 'Payment processing failed'
            ]);
        }
        
        return Response::ok($result['data'] ?? []);
    });
    
    // Payment webhook (not protected by auth middleware)
    Router::post('/webhook/{gateway}', function($request, $gateway) {
        $body = $request->getParsedBody();
        
        // Process webhook payload
        // This would typically verify the signature and update payment status
        
        return Response::ok([
            'received' => true,
            'gateway' => $gateway
        ]);
    });
    
    // Get payment status
    Router::get('/status/{transactionId}', function($request, $transactionId) {
        // In a real implementation, look up transaction status
        
        return Response::ok([
            'transaction_id' => $transactionId,
            'status' => 'pending', // Would be fetched from database
            'created_at' => date('Y-m-d H:i:s')
        ]);
    });
    
})->middleware($authMiddleware, ['except' => ['POST /{{SNAKE_CASE_NAME}}/webhook/{gateway}']]);