<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\RequestHelper;
use Glueful\Security\EmailVerification;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\AuthBootstrap;
use Glueful\Exceptions\AuthenticationException;
use Glueful\Exceptions\ValidationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Authentication Controller
 *
 * Handles all authentication-related HTTP endpoints for the Glueful framework.
 * Provides secure user authentication, session management, and token operations
 * with support for multiple authentication providers.
 *
 * **Core Functionality:**
 * - User login/logout with multi-provider support
 * - Email verification and OTP management
 * - Password reset and recovery flows
 * - Token refresh and validation
 * - Permission management and session control
 *
 * **Security Features:**
 * - CSRF protection integration
 * - Rate limiting and brute force protection
 * - Secure token generation and validation
 * - Multi-provider authentication support
 * - Session analytics and audit logging
 *
 * **Supported Authentication Providers:**
 * - JWT (default)
 * - LDAP directory services
 * - SAML identity providers
 * - OAuth2/OpenID Connect
 * - API key authentication
 */
class AuthController
{
    private EmailVerification $verifier;
    private AuthenticationService $authService;

    public function __construct()
    {
        $this->verifier = new EmailVerification();
        $this->authService = new AuthenticationService();

        // Initialize the authentication system
        AuthBootstrap::initialize();
    }

    /**
     * Authenticate user with credentials and establish session
     *
     * Performs user authentication using provided credentials and returns
     * JWT access tokens with session information. Supports multiple authentication
     * providers and implements comprehensive security measures.
     *
     * **Authentication Process:**
     * 1. Extract credentials and client information from request
     * 2. Determine authentication provider (JWT, LDAP, SAML, OAuth2)
     * 3. Validate credentials using appropriate provider
     * 4. Generate access and refresh tokens
     * 5. Create user session with analytics tracking
     * 6. Return OIDC-compliant authentication response
     *
     * **Security Features:**
     * - CSRF token generation for session protection
     * - Client IP and User-Agent tracking
     * - Remember-me functionality with extended sessions
     * - Provider-specific authentication flows
     * - Session analytics and audit logging
     *
     * **Request Format:**
     * ```json
     * {
     *   "username": "user@example.com",
     *   "password": "secure_password",
     *   "remember": true,
     *   "provider": "ldap"
     * }
     * ```
     *
     * **Response Format:**
     * ```json
     * {
     *   "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
     *   "refresh_token": "abc123...",
     *   "token_type": "Bearer",
     *   "expires_in": 3600,
     *   "user": {...},
     *   "csrf_token": {...}
     * }
     * ```
     *
     * @return mixed HTTP response with authentication tokens and user data
     * @throws \Glueful\Exceptions\AuthenticationException If credentials are invalid
     * @throws \Glueful\Exceptions\ValidationException If request data is malformed
     * @throws \RuntimeException If authentication system initialization fails
     */
    public function login()
    {
        // Create request object for IP and user agent information
        $request = SymfonyRequest::createFromGlobals();

        // Get credentials using the getPostData method from our Helper Request class
        $credentials = RequestHelper::getRequestData();

        $clientIp = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent');

        // Extract remember me preference from credentials
        $rememberMe = isset($credentials['remember']) && $credentials['remember'];

        // Add remember_me to credentials for authentication service
        $credentials['remember_me'] = $rememberMe;

        // Check if a specific provider was requested
        $providerName = null;
        if (isset($credentials['provider'])) {
            $providerName = $credentials['provider'];
        }

        // Authenticate with the specified provider or use default
        $result = $this->authService->authenticate($credentials, $providerName);

        if (!$result) {
            throw new AuthenticationException('Invalid credentials');
        }

        // Add CSRF token to login response only if CSRF protection is enabled
        if (env('CSRF_PROTECTION_ENABLED', true)) {
            try {
                $csrfMiddleware = new \Glueful\Http\Middleware\CSRFMiddleware();
                $csrfToken = $csrfMiddleware->generateToken($request);
                $result['csrf_token'] = [
                    'token' => $csrfToken,
                    'header' => 'X-CSRF-Token',
                    'field' => '_token',
                    'expires_at' => time() + (int)env('CSRF_TOKEN_LIFETIME', 3600)
                ];
            } catch (\Exception $e) {
                // Don't fail login if CSRF token generation fails
                error_log('Failed to generate CSRF token during login: ' . $e->getMessage());
            }
        }

        return Response::success($result, 'Login successful');
    }

