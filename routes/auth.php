<?php

use Glueful\Http\Router;
use Glueful\Controllers\AuthController;
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
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     tokens:{
     *       access_token:string="JWT access token",
     *       refresh_token:string="JWT refresh token",
     *       token_type:string="Token type (Bearer)",
     *       expires_in:integer="Token expiration in seconds"
     *     },
     *     user:{
     *       uuid:string="User unique identifier",
     *       username:string="Username",
     *       email:string="Email address",
     *       roles:array="User roles",
     *       permissions:array="User permissions",
     *       created_at:string="Account creation timestamp",
     *       last_login:string="Last login timestamp",
     *       profile:{
     *         first_name:string="First name",
     *         last_name:string="Last name",
     *         avatar_url:string="Profile image URL",
     *         full_name:string="Full name"
     *       }
     *     }
     *   },
     *   code:integer="HTTP status code"
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
     *   success:boolean="Success status",
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
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     verified:boolean="Verification status",
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
     *   success:boolean="Success status",
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
     *   success:boolean="Success status",
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
     *   success:boolean="Success status",
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
     *   success:boolean="Success status",
     *   message:string="Success message",
     *   data:{
     *     tokens:{
     *       access_token:string="New JWT access token",
     *       refresh_token:string="New JWT refresh token",
     *       token_type:string="Token type (Bearer)",
     *       expires_in:integer="Token expiration in seconds"
     *     }
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
     *   success:boolean="Success status",
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
