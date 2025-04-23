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
    // Google authentication routes
    // GET - initiates web OAuth flow
    Router::get('/google', function (Request $request) {
        // Initialize the Google auth provider
        $googleProvider = new \Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider();
        
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $googleProvider->authenticate($request);
            
            // If we reach this point, something went wrong as we should have been redirected
            $error = $googleProvider->getError() ?: "Failed to initiate Google authentication";
            return Response::error($error, 500)->send();
        } catch (\Exception $e) {
            error_log("Google authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize Google authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // POST - handles mobile token verification
    Router::post('/google', function (Request $request) {
        try {
            // Get the ID token from the request
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;
            
            if (empty($idToken)) {
                return Response::error('Missing ID token', 400)->send();
            }
            
            // Create the Google provider
            $googleProvider = new \Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider();
            
            // Verify the ID token
            $userData = $googleProvider->verifyNativeToken($idToken);
            
            if (!$userData) {
                $error = $googleProvider->getError() ?: 'Failed to verify Google ID token';
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $googleProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with Google')->send();
            
        } catch (\Exception $e) {
            error_log('Google token verification error: ' . $e->getMessage());
            return Response::error('Failed to authenticate with Google: ' . $e->getMessage(), 500)->send();
        }
    });
    
    // Google callback only for web OAuth flows
    Router::get('/google/callback', function (Request $request) {
        try {
            // Initialize the Google auth provider
            $googleProvider = new \Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider();
            
            // Process the OAuth callback
            $userData = $googleProvider->authenticate($request);
            
            if (!$userData) {
                $error = $googleProvider->getError() ?: "Failed to authenticate with Google";
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $googleProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with Google")->send();
        } catch (\Exception $e) {
            error_log("Google callback error: " . $e->getMessage());
            return Response::error("Failed to process Google authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // Facebook authentication routes
    // GET - initiates web OAuth flow
    Router::get('/facebook', function (Request $request) {
        // Initialize the Facebook auth provider
        $facebookProvider = new \Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider();
        
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $facebookProvider->authenticate($request);
            
            // If we reach this point, something went wrong as we should have been redirected
            $error = $facebookProvider->getError() ?: "Failed to initiate Facebook authentication";
            return Response::error($error, 500)->send();
        } catch (\Exception $e) {
            error_log("Facebook authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize Facebook authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // POST - handles mobile token verification
    Router::post('/facebook', function (Request $request) {
        try {
            // Get the access token from the request
            $requestData = json_decode($request->getContent(), true);
            $accessToken = $requestData['access_token'] ?? null;
            
            if (empty($accessToken)) {
                return Response::error('Missing access token', 400)->send();
            }
            
            // Create the Facebook provider
            $facebookProvider = new \Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider();
            
            // Verify the access token
            $userData = $facebookProvider->verifyNativeToken($accessToken);
            
            if (!$userData) {
                $error = $facebookProvider->getError() ?: 'Failed to verify Facebook access token';
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $facebookProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with Facebook')->send();
            
        } catch (\Exception $e) {
            error_log('Facebook token verification error: ' . $e->getMessage());
            return Response::error('Failed to authenticate with Facebook: ' . $e->getMessage(), 500)->send();
        }
    });
    
    // Facebook callback only for web OAuth flows
    Router::get('/facebook/callback', function (Request $request) {
        try {
            // Initialize the Facebook auth provider
            $facebookProvider = new \Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider();
            
            // Process the OAuth callback
            $userData = $facebookProvider->authenticate($request);
            
            if (!$userData) {
                $error = $facebookProvider->getError() ?: "Failed to authenticate with Facebook";
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $facebookProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with Facebook")->send();
        } catch (\Exception $e) {
            error_log("Facebook callback error: " . $e->getMessage());
            return Response::error("Failed to process Facebook authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // GitHub authentication routes
    // GET - initiates web OAuth flow
    Router::get('/github', function (Request $request) {
        // Initialize the GitHub auth provider
        $githubProvider = new \Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider();
        
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $githubProvider->authenticate($request);
            
            // If we reach this point, something went wrong as we should have been redirected
            $error = $githubProvider->getError() ?: "Failed to initiate GitHub authentication";
            return Response::error($error, 500)->send();
        } catch (\Exception $e) {
            error_log("GitHub authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize GitHub authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // POST - handles mobile token verification
    Router::post('/github', function (Request $request) {
        try {
            // Get the access token from the request
            $requestData = json_decode($request->getContent(), true);
            $accessToken = $requestData['access_token'] ?? null;
            
            if (empty($accessToken)) {
                return Response::error('Missing access token', 400)->send();
            }
            
            // Create the GitHub provider
            $githubProvider = new \Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider();
            
            // Verify the access token
            $userData = $githubProvider->verifyNativeToken($accessToken);
            
            if (!$userData) {
                $error = $githubProvider->getError() ?: 'Failed to verify GitHub access token';
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $githubProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with GitHub')->send();
            
        } catch (\Exception $e) {
            error_log('GitHub token verification error: ' . $e->getMessage());
            return Response::error('Failed to authenticate with GitHub: ' . $e->getMessage(), 500)->send();
        }
    });
    
    // GitHub callback only for web OAuth flows
    Router::get('/github/callback', function (Request $request) {
        try {
            // Initialize the GitHub auth provider
            $githubProvider = new \Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider();
            
            // Process the OAuth callback
            $userData = $githubProvider->authenticate($request);
            
            if (!$userData) {
                $error = $githubProvider->getError() ?: "Failed to authenticate with GitHub";
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $githubProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with GitHub")->send();
        } catch (\Exception $e) {
            error_log("GitHub callback error: " . $e->getMessage());
            return Response::error("Failed to process GitHub authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // Apple authentication routes
    // GET - initiates web OAuth flow
    Router::get('/apple', function (Request $request) {
        // Initialize the Apple auth provider
        $appleProvider = new \Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider();
        
        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $appleProvider->authenticate($request);
            
            // If we reach this point, something went wrong as we should have been redirected
            $error = $appleProvider->getError() ?: "Failed to initiate Apple authentication";
            return Response::error($error, 500)->send();
        } catch (\Exception $e) {
            error_log("Apple authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize Apple authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // POST - handles mobile token verification
    Router::post('/apple', function (Request $request) {
        try {
            // Get the ID token from the request
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;
            
            if (empty($idToken)) {
                return Response::error('Missing ID token', 400)->send();
            }
            
            // Create the Apple provider
            $appleProvider = new \Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider();
            
            // Verify the ID token
            $userData = $appleProvider->verifyNativeToken($idToken);
            
            if (!$userData) {
                $error = $appleProvider->getError() ?: 'Failed to verify Apple ID token';
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $appleProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], 'Successfully authenticated with Apple')->send();
            
        } catch (\Exception $e) {
            error_log('Apple token verification error: ' . $e->getMessage());
            return Response::error('Failed to authenticate with Apple: ' . $e->getMessage(), 500)->send();
        }
    });
    
    // Apple callback for web OAuth flows - using POST as Apple requires form_post response mode
    Router::post('/apple/callback', function (Request $request) {
        try {
            // Initialize the Apple auth provider
            $appleProvider = new \Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider();
            
            // Process the OAuth callback
            $userData = $appleProvider->authenticate($request);
            
            if (!$userData) {
                $error = $appleProvider->getError() ?: "Failed to authenticate with Apple";
                return Response::error($error, 401)->send();
            }
            
            // Generate authentication tokens
            $tokens = $appleProvider->generateTokens($userData);
            
            // Return tokens along with user data
            return Response::ok([
                'user' => $userData,
                'tokens' => $tokens
            ], "Successfully authenticated with Apple")->send();
        } catch (\Exception $e) {
            error_log("Apple callback error: " . $e->getMessage());
            return Response::error("Failed to process Apple authentication: " . $e->getMessage(), 500)->send();
        }
    });
    
    // Remove the /native group since we're using HTTP methods instead
    
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