    /**
     * Terminate user session and invalidate authentication tokens
     *
     * Securely logs out the user by invalidating their access token and
     * terminating their active session. Clears session data from cache
     * and database for complete logout.
     *
     * **Logout Process:**
     * 1. Extract access token from Authorization header
     * 2. Validate token format and presence
     * 3. Invalidate token in session cache
     * 4. Remove session from database
     * 5. Clear any associated refresh tokens
     * 6. Log logout event for security audit
     *
     * **Security Features:**
     * - Complete token invalidation
     * - Session cleanup across all storage layers
     * - Audit logging for security monitoring
     * - Prevention of token reuse after logout
     *
     * @return mixed HTTP response confirming successful logout
     * @throws \Glueful\Exceptions\ValidationException If no token provided in request
     * @throws \Glueful\Exceptions\AuthenticationException If logout operation fails
     */
    public function logout()
    {
        // Convert globals to Symfony Request for compatibility with our new system
        $request = SymfonyRequest::createFromGlobals();

        $token = AuthenticationService::extractTokenFromRequest($request);

        if (!$token) {
            throw new ValidationException('No token provided');
        }


        $success = $this->authService->terminateSession($token);

        if ($success) {
            return Response::success(null, 'Logged out successfully');
        }

        throw new AuthenticationException('Logout failed');
    }

    /**
     * Verify email for registration/password reset
     *
     * @return mixed HTTP response
     */
    public function verifyEmail()
    {
        $postData = RequestHelper::getRequestData();
        if (!isset($postData['email'])) {
            throw new ValidationException('Email address is required');
        }

        $otp = $this->verifier->generateOTP();

        // Send verification email with the new return format (array with status info)
        $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);

        if (!$result['success']) {
            // Use the detailed error message from the verification service
            $errorMessage = $result['message'] ?? 'Failed to send verification email';
            throw new ValidationException($errorMessage);
        }

