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
     * User login
     *
     * Authenticates user with credentials and returns tokens.
     * Supports different authentication providers.
     *
     * @return mixed HTTP response
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
     * User logout
     *
     * Terminates user session and invalidates tokens.
     *
     * @return mixed HTTP response
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
     * Reset user password
     *
     * Securely changes a user's password after verification.
     *
     * @return mixed HTTP response
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
     * Refresh authentication token
     *
     * Generates a new access token using a valid refresh token.
     *
     * @return mixed HTTP response
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
