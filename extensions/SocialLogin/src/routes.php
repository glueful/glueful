<?php

declare(strict_types=1);

use Glueful\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Constants\ErrorCodes;

/*
 * Social Login Routes
 *
 * This file defines routes for social authentication:
 * - Provider initialization endpoints
 * - OAuth callback handlers
 * - Account management endpoints
 */

// Social Login Initialization Routes
Router::group('/auth/social', function () {
    /**
     * @route GET /auth/social/google
     * @summary Google OAuth Authentication
     * @description Initiates the OAuth flow with Google for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to Google's OAuth authorization page"
     */
    Router::get('/google', function (Request $request) {
        // Get the Google auth provider from DI container
        $container = app();
        $googleProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider::class);

        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $googleProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $googleProvider->getError() ?: "Failed to initiate Google authentication";
            return Response::error($error, ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        } catch (\Exception $e) {
            error_log("Google authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize Google authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route POST /auth/social/google
     * @summary Google Native Authentication
     * @description Authenticates a user with a Google ID token from a native mobile app
     * @tag Social Authentication
     * @requestBody id_token:string="ID token obtained from Google Sign-In SDK" {required=id_token}
     * @response 200 application/json "Successfully authenticated with Google" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing ID token"
     * @response 401 "Failed to verify Google ID token"
     * @response 500 "Server error during authentication"
     */
    Router::post('/google', function (Request $request) {
        try {
            // Get the ID token from the request
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;

            if (empty($idToken)) {
                return Response::error('Missing ID token', ErrorCodes::BAD_REQUEST)->send();
            }

            // Get the Google provider from DI container
            $container = app();
            $googleProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider::class);

            // Verify the ID token
            $userData = $googleProvider->verifyNativeToken($idToken);

            if (!$userData) {
                $error = $googleProvider->getError() ?: 'Failed to verify Google ID token';
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error('Failed to authenticate with Google: ' . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route GET /auth/social/google/callback
     * @summary Google OAuth Callback
     * @description Callback endpoint that processes the OAuth response from Google
     * @tag Social Authentication
     * @param code query string true "Authorization code from Google"
     * @param state query string true "State token for CSRF protection"
     * @response 200 application/json "Successfully authenticated with Google" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     * expires_in:integer="Token expiration time in seconds",
     * user:object="User profile information"}
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    Router::get('/google/callback', function (Request $request) {
        try {
            // Get the Google auth provider from DI container
            $container = app();
            $googleProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider::class);

            // Process the OAuth callback
            $userData = $googleProvider->authenticate($request);

            if (!$userData) {
                $error = $googleProvider->getError() ?: "Failed to authenticate with Google";
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error("Failed to process Google authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route GET /auth/social/facebook
     * @summary Facebook OAuth Authentication
     * @description Initiates the OAuth flow with Facebook for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to Facebook's OAuth authorization page"
     */
    Router::get('/facebook', function (Request $request) {
        // Get the Facebook auth provider from DI container
        $container = app();
        $facebookProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider::class);

        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $facebookProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $facebookProvider->getError() ?: "Failed to initiate Facebook authentication";
            return Response::error($error, ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        } catch (\Exception $e) {
            error_log("Facebook authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize Facebook authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route POST /auth/social/facebook
     * @summary Facebook Native Authentication
     * @description Authenticates a user with a Facebook access token from a native mobile app
     * @tag Social Authentication
     * @requestBody access_token:string="Access token obtained from Facebook Login SDK" {required=access_token}
     * @response 200 application/json "Successfully authenticated with Facebook" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing access token"
     * @response 401 "Failed to verify Facebook access token"
     * @response 500 "Server error during authentication"
     */
    Router::post('/facebook', function (Request $request) {
        try {
            // Get the access token from the request
            $requestData = json_decode($request->getContent(), true);
            $accessToken = $requestData['access_token'] ?? null;

            if (empty($accessToken)) {
                return Response::error('Missing access token', ErrorCodes::BAD_REQUEST)->send();
            }

            // Get the Facebook provider from DI container
            $container = app();
            $facebookProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider::class);

            // Verify the access token
            $userData = $facebookProvider->verifyNativeToken($accessToken);

            if (!$userData) {
                $error = $facebookProvider->getError() ?: 'Failed to verify Facebook access token';
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error('Failed to authenticate with Facebook: ' . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route GET /auth/social/facebook/callback
     * @summary Facebook OAuth Callback
     * @description Callback endpoint that processes the OAuth response from Facebook
     * @tag Social Authentication
     * @param code query string true "Authorization code from Facebook"
     * @param state query string true "State token for CSRF protection"
     * @response 200 application/json "Successfully authenticated with Facebook" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     * user:object="User profile information"}
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    Router::get('/facebook/callback', function (Request $request) {
        try {
            // Get the Facebook auth provider from DI container
            $container = app();
            $facebookProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider::class);

            // Process the OAuth callback
            $userData = $facebookProvider->authenticate($request);

            if (!$userData) {
                $error = $facebookProvider->getError() ?: "Failed to authenticate with Facebook";
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error("Failed to process Facebook authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route GET /auth/social/github
     * @summary GitHub OAuth Authentication
     * @description Initiates the OAuth flow with GitHub for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to GitHub's OAuth authorization page"
     */
    Router::get('/github', function (Request $request) {
        // Get the GitHub auth provider from DI container
        $container = app();
        $githubProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider::class);

        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $githubProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $githubProvider->getError() ?: "Failed to initiate GitHub authentication";
            return Response::error($error, ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        } catch (\Exception $e) {
            error_log("GitHub authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize GitHub authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route POST /auth/social/github
     * @summary GitHub Native Authentication
     * @description Authenticates a user with a GitHub access token from a native mobile app
     * @tag Social Authentication
     * @requestBody access_token:string="Access token obtained from GitHub OAuth" {required=access_token}
     * @response 200 application/json "Successfully authenticated with GitHub" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing access token"
     * @response 401 "Failed to verify GitHub access token"
     * @response 500 "Server error during authentication"
     */
    Router::post('/github', function (Request $request) {
        try {
            // Get the access token from the request
            $requestData = json_decode($request->getContent(), true);
            $accessToken = $requestData['access_token'] ?? null;

            if (empty($accessToken)) {
                return Response::error('Missing access token', ErrorCodes::BAD_REQUEST)->send();
            }

            // Get the GitHub provider from DI container
            $container = app();
            $githubProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider::class);

            // Verify the access token
            $userData = $githubProvider->verifyNativeToken($accessToken);

            if (!$userData) {
                $error = $githubProvider->getError() ?: 'Failed to verify GitHub access token';
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error('Failed to authenticate with GitHub: ' . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route GET /auth/social/github/callback
     * @summary GitHub OAuth Callback
     * @description Callback endpoint that processes the OAuth response from GitHub
     * @tag Social Authentication
     * @param code query string true "Authorization code from GitHub"
     * @param state query string true "State token for CSRF protection"
     * @response 200 application/json "Successfully authenticated with GitHub" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     *  user:object="User profile information"}
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    Router::get('/github/callback', function (Request $request) {
        try {
            // Get the GitHub auth provider from DI container
            $container = app();
            $githubProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider::class);

            // Process the OAuth callback
            $userData = $githubProvider->authenticate($request);

            if (!$userData) {
                $error = $githubProvider->getError() ?: "Failed to authenticate with GitHub";
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error("Failed to process GitHub authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route GET /auth/social/apple
     * @summary Apple OAuth Authentication
     * @description Initiates the OAuth flow with Apple for user authentication
     * @tag Social Authentication
     * @response 302 "Redirects to Apple's OAuth authorization page"
     */
    Router::get('/apple', function (Request $request) {
        // Get the Apple auth provider from DI container
        $container = app();
        $appleProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider::class);

        try {
            // The authenticate method handles web-based OAuth flow with redirects
            $result = $appleProvider->authenticate($request);

            // If we reach this point, something went wrong as we should have been redirected
            $error = $appleProvider->getError() ?: "Failed to initiate Apple authentication";
            return Response::error($error, ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        } catch (\Exception $e) {
            error_log("Apple authentication error: " . $e->getMessage());
            return Response::error("Failed to initialize Apple authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route POST /auth/social/apple
     * @summary Apple Native Authentication
     * @description Authenticates a user with an Apple ID token from a native mobile app
     * @tag Social Authentication
     * @requestBody id_token:string="ID token obtained from Sign in with Apple SDK" {required=id_token}
     * @response 200 application/json "Successfully authenticated with Apple" {
     *   user:object="User profile information",
     *   tokens:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token",
     *     expires_in:integer="Token expiration time in seconds"
     *   }
     * }
     * @response 400 "Missing ID token"
     * @response 401 "Failed to verify Apple ID token"
     * @response 500 "Server error during authentication"
     */
    Router::post('/apple', function (Request $request) {
        try {
            // Get the ID token from the request
            $requestData = json_decode($request->getContent(), true);
            $idToken = $requestData['id_token'] ?? null;

            if (empty($idToken)) {
                return Response::error('Missing ID token', ErrorCodes::BAD_REQUEST)->send();
            }

            // Get the Apple provider from DI container
            $container = app();
            $appleProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider::class);

            // Verify the ID token
            $userData = $appleProvider->verifyNativeToken($idToken);

            if (!$userData) {
                $error = $appleProvider->getError() ?: 'Failed to verify Apple ID token';
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error('Failed to authenticate with Apple: ' . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    /**
     * @route POST /auth/social/apple/callback
     * @summary Apple OAuth Callback
     * @description Callback endpoint that processes the OAuth response from Apple
     * @tag Social Authentication
     * @requestBody code:string="Authorization code from Apple"
     * @requestBody state:string="State token for CSRF protection"
     * @requestBody user:string="JSON string containing user information (only provided on first login)"
     * {required=code,state}
     * @response 200 application/json "Successfully authenticated with Apple" {
     *   access_token:string="JWT access token",
     *   refresh_token:string="JWT refresh token",
     *   expires_in:integer="Token expiration time in seconds",
     * user:object="User profile information"}
     * @response 400 "Bad request or invalid parameters"
     * @response 401 "Authentication failed"
     */
    Router::post('/apple/callback', function (Request $request) {
        try {
            // Get the Apple auth provider from DI container
            $container = app();
            $appleProvider = $container->get(\Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider::class);

            // Process the OAuth callback
            $userData = $appleProvider->authenticate($request);

            if (!$userData) {
                $error = $appleProvider->getError() ?: "Failed to authenticate with Apple";
                return Response::error($error, ErrorCodes::UNAUTHORIZED)->send();
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
            return Response::error("Failed to process Apple authentication: " . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    });

    // Remove the /native group since we're using HTTP methods instead
});

// User social accounts management (requires authentication)
Router::group('/user/social-accounts', function () {
    /**
     * @route GET /user/social-accounts
     * @summary Get Connected Social Accounts
     * @description Retrieve all social accounts connected to the authenticated user
     * @tag Social Account Management
     * @requiresAuth true
     * @response 200 application/json "Successfully retrieved social accounts" {
     *   status:string="success",
     *   message:string="Social accounts retrieved successfully",
     *   data:[{
     *     uuid:string="Unique identifier for the social account",
     *     provider:string="Social provider name (google, facebook, github, etc.)",
     *     created_at:string="When the account was connected",
     * updated_at:string="When the account was last updated"}]}
     * @response 401 "Unauthorized - User is not authenticated"
     * @response 500 "Server error retrieving social accounts"
     */
    Router::get('/', function (Request $request) {
        try {
            // Get authenticated user
            $userData = $request->attributes->get('user');

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', ErrorCodes::UNAUTHORIZED)->send();
            }

            $userUuid = $userData['uuid'];

            // Get database components from DI container
            $container = app();
            $connection = $container->get(\Glueful\Database\Connection::class);
            $db = $container->get(\Glueful\Database\QueryBuilder::class);

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
            return Response::error('Failed to retrieve social accounts: ' . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }, requiresAuth: true);

    /**
     * @route DELETE /user/social-accounts/{uuid}
     * @summary Unlink Social Account
     * @description Remove a social provider connection from the authenticated user
     * @tag Social Account Management
     * @requiresAuth true
     * @param uuid path string true "UUID of the social account to unlink"
     * @response 200 application/json "Successfully unlinked social account" {status:string="success",
     * message:string="Social account unlinked successfully"}
     * @response 401 "Unauthorized - User is not authenticated"
     * @response 404 "Social account not found or not owned by user"
     * @response 500 "Server error unlinking social account"
     */
    Router::delete('/{uuid}', function (Request $request, string $uuid) {
        try {
            // Get authenticated user
            $userData = $request->attributes->get('user');

            if (!$userData || !isset($userData['uuid'])) {
                return Response::error('Unauthorized', ErrorCodes::UNAUTHORIZED)->send();
            }

            $userUuid = $userData['uuid'];

            // Get database components from DI container
            $container = app();
            $connection = $container->get(\Glueful\Database\Connection::class);
            $db = $container->get(\Glueful\Database\QueryBuilder::class);

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
                return Response::error('Failed to unlink social account', ErrorCodes::INTERNAL_SERVER_ERROR)->send();
            }

            return Response::ok(null, 'Social account unlinked successfully')->send();
        } catch (\Exception $e) {
            return Response::error('Failed to unlink social account: ' . $e->getMessage(), ErrorCodes::INTERNAL_SERVER_ERROR)->send();
        }
    }, requiresAuth: true);
});