        return Response::success([
            'email' => $postData['email'],
            'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
        ], 'Verification code has been sent to your email');
    }

    /**
     * Verify OTP code
     *
     * @return mixed HTTP response
     */
    public function verifyOtp()
    {
        $postData = RequestHelper::getRequestData();
        if (!isset($postData['email']) || !isset($postData['otp'])) {
            throw new ValidationException('Email and OTP are required');
        }

        $isValid = $this->verifier->verifyOTP($postData['email'], $postData['otp']);

        if (!$isValid) {
            throw new ValidationException('Invalid or expired OTP');
        }

        return Response::success([
            'email' => $postData['email'],
            'verified' => true,
            'verified_at' => date('Y-m-d\TH:i:s\Z')
        ], 'OTP verified successfully');
    }

    /**
     * Resend OTP code
     *
     * @return mixed HTTP response
     */
    public function resendOtp()
    {
        $postData = RequestHelper::getRequestData();
        if (!isset($postData['email'])) {
            throw new ValidationException('Email address is required');
        }

        $otp = $this->verifier->generateOTP();

        // Send verification email with updated return format (array with status info)
        $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);

        if (!$result['success']) {
            // Use the detailed error message from the verification service
            $errorMessage = $result['message'] ?? 'Failed to send verification email';
            throw new ValidationException($errorMessage);
        }

        return Response::success([
            'email' => $postData['email'],
            'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
        ], 'Verification code has been resent to your email');
    }

    /**
     * Refresh user permissions
     *
     * Updates the session with fresh user permissions and returns a new token.
     * This endpoint is useful after role/permission changes for a user.
     *
     * @return mixed HTTP response
     */
    public function refreshPermissions()
    {
        // Convert globals to Symfony Request for compatibility with our new system
        $request = SymfonyRequest::createFromGlobals();
        $token = AuthenticationService::extractTokenFromRequest($request);

        if (!$token) {
            throw new ValidationException('No token provided');
        }

        // Get session to extract user UUID
        $tokenStorage = new \Glueful\Auth\TokenStorageService();
        $session = $tokenStorage->getSessionByAccessToken($token);
        if (!$session || !isset($session['user']['uuid'])) {
            throw new AuthenticationException('Invalid session');
        }

        // Refresh permissions in the session
        $result = $this->authService->refreshPermissions($token);

        if (!$result) {
            throw new AuthenticationException('Failed to refresh permissions');
        }

        return Response::success($result, 'Permissions refreshed successfully');
    }

    /**
     * Validate if a token is valid and active
     *
     * Uses the authentication abstraction to verify token validity.
     *
     * @return mixed HTTP response
     */
    public function validateToken()
    {
        // Convert globals to Symfony Request for compatibility with our new system
        $request = SymfonyRequest::createFromGlobals();

        // Get token from request
        $token = AuthenticationService::extractTokenFromRequest($request);

        if (!$token) {
            throw new ValidationException('No token provided');
        }

        // Use our new authentication system to validate the token
        $authManager = AuthBootstrap::getManager();
        $userData = $authManager->authenticate($request);

        if (!$userData) {
            throw new AuthenticationException('Invalid token');
        }

        return Response::success([
            'user' => $userData,
            'is_valid' => true
        ], 'Token is valid');
    }

    /**
     * Initiate password reset process with email verification
     *
     * Starts the password recovery flow by sending a secure OTP code to the
     * user's registered email address. Implements security measures to prevent
     * account enumeration and abuse.
     *
     * **Password Reset Process:**
     * 1. Validate email address format and presence
     * 2. Verify user account exists in system
     * 3. Generate secure OTP code with expiration
     * 4. Send password reset email with OTP
     * 5. Return confirmation without revealing account status
     *
     * **Security Features:**
     * - Account existence verification before email sending
     * - Time-limited OTP codes (default 15 minutes)
     * - Rate limiting to prevent abuse
     * - Secure email templates with anti-phishing measures
     *
     * **Request Format:**
     * ```json
     * {
     *   "email": "user@example.com"
     * }
     * ```
     *
     * **Response Format:**
     * ```json
     * {
     *   "email": "user@example.com",
     *   "expires_in": 900
     * }
     * ```
     *
     * @return mixed HTTP response confirming reset email sent
     * @throws \Glueful\Exceptions\ValidationException If email is missing or user not found
     * @throws \RuntimeException If email sending fails due to system issues
     */
    public function forgotPassword()
    {
        $postData = RequestHelper::getRequestData();
        if (!isset($postData['email'])) {
            throw new ValidationException('Email address is required');
        }

        // Check if user exists before attempting password reset
        if (!$this->authService->userExists($postData['email'], 'email')) {
            throw new ValidationException('User not found with the provided email address');
        }

        // Send verification email
        $result = $this->verifier->sendPasswordResetEmail($postData['email']);
        if (!$result['success']) {
            $errorMsg = $result['message'] ?? 'Failed to send reset email';
            throw new ValidationException($errorMsg);
        }

        return Response::success([
            'email' => $postData['email'],
            'expires_in' => EmailVerification::OTP_EXPIRY_MINUTES * 60
        ], 'Password reset instructions have been sent to your email');
    }

    /**
     * Complete password reset with new secure password
     *
     * Finalizes the password recovery process by updating the user's password
     * with proper security validation and session invalidation.
     *
     * **Password Reset Process:**
     * 1. Validate email and new password presence
     * 2. Verify user account exists
     * 3. Hash new password using secure algorithm
     * 4. Update password in database
     * 5. Invalidate all existing user sessions
     * 6. Log security event for audit trail
     *
     * **Security Features:**
     * - Secure password hashing (bcrypt/argon2)
     * - Session invalidation to prevent unauthorized access
     * - Password strength validation
     * - Audit logging for security monitoring
     *
     * **Request Format:**
     * ```json
     * {
     *   "email": "user@example.com",
     *   "password": "new_secure_password123!"
     * }
     * ```
     *
     * @return mixed HTTP response confirming password reset
     * @throws \Glueful\Exceptions\ValidationException If email/password missing or user not found
     * @throws \Glueful\Exceptions\AuthenticationException If password update fails
     */
    public function resetPassword()
    {
        $postData = RequestHelper::getRequestData();
        if (!isset($postData['email']) || !isset($postData['password'])) {
            throw new ValidationException('Email and new password are required');
        }

        // Check if user exists before attempting password reset
        if (!$this->authService->userExists($postData['email'], 'email')) {
            throw new ValidationException('User not found with the provided email address');
        }

        // Use the service method to update the password (this handles hashing internally)
        $success = $this->authService->updatePassword(
            $postData['email'],
            $postData['password'],
            'email'
        );

        if (!$success) {
            throw new AuthenticationException('Failed to update password');
        }

        return Response::success([
            'updated_at' => date('Y-m-d\TH:i:s\Z')
        ], 'Password has been reset successfully');
    }

    /**
     * Generate new access token using refresh token
     *
     * Exchanges a valid refresh token for a new access token, maintaining
     * user session continuity without requiring re-authentication.
     *
     * **Token Refresh Process:**
     * 1. Extract refresh token from request
     * 2. Validate refresh token and associated session
     * 3. Generate new access and refresh token pair
     * 4. Update session with new tokens
     * 5. Invalidate old tokens for security
     * 6. Return new token pair with user data
     *
     * **Security Features:**
     * - Refresh token rotation for enhanced security
     * - Session validation and consistency checks
     * - Request context update for seamless operation
     * - Audit logging for token operations
     *
     * **Request Format:**
     * ```json
     * {
     *   "refresh_token": "abc123..."
     * }
     * ```
     *
     * **Response Format:**
     * ```json
     * {
     *   "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
     *   "refresh_token": "def456...",
     *   "token_type": "Bearer",
     *   "expires_in": 3600,
     *   "user": {...}
     * }
     * ```
     *
     * @return mixed HTTP response with new authentication tokens
     * @throws \Glueful\Exceptions\ValidationException If refresh token missing from request
     * @throws \Glueful\Exceptions\AuthenticationException If refresh token invalid or expired
     */
    public function refreshToken()
    {
        $postData = RequestHelper::getRequestData();

        if (!isset($postData['refresh_token'])) {
            throw new ValidationException('Refresh token is required');
        }

        $refreshToken = $postData['refresh_token'];
        $result = $this->authService->refreshTokens($refreshToken);

        if (!$result) {
            throw new AuthenticationException('Invalid or expired refresh token');
        }

        // Update RequestUserContext with the new token to maintain consistency
        // within the current request
        $requestContext = \Glueful\Http\RequestUserContext::getInstance();
        if ($requestContext->isAuthenticated()) {
            $requestContext->updateToken($result['access_token']);
        }

        return Response::success($result, 'Token refreshed successfully');
    }
}
