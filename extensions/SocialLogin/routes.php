<?php
declare(strict_types=1);

use Glueful\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;

/**
 * Social Login Routes
 * 
 * This file defines routes for social authentication:
 * - Provider initialization endpoints
 * - OAuth callback handlers
 * - Account management endpoints
 */

// Social Login Initialization Routes
Router::group('/auth/social', function() {
    // Google authentication
    Router::get('/google', function (Request $request) {
        // This will be handled by the GoogleAuthProvider
        // The provider will redirect to Google's OAuth page
        return null;
    });
    
    Router::get('/google/callback', function (Request $request) {
        // This will be handled by the GoogleAuthProvider
        // The provider will process the OAuth callback
        return null;
    });
    
    // Facebook authentication
    Router::get('/facebook', function (Request $request) {
        // This will be handled by the FacebookAuthProvider
        // The provider will redirect to Facebook's OAuth page
        return null;
    });
    
    Router::get('/facebook/callback', function (Request $request) {
        // This will be handled by the FacebookAuthProvider
        // The provider will process the OAuth callback
        return null;
    });
    
    // GitHub authentication
    Router::get('/github', function (Request $request) {
        // This will be handled by the GithubAuthProvider
        // The provider will redirect to GitHub's OAuth page
        return null;
    });
    
    Router::get('/github/callback', function (Request $request) {
        // This will be handled by the GithubAuthProvider
        // The provider will process the OAuth callback
        return null;
    });
});

// User social accounts management (requires authentication)
Router::group('/user/social-accounts', function() {
    // Get connected social accounts
    Router::get('/', function (Request $request) {
        try {
            // Get authenticated user
            $userData = $request->attributes->get('user');
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', 401)->send();
            }
            
            $userUuid = $userData['uuid'];
            
            // Query social accounts
            $connection = new \Glueful\Database\Connection();
            $db = new \Glueful\Database\QueryBuilder(
                $connection->getPDO(), 
                $connection->getDriver()
            );
            
            $accounts = $db->select('social_accounts', [
                    'uuid', 
                    'provider', 
                    'created_at',
                    'updated_at'
                ])
                ->where(['user_uuid' => $userUuid])
                ->get();
            
            return Response::ok($accounts, 'Social accounts retrieved successfully')->send();
            
        } catch (\Exception $e) {
            return Response::error('Failed to retrieve social accounts: ' . $e->getMessage(), 500)->send();
        }
    }, requiresAuth: true);
    
    // Unlink a social account
    Router::delete('/{uuid}', function (Request $request, string $uuid) {
        try {
            // Get authenticated user
            $userData = $request->attributes->get('user');
            
            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', 401)->send();
            }
            
            $userUuid = $userData['uuid'];
            
            // Query social account to check ownership
            $connection = new \Glueful\Database\Connection();
            $db = new \Glueful\Database\QueryBuilder(
                $connection->getPDO(), 
                $connection->getDriver()
            );
            
            $account = $db->select('social_accounts')
                ->where([
                    'uuid' => $uuid,
                    'user_uuid' => $userUuid
                ])
                ->limit(1)
                ->get();
            
            if (empty($account)) {
                return Response::error('Social account not found or not owned by user', 404)->send();
            }
            // Delete the social account
            $deleted = $db->delete('social_accounts', [
                'uuid' => $uuid,
                'user_uuid' => $userUuid
            ]);
            
            if (!$deleted) {
                return Response::error('Failed to unlink social account', 500)->send();
            }
            
            return Response::ok(null, 'Social account unlinked successfully')->send();
            
        } catch (\Exception $e) {
            return Response::error('Failed to unlink social account: ' . $e->getMessage(), 500)->send();
        }
    }, requiresAuth: true);
});