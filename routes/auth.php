<?php

use Glueful\Http\Router;
use Glueful\Controllers\AuthController;
use Glueful\Http\Response;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

// Auth routes
Router::group('/auth', function () use ($container) {
    /**
     * @route POST /auth/login
     * @summary User Login
     * @description Authenticates a user with username/email and password
     * @tag Authentication
     * @requestBody username:string="Username or email address" password:string="User password"
     * {required=username,password}
     * @response 200 application/json "Login successful" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="JWT access token",
     *     token_type:string="Bearer",
     *     expires_in:integer="Token expiration in seconds",
     *     refresh_token:string="JWT refresh token",
     *     user:{
     *       id:string="User unique identifier",
     *       email:string="Email address",
     *       email_verified:boolean="Email verification status",
     *       username:string="Username",
     *       name:string="Full name",
     *       given_name:string="First name",
     *       family_name:string="Last name",
     *       picture:string="Profile image URL",
     *       locale:string="User locale (e.g., en-US)",
     *       updated_at:integer="Last update timestamp (Unix epoch)"
     *     }
     *   },
     * }
     * @response 401 "Invalid credentials"
     * @response 400 "Missing required fields"
     */
    Router::post('/login', function (Request $request) use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->login();
    });

    /**
     * @route POST /auth/verify-email
     * @summary Verify Email
     * @description Sends a verification code to the provided email
     * @tag Authentication
     * @requestBody email:string="Email address to verify" {required=email}
     * @response 200 application/json "Verification code has been sent to your email" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="OTP expiration time in seconds"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 400 "Invalid email address"
     * @response 404 "Email not found"
     */
    Router::post('/verify-email', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->verifyEmail();
    });

    /**
     * @route POST /auth/verify-otp
     * @summary Verify OTP
     * @description Verifies the one-time password (OTP) sent to a user's email
     * @tag Authentication
     * @requestBody email:string="Email address" otp:string="One-time password code" {required=email,otp}
     * @response 200 application/json "OTP verified successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     verified:boolean="true",
     *     verified_at:string="Verification timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 400 "Invalid OTP"
     * @response 401 "OTP expired"
     */
    Router::post('/verify-otp', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->verifyOtp();
    });

    /**
     * @route POST /auth/forgot-password
     * @summary Forgot Password
     * @description Initiates the password reset process by sending a reset code
     * @tag Authentication
     * @requestBody email:string="Email address associated with account" {required=email}
     * @response 200 application/json "Password reset instructions sent to email" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="Reset code expiration time in seconds"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 404 "Email not found"
     * @response 400 "Invalid email format"
     */
    Router::post('/forgot-password', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->forgotPassword();
    });

    /**
     * @route POST /auth/reset-password
     * @summary Reset Password
     * @description Resets the user's password using the verification code
     * @tag Authentication
     * @requestBody email:string="Email address" password:string="New password" {required=email,password}
     * @response 200 application/json "Password has been reset successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     updated_at:string="Password reset timestamp"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 400 "Invalid password format"
     * @response 404 "Email not found"
     */
    Router::post('/reset-password', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->resetPassword();
    });

    /**
     * @route POST /auth/validate-token
     * @summary Validate Token
     * @description Validates the current authentication token
     * @tag Authentication
     * @requiresAuth true
     * @response 200 application/json "Token is valid" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="JWT access token",
     *     refresh_token:string="JWT refresh token"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 401 "Invalid or expired token"
     */
    Router::post('/validate-token', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->validateToken();
    });

    /**
     * @route POST /auth/refresh-token
     * @summary Refresh Token
     * @description Generates new access token using a valid refresh token
     * @tag Authentication
     * @requestBody refresh_token:string="JWT refresh token" {required=refresh_token}
     * @response 200 application/json "Token refreshed successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     access_token:string="New JWT access token",
     *     token_type:string="Bearer",
     *     expires_in:integer="Token expiration in seconds",
     *     refresh_token:string="New JWT refresh token"
     *   },
     *   code:integer="HTTP status code"
     * }
     * @response 401 "Invalid refresh token"
     * @response 400 "Missing refresh token"
     */
    Router::post('/refresh-token', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->refreshToken();
    });

    /**
     * @route POST /auth/logout
     * @summary User Logout
     * @description Invalidates the current authentication token
     * @tag Authentication
     * @requiresAuth true
     * @response 200 application/json "Logout successful" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   code:integer="HTTP status code"
     * }
     * @response 401 "Unauthorized - not logged in"
     */
    Router::post('/logout', function () use ($container) {
        $authController = $container->get(AuthController::class);
        return $authController->logout();
    });
});

/**
 * @route GET /csrf-token
 * @summary Get CSRF Token
 * @description Retrieves a CSRF token for form and AJAX request protection
 * @tag Security
 * @response 200 application/json "CSRF token retrieved successfully" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     token:string="CSRF token value",
 *     header:string="Header name for CSRF token (X-CSRF-Token)",
 *     field:string="Form field name for CSRF token (_token)",
 *     expires_at:integer="Token expiration timestamp"
 *   },
 *   code:integer="HTTP status code"
 * }
 * @response 500 "Failed to generate CSRF token"
 */
Router::get('/csrf-token', function (Request $request) {
    try {
        $tokenData = Utils::csrfTokenData($request);
        return Response::success($tokenData, 'CSRF token retrieved successfully');
    } catch (\Exception $e) {
        return Response::error('Failed to generate CSRF token: ' . $e->getMessage(), 500);
    }
});